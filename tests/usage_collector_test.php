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
 * Tests for plugin usage collection.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use advanced_testcase;
use tool_upgradeguard\local\collector\usage_collector;
use tool_upgradeguard\local\plugin_snapshot;

/**
 * Tests that theme usage includes every supported override location.
 *
 * @package    tool_upgradeguard
 * @covers     \tool_upgradeguard\local\collector\usage_collector
 */
final class usage_collector_test extends advanced_testcase {
    /**
     * Category and user theme overrides count as theme usage.
     *
     * @covers \tool_upgradeguard\local\collector\usage_collector::collect
     */
    public function test_theme_usage_includes_category_and_user_overrides(): void {
        $this->resetAfterTest();
        global $DB;

        $category = self::getDataGenerator()->create_category();
        $DB->set_field('course_categories', 'theme', 'exampletheme', ['id' => $category->id]);
        $user = self::getDataGenerator()->create_user();
        $DB->set_field('user', 'theme', 'exampletheme', ['id' => $user->id]);

        $theme = new plugin_snapshot(
            'theme_exampletheme',
            'theme',
            'exampletheme',
            'Example theme',
            '2026010100',
            '2026010100',
            null,
            2024042200,
            '',
            null,
            false,
            true,
            '/public/theme/exampletheme',
            '/public/theme/exampletheme',
            false,
            null,
        );

        $usage = (new usage_collector())->collect(['theme_exampletheme' => $theme]);

        $this->assertArrayHasKey('theme_exampletheme', $usage);
        $this->assertGreaterThanOrEqual(2, $usage['theme_exampletheme']['count']);
    }
}
