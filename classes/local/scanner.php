<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Runs a scan from start to finish.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

use Throwable;
use tool_upgradeguard\event\scan_completed;
use tool_upgradeguard\event\scan_started;
use tool_upgradeguard\local\check\registry;
use tool_upgradeguard\local\collector\orphan_scanner;
use tool_upgradeguard\local\collector\plugin_inventory;
use tool_upgradeguard\local\collector\update_checker;
use tool_upgradeguard\local\collector\usage_collector;
use tool_upgradeguard\local\repository\scan_repository;

/**
 * Orchestrates a scan: collect, check, score, store.
 *
 * The scanner owns the order of the steps and nothing else: collecting is done
 * by collectors, deciding by checks and calculators, and storing by the
 * repository. Every dependency can be replaced, which is how this class is unit
 * tested without a real site.
 */
final class scanner {
    /** @var int Progress percentage after collecting the plugin inventory. */
    private const PROGRESS_PLUGINS = 30;

    /** @var int Progress percentage after measuring usage. */
    private const PROGRESS_USAGE = 50;

    /** @var int Progress percentage after running the checks. */
    private const PROGRESS_CHECKS = 80;

    /** @var scan_repository Database access. */
    private scan_repository $repository;

    /** @var registry The checks to run. */
    private registry $checks;

    /** @var plugin_inventory Collector of the plugin inventory. */
    private plugin_inventory $inventory;

    /** @var orphan_scanner Collector of plugins outside the expected location. */
    private orphan_scanner $orphans;

    /** @var usage_collector Collector of usage counts. */
    private usage_collector $usage;

    /** @var update_checker Collector of available updates. */
    private update_checker $updates;

    /** @var score_calculator Calculator of the readiness score. */
    private score_calculator $scorer;

    /** @var verdict_calculator Calculator of the verdict. */
    private verdict_calculator $verdicts;

    /** @var status_calculator Calculator of the per plugin status. */
    private status_calculator $statuses;

    /**
     * Create the scanner.
     *
     * @param scan_repository|null $repository Database access.
     * @param registry|null $checks The checks to run.
     * @param plugin_inventory|null $inventory Collector of the plugin inventory.
     * @param orphan_scanner|null $orphans Collector of misplaced plugins.
     * @param usage_collector|null $usage Collector of usage counts.
     * @param update_checker|null $updates Collector of available updates.
     * @param score_calculator|null $scorer Calculator of the readiness score.
     * @param verdict_calculator|null $verdicts Calculator of the verdict.
     * @param status_calculator|null $statuses Calculator of the per plugin status.
     */
    public function __construct(
        ?scan_repository $repository = null,
        ?registry $checks = null,
        ?plugin_inventory $inventory = null,
        ?orphan_scanner $orphans = null,
        ?usage_collector $usage = null,
        ?update_checker $updates = null,
        ?score_calculator $scorer = null,
        ?verdict_calculator $verdicts = null,
        ?status_calculator $statuses = null,
    ) {
        $this->repository = $repository ?? new scan_repository();
        $this->checks = $checks ?? new registry();
        $this->inventory = $inventory ?? new plugin_inventory();
        $this->orphans = $orphans ?? new orphan_scanner();
        $this->usage = $usage ?? new usage_collector();
        $this->updates = $updates ?? new update_checker();
        $this->scorer = $scorer ?? new score_calculator();
        $this->verdicts = $verdicts ?? new verdict_calculator();
        $this->statuses = $statuses ?? new status_calculator();
    }

    /**
     * Queue a new scan.
     *
     * @param target $target The Moodle version to scan against.
     * @param int $userid The administrator who wants the scan.
     * @param array $environment Environment snapshot from environment_inspector.
     * @return int Id of the queued scan.
     */
    public function queue_scan(target $target, int $userid, array $environment = []): int {
        global $CFG;

        $scanid = $this->repository->create_queued_scan(
            $target,
            (string) ($CFG->release ?? ''),
            (int) ($CFG->branch ?? 0),
            (new target_repository())->get_dataset_version(),
            $userid,
            $environment
        );

        scan_started::create([
            'context' => \context_system::instance(),
            'objectid' => $scanid,
            'userid' => $userid,
            'other' => ['targetversion' => $target->version],
        ])->trigger();

        return $scanid;
    }

