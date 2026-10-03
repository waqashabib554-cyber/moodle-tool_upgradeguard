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
 * Tests for the readiness score.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use advanced_testcase;
use tool_upgradeguard\local\score_calculator;

/**
 * Tests Section 6.5's status, type, and usage scoring formula.
 *
 * @package    tool_upgradeguard
 * @covers     \tool_upgradeguard\local\score_calculator
 */
final class score_calculator_test extends advanced_testcase {
    /**
     * Make one stored-plugin-like record for the calculator.
     *
     * @param string $component Component name.
     * @param string $type Plugin type.
     * @param string $status Calculated plugin status.
     * @param int|null $usagecount Usage count, null when unavailable.
     * @return \stdClass
     */
    private function plugin(string $component, string $type, string $status, ?int $usagecount): \stdClass {
        return (object) [
            'component' => $component,
            'plugintype' => $type,
            'status' => $status,
            'usagecount' => $usagecount,
        ];
    }

    /**
     * The spec formula applies status base, type weight, and usage multiplier.
     *
     * @covers \tool_upgradeguard\local\score_calculator::get_breakdown
     * @covers \tool_upgradeguard\local\score_calculator::calculate
     */
    public function test_spec_formula_and_breakdown_order(): void {
        $this->resetAfterTest();
        set_config('weightcaution', 4, 'tool_upgradeguard');
        set_config('weightunknown', 3, 'tool_upgradeguard');
        set_config('weightupdate', 1, 'tool_upgradeguard');

        $plugins = [
            $this->plugin('mod_used', 'mod', 'caution', 1),
            $this->plugin('theme_unused', 'theme', 'caution', 0),
            $this->plugin('auth_unknown', 'auth', 'unknown', null),
            $this->plugin('block_update', 'block', 'update', 4),
            $this->plugin('tool_ready', 'tool', 'ready', null),
        ];

        $calculator = new score_calculator();
        $breakdown = $calculator->get_breakdown($plugins);

        $this->assertSame('mod_used', $breakdown[0]['component']);
        $this->assertSame(12.0, $breakdown[0]['penalty']);
        $this->assertSame('auth_unknown', $breakdown[1]['component']);
        $this->assertSame(9.0, $breakdown[1]['penalty']);
        $this->assertSame('theme_unused', $breakdown[2]['component']);
        $this->assertSame(3.6, $breakdown[2]['penalty']);
        $this->assertSame('block_update', $breakdown[3]['component']);
        $this->assertSame(1.5, $breakdown[3]['penalty']);
        $this->assertSame('tool_ready', $breakdown[4]['component']);
        $this->assertSame(74, $calculator->calculate($plugins));
    }

    /**
     * Removing unused plugins excludes their risk from the alternative score.
     *
     * @covers \tool_upgradeguard\local\score_calculator::calculate_without_unused
     */
    public function test_score_after_removing_unused_plugins(): void {
        $this->resetAfterTest();
        set_config('weightcaution', 4, 'tool_upgradeguard');

        $plugins = [$this->plugin('mod_used', 'mod', 'caution', 1)];
        for ($i = 1; $i <= 7; $i++) {
            $plugins[] = $this->plugin('theme_unused_' . $i, 'theme', 'caution', 0);
        }

        $calculator = new score_calculator();
        $this->assertSame(63, $calculator->calculate($plugins));
        $this->assertSame(88, $calculator->calculate_without_unused($plugins));
    }

    /**
     * Administrators can tune the individual scoring weights.
     *
     * @covers \tool_upgradeguard\local\score_calculator::calculate
     */
    public function test_configured_weights_are_used(): void {
        $this->resetAfterTest();
        set_config('weightcaution', 10, 'tool_upgradeguard');
        set_config('weightmod', 4, 'tool_upgradeguard');
        set_config('usagemultiplierused', 2, 'tool_upgradeguard');

        $plugins = [$this->plugin('mod_used', 'mod', 'caution', 1)];
        $breakdown = (new score_calculator())->get_breakdown($plugins);
        $this->assertSame(80.0, $breakdown[0]['penalty']);
        $this->assertSame(20, (new score_calculator())->calculate($plugins));
    }

    /**
     * A blocker on a plugin the site actually uses is the most expensive risk.
     *
     * @covers \tool_upgradeguard\local\score_calculator::get_breakdown
     * @covers \tool_upgradeguard\local\score_calculator::calculate
     */
    public function test_used_blocker_penalty(): void {
        $this->resetAfterTest();
        set_config('weightblocker', 12, 'tool_upgradeguard');
        set_config('weightmod', 2, 'tool_upgradeguard');
        set_config('usagemultiplierused', 1.5, 'tool_upgradeguard');

        $plugins = [$this->plugin('mod_used_blocker', 'mod', 'blocker', 3)];
        $breakdown = (new score_calculator())->get_breakdown($plugins);

        // 12 (blocker) * 2 (mod) * 1.5 (used) = 36.
        $this->assertSame('used', $breakdown[0]['usage']);
        $this->assertSame(36.0, $breakdown[0]['penalty']);
        $this->assertSame(64, (new score_calculator())->calculate($plugins));
    }

