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
 * Data for the Upgrade Guard dashboard.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\output;

use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;
use tool_upgradeguard\local\checklist;
use tool_upgradeguard\local\collector\update_checker;
use tool_upgradeguard\local\finding;
use tool_upgradeguard\local\move_list;
use tool_upgradeguard\local\plural;
use tool_upgradeguard\local\score_calculator;
use tool_upgradeguard\local\severity;
use tool_upgradeguard\local\status;
use tool_upgradeguard\local\target;
use tool_upgradeguard\local\target_feed;
use tool_upgradeguard\local\target_repository;
use tool_upgradeguard\local\usage_text;
use tool_upgradeguard\local\verdict as verdict_value;

/**
 * Everything the dashboard template needs, already turned into text.
 *
 * Mustache cannot look things up, so every label, class name and formatted date
 * is prepared here. That keeps the template free of logic and the page easy to
 * review.
 */
final class dashboard implements renderable, templatable {
    /** @var int How many actions the overview shows before it hides the rest. */
    private const MAXVISIBLEACTIONS = 3;

    /**
     * @var int Fewest hidden rows worth a disclosure control. Hiding one or two
     * rows behind a button costs more space than it saves and hides work from
     * the reviewer, so below this the whole list is shown instead.
     */
    private const MINHIDDENACTIONS = 3;

    /** @var stdClass|null The scan being shown, null when there is none. */
    private ?stdClass $scan;

    /** @var array The stored plugin rows of the scan. */
    private array $plugins;

    /** @var array Findings grouped by component, '' for site wide findings. */
    private array $findings;

    /** @var array The action list built from the findings. */
    private array $actions;

    /** @var array Recent scans. */
    private array $history;

    /** @var string Rendered HTML of the "start a scan" form. */
    private string $formhtml;

    /** @var int Version of the rule dataset. */
    private int $datasetversion;

    /** @var bool Whether the current user may start a scan. */
    private bool $canrunscan;

    /** @var bool Whether the current user may export a report. */
    private bool $canexport;

    /** @var int Unix time at which Moodle last began cron, zero when unavailable. */
    private int $lastcronstart;

    /** @var target|null The target the scan was run against, null when unknown. */
    private ?target $target;

    /**
     * Create the dashboard data.
     *
     * @param stdClass|null $scan The scan to show.
     * @param array $plugins Plugin rows of that scan.
     * @param array $findings Findings grouped by component.
     * @param array $actions Action list of that scan.
     * @param array $history Recent scans.
     * @param string $formhtml Rendered scan form.
     * @param int $datasetversion Version of the rule dataset.
     * @param bool $canrunscan Whether the user may start a scan.
     * @param bool $canexport Whether the user may export.
     * @param int $lastcronstart Unix time when cron last started, or zero when unavailable.
     * @param target|null $target The target the scan was run against.
     */
    public function __construct(
        ?stdClass $scan,
        array $plugins,
        array $findings,
        array $actions,
        array $history,
        string $formhtml,
        int $datasetversion,
        bool $canrunscan,
        bool $canexport,
        int $lastcronstart,
        ?target $target = null,
    ) {
        $this->scan = $scan;
        $this->plugins = $plugins;
        $this->findings = $findings;
        $this->actions = $actions;
        $this->history = $history;
        $this->formhtml = $formhtml;
        $this->datasetversion = $datasetversion;
        $this->canrunscan = $canrunscan;
        $this->canexport = $canexport;
        $this->lastcronstart = $lastcronstart;
        $this->target = $target;
    }

