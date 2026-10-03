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
 * Tests that the plugin inventory is complete before a scan can continue.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use advanced_testcase;
use RuntimeException;
use tool_upgradeguard\local\collector\plugin_inventory;
use tool_upgradeguard\local\target;

/**
 * Tests plugin inventory failure handling.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_upgradeguard\local\collector\plugin_inventory
 */
final class plugin_inventory_test extends advanced_testcase {
    /**
     * A plugin inspection failure aborts rather than returning a partial inventory.
     */
    public function test_an_unreadable_plugin_does_not_disappear_from_the_inventory(): void {
        $plugin = $this->getMockBuilder(\core\plugininfo\base::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_dir', 'is_standard'])
            ->getMock();
        $plugin->component = 'mod_broken';
        $plugin->type = 'mod';
        $plugin->name = 'broken';
        $plugin->displayname = 'Broken plugin';
        $plugin->versiondisk = '2026050100';
        $plugin->versiondb = '2026050100';
        $plugin->pluginsupported = [];
        $plugin->method('get_dir')->willThrowException(new RuntimeException('broken plugin metadata'));
        $plugin->method('is_standard')->willReturn(false);

        $pluginmanager = $this->getMockBuilder(\core_plugin_manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_plugins'])
            ->getMock();
        $pluginmanager->method('get_plugins')->willReturn(['mod' => ['broken' => $plugin]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not inspect plugin mod_broken');

        (new plugin_inventory($pluginmanager))->collect(
            new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified')
        );
    }
}
