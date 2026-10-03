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
 * The "in use" text of one plugin, written once for every screen.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

use stdClass;

/**
 * Turns a plugin usage count into the sentence shown in the tables.
 *
 * The dashboard, the results table and the printable report all show how many
 * places a plugin is used in. They used to build that sentence separately, which
 * is how they drifted apart: the report left the cell empty when the count was
 * unknown while the dashboard said "not in use". One place builds it now.
 */
final class usage_text {
    /**
     * The usage sentence for a count, or for a stored plugin row.
     *
     * @param int|null $count The usage count, null when the scan could not count it.
     * @return string
     */
    public static function from_count(?int $count): string {
        if ($count === null) {
            // The scan could not measure this plugin. Saying "not used" here
            // would be a guess, and a wrong one: a plugin that only runs from
            // cron has no usage rows at all.
            return get_string('usageunknown', 'tool_upgradeguard');
        }
        if ($count === 0) {
            return get_string('usagenone', 'tool_upgradeguard');
        }

        return get_string('usagecount', 'tool_upgradeguard', (object) [
            'count' => $count,
            'noun' => plural::form($count, 'noun_place', 'noun_places'),
        ]);
    }

    /**
     * The usage sentence of one stored plugin row.
     *
     * @param stdClass $plugin A row of the stored plugin table.
     * @return string
     */
    public static function from_plugin(stdClass $plugin): string {
        $count = $plugin->usagecount ?? null;

        return self::from_count($count === null ? null : (int) $count);
    }
}