    /**
     * Export the template context.
     *
     * @param renderer_base $output The renderer.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $environmentcontext = environment_context::build($this->target, $this->scan, $this->findings[''] ?? []);

        $context = [
            'hasscan' => $this->scan !== null,
            'formhtml' => $this->formhtml,
            'canrunscan' => $this->canrunscan,
            // The start scan partial is rendered both with and without a stored
            // scan, so its label is part of the shared context, not the empty
            // state below.
            'welcomestartscan' => get_string('welcomestartscan', 'tool_upgradeguard'),
            'disclaimer' => get_string('disclaimer', 'tool_upgradeguard'),
            'history' => $this->get_history_context(),
            'hashistory' => !empty($this->history),
            'historyheading' => get_string('scanhistory', 'tool_upgradeguard'),
            'plugins' => $this->get_plugins_context(),
            'hasplugins' => !empty($this->plugins),
            'actions' => $this->get_actions_context(),
            'hasactions' => !empty($this->actions),
            'actionsheading' => get_string('actions', 'tool_upgradeguard'),
            // The shared findings partial iterates over a plain "findings" list,
            // so the site wide findings of the scan are exported under that key.
            'findings' => $this->get_findings_context($this->findings[''] ?? []),
            'hassitefindings' => !empty($this->findings['']),
            'datasetversion' => get_string('dataset_version', 'tool_upgradeguard', $this->datasetversion),
        ];
        $context += $environmentcontext;
        $context += $this->get_move_list_context();
        $context += $this->get_checklist_context();

        // The tab bar, and every link a tab needs, is visible in every state, so
        // it is added before the early return for a site without scans.
        $context['tabs'] = [
            [
                'id' => 'overview',
                'label' => get_string('tab_overview', 'tool_upgradeguard'),
                'icon' => 'fa fa-gauge-high',
                'active' => true,
            ],
            [
                'id' => 'plugins',
                'label' => get_string('tab_plugins', 'tool_upgradeguard'),
                'icon' => 'fa fa-plug',
                'active' => false,
            ],
            [
                'id' => 'environment',
                'label' => get_string('tab_environment', 'tool_upgradeguard'),
                'icon' => 'fa fa-server',
                'active' => false,
            ],
            [
                'id' => 'files',
                'label' => get_string('tab_files', 'tool_upgradeguard'),
                'icon' => 'fa fa-folder-tree',
                'active' => false,
            ],
            [
                'id' => 'checklist',
                'label' => get_string('tab_checklist', 'tool_upgradeguard'),
                'icon' => 'fa fa-list-check',
                'active' => false,
            ],
            [
                'id' => 'history',
                'label' => get_string('tab_history', 'tool_upgradeguard'),
                'icon' => 'fa fa-clock-rotate-left',
                'active' => false,
            ],
        ];

        $context['historyurl'] = (new moodle_url('/admin/tool/upgradeguard/history.php'))->out(false);
        $context['pluginspagetext'] = get_string('pluginspagetext', 'tool_upgradeguard');
        $context['filenoneeded'] = get_string('filenoneeded', 'tool_upgradeguard');
        $context['checklistnotready'] = get_string('checklistnotready', 'tool_upgradeguard');
        $context['rulesrefresherror'] = (string) get_config('tool_upgradeguard', 'ruleslasterror');
        $context += $this->get_target_context();

        if ($this->scan === null) {
            $context['noscansyet'] = get_string('noscansyet', 'tool_upgradeguard');
            $context['welcomeheading'] = get_string('welcomeheading', 'tool_upgradeguard');
            $context['welcomewhat'] = get_string('welcomewhat', 'tool_upgradeguard');
            // The claim about where data goes depends on a setting, so the text is
            // chosen here rather than claiming in the template what is not true.
            $context['welcomedata'] = (new update_checker())->is_enabled()
                ? get_string('welcomedataremote', 'tool_upgradeguard')
                : get_string('welcomedatalocal', 'tool_upgradeguard');
            $context['verdict'] = get_string('verdict_unknown', 'tool_upgradeguard');
            $context['verdictclass'] = 'secondary';
            return $context;
        }

        $verdict = verdict_value::tryFrom((string) $this->scan->verdict) ?? verdict_value::unknown;
        // A queued scan has not started yet and a running one has. Telling a
        // waiting reader that the plugins are being scanned is a lie, so the
        // two states get different words. Exports and the cron notice depend on
        // both, which is what $isinprogress is for.
        $isqueued = (string) $this->scan->status === 'queued';
        $isrunning = (string) $this->scan->status === 'running';
        $isinprogress = $isqueued || $isrunning;

        $context['scanid'] = (int) $this->scan->id;
        $context['currentversion'] = (string) $this->scan->currentversion;
        $context['targetversion'] = (string) $this->scan->targetversion;
        $context['score'] = $this->scan->score === null ? 0 : (int) $this->scan->score;
        $context['hasscore'] = $this->scan->score !== null;
        $context['verdict'] = get_string($verdict->get_string_key(), 'tool_upgradeguard');
        $context['verdictclass'] = $this->get_verdict_class($verdict);
        $context['isrunning'] = $isrunning;
        $context['isqueued'] = $isqueued;
        $context['isinprogress'] = $isinprogress;
        $context['cronrequired'] = $isinprogress;
        $context['cronmessage'] = get_string(
            'cron_required',
            'tool_upgradeguard',
            $this->lastcronstart > 0 ? userdate($this->lastcronstart) : get_string('cron_unknown', 'tool_upgradeguard')
        );
        $context['isfailed'] = $this->scan->status === 'failed';
        // A failed scan stores the reason, but an older row can have none. An
        // empty red box says nothing, so the fallback names the problem instead.
        $context['errormessage'] = (string) $this->scan->errormessage !== ''
            ? (string) $this->scan->errormessage
            : get_string('scan_failed_unknown', 'tool_upgradeguard');
        $context['timecreated'] = userdate((int) $this->scan->timecreated);
        $context['summary'] = $this->get_summary_context();
        $context['canexport'] = $this->canexport && !$isinprogress;
        $context += $this->get_score_context();

        if ($context['canexport']) {
            $context['exportcsvurl'] = (new moodle_url('/admin/tool/upgradeguard/export.php', [
                'id' => $this->scan->id,
                'format' => 'csv',
                'sesskey' => sesskey(),
            ]))->out(false);
            $context['exportjsonurl'] = (new moodle_url('/admin/tool/upgradeguard/export.php', [
                'id' => $this->scan->id,
                'format' => 'json',
                'sesskey' => sesskey(),
            ]))->out(false);
        }
        $context['reporturl'] = (new moodle_url('/admin/tool/upgradeguard/report.php', ['id' => $this->scan->id]))->out(false);
        $context['resultsurl'] = (new moodle_url('/admin/tool/upgradeguard/results.php', ['id' => $this->scan->id]))->out(false);
        // The overview leads with the three most important actions and keeps the
        // rest one click away, so a long action list neither hides work from the
        // reviewer nor buries the verdict under it. A short list is shown whole:
        // hiding one row behind a button that has to be pressed to reveal a
        // single row makes the page longer and the work harder to find, not
        // shorter.
        $allactions = $context['actions'];
        if (count($allactions) <= self::MAXVISIBLEACTIONS + self::MINHIDDENACTIONS) {
            $context['overviewactions'] = $allactions;
            $context['restactions'] = [];
        } else {
            $context['overviewactions'] = array_slice($allactions, 0, self::MAXVISIBLEACTIONS);
            $context['restactions'] = array_slice($allactions, self::MAXVISIBLEACTIONS);
        }
        $context['hasoverviewactions'] = $context['overviewactions'] !== [];
        $context['hasrestactions'] = $context['restactions'] !== [];
        // The link counts only the rows it actually reveals. Counting the whole
        // list would promise actions the reader can already see.
        $context['showallactions'] = get_string('showallactions', 'tool_upgradeguard', (object) [
            'count' => count($context['restactions']),
            'noun' => plural::form(count($context['restactions']), 'noun_action', 'noun_actions'),
        ]);
        // The same control has to read "Hide" once pressed, and Bootstrap never
        // changes a toggle's own label, so the wording is prepared here.
        $context['hideactions'] = get_string('hideactions', 'tool_upgradeguard', (object) [
            'count' => count($context['restactions']),
            'noun' => plural::form(count($context['restactions']), 'noun_action', 'noun_actions'),
        ]);

        return $context;
    }

    /**
     * Whether this site has any newer released branch to scan against.
     *
     * A site already running the newest released Moodle has nothing to scan
     * yet, so the scan form shows no version selector. That is correct, but
     * the notice sat at the very bottom of a long page and read like a
     * control that had failed to load. Putting it near the top, next to the
     * verdict, tells an administrator that the site is simply up to date
     * before they start looking for a broken button.
     *
     * @return array Template context values.
     */
    private function get_target_context(): array {
        global $CFG;

        $currentbranch = (int) ($CFG->branch ?? 0);
        $repository = new target_repository();
        $targets = $repository->get_upgrade_targets($currentbranch);
        $lastrefresh = $repository->get_last_refresh();

        if ($targets !== []) {
            return ['hastargets' => true];
        }

        return [
            'hastargets' => false,
            'notargetmessage' => get_string('notarget', 'tool_upgradeguard', (object) [
                'version' => $this->format_branch($currentbranch),
                'datasetversion' => $repository->get_dataset_version(),
            ]),
            'rulesupdated' => $repository->get_dataset_updated(),
            'ruleslastchecked' => $lastrefresh
                ? userdate($lastrefresh)
                : '',
            'rulesfeedconfigured' => target_feed::is_configured(),
        ];
    }

