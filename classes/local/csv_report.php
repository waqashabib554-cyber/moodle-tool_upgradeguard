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
 * Builds the flat CSV report of one scan.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

use stdClass;
use tool_upgradeguard\local\status;

/**
 * Prepares the plugin and finding rows used by the CSV export.
 */
final class csv_report {
    /**
     * Build the columns and rows of the CSV report.
     *
     * Site-wide findings are first-class rows. A site finding has no plugin row,
     * so it is labelled with the site scope instead of being silently dropped.
     *
     * @param stdClass[] $plugins Stored plugin rows.
     * @param finding[][] $findings Findings grouped by component, '' for site wide.
     * @return array{columns:string[], rows:array[]}
     */
    public static function build(array $plugins, array $findings): array {
        $columns = [
            get_string('col_component', 'tool_upgradeguard'),
            get_string('col_type', 'tool_upgradeguard'),
            get_string('col_status', 'tool_upgradeguard'),
            get_string('col_installedversion', 'tool_upgradeguard'),
            get_string('col_availableversion', 'tool_upgradeguard'),
            get_string('col_usage', 'tool_upgradeguard'),
            get_string('col_severity', 'tool_upgradeguard'),
            get_string('col_confidence', 'tool_upgradeguard'),
            get_string('checkkey', 'tool_upgradeguard'),
            get_string('col_findings', 'tool_upgradeguard'),
            get_string('col_action', 'tool_upgradeguard'),
        ];
        $rows = [];

        foreach ($findings[''] ?? [] as $finding) {
            $rows[] = self::neutralise([
                $columns[0] => get_string('sitewidescope', 'tool_upgradeguard'),
                $columns[1] => '',
                $columns[2] => '',
                $columns[3] => '',
                $columns[4] => '',
                $columns[5] => '',
                $columns[6] => get_string($finding->severity->get_string_key(), 'tool_upgradeguard'),
                $columns[7] => get_string($finding->confidence->get_string_key(), 'tool_upgradeguard'),
                $columns[8] => $finding->checkkey,
                $columns[9] => $finding->get_message(),
                $columns[10] => $finding->get_action(),
            ]);
        }

        foreach ($plugins as $plugin) {
            $pluginfindings = $findings[$plugin->component] ?? [];
            if ($pluginfindings === []) {
                $rows[] = self::neutralise(self::plugin_row($columns, $plugin, null));
                continue;
            }

            foreach ($pluginfindings as $finding) {
                $rows[] = self::neutralise(self::plugin_row($columns, $plugin, $finding));
            }
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * Build one plugin row.
     *
     * @param string[] $columns CSV column headings.
     * @param stdClass $plugin Stored plugin row.
     * @param finding|null $finding Finding for the row, or null.
     * @return array<string, string>
     */
    private static function plugin_row(array $columns, stdClass $plugin, ?finding $finding): array {
        $status = status::tryFrom((string) $plugin->status) ?? status::unknown;

        return [
            $columns[0] => (string) $plugin->component,
            $columns[1] => (string) $plugin->plugintype,
            $columns[2] => get_string($status->get_string_key(), 'tool_upgradeguard'),
            $columns[3] => (string) $plugin->versiondisk,
            $columns[4] => (string) ($plugin->updateversion ?? ''),
            $columns[5] => $plugin->usagecount === null ? '' : (string) $plugin->usagecount,
            $columns[6] => $finding === null ? '' : get_string($finding->severity->get_string_key(), 'tool_upgradeguard'),
            $columns[7] => $finding === null ? '' : get_string($finding->confidence->get_string_key(), 'tool_upgradeguard'),
            $columns[8] => $finding === null ? '' : $finding->checkkey,
            $columns[9] => $finding === null ? '' : $finding->get_message(),
            $columns[10] => $finding === null ? '' : $finding->get_action(),
        ];
    }

    /**
     * Neutralise spreadsheet formulas in every cell.
     *
     * @param array<string, string> $row CSV row.
     * @return array<string, string>
     */
    private static function neutralise(array $row): array {
        return array_map(static fn(string $value): string => csv_value::neutralise($value), $row);
    }
}