    /**
     * Calculate the score, verdict and summary counts for collected results.
     *
     * Site-wide findings are separated from plugin rows before scoring. This is
     * the single aggregation boundary used by the scan task, so an environment
     * blocker cannot disappear between the checks and the stored verdict.
     *
     * @param array $pluginrecords Stored plugin records.
     * @param array $findings Findings with their component and finding object.
     * @return array{score:int, verdict:verdict, counts:array}
     */
    public function calculate_result(array $pluginrecords, array $findings): array {
        $sitefindings = [];
        foreach ($findings as $entry) {
            if ($entry['component'] === null) {
                $sitefindings[] = $entry['finding'];
            }
        }

        $counts = $this->scorer->count_statuses($pluginrecords);
        $environmentcounts = $this->scorer->count_environment($sitefindings);
        $counts['blockers'] += $environmentcounts['environmentblockers'];
        $counts['cautions'] += $environmentcounts['environmentcautions'];
        $counts['environmentblockers'] = $environmentcounts['environmentblockers'];
        $counts['environmentcautions'] = $environmentcounts['environmentcautions'];

        $score = $this->scorer->calculate($pluginrecords, $sitefindings);
        $verdict = $this->verdicts->calculate(
            $score,
            $counts['hasusedblocker'],
            $counts['cautions'] > 0,
            $counts['environmentblockers'] > 0
        );

        return [
            'score' => $score,
            'verdict' => $verdict,
            'counts' => $counts,
        ];
    }