    /**
     * Turn a Moodle branch number into the version string people recognise.
     *
     * Moodle reports branches as integers (502) while every label in this
     * plugin uses "5.2". Showing 502 would read like a build number.
     *
     * @param int $branch Branch number, eg 502.
     * @return string
     */
    private function format_branch(int $branch): string {
        if ($branch <= 0) {
            return (string) $branch;
        }
        return intdiv($branch, 100) . '.' . ($branch % 100);
    }

    /**
     * Build the score explanation and unused-plugin estimate.
     *
     * @return array Template context values.
     */
    private function get_score_context(): array {
        if ($this->scan === null || $this->scan->score === null) {
            return [];
        }

        $calculator = new score_calculator();
        $sitefindings = $this->findings[''] ?? [];
        $breakdown = array_slice($calculator->get_breakdown($this->plugins, $sitefindings), 0, 5);
        $rows = [];
        foreach ($breakdown as $entry) {
            if ($entry['penalty'] <= 0) {
                continue;
            }
            $formula = implode(' × ', [
                $this->format_score_number($entry['basepenalty']),
                $this->format_score_number($entry['typeweight']),
                $this->format_score_number($entry['usagemultiplier']),
            ]);
            $rows[] = [
                'text' => get_string('score_penalty', 'tool_upgradeguard', (object) [
                    'component' => $entry['component'],
                    'formula' => $formula,
                    'penalty' => $this->format_score_number($entry['penalty']),
                ]),
            ];
        }

        $unused = [];
        foreach ($this->plugins as $plugin) {
            if ($plugin->usagecount !== null && (int) $plugin->usagecount === 0) {
                $unused[] = ['component' => $plugin->component];
            }
        }

        return [
            'scoreafterunusedremoval' => $calculator->calculate_without_unused($this->plugins, $sitefindings),
            'scorebreakdown' => $rows,
            'hasscorebreakdown' => !empty($rows),
            'unusedplugins' => $unused,
            'hasunusedplugins' => !empty($unused),
        ];
    }

