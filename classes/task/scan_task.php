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
 * Ad hoc task that runs a scan in the background.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\task;

use core\task\adhoc_task;
use tool_upgradeguard\local\scanner;

/**
 * Runs one queued scan.
 *
 * Scans read the whole plugin inventory, the whole user table and, optionally,
 * moodle.org, so they must never run inside a web request: this task does the
 * work in cron instead and reports its progress in the database.
 */
class scan_task extends adhoc_task {
    /**
     * Name shown in the task log.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('pluginname', 'tool_upgradeguard');
    }

    /**
     * Only one scan at a time on the whole site.
     *
     * @return int
     */
    protected function get_default_concurrency_limit(): int {
        return 1;
    }

    /**
     * Run the scan the task was queued for.
     *
     * @return void
     */
    public function execute(): void {
        $customdata = $this->get_custom_data();
        $scanid = isset($customdata->scanid) ? (int) $customdata->scanid : 0;

        if ($scanid <= 0) {
            mtrace('tool_upgradeguard: scan task without a scan id, nothing to do');
            return;
        }

        mtrace('tool_upgradeguard: starting scan ' . $scanid);
        (new scanner())->run_scan($scanid);
        mtrace('tool_upgradeguard: finished scan ' . $scanid);
    }
}
