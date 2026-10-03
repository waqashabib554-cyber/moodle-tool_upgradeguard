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
 * Tests for the status calculator.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use basic_testcase;
use tool_upgradeguard\local\confidence;
use tool_upgradeguard\local\finding;
use tool_upgradeguard\local\severity;
use tool_upgradeguard\local\status;
use tool_upgradeguard\local\status_calculator;

/**
 * Tests how a plugin status is derived from its findings.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_upgradeguard\local\status_calculator
 */
final class status_calculator_test extends basic_testcase {
    /**
     * Make a finding with the given severity.
     *
     * @param severity $severity The severity.
     * @param confidence $confidence The confidence.
     * @param bool $fixedbyupdate Whether an update fixes it.
     * @return finding
     */
    private function make_finding(
        severity $severity,
        confidence $confidence = confidence::high,
        bool $fixedbyupdate = false,
    ): finding {
        return new finding('test', $severity, 'finding_unused_plugin', [], $confidence, $fixedbyupdate);
    }

    /**
     * Nothing checked means unknown, not ready.
     *
     * @covers \tool_upgradeguard\local\status_calculator::calculate
     */
    public function test_no_findings_means_unknown(): void {
        $this->assertSame(status::unknown, (new status_calculator())->calculate([]));
    }

    /**
     * An unresolved blocker wins.
     *
     * @covers \tool_upgradeguard\local\status_calculator::calculate
     */
    public function test_blocker_wins(): void {
        $findings = [
            $this->make_finding(severity::info),
            $this->make_finding(severity::caution),
            $this->make_finding(severity::blocker),
        ];
        $this->assertSame(status::blocker, (new status_calculator())->calculate($findings));
    }

    /**
     * A blocker resolved by an available update is not a current blocker.
     *
     * @covers \tool_upgradeguard\local\status_calculator::calculate
     */
    public function test_update_resolves_blocker(): void {
        $findings = [$this->make_finding(severity::blocker, confidence::high, true)];

        $this->assertSame(status::update, (new status_calculator())->calculate($findings));
    }

    /**
     * An unresolved blocker still wins when another finding has an update.
     *
     * @covers \tool_upgradeguard\local\status_calculator::calculate
     */
    public function test_unresolved_blocker_beats_available_update(): void {
        $findings = [
            $this->make_finding(severity::blocker),
            $this->make_finding(severity::caution, confidence::high, true),
        ];

        $this->assertSame(status::blocker, (new status_calculator())->calculate($findings));
    }

    /**
     * A caution outranks an update.
     *
     * @covers \tool_upgradeguard\local\status_calculator::calculate
     */
    public function test_caution_outranks_update(): void {
        $findings = [
            $this->make_finding(severity::info, confidence::high, true),
            $this->make_finding(severity::caution),
        ];
        $this->assertSame(status::caution, (new status_calculator())->calculate($findings));
    }

    /**
     * An information finding that an update fixes gives the update status.
     *
     * @covers \tool_upgradeguard\local\status_calculator::calculate
     */
    public function test_update_available(): void {
        $findings = [$this->make_finding(severity::info, confidence::high, true)];
        $this->assertSame(status::update, (new status_calculator())->calculate($findings));
    }

    /**
     * Information only means the plugin is ready.
     *
     * @covers \tool_upgradeguard\local\status_calculator::calculate
     */
    public function test_ready(): void {
        $findings = [$this->make_finding(severity::info)];
        $this->assertSame(status::ready, (new status_calculator())->calculate($findings));
    }

    /**
     * Missing update data does not override a positive declared compatibility check.
     *
     * @covers \tool_upgradeguard\local\status_calculator::calculate
     */
    public function test_declared_compatibility_without_update_information_is_ready(): void {
        $findings = [
            $this->make_finding(severity::info, confidence::high),
            $this->make_finding(severity::info, confidence::low),
        ];

        $this->assertSame(status::ready, (new status_calculator())->calculate($findings));
    }

    /**
     * A declared compatibility concern remains a caution when update data is missing.
     *
     * @covers \tool_upgradeguard\local\status_calculator::calculate
     */
    public function test_declared_compatibility_concern_without_update_information_is_caution(): void {
        $findings = [
            $this->make_finding(severity::caution, confidence::high),
            $this->make_finding(severity::info, confidence::low),
        ];

        $this->assertSame(status::caution, (new status_calculator())->calculate($findings));
    }
}
