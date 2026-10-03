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
 * Tests for the directory layout helper.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use basic_testcase;
use tool_upgradeguard\local\layout;
use tool_upgradeguard\local\target;

/**
 * Tests the rules that decide whether a plugin has to move into public/.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_upgradeguard\local\layout
 */
final class layout_test extends basic_testcase {
    /**
     * Build a target with the given public layout flag.
     *
     * @param bool $publiclayout Whether the branch reads plugins from public/.
     * @return target
     */
    private function make_target(bool $publiclayout): target {
        return new target('9.9', 999, 2099040100, '8.1.0', '4.1.2', $publiclayout, 'stable', 'verified');
    }

    /**
     * A plugin in the old location has to move for a public layout target.
     *
     * @covers \tool_upgradeguard\local\layout::get_expected_path
     */
    public function test_get_expected_path_adds_public_for_public_layout_target(): void {
        $this->assertSame('/public/theme/example', layout::get_expected_path('/theme/example', $this->make_target(true)));
        $this->assertTrue(layout::needs_move('/theme/example', $this->make_target(true)));
    }

    /**
     * A plugin that is already in public/ stays where it is.
     *
     * @covers \tool_upgradeguard\local\layout::get_expected_path
     */
    public function test_get_expected_path_keeps_public_path(): void {
        $this->assertSame(
            '/public/theme/example',
            layout::get_expected_path('/public/theme/example', $this->make_target(true))
        );
        $this->assertFalse(layout::needs_move('/public/theme/example', $this->make_target(true)));
    }

    /**
     * A target without public layout expects plugins above the public directory.
     *
     * A plugin that currently lives in public/ would have to move back out for
     * such a target, which is what a downgrade would require. The documented
     * upgrade path never does this, but the rule must still be symmetrical.
     *
     * @covers \tool_upgradeguard\local\layout::get_expected_path
     */
    public function test_get_expected_path_removes_public_for_legacy_target(): void {
        $this->assertSame('/mod/forum', layout::get_expected_path('/public/mod/forum', $this->make_target(false)));
        $this->assertTrue(layout::needs_move('/public/mod/forum', $this->make_target(false)));
        $this->assertFalse(layout::needs_move('/mod/forum', $this->make_target(false)));
    }

    /**
     * Relative paths are normalised before they are compared.
     *
     * @covers \tool_upgradeguard\local\layout::normalise_relative_path
     */
    public function test_normalise_relative_path(): void {
        $this->assertSame('/mod/forum', layout::normalise_relative_path('mod/forum'));
        $this->assertSame('/mod/forum', layout::normalise_relative_path('\\mod/forum'));
        $this->assertSame('/mod/forum', layout::normalise_relative_path('/mod/forum/'));
    }

    /**
     * Only paths strictly inside public/ count as being in the public directory.
     *
     * @covers \tool_upgradeguard\local\layout::is_inside_public_directory
     */
    public function test_is_inside_public_directory(): void {
        $this->assertTrue(layout::is_inside_public_directory('/public/mod/forum'));
        $this->assertTrue(layout::is_inside_public_directory('public/mod/forum'));
        $this->assertFalse(layout::is_inside_public_directory('/mod/forum'));
        $this->assertFalse(layout::is_inside_public_directory('/publice/mod/forum'));
        $this->assertFalse(layout::is_inside_public_directory('/public'));
    }

    /**
     * Core reports plugin paths relative to dirroot, which we convert to the installation root.
     *
     * @covers \tool_upgradeguard\local\layout::to_root_relative
     */
    public function test_to_root_relative_follows_the_site_layout(): void {
        $expected = layout::is_public_layout_site() ? '/public/mod/forum' : '/mod/forum';
        $this->assertSame($expected, layout::to_root_relative('/mod/forum'));
    }
}
