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
 * The machine readable JSON payload of one scan.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

use stdClass;
use tool_upgradeguard\local\verdict as verdict_value;

/**
 * Builds the JSON export: the whole scan in one stable structure.
 *
 * Machine readable values (codes, numbers, ISO dates) are kept raw, human
 * readable labels are added next to them, and every finding carries its
 * confidence so that consumers can see how sure each statement is.
 */
final class export_payload {
    /**
     * Build the payload of one finished scan.
     *
     * @param target $target The target the scan was run against.
     * @param stdClass $scan The stored scan row.
     * @param stdClass[] $pluginrows The stored plugin rows of the scan.
     * @param finding[][] $findings Findings grouped by component.
     * @param string $supportemail Configured support email, empty when unset.
     * @return array The payload to encode as JSON.
     */
    public static function build(
        target $target,
        stdClass $scan,
        array $pluginrows,
        array $findings,
        string $supportemail,
    ): array {
        $verdict = verdict_value::tryFrom((string) $scan->verdict) ?? verdict_value::unknown;

        $plugins = [];
        foreach ($pluginrows as $row) {
            $status = status::tryFrom((string) $row->status) ?? status::unknown;
            $pluginfindings = [];
            foreach ($findings[$row->component] ?? [] as $finding) {
                $pluginfindings[] = [
                    'checkkey' => $finding->checkkey,
                    'severity' => $finding->severity->value,
                    'confidence' => $finding->confidence->value,
                    'fixedbyupdate' => !empty($finding->fixedbyupdate),
                    'message' => $finding->get_message(),
                    'action' => $finding->get_action(),
                ];
            }

            $plugins[] = [
                'component' => $row->component,
                'type' => $row->plugintype,
                'name' => $row->name,
                'displayname' => $row->displayname,
                'status' => $status->value,
                'statuslabel' => get_string($status->get_string_key(), 'tool_upgradeguard'),
                'isstandard' => !empty($row->isstandard),
                'installed' => !empty($row->installed),
                'versiondisk' => (string) $row->versiondisk,
                'versiondb' => (string) $row->versiondb,
                'versionrequires' => $row->versionrequires === null ? null : (int) $row->versionrequires,
                'supportedlist' => (string) $row->supportedlist,
                'currentpath' => ltrim((string) $row->currentpath, '/'),
                'newpath' => ltrim((string) $row->newpath, '/'),
                'needsmove' => (string) $row->currentpath !== (string) $row->newpath,
                'usagecount' => $row->usagecount === null ? null : (int) $row->usagecount,
                'updateavailable' => !empty($row->updateavailable),
                'updateversion' => (string) ($row->updateversion ?? ''),
                'findings' => $pluginfindings,
            ];
        }

        return self::assemble($target, $scan, $pluginrows, $plugins, $verdict, $supportemail, $findings[''] ?? []);
    }

    /**
     * Prepare findings for a machine readable export.
     *
     * @param finding[] $findings Findings to prepare.
     * @return array[]
     */
    private static function prepare_findings(array $findings): array {
        $prepared = [];
        foreach ($findings as $finding) {
            $prepared[] = [
                'checkkey' => $finding->checkkey,
                'severity' => $finding->severity->value,
                'confidence' => $finding->confidence->value,
                'fixedbyupdate' => !empty($finding->fixedbyupdate),
                'message' => $finding->get_message(),
                'action' => $finding->get_action(),
            ];
        }

        return $prepared;
    }

    /**
     * The mailto link for reporting a wrong result.
     *
     * @param string|null $supportemail Configured support email.
     * @param int $scanid The scan the report is about.
     * @return string|null Null when no support email is configured.
     */
    public static function wrongresult_url(?string $supportemail, int $scanid): ?string {
        $supportemail = trim((string) $supportemail);
        if ($supportemail === '') {
            return null;
        }

        $subject = 'Upgrade Guard: wrong result (scan ' . $scanid . ')';
        return 'mailto:' . $supportemail . '?subject=' . rawurlencode($subject);
    }