    /**
     * Format a score operand without meaningless trailing decimal places.
     *
     * @param float $number A score operand.
     * @return string Formatted number.
     */
    private function format_score_number(float $number): string {
        return number_format($number, 2, '.', '');
    }

    /**
     * The score summary line.
     *
     * @return array
     */
    private function get_summary_context(): array {
        if ($this->scan === null) {
            return [];
        }

        $summary = [];
        $counts = [
            'summary_blockers' => [
                'count' => (int) $this->scan->blockercount,
                'severityclass' => 'danger',
                'one' => 'noun_finding',
                'many' => 'noun_findings',
            ],
            'summary_cautions' => [
                'count' => (int) $this->scan->cautioncount,
                'severityclass' => 'warning',
                'one' => 'noun_finding',
                'many' => 'noun_findings',
            ],
            'summary_unknowns' => [
                'count' => (int) $this->scan->unknowncount,
                'severityclass' => 'secondary',
                'one' => 'noun_finding',
                'many' => 'noun_findings',
            ],
        ];

        foreach ($counts as $stringkey => $data) {
            if ($data['count'] === 0) {
                continue;
            }
            $summary[] = [
                'text' => get_string($stringkey, 'tool_upgradeguard', (object) [
                    'count' => $data['count'],
                    'noun' => plural::form($data['count'], $data['one'], $data['many']),
                ]),
                'severityclass' => $data['severityclass'],
            ];
        }

        if (empty($summary)) {
            $summary[] = [
                'text' => get_string('summary_clean', 'tool_upgradeguard'),
                'severityclass' => 'success',
            ];
        }

        return $summary;
    }

