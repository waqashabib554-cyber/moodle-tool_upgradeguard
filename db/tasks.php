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
 * Scheduled task definitions for Upgrade Guard.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'tool_upgradeguard\\task\\refresh_targets_task',
        'blocking' => 0,
        // Check hourly; the task throttles outbound requests to one per six hours.
        'minute' => 'R',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
    [
        'classname' => 'tool_upgradeguard\task\cleanup_task',
        'blocking' => 0,
        // Once a day, at a random hour, so that cleanup never competes with
        // other daily jobs at a fixed time.
        'minute' => '0',
        'hour' => 'R',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
];