    /**
     * Run a queued scan.
     *
     * This is the entry point of the ad hoc task. It never throws: a failure is
     * stored on the scan, so that an administrator sees something useful instead
     * of a task that quietly disappears.
     *
     * @param int $scanid The scan to run.
     * @return void
     */
    public function run_scan(int $scanid): void {
        if (!$this->repository->claim_scan($scanid)) {
            // Somebody else owns this scan already.
            return;
        }

        try {
            $this->do_run_scan($scanid);
        } catch (Throwable $e) {
            debugging('tool_upgradeguard: scan ' . $scanid . ' failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $this->repository->fail_scan($scanid, get_string('scan_failed', 'tool_upgradeguard'));
        }
    }

    /**
     * Do the actual work of a claimed scan.
     *
     * @param int $scanid The scan to run.
     * @return void
     */
    private function do_run_scan(int $scanid): void {
        $scan = $this->repository->get_scan($scanid);
        if ($scan === null) {
            return;
        }

        $target = (new target_repository())->get_target((string) $scan->targetversion);
        if ($target === null) {
            $this->repository->fail_scan($scanid, get_string('error_targetnotfound', 'tool_upgradeguard'));
            return;
        }

        $this->repository->update_progress($scanid, 'stage_collecting', 10);

        $plugins = $this->inventory->collect($target);
        $plugins += $this->orphans->collect($target);

        $this->repository->update_progress($scanid, 'stage_measuring', self::PROGRESS_PLUGINS);

        $usage = $this->usage->collect($plugins);
        $updates = $this->updates->get_updates($plugins);

        $this->repository->update_progress($scanid, 'stage_checking', self::PROGRESS_USAGE);

        // The environment was captured when the scan was queued, so the report
        // keeps describing the server the site was actually serving with.
        $environment = scan_repository::get_environment($scan);

        $context = new scan_context(
            $target,
            $plugins,
            $usage,
            $updates,
            $environment['php'] ?? PHP_VERSION,
            $this->updates->is_information_available($updates),
            $environment['dbvendor'] ?? '',
            $environment['dbversion'] ?? '',
            $environment['extensions'] ?? []
        );
        [$pluginrecords, $findings] = $this->run_checks($context);

        $this->repository->update_progress($scanid, 'stage_storing', self::PROGRESS_CHECKS);

        $result = $this->calculate_result($pluginrecords, $findings);
        $score = $result['score'];
        $verdict = $result['verdict'];
        $counts = $result['counts'];

        $this->repository->save_results($scanid, $pluginrecords, $findings);
        $this->repository->finish_scan($scanid, $score, $verdict->value, [
            'plugincount' => count($pluginrecords),
            'blockercount' => $counts['blockers'],
            'cautioncount' => $counts['cautions'],
            'unknowncount' => $counts['unknowns'],
            'environmentblockercount' => $counts['environmentblockers'],
            'environmentcautioncount' => $counts['environmentcautions'],
        ]);

        scan_completed::create([
            'context' => \context_system::instance(),
            'objectid' => $scanid,
            'userid' => (int) $scan->userid,
            'other' => [
                'score' => $score,
                'verdict' => $verdict->value,
                'targetversion' => $target->version,
            ],
        ])->trigger();
    }

    /**
     * Run every check for the site and for every plugin.
     *
     * @param scan_context $context Everything collected for this scan.
     * @return array Two element list: the plugin records and the findings.
     */
    private function run_checks(scan_context $context): array {
        $findings = [];
        $pluginrecords = [];

        foreach ($this->checks->get_checks() as $check) {
            if (!$check->is_applicable(null, $context)) {
                continue;
            }
            foreach ($check->run(null, $context) as $finding) {
                $findings[] = ['component' => null, 'finding' => $finding];
            }
        }

        foreach ($context->plugins as $component => $plugin) {
            if ($plugin->isstandard) {
                // Plugins that ship with Moodle are upgraded together with core,
                // so they are not part of the report. They stay in the inventory
                // because checks do look them up (for dependencies, for example).
                continue;
            }

            $pluginfindings = [];

            foreach ($this->checks->get_checks() as $check) {
                if (!$check->is_applicable($plugin, $context)) {
                    continue;
                }
                foreach ($check->run($plugin, $context) as $finding) {
                    $pluginfindings[] = $finding;
                    $findings[] = ['component' => $component, 'finding' => $finding];
                }
            }

            usort($pluginfindings, [finding::class, 'compare']);

            $pluginrecords[] = $this->get_plugin_record(
                $plugin,
                $this->statuses->calculate($pluginfindings),
                $context
            );
        }

        return [$pluginrecords, $findings];
    }

    /**
     * Build the stored record for one scanned plugin.
     *
     * @param plugin_snapshot $plugin The scanned plugin.
     * @param status $status The status the checks gave it.
     * @param scan_context $context Everything collected for this scan.
     * @return \stdClass
     */
    private function get_plugin_record(plugin_snapshot $plugin, status $status, scan_context $context): \stdClass {
        $usage = $context->get_usage($plugin->component);
        $update = $context->get_update($plugin->component);

        return (object) [
            // Stamp filled in by the repository when the row is stored.
            'scanid' => 0,
            'component' => $plugin->component,
            'plugintype' => $plugin->plugintype,
            'name' => $plugin->name,
            'displayname' => shorten_text($plugin->displayname, 255),
            'versiondisk' => $plugin->versiondisk,
            'versiondb' => $plugin->versiondb,
            'pluginrelease' => $plugin->release === null ? null : shorten_text($plugin->release, 255),
            'versionrequires' => $plugin->versionrequires === null ? null : (string) $plugin->versionrequires,
            'supportedlist' => $plugin->supportedlist,
            'incompatiblebranch' => $plugin->incompatible === null ? null : (string) $plugin->incompatible,
            'isstandard' => $plugin->isstandard ? 1 : 0,
            'installed' => $plugin->installed ? 1 : 0,
            'currentpath' => shorten_text($plugin->currentpath, 255),
            'newpath' => shorten_text($plugin->newpath, 255),
            'status' => $status->value,
            'usagecount' => $usage === null ? null : ($usage['count'] ?? null),
            'usagedetail' => empty($usage['detail']) ? null : json_encode($usage['detail']),
            'updateavailable' => $update === null ? 0 : 1,
            'updateversion' => $update === null ? null : (string) $update->version,
            'updaterelease' => $update === null || empty($update->release) ? null : shorten_text((string) $update->release, 255),
            'timecreated' => time(),
        ];
    }
}
