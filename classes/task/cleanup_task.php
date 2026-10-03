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
 * Scheduled task that removes old scan history.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\task;

use core\task\scheduled_task;
use tool_upgradeguard\local\repository\scan_repository;

/**
 * Removes scans that are older than the configured retention period.
 *
 * Keeping scans forever would slowly fill the database of a site that scans
 * regularly, and keeping them longer than needed keeps the name of the
 * administrator who ran them around for longer than necessary.
 */
class cleanup_task extends scheduled_task {
    /**
     * Name shown in the scheduled task list.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup', 'tool_upgradeguard');
    }

    /**
     * Delete expired scans.
     *
     * @return void
     */
    public function execute(): void {
        $days = (int) get_config('tool_upgradeguard', 'retentiondays');

        if ($days <= 0) {
            mtrace('tool_upgradeguard: scan history is kept forever, nothing to remove');
            return;
        }

        $deleted = (new scan_repository())->delete_scans_older_than($days);
        mtrace('tool_upgradeguard: removed ' . $deleted . ' scan(s) older than ' . $days . ' day(s)');
    }
}