    /**
     * A unix timestamp as an ISO 8601 string, null when not recorded.
     *
     * @param int|string|null $time The timestamp.
     * @return string|null
     */
    private static function iso(int|string|null $time): ?string {
        if (empty($time)) {
            return null;
        }

        return gmdate(DATE_ATOM, (int) $time);
    }

    /**
     * The stored extension list as an array.
     *
     * @param string|null $stored The JSON list stored with the scan.
     * @return string[]
     */
    private static function extensions(?string $stored): array {
        $decoded = json_decode((string) $stored, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $decoded)));
    }

    /**
     * Assemble the top level payload around the prepared plugin list.
     *
     * @param target $target The target the scan was run against.
     * @param stdClass $scan The stored scan row.
     * @param stdClass[] $pluginrows The stored plugin rows of the scan.
     * @param array[] $plugins The prepared plugin entries.
     * @param verdict_value $verdict The verdict of the scan.
     * @param string $supportemail Configured support email, empty when unset.
     * @param finding[] $sitefindings Site-wide findings of the scan.
     * @return array
     */
    private static function assemble(
        target $target,
        stdClass $scan,
        array $pluginrows,
        array $plugins,
        verdict_value $verdict,
        string $supportemail,
        array $sitefindings = [],
    ): array {
        return [
            'generator' => [
                'component' => 'tool_upgradeguard',
                'datasetversion' => (int) $scan->datasetversion,
                'generatedat' => gmdate(DATE_ATOM),
                'scanid' => (int) $scan->id,
            ],
            'scan' => [
                'status' => (string) $scan->status,
                'currentversion' => (string) $scan->currentversion,
                'targetversion' => (string) $scan->targetversion,
                'score' => $scan->score === null ? null : (int) $scan->score,
                'verdict' => $verdict->value,
                'verdictlabel' => get_string($verdict->get_string_key(), 'tool_upgradeguard'),
                'plugincount' => (int) $scan->plugincount,
                'blockercount' => (int) $scan->blockercount,
                'cautioncount' => (int) $scan->cautioncount,
                'unknowncount' => (int) $scan->unknowncount,
                'environmentblockercount' => (int) ($scan->environmentblockercount ?? 0),
                'environmentcautioncount' => (int) ($scan->environmentcautioncount ?? 0),
                'timecreated' => self::iso($scan->timecreated),
                'timefinished' => self::iso($scan->timefinished),
            ],
            'environment' => [
                'phpversion' => (string) ($scan->phpversion ?? ''),
                'dbvendor' => (string) ($scan->dbvendor ?? ''),
                'dbversion' => (string) ($scan->dbversion ?? ''),
                'phpextensions' => self::extensions($scan->phpextensions ?? null),
            ],
            'target' => [
                'version' => $target->version,
                'branch' => $target->branch,
                'requiresint' => $target->requiresint,
                'requiresintsource' => $target->requiresintsource,
                'phpmin' => $target->phpmin,
                'minsource' => $target->minsource,
                'publiclayout' => $target->publiclayout,
                // The release status and where it was read, so a consumer can
                // check the claim instead of trusting it.
                'status' => $target->status,
                'statussource' => $target->statussource,
                'fullysupported' => $target->is_fully_supported(),
                'securityonly' => $target->is_security_only(),
                'environment' => $target->environment,
            ],
            'sitefindings' => self::prepare_findings($sitefindings),
            'scorebreakdown' => (new score_calculator())->get_breakdown($pluginrows, $sitefindings),
            'plugins' => $plugins,
            'disclaimer' => get_string('disclaimer', 'tool_upgradeguard'),
            'support' => [
                'reportwrongresult' => self::wrongresult_url($supportemail, (int) $scan->id),
            ],
        ];
    }
}
