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
 * Tests for the available update informational tag.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use basic_testcase;
use core\update\info;
use tool_upgradeguard\local\check\update_available_check;
use tool_upgradeguard\local\confidence;
use tool_upgradeguard\local\plugin_snapshot;
use tool_upgradeguard\local\scan_context;
use tool_upgradeguard\local\severity;
use tool_upgradeguard\local\target;

/**
 * Tests that an update is informative rather than a compatibility promise.
 *
 * @package    tool_upgradeguard
 * @covers     \tool_upgradeguard\local\check\update_available_check
 */
final class update_available_check_test extends basic_testcase {
    /**
     * An available core update has no target support metadata.
     *
     * @covers \tool_upgradeguard\local\check\update_available_check::run
     */
    public function test_update_is_low_confidence_information_not_a_fix(): void {
        $target = new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified');
        $plugin = new plugin_snapshot(
            'enrol_example',
            'enrol',
            'example',
            'Example enrolment',
            '2026010100',
            '2026010100',
            null,
            2024042200,
            '404-502',
            null,
            false,
            true,
            '/public/enrol/example',
            '/public/enrol/example',
            false,
            null,
        );
        $update = new info('enrol_example', ['version' => 2026092200, 'release' => '2.0.0']);
        $context = new scan_context($target, [], [], ['enrol_example' => $update], PHP_VERSION);

        $findings = (new update_available_check())->run($plugin, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(severity::info, $findings[0]->severity);
        $this->assertSame(confidence::low, $findings[0]->confidence);
        $this->assertFalse($findings[0]->fixedbyupdate);
        $this->assertSame('finding_update_available', $findings[0]->messagekey);
        $this->assertSame('5.2', $findings[0]->params['target']);
    }
}
