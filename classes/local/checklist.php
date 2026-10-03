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
 * The pre-upgrade checklist built from the results of one scan.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

use stdClass;

/**
 * Builds the pre-upgrade checklist of a finished scan.
 *
 * The order and the content follow Section 6.6 of the specification: items
 * that always apply come first and last, items that depend on the scan
 * results only appear when they have something to say. The builder returns
 * item keys with optional counts; the output layer turns them into text.
 */
final class checklist {
    /**
     * Build the checklist items, or an empty array when there is nothing to show.
     *
     * @param target|null $target The target the scan was run against.
     * @param stdClass|null $scan The stored scan row.
     * @param stdClass[] $pluginrows The stored plugin rows of the scan.
     * @param stdClass[]|finding[] $sitefindings The site wide findings of the scan.
     * @return array[] Items with a key and optional params, in checklist order.
     */
    public static function build(?target $target, ?stdClass $scan, array $pluginrows, array $sitefindings): array {
        if ($scan === null || (string) $scan->status !== 'finished') {
            return [];
        }

        $blockers = (int) $scan->blockercount;
        $updates = 0;
        $unused = 0;
        foreach ($pluginrows as $row) {
            if (!empty($row->isstandard)) {
                continue;
            }
            if (!empty($row->updateavailable)) {
                $updates++;
            }
            if ($row->usagecount !== null && (int) $row->usagecount === 0) {
                $unused++;
            }
        }

        $moves = 0;
        if ($target !== null && $target->publiclayout) {
            $movelist = move_list::build($target, $pluginrows);
            if ($movelist !== null) {
                $moves = $movelist['movecount'];
            }
        }

        $items = [
            ['key' => 'backup', 'params' => null],
            ['key' => 'staging', 'params' => null],
        ];

        if (self::has_environment_finding($sitefindings)) {
            $items[] = ['key' => 'environment', 'params' => null];
        }

        if ($blockers > 0) {
            $items[] = ['key' => 'blockers', 'params' => $blockers];
        }

        if ($unused > 0) {
            $items[] = ['key' => 'unused', 'params' => $unused];
        }

        if ($updates > 0) {
            $items[] = ['key' => 'updates', 'params' => $updates];
        }

        if ($moves > 0) {
            $items[] = ['key' => 'move', 'params' => $moves];
        }

        $items[] = ['key' => 'maintenance', 'params' => null];
        $items[] = ['key' => 'upgrade', 'params' => null];
        $items[] = ['key' => 'rescan', 'params' => null];

        return $items;
    }

    /**
     * Turn built items into display rows with labels and details.
     *
     * @param array[] $items Items as returned by build().
     * @return array[] Rows with key, label, detail and hasdetail.
     */
    public static function translate(array $items): array {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = match ($item['key']) {
                'backup' => [
                    'key' => 'backup',
                    'label' => get_string('cl_backup', 'tool_upgradeguard'),
                    'detail' => get_string('cl_backup_desc', 'tool_upgradeguard'),
                ],
                'staging' => [
                    'key' => 'staging',
                    'label' => get_string('cl_staging', 'tool_upgradeguard'),
                    'detail' => get_string('cl_staging_desc', 'tool_upgradeguard'),
                ],
                'environment' => [
                    'key' => 'environment',
                    'label' => get_string('cl_environment', 'tool_upgradeguard'),
                    'detail' => get_string('cl_environment_desc', 'tool_upgradeguard'),
                ],
                'blockers' => [
                    'key' => 'blockers',
                    'label' => get_string('cl_blockers', 'tool_upgradeguard'),
                    'detail' => get_string('cl_blockers_desc', 'tool_upgradeguard', [
                        'count' => (int) $item['params'],
                        'noun' => plural::form((int) $item['params'], 'noun_finding', 'noun_findings'),
                    ]),
                ],
                'unused' => [
                    'key' => 'unused',
                    'label' => get_string('cl_unused', 'tool_upgradeguard'),
                    'detail' => get_string('cl_unused_desc', 'tool_upgradeguard', [
                        'count' => (int) $item['params'],
                        'noun' => plural::form((int) $item['params'], 'noun_plugin', 'noun_plugins'),
                        'verb' => plural::form((int) $item['params'], 'verb_is', 'verb_are'),
                        'pronoun' => plural::form((int) $item['params'], 'pronoun_it', 'pronoun_them'),
                    ]),
                ],
                'updates' => [
                    'key' => 'updates',
                    'label' => get_string('cl_updates', 'tool_upgradeguard'),
                    'detail' => get_string('cl_updates_desc', 'tool_upgradeguard', [
                        'count' => (int) $item['params'],
                        'noun' => plural::form((int) $item['params'], 'noun_plugin', 'noun_plugins'),
                        'verb' => plural::form((int) $item['params'], 'verb_reports', 'verb_report'),
                        'pronoun' => plural::form((int) $item['params'], 'pronoun_it', 'pronoun_them'),
                    ]),
                ],
                'move' => [
                    'key' => 'move',
                    'label' => get_string('cl_move', 'tool_upgradeguard'),
                    'detail' => get_string('cl_move_desc', 'tool_upgradeguard', [
                        'count' => (int) $item['params'],
                        'noun' => plural::form((int) $item['params'], 'noun_plugin', 'noun_plugins'),
                        'verb' => plural::form((int) $item['params'], 'verb_lives', 'verb_live'),
                    ]),
                ],
                'maintenance' => [
                    'key' => 'maintenance',
                    'label' => get_string('cl_maintenance', 'tool_upgradeguard'),
                    'detail' => get_string('cl_maintenance_desc', 'tool_upgradeguard'),
                ],
                'upgrade' => [
                    'key' => 'upgrade',
                    'label' => get_string('cl_upgrade', 'tool_upgradeguard'),
                    'detail' => get_string('cl_upgrade_desc', 'tool_upgradeguard'),
                ],
                'rescan' => [
                    'key' => 'rescan',
                    'label' => get_string('cl_rescan', 'tool_upgradeguard'),
                    'detail' => get_string('cl_rescan_desc', 'tool_upgradeguard'),
                ],
            };
        }

        return $rows;
    }

    /**
     * Whether the scan reported any environment problem.
     *
     * @param stdClass[]|finding[] $sitefindings The site wide findings of the scan.
     * @return bool
     */
    private static function has_environment_finding(array $sitefindings): bool {
        foreach ($sitefindings as $finding) {
            if (in_array($finding->checkkey, ['site_php', 'environment_database', 'environment_extensions'], true)) {
                return true;
            }
        }

        return false;
    }
}
