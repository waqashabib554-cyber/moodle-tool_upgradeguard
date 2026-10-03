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
 * Tests the /public move list builder.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use basic_testcase;
use stdClass;
use tool_upgradeguard\local\layout;
use tool_upgradeguard\local\move_list;
use tool_upgradeguard\local\target;

/**
 * Check E: the move list exists only for public layout targets and only displays.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class move_list_test extends basic_testcase {
    /**
     * A target that reads plugins from the public directory.
     *
     * @return target
     */
    private function public_target(): target {
        return new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified');
    }

    /**
     * A target that still reads plugins from the old layout.
     *
     * @return target
     */
    private function legacy_target(): target {
        return new target('5.0', 500, 2025041400, '8.2.0', '4.2.3', false, 'stable', 'verified');
    }

    /**
     * One stored plugin row.
     *
     * @param string $component Frankenstyle component name.
     * @param string $type Plugin type.
     * @param bool $isstandard Whether the plugin ships with Moodle.
     * @param string $current Path the plugin lives in, relative to the install root.
     * @param string $new Path the plugin has to live in for the target.
     * @return stdClass
     */
    private function row(string $component, string $type, bool $isstandard, string $current, string $new): stdClass {
        $row = new stdClass();
        $row->component = $component;
        $row->plugintype = $type;
        $row->isstandard = $isstandard ? 1 : 0;
        $row->currentpath = $current;
        $row->newpath = $new;
        return $row;
    }

    /**
     * The installation root as it appears in the generated commands.
     *
     * @return string
     */
    private function command_root(): string {
        return rtrim(str_replace('\\', '/', layout::get_root()), '/');
    }

    /**
     * Targets without the public layout must not show the section at all.
     */
    public function test_section_is_hidden_without_a_public_layout_target(): void {
        $rows = [$this->row('mod_old', 'mod', false, '/mod/old', '/public/mod/old')];

        $this->assertNull(move_list::build($this->legacy_target(), $rows));
        $this->assertNull(move_list::build(null, $rows));
    }

    /**
     * A plugin in the old layout gets its public target path and a copy command.
     */
    public function test_legacy_plugin_gets_the_public_target_path(): void {
        $list = move_list::build($this->public_target(), [
            $this->row('mod_attend', 'mod', false, '/mod/attend', '/public/mod/attend'),
        ]);

        $this->assertNotNull($list);
        $this->assertSame(1, $list['plugincount']);
        $this->assertSame(1, $list['movecount']);
        $this->assertTrue($list['rows'][0]['hasmove']);
        $this->assertSame('mod/attend', $list['rows'][0]['currentpath']);
        $this->assertSame('public/mod/attend', $list['rows'][0]['newpath']);
        $this->assertSame(
            "mkdir -p " . $this->command_root() . "/public/mod\n" .
            'cp -r ' . $this->command_root() . '/mod/attend ' . $this->command_root() . '/public/mod/attend',
            $list['commandstext']
        );
    }

    /**
     * Admin tool plugins keep their nested admin/tool path inside public.
     */
    public function test_admin_tool_plugin_target_path(): void {
        $list = move_list::build($this->public_target(), [
            $this->row('tool_custom', 'admin', false, '/admin/tool/custom', '/public/admin/tool/custom'),
        ]);

        $this->assertNotNull($list);
        $this->assertSame('public/admin/tool/custom', $list['rows'][0]['newpath']);
        $this->assertTrue($list['rows'][0]['hasmove']);
    }

    /**
     * A plugin already in the public layout needs no move and no command.
     */
    public function test_plugin_already_in_public_needs_no_move(): void {
        $list = move_list::build($this->public_target(), [
            $this->row('theme_x', 'theme', false, '/public/theme/x', '/public/theme/x'),
        ]);

        $this->assertNotNull($list);
        $this->assertFalse($list['rows'][0]['hasmove']);
        $this->assertSame($list['rows'][0]['currentpath'], $list['rows'][0]['newpath']);
        $this->assertSame(0, $list['movecount']);
        $this->assertSame('', $list['commandstext']);
        $this->assertStringContainsString(
            'theme_x,theme,public/theme/x,public/theme/x',
            $list['csvtext']
        );
    }

    /**
     * Plugins that ship with Moodle are never part of the move list.
     */
    public function test_core_plugins_are_excluded(): void {
        $list = move_list::build($this->public_target(), [
            $this->row('mod_forum', 'mod', true, '/mod/forum', '/public/mod/forum'),
            $this->row('mod_custom', 'mod', false, '/mod/custom', '/public/mod/custom'),
        ]);

        $this->assertNotNull($list);
        $this->assertSame(1, $list['plugincount']);
        $this->assertSame('mod_custom', $list['rows'][0]['component']);
    }

    /**
     * Plugins found only on disk are third-party plugins and stay listed.
     */
    public function test_orphan_plugins_are_listed(): void {
        $list = move_list::build($this->public_target(), [
            $this->row('theme_lost', 'theme', false, '/theme/lost', '/public/theme/lost'),
        ]);

        $this->assertNotNull($list);
        $this->assertSame(1, $list['plugincount']);
        $this->assertSame(1, $list['movecount']);
    }

    /**
     * Every cell goes through the same CSV neutralisation as the report export.
     */
    public function test_every_csv_cell_is_neutralised(): void {
        $list = move_list::build($this->public_target(), [
            $this->row('mod_a', '=evil', false, '/mod/a', '/public/mod/a'),
            $this->row('mod_b', '-dash', false, '/mod/b', '/public/mod/b'),
        ]);

        $this->assertNotNull($list);
        $this->assertStringContainsString("'=evil", $list['csvtext']);
        $this->assertStringContainsString("'-dash", $list['csvtext']);
    }

    /**
     * The CSV has the fixed four column header of Check E.
     */
    public function test_csv_has_the_fixed_column_header(): void {
        $list = move_list::build($this->public_target(), [
            $this->row('mod_a', 'mod', false, '/mod/a', '/public/mod/a'),
        ]);

        $this->assertNotNull($list);
        $this->assertSame(
            "component,type,current_path,new_path\nmod_a,mod,mod/a,public/mod/a",
            $list['csvtext']
        );
    }

    /**
     * Commands create the target directories first, and each one only once.
     */
    public function test_commands_start_with_deduplicated_mkdir_lines(): void {
        $list = move_list::build($this->public_target(), [
            $this->row('mod_a', 'mod', false, '/mod/a', '/public/mod/a'),
            $this->row('mod_b', 'mod', false, '/mod/b', '/public/mod/b'),
            $this->row('theme_c', 'theme', false, '/theme/c', '/public/theme/c'),
        ]);

        $this->assertNotNull($list);
        $root = $this->command_root();
        $expected = implode("\n", [
            'mkdir -p ' . $root . '/public/mod',
            'mkdir -p ' . $root . '/public/theme',
            'cp -r ' . $root . '/mod/a ' . $root . '/public/mod/a',
            'cp -r ' . $root . '/mod/b ' . $root . '/public/mod/b',
            'cp -r ' . $root . '/theme/c ' . $root . '/public/theme/c',
        ]);
        $this->assertSame($expected, $list['commandstext']);
    }

    /**
     * Rows are sorted by plugin type, then by component.
     */
    public function test_rows_are_sorted_by_type_then_component(): void {
        $list = move_list::build($this->public_target(), [
            $this->row('theme_z', 'theme', false, '/theme/z', '/public/theme/z'),
            $this->row('mod_b', 'mod', false, '/mod/b', '/public/mod/b'),
            $this->row('mod_a', 'mod', false, '/mod/a', '/public/mod/a'),
        ]);

        $this->assertNotNull($list);
        $this->assertSame(
            ['mod_a', 'mod_b', 'theme_z'],
            array_column($list['rows'], 'component')
        );
    }
}