    /**
     * The plugin rows for the template.
     *
     * @return array
     */
    private function get_plugins_context(): array {
        $rows = [];

        foreach ($this->plugins as $plugin) {
            $status = status::tryFrom((string) $plugin->status) ?? status::unknown;
            $findings = $this->findings[$plugin->component] ?? [];

            $rows[] = [
                'component' => $plugin->component,
                'displayname' => $plugin->displayname,
                'type' => $plugin->plugintype,
                'installedversion' => $plugin->versiondisk === '' ? $plugin->pluginrelease : $plugin->versiondisk,
                'availableversion' => $plugin->updateversion ?? '',
                'hasupdate' => !empty($plugin->updateavailable),
                'status' => get_string($status->get_string_key(), 'tool_upgradeguard'),
                'statusclass' => $status->get_css_class(),
                'usage' => usage_text::from_plugin($plugin),
                'findings' => $this->get_findings_context($findings),
                'hasfindings' => !empty($findings),
                'currentpath' => ltrim((string) $plugin->currentpath, '/'),
                'newpath' => ltrim((string) $plugin->newpath, '/'),
                'needsmove' => (string) $plugin->currentpath !== (string) $plugin->newpath,
            ];
        }

        return $rows;
    }

    /**
     * The action list for the template.
     *
     * @return array
     */
    private function get_actions_context(): array {
        $rows = [];

        foreach ($this->actions as $action) {
            $actionurl = $action['actionurl'] ?? null;
            $rows[] = [
                'action' => $action['action'],
                'actionkey' => $action['actionkey'] ?? '',
                'actionurl' => $actionurl ?? '',
                'hasactionurl' => !empty($actionurl),
                'severity' => get_string($action['severity']->get_string_key(), 'tool_upgradeguard'),
                'severityclass' => $this->get_severity_class($action['severity']),
                'components' => implode(', ', $action['components']),
                'componentcount' => count($action['components']),
            ];
        }

        return $rows;
    }

    /**
     * Turn findings into template rows.
     *
     * @param finding[] $findings The findings to show.
     * @return array
     */
    private function get_findings_context(array $findings): array {
        $rows = [];

        foreach ($findings as $finding) {
            $actionurl = $finding->get_action_url();
            $rows[] = [
                'message' => $finding->get_message(),
                'action' => $finding->get_action(),
                'actionurl' => $actionurl ?? '',
                'hasactionurl' => !empty($actionurl),
                'severity' => get_string($finding->severity->get_string_key(), 'tool_upgradeguard'),
                'severityclass' => $this->get_severity_class($finding->severity),
                'confidence' => get_string($finding->confidence->get_string_key(), 'tool_upgradeguard'),
                'checkkey' => $finding->checkkey,
                'docsurl' => $finding->docsurl ?? '',
                'hasdocs' => !empty($finding->docsurl),
            ];
        }

        return $rows;
    }

