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
 * The list of plugins that have to move into the public directory.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

use stdClass;

/**
 * Builds the /public move list of a finished scan.
 *
 * Check E of the specification: from Moodle 5.1 onwards web accessible code is
 * read from the public directory, so every third-party plugin stored outside
 * it has to be moved by hand. The list is strictly display only, the tool never
 * copies or moves anything itself. Whether a target uses the public layout is
 * read from the rule dataset, nothing is hard coded here.
 */
final class move_list {
    /**
     * Build the move list, or null when the section must not be shown.
     *
     * @param target|null $target The target the scan was run against.
     * @param stdClass[] $pluginrows The stored plugin rows of the scan.
     * @return array|null Null when the target does not use the public layout.
     */
    public static function build(?target $target, array $pluginrows): ?array {
        if ($target === null || !$target->publiclayout) {
            return null;
        }

        $thirdparty = [];
        foreach ($pluginrows as $row) {
            if (!empty($row->isstandard)) {
                continue;
            }
            $thirdparty[] = $row;
        }

        usort($thirdparty, static function (stdClass $a, stdClass $b): int {
            return [$a->plugintype, $a->component] <=> [$b->plugintype, $b->component];
        });

        $root = self::command_root();
        $rows = [];
        $copies = [];
        $directories = [];

        foreach ($thirdparty as $row) {
            $current = ltrim((string) $row->currentpath, '/');
            $new = ltrim((string) $row->newpath, '/');
            $needsmove = $current !== $new;

            if ($needsmove) {
                $directories[$root . dirname('/' . $new)] = true;
                $copies[] = 'cp -r ' . $root . '/' . $current . ' ' . $root . '/' . $new;
            }

            $rows[] = [
                'component' => (string) $row->component,
                'type' => (string) $row->plugintype,
                'currentpath' => $current,
                'newpath' => $new,
                'hasmove' => $needsmove,
            ];
        }

        ksort($directories);
        $commands = array_map(
            static fn(string $directory): string => 'mkdir -p ' . $directory,
            array_keys($directories)
        );
        $commands = array_merge($commands, $copies);

        return [
            'plugincount' => count($rows),
            'movecount' => count($copies),
            'rows' => $rows,
            'csvtext' => self::build_csv($rows),
            'commandstext' => implode("\n", $commands),
        ];
    }

    /**
     * Decorate the built rows with the status labels for display.
     *
     * @param array $build The array returned by build().
     * @return array[] The rows with a status label and class added.
     */
    public static function to_rows(array $build): array {
        $rows = [];
        foreach ($build['rows'] as $row) {
            $rows[] = $row + [
                'statuslabel' => get_string($row['hasmove'] ? 'move_needed' : 'move_ok', 'tool_upgradeguard'),
                'statusclass' => $row['hasmove'] ? 'warning' : 'success',
            ];
        }

        return $rows;
    }

    /**
     * The move list as CSV text, safe for spreadsheet imports.
     *
     * @param array $rows The move list rows.
     * @return string The CSV text, header row first.
     */
    private static function build_csv(array $rows): string {
        $lines = ['component,type,current_path,new_path'];

        foreach ($rows as $row) {
            $lines[] = implode(',', [
                self::csv_cell($row['component']),
                self::csv_cell($row['type']),
                self::csv_cell($row['currentpath']),
                self::csv_cell($row['newpath']),
            ]);
        }

        return implode("\n", $lines);
    }

    /**
     * Make one value safe as a CSV cell.
     *
     * The same neutralisation as the report export is applied first, so that
     * spreadsheet software cannot execute a crafted cell as a formula.
     *
     * @param string $value The value to export.
     * @return string A safe CSV cell value.
     */
    private static function csv_cell(string $value): string {
        $value = csv_value::neutralise($value);

        if (preg_match('/[",\r\n]/', $value)) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    /**
     * The installation root as it is used in the copy-paste commands.
     *
     * @return string Absolute path with forward slashes and no trailing slash.
     */
    private static function command_root(): string {
        return rtrim(str_replace('\\', '/', layout::get_root()), '/');
    }
}
