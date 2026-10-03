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
 * Tests the pre-upgrade checklist builder.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use basic_testcase;
use stdClass;
use tool_upgradeguard\local\checklist;
use tool_upgradeguard\local\confidence;
use tool_upgradeguard\local\finding;
use tool_upgradeguard\local\severity;
use tool_upgradeguard\local\target;

/**
 * Section 6.6: the checklist always shows the fixed items and only shows the
 * result dependent items when the scan has something to say about them.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class checklist_test extends basic_testcase {
    /**
     * A target the scan was run against.
     *
     * @param bool $publiclayout Whether the target reads plugins from public.
     * @return target
     */
    private function target(bool $publiclayout): target {
        return new target('5.2', 502, 2026042000, '8.3.0', '4.4', $publiclayout, 'stable', 'verified');
    }

    /**
     * A stored scan row.
     *
     * @param int $blockers Number of blocker findings.
     * @param string $status Scan status.
     * @return stdClass
     */
    private function scan(int $blockers = 0, string $status = 'finished'): stdClass {
        $scan = new stdClass();
        $scan->status = $status;
        $scan->blockercount = $blockers;
        return $scan;
    }

    /**
     * One stored plugin row.
     *
     * @param bool $isstandard Whether the plugin ships with Moodle.
     * @param int $update Whether an update is available.
     * @param int|null $usage Usage count, null when it could not be measured.
     * @param string $current Path the plugin lives in, relative to the install root.
     * @param string $new Path the plugin has to live in for the target.
     * @return stdClass
     */
    private function row(
        bool $isstandard = false,
        int $update = 0,
        ?int $usage = null,
        string $current = '/public/mod/x',
        string $new = '/public/mod/x',
    ): stdClass {
        $row = new stdClass();
        $row->component = 'mod_x';
        $row->plugintype = 'mod';
        $row->isstandard = $isstandard ? 1 : 0;
        $row->currentpath = $current;
        $row->newpath = $new;
        $row->updateavailable = $update;
        $row->usagecount = $usage;
        return $row;
    }

    /**
     * One environment finding of the scan.
     *
     * @return finding
     */
    private function envfinding(): finding {
        return new finding(
            'site_php',
            severity::blocker,
            'finding_site_php_too_old',
            ['php' => '8.1.0', 'phpmin' => '8.3.0', 'target' => '5.2'],
            confidence::high,
            false,
            'action_upgrade_php',
        );
    }

    /**
     * The item keys of a built checklist.
     *
     * @param array $items The built items.
     * @return string[]
     */
    private function keys(array $items): array {
        return array_column($items, 'key');
    }

    /**
     * Without a scan the checklist is hidden completely.
     */
    public function test_no_scan_hides_the_checklist(): void {
        $this->assertSame([], checklist::build($this->target(true), null, [], []));
    }

    /**
     * A scan that has not finished yet has no results to build items from.
     */
    public function test_running_scan_hides_the_checklist(): void {
        $this->assertSame([], checklist::build($this->target(true), $this->scan(0, 'running'), [], []));
    }

    /**
     * A clean scan shows only the items that always apply.
     */
    public function test_clean_scan_has_only_the_always_items(): void {
        $items = checklist::build($this->target(false), $this->scan(), [$this->row()], []);

        $this->assertSame(
            ['backup', 'staging', 'maintenance', 'upgrade', 'rescan'],
            $this->keys($items)
        );
    }

    /**
     * The blocker item appears exactly when the scan has blockers.
     */
    public function test_blocker_item_appears_only_with_blockers(): void {
        $clean = checklist::build($this->target(false), $this->scan(), [$this->row()], []);
        $blocked = checklist::build($this->target(false), $this->scan(2), [$this->row()], []);

        $this->assertNotContains('blockers', $this->keys($clean));
        $this->assertContains('blockers', $this->keys($blocked));

        $item = $blocked[array_search('blockers', array_column($blocked, 'key'), true)];
        $this->assertSame(2, $item['params']);
    }

    /**
     * The environment item appears only when the scan reported an environment problem.
     */
    public function test_environment_item_needs_an_environment_finding(): void {
        $withenv = checklist::build($this->target(false), $this->scan(), [$this->row()], [$this->envfinding()]);

        $otherfinding = new finding(
            'update_information',
            severity::info,
            'finding_update_information_missing',
            [],
            confidence::low,
            false,
            'action_none',
        );
        $withother = checklist::build($this->target(false), $this->scan(), [$this->row()], [$otherfinding]);
        $without = checklist::build($this->target(false), $this->scan(), [$this->row()], []);

        $this->assertContains('environment', $this->keys($withenv));
        $this->assertNotContains('environment', $this->keys($withother));
        $this->assertNotContains('environment', $this->keys($without));
    }

    /**
     * The unused plugin item counts only plugins with a known zero usage.
     */
    public function test_unused_item_needs_known_unused_plugins(): void {
        $unused = checklist::build($this->target(false), $this->scan(), [$this->row(usage: 0)], []);
        $used = checklist::build($this->target(false), $this->scan(), [$this->row(usage: 3)], []);
        $unknown = checklist::build($this->target(false), $this->scan(), [$this->row()], []);

        $this->assertContains('unused', $this->keys($unused));
        $this->assertNotContains('unused', $this->keys($used));
        $this->assertNotContains('unused', $this->keys($unknown));
    }

    /**
     * The update item appears exactly when a plugin reports an update.
     */
    public function test_update_item_appears_only_with_available_updates(): void {
        $withupdate = checklist::build($this->target(false), $this->scan(), [$this->row(update: 1)], []);
        $without = checklist::build($this->target(false), $this->scan(), [$this->row()], []);

        $this->assertContains('updates', $this->keys($withupdate));
        $this->assertNotContains('updates', $this->keys($without));
    }

    /**
     * The move item needs a public layout target AND at least one mover.
     */
    public function test_move_item_needs_public_target_and_moves(): void {
        $mover = $this->row(current: '/mod/x', new: '/public/mod/x');
        $public = checklist::build($this->target(true), $this->scan(), [$mover], []);
        $oldtarget = checklist::build($this->target(false), $this->scan(), [$mover], []);
        $nomoves = checklist::build($this->target(true), $this->scan(), [$this->row()], []);

        $this->assertContains('move', $this->keys($public));
        $this->assertNotContains('move', $this->keys($oldtarget));
        $this->assertNotContains('move', $this->keys($nomoves));
    }

    /**
     * Plugins that ship with Moodle never create checklist items.
     */
    public function test_core_plugins_are_ignored(): void {
        $items = checklist::build($this->target(true), $this->scan(), [$this->row(isstandard: true, update: 1, usage: 0)], []);

        $this->assertNotContains('updates', $this->keys($items));
        $this->assertNotContains('unused', $this->keys($items));
        $this->assertNotContains('move', $this->keys($items));
    }

    /**
     * With everything present the order follows Section 6.6.
     */
    public function test_full_scan_follows_the_spec_order(): void {
        $mover = $this->row(current: '/mod/x', new: '/public/mod/x', update: 1, usage: 0);

        $items = checklist::build($this->target(true), $this->scan(3), [$mover], [$this->envfinding()]);

        $this->assertSame(
            [
                'backup',
                'staging',
                'environment',
                'blockers',
                'unused',
                'updates',
                'move',
                'maintenance',
                'upgrade',
                'rescan',
            ],
            $this->keys($items)
        );
    }

    /**
     * Every item carries a key the dashboard can translate.
     */
    public function test_every_item_has_a_translatable_key(): void {
        $mover = $this->row(current: '/mod/x', new: '/public/mod/x', update: 1, usage: 0);
        $items = checklist::build($this->target(true), $this->scan(1), [$mover], [$this->envfinding()]);

        foreach ($items as $item) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $item['key']);
            $this->assertTrue(array_key_exists('params', $item));
        }
    }
}
