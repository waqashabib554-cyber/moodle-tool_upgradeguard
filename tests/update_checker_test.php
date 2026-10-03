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
 * Tests for the update information state of a scan.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use advanced_testcase;
use core\update\info;
use tool_upgradeguard\local\check\update_information_check;
use tool_upgradeguard\local\collector\update_checker;
use tool_upgradeguard\local\scan_context;
use tool_upgradeguard\local\target;

/**
 * Tests remote-check configuration and core plugin-update response states.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_upgradeguard\local\collector\update_checker
 */
final class update_checker_test extends advanced_testcase {
    /**
     * Build a context with the given update information state.
     *
     * @param array $updates Updates that were collected.
     * @param bool $available Whether the information counts as available.
     * @return scan_context
     */
    private function make_context(array $updates, bool $available): scan_context {
        $target = new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified');
        return new scan_context($target, [], [], $updates, PHP_VERSION, $available);
    }

    /**
     * Switched off: no remote check was wanted, so nothing is missing.
     *
     * A site with the remote check switched off must not be told to click
     * "Check for updates now": the administrator switched it off on purpose.
     *
     * @covers \tool_upgradeguard\local\collector\update_checker::is_information_available
     * @covers \tool_upgradeguard\local\check\update_information_check::is_applicable
     */
    public function test_switched_off_remote_check_is_not_missing_information(): void {
        $this->resetAfterTest();
        set_config('checkremote', 0, 'tool_upgradeguard');

        $checker = new update_checker();
        $this->assertFalse($checker->is_enabled());
        $this->assertSame([], $checker->get_updates([]));

        $available = $checker->is_information_available([]);
        $this->assertTrue($available);

        $context = $this->make_context([], $available);
        $check = new update_information_check();
        $this->assertFalse($check->is_applicable(null, $context));
        $this->assertSame([], $check->run(null, $context));
    }

    /**
     * Switched on with no answers: reported as missing information.
     *
     * With no valid core response stored, an empty result is surfaced for the
     * administrator to resolve.
     *
     * @covers \tool_upgradeguard\local\collector\update_checker::is_information_available
     */
    public function test_switched_on_without_answers_is_missing_information(): void {
        $this->resetAfterTest();
        set_config('checkremote', 1, 'tool_upgradeguard');
        set_config('recentresponse', '', 'core_plugin');
        set_config('recentfetch', 0, 'core_plugin');
        \core\update\checker::reset_caches(true);

        try {
            $checker = new update_checker();
            $this->assertTrue($checker->is_enabled());
            $this->assertFalse($checker->is_information_available([]));

            $context = $this->make_context([], $checker->is_information_available([]));
            $this->assertTrue((new update_information_check())->is_applicable(null, $context));
        } finally {
            \core\update\checker::reset_caches(true);
        }
    }

    /**
     * A valid core update response with no newer versions is not missing data.
     *
     * @covers \tool_upgradeguard\local\collector\update_checker::is_information_available
     */
    public function test_switched_on_with_a_valid_empty_core_response_has_information(): void {
        $this->resetAfterTest();
        set_config('checkremote', 1, 'tool_upgradeguard');
        set_config('recentresponse', json_encode([
            'status' => 'OK',
            'apiver' => '1.3',
            'forbranch' => moodle_major_version(true),
            'updates' => [],
        ]), 'core_plugin');
        set_config('recentfetch', time(), 'core_plugin');
        \core\update\checker::reset_caches(true);

        try {
            $checker = new update_checker();
            $this->assertTrue($checker->is_information_available([]));
            $context = $this->make_context([], $checker->is_information_available([]));
            $this->assertFalse((new update_information_check())->is_applicable(null, $context));
        } finally {
            \core\update\checker::reset_caches(true);
        }
    }

    /**
     * Switched on with an available update: information is present.
     *
     * @covers \tool_upgradeguard\local\collector\update_checker::is_information_available
     */
    public function test_switched_on_with_an_update_has_information(): void {
        $this->resetAfterTest();
        set_config('checkremote', 1, 'tool_upgradeguard');

        $checker = new update_checker();
        $updates = ['enrol_example' => new info('enrol_example', ['version' => 2026092200])];

        $this->assertTrue($checker->is_information_available($updates));

        $context = $this->make_context($updates, true);
        $this->assertFalse((new update_information_check())->is_applicable(null, $context));
    }
}
