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
 * Tests for readiness verdict rules.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use basic_testcase;
use tool_upgradeguard\local\verdict;
use tool_upgradeguard\local\verdict_calculator;

/**
 * Tests the Stop, Careful, and Go rules from Section 6.5.
 *
 * @package    tool_upgradeguard
 * @covers     \tool_upgradeguard\local\verdict_calculator
 */
final class verdict_calculator_test extends basic_testcase {
    /**
     * Evaluate a verdict at a scoring-rule boundary.
     *
     * @dataProvider verdict_provider
     * @covers \tool_upgradeguard\local\verdict_calculator::calculate
     * @param int $score Score.
     * @param bool $hasusedblocker Whether a blocker is used.
     * @param bool $hascaution Whether any plugin is a caution.
     * @param bool $hasenvironmentblocker Whether the server environment has a blocker.
     * @param verdict $expected Expected verdict.
     */
    public function test_spec_verdicts(
        int $score,
        bool $hasusedblocker,
        bool $hascaution,
        verdict $expected,
        bool $hasenvironmentblocker = false
    ): void {
        $this->assertSame(
            $expected,
            (new verdict_calculator())->calculate($score, $hasusedblocker, $hascaution, $hasenvironmentblocker)
        );
    }

    /**
     * Cases at every rule boundary.
     *
     * @return array
     */
    public static function verdict_provider(): array {
        return [
            'used blocker stops' => [99, true, false, verdict::stop],
            'score below stop threshold stops' => [59, false, false, verdict::stop],
            'caution is careful even at 85' => [85, false, true, verdict::careful],
            'score 84 is careful' => [84, false, false, verdict::careful],
            'clean score 85 goes' => [85, false, false, verdict::go],
            // An unused blocker does not stop the upgrade by itself: the plugin
            // can be removed first. The score and the cautions still count, which
            // is why the two cases below differ only in the caution flag.
            'unused blocker does not stop a clean scan' => [90, false, false, verdict::go],
            'unused blocker with a caution is careful' => [90, false, true, verdict::careful],
        ];
    }

    /**
     * A site-wide environment blocker cannot be hidden by a clean plugin score.
     */
    public function test_environment_blocker_forces_stop_even_without_plugin_blockers(): void {
        $this->assertSame(
            verdict::stop,
            (new verdict_calculator())->calculate(100, false, false, true)
        );
    }

    /**
     * The score threshold still applies to a clean environment.
     */
    public function test_clean_environment_keeps_the_score_rules(): void {
        $calculator = new verdict_calculator();

        $this->assertSame(verdict::go, $calculator->calculate(100, false, false));
        $this->assertSame(verdict::careful, $calculator->calculate(84, false, false));
        $this->assertSame(verdict::stop, $calculator->calculate(59, false, false));
    }
}
