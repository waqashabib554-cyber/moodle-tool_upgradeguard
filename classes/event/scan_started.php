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
 * Event emitted when an administrator queues a new scan.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\event;

/**
 * Records who queued a scan and against which Moodle version.
 */
final class scan_started extends \core\event\base {
    /**
     * Initialise the event metadata.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'tool_upgradeguard_scan';
    }

    /**
     * Event name.
     *
     * @return \lang_string
     */
    public static function get_name(): \lang_string {
        return new \lang_string('eventscan_started', 'tool_upgradeguard');
    }

    /**
     * Human-readable event description.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' queued Upgrade Guard scan '{$this->objectid}' " .
            "against Moodle '{$this->other['targetversion']}'.";
    }

    /**
     * Where the scan is visible again.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/admin/tool/upgradeguard/index.php', ['id' => $this->objectid]);
    }
}