    /**
     * A blocker on a plugin nothing uses still costs, but far less.
     *
     * @covers \tool_upgradeguard\local\score_calculator::get_breakdown
     * @covers \tool_upgradeguard\local\score_calculator::calculate
     */
    public function test_unused_blocker_penalty(): void {
        $this->resetAfterTest();
        set_config('weightblocker', 12, 'tool_upgradeguard');
        set_config('weighttheme', 3, 'tool_upgradeguard');
        set_config('usagemultiplierunused', 0.3, 'tool_upgradeguard');

        $plugins = [$this->plugin('theme_unused_blocker', 'theme', 'blocker', 0)];
        $breakdown = (new score_calculator())->get_breakdown($plugins);

        // 12 (blocker) * 3 (theme) * 0.3 (unused) = 10.8, so the score stays high.
        $this->assertSame('unused', $breakdown[0]['usage']);
        $this->assertSame(10.8, $breakdown[0]['penalty']);
        $this->assertSame(89, (new score_calculator())->calculate($plugins));
    }

    /**
     * Only a blocker that is in use sets the flag the verdict looks at.
     *
     * @covers \tool_upgradeguard\local\score_calculator::count_statuses
     */
    public function test_count_statuses_reports_used_blockers(): void {
        $plugins = [
            $this->plugin('mod_used_blocker', 'mod', 'blocker', 2),
            $this->plugin('theme_unused_blocker', 'theme', 'blocker', 0),
            $this->plugin('local_unknown_blocker', 'local', 'blocker', null),
            $this->plugin('mod_caution', 'mod', 'caution', 1),
        ];

        $counts = (new score_calculator())->count_statuses($plugins);

        $this->assertSame(3, $counts['blockers']);
        $this->assertSame(1, $counts['cautions']);
        $this->assertTrue($counts['hasusedblocker']);
    }

    /**
     * Unmeasurable usage does not count as "in use".
     *
     * Recorded so this is a decision rather than an accident: a blocker in a
     * plugin whose usage cannot be measured (null) does not set the used blocker
     * flag, so the stop decision then rests on the score alone.
     *
     * @covers \tool_upgradeguard\local\score_calculator::count_statuses
     */
    public function test_unmeasurable_usage_is_not_treated_as_used(): void {
        $plugins = [
            $this->plugin('local_unknown_blocker', 'local', 'blocker', null),
            $this->plugin('theme_unused_blocker', 'theme', 'blocker', 0),
        ];

        $counts = (new score_calculator())->count_statuses($plugins);

        $this->assertSame(2, $counts['blockers']);
        $this->assertFalse($counts['hasusedblocker']);
    }

    public function test_environment_blockers_and_cautions_affect_the_score(): void {
        $this->resetAfterTest();
        set_config('weightblocker', 12, 'tool_upgradeguard');
        set_config('weightcaution', 4, 'tool_upgradeguard');

        $blocker = new \tool_upgradeguard\local\finding(
            'site_php',
            \tool_upgradeguard\local\severity::blocker,
            'finding_site_php_too_old',
            [],
            \tool_upgradeguard\local\confidence::high
        );
        $caution = new \tool_upgradeguard\local\finding(
            'environment_database',
            \tool_upgradeguard\local\severity::caution,
            'finding_environment_database_unknown',
            [],
            \tool_upgradeguard\local\confidence::low
        );

        $calculator = new score_calculator();
        $this->assertSame(75, $calculator->calculate([], [$blocker]));
        $this->assertSame(95, $calculator->calculate([], [$caution]));
        $this->assertSame(1, $calculator->count_environment([$blocker])['environmentblockers']);
        $this->assertSame(1, $calculator->count_environment([$caution])['environmentcautions']);
    }

    /**
     * Environment findings are visible in the machine-readable score breakdown.
     *
     * @covers \tool_upgradeguard\local\score_calculator::get_breakdown
     */
    public function test_environment_findings_are_in_the_score_breakdown(): void {
        $finding = new \tool_upgradeguard\local\finding(
            'site_php',
            \tool_upgradeguard\local\severity::blocker,
            'finding_site_php_too_old',
            [],
            \tool_upgradeguard\local\confidence::high
        );

        $breakdown = (new score_calculator())->get_breakdown([], [$finding]);

        $this->assertCount(1, $breakdown);
        $this->assertSame('site', $breakdown[0]['component']);
        $this->assertSame(25.0, $breakdown[0]['penalty']);
    }

    /**
     * Three hundred records are scored in memory without a per-plugin query.
     *
     * @covers \tool_upgradeguard\local\score_calculator::calculate
     */
    public function test_three_hundred_plugin_score_is_fast(): void {
        $this->resetAfterTest();
        $plugins = [];
        for ($number = 1; $number <= 300; $number++) {
            $plugins[] = $this->plugin('mod_fixture_' . $number, 'mod', 'ready', $number);
        }

        $started = microtime(true);
        $score = (new score_calculator())->calculate($plugins);

        $this->assertSame(100, $score);
        $this->assertLessThan(1.0, microtime(true) - $started);
    }
}
