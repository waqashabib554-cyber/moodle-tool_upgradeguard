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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See
// the GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Scheduled refresh of Moodle target rules.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\task;

use core\task\scheduled_task;
use tool_upgradeguard\local\target_feed;

/**
 * Refreshes the target rules feed, retaining the last known good rules offline.
 */
class refresh_targets_task extends scheduled_task {
    /**
     * Name shown in the scheduled task list.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_refresh_targets', 'tool_upgradeguard');
    }

    /**
     * Refresh target rules if the maintainer feed has been configured.
     *
     * @return void
     */
    public function execute(): void {
        if (!target_feed::is_configured()) {
            return;
        }

        $feed = new target_feed();
        if ($feed->refresh()) {
            mtrace('tool_upgradeguard: Moodle target rules were refreshed successfully');
            return;
        }

        $error = (string) get_config('tool_upgradeguard', 'ruleslasterror');
        if ($error !== '') {
            mtrace('tool_upgradeguard: target rules refresh failed; the last known good rules remain active');
        }
    }
}
