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
 * Tests for the update information site-wide notification.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use basic_testcase;
use core\update\info;
use tool_upgradeguard\local\check\update_information_check;
use tool_upgradeguard\local\confidence;
use tool_upgradeguard\local\scan_context;
use tool_upgradeguard\local\severity;
use tool_upgradeguard\local\target;

/**
 * Tests the update-information state supplied to checks.
 *
 * @package    tool_upgradeguard
 * @covers     \tool_upgradeguard\local\check\update_information_check
 */
final class update_information_check_test extends basic_testcase {
    /**
     * Build a context with the requested update-data state.
     *
     * @param array $updates Available updates.
     * @param bool $available Whether update data was available.
     * @return scan_context
     */
    private function make_context(array $updates, bool $available): scan_context {
        $target = new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified');
        return new scan_context($target, [], [], $updates, PHP_VERSION, $available);
    }

    /**
     * Empty update data produces the site-wide recovery notification.
     *
     * @covers \tool_upgradeguard\local\check\update_information_check::run
     */
    public function test_empty_update_information_has_a_site_wide_notice(): void {
        $findings = (new update_information_check())->run(null, $this->make_context([], false));

        $this->assertCount(1, $findings);
        $this->assertSame(severity::info, $findings[0]->severity);
        $this->assertSame(confidence::low, $findings[0]->confidence);
        $this->assertSame('finding_update_information_missing', $findings[0]->messagekey);
        $this->assertSame('action_check_updates_now', $findings[0]->actionkey);
    }

    /**
     * Available update data does not create a missing-information notification.
     *
     * @covers \tool_upgradeguard\local\check\update_information_check::run
     */
    public function test_available_update_information_has_no_site_wide_notice(): void {
        $update = new info('enrol_example', ['version' => 2026092200]);
        $findings = (new update_information_check())->run(
            null,
            $this->make_context(['enrol_example' => $update], true)
        );

        $this->assertSame([], $findings);
    }
}
