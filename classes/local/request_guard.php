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
 * Request level guards for page controllers.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

use moodle_exception;

/**
 * Guards that keep state changing actions out of reach of plain links.
 *
 * Deliberately a class instead of an inline check in the page, so the rule can
 * be unit tested: a wrong guard is invisible in production until someone
 * deletes data with a link.
 */
final class request_guard {
    /**
     * Require the current request to carry submitted POST data.
     *
     * Core answers false when $_POST is empty, which is exactly the case for a
     * link, an image tag or any other GET request, so those cannot reach a
     * state changing action.
     *
     * @return void
     * @throws moodle_exception When the request submitted no POST data.
     */
    public static function require_post(): void {
        if (data_submitted() === false) {
            throw new moodle_exception('error_requirepost', 'tool_upgradeguard');
        }
    }
}