    /**
     * The scan history for the template.
     *
     * @return array
     */
    private function get_history_context(): array {
        $rows = [];

        foreach ($this->history as $scan) {
            $verdict = verdict_value::tryFrom((string) $scan->verdict) ?? verdict_value::unknown;

            $rows[] = [
                'targetversion' => (string) $scan->targetversion,
                'timecreated' => userdate((int) $scan->timecreated),
                'verdict' => get_string($verdict->get_string_key(), 'tool_upgradeguard'),
                'verdictclass' => $this->get_verdict_class($verdict),
                // A scan that has not finished has no score. Rendering an empty
                // cell would read as a score of nothing, so the whole number is
                // left out and the template prints a dash instead.
                'score' => $scan->score === null ? '' : (string) $scan->score,
                'hasscore' => $scan->score !== null,
                'url' => (new moodle_url('/admin/tool/upgradeguard/index.php', ['id' => $scan->id]))->out(false),
            ];
        }

        return $rows;
    }

    /**
     * Bootstrap background class for a severity.
     *
     * @param severity $severity The severity.
     * @return string
     */
    private function get_severity_class(severity $severity): string {
        return match ($severity) {
            severity::blocker => 'danger',
            severity::caution => 'warning',
            severity::info => 'info',
        };
    }

    /**
     * Bootstrap background class for a verdict.
     *
     * @param verdict_value $verdict The verdict.
     * @return string
     */
    private function get_verdict_class(verdict_value $verdict): string {
        return match ($verdict) {
            verdict_value::go => 'success',
            verdict_value::careful => 'warning',
            verdict_value::stop => 'danger',
            verdict_value::unknown => 'secondary',
        };
    }

    /**
     * The /public move list card data.
     *
     * @return array Empty when the target predates the public layout change.
     */
    private function get_move_list_context(): array {
        $build = move_list::build($this->target, $this->plugins);
        if ($build === null) {
            return [];
        }

        return [
            'hasmovelist' => true,
            'moveheading' => get_string('moveheading', 'tool_upgradeguard'),
            'movenote' => get_string('movenote', 'tool_upgradeguard'),
            'movesummary' => get_string(
                'movesummary',
                'tool_upgradeguard',
                (object) [
                    'moved' => $build['movecount'],
                    'total' => $build['plugincount'],
                    'noun' => plural::form($build['movecount'], 'noun_plugin', 'noun_plugins'),
                ]
            ),
            'moveplugins' => move_list::to_rows($build),
            'hasmoves' => $build['movecount'] > 0,
            'movecsvtext' => $build['csvtext'],
            'movecommandstext' => $build['commandstext'],
            'hasmovecommands' => $build['commandstext'] !== '',
            'movenocommands' => get_string('movenocommands', 'tool_upgradeguard'),
            'movedisclaimer' => get_string('movedisclaimer', 'tool_upgradeguard'),
        ];
    }

    /**
     * The pre-upgrade checklist card data.
     *
     * @return array Empty when there is no finished scan to build it from.
     */
    private function get_checklist_context(): array {
        $items = checklist::build($this->target, $this->scan, $this->plugins, $this->findings[''] ?? []);
        if (empty($items)) {
            return [];
        }

        return [
            'haschecklist' => true,
            'checklistheading' => get_string('checklistheading', 'tool_upgradeguard'),
            'checklistnote' => get_string('checklistnote', 'tool_upgradeguard'),
            'checklistitems' => checklist::translate($items),
        ];
    }
}
