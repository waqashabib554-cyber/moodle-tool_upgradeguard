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
 * Event emitted when a scan finishes successfully.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\event;

/**
 * Records the outcome of a finished scan for the audit trail.
 */
final class scan_completed extends \core\event\base {
    /**
     * Initialise the event metadata.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'tool_upgradeguard_scan';
    }

    /**
     * Event name.
     *
     * @return \lang_string
     */
    public static function get_name(): \lang_string {
        return new \lang_string('eventscan_completed', 'tool_upgradeguard');
    }

    /**
     * Human-readable event description.
     *
     * @return string
     */
    public function get_description(): string {
        return "The Upgrade Guard scan '{$this->objectid}' against Moodle '{$this->other['targetversion']}' " .
            "finished with score '{$this->other['score']}' and verdict '{$this->other['verdict']}'.";
    }
}
