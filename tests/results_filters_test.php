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
 * Tests the normalisation of the results table request parameters.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use basic_testcase;
use tool_upgradeguard\local\results_filters;

/**
 * Nothing user supplied may reach the repository unvalidated.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class results_filters_test extends basic_testcase {
    /**
     * Without parameters the defaults are the safe ones.
     */
    public function test_defaults_when_nothing_is_passed(): void {
        $filters = results_filters::normalise([]);

        $this->assertSame('', $filters['search']);
        $this->assertSame('', $filters['status']);
        $this->assertSame('', $filters['type']);
        $this->assertFalse($filters['inuse']);
        $this->assertSame('status', $filters['sortby']);
        $this->assertSame('asc', $filters['dir']);
        $this->assertSame(1, $filters['page']);
    }

    /**
     * An unknown status filter is dropped, a known one is kept.
     */
    public function test_status_must_be_a_known_status(): void {
        $this->assertSame('', results_filters::normalise(['status' => 'hacked'])['status']);
        $this->assertSame('caution', results_filters::normalise(['status' => 'caution'])['status']);
    }

    /**
     * The sort column and direction come from a whitelist.
     */
    public function test_sort_is_whitelisted(): void {
        $this->assertSame('status', results_filters::normalise(['sortby' => 'hacked'])['sortby']);
        $this->assertSame('name', results_filters::normalise(['sortby' => 'name'])['sortby']);
        $this->assertSame('type', results_filters::normalise(['sortby' => 'type'])['sortby']);
        $this->assertSame('asc', results_filters::normalise(['dir' => 'banana'])['dir']);
        $this->assertSame('desc', results_filters::normalise(['dir' => 'desc'])['dir']);
    }

    /**
     * The page number never drops below one.
     */
    public function test_page_is_at_least_one(): void {
        $this->assertSame(1, results_filters::normalise(['page' => 0])['page']);
        $this->assertSame(1, results_filters::normalise(['page' => -5])['page']);
        $this->assertSame(3, results_filters::normalise(['page' => 3])['page']);
    }

    /**
     * The search is trimmed and the type keeps only plugin type characters.
     */
    public function test_search_and_type_are_cleaned(): void {
        $filters = results_filters::normalise(['search' => ' foo bar ', 'type' => 'mod!@x']);

        $this->assertSame('foo bar', $filters['search']);
        $this->assertSame('modx', $filters['type']);
    }

    /**
     * The in use filter is a real boolean.
     */
    public function test_inuse_is_a_bool(): void {
        $this->assertFalse(results_filters::normalise([])['inuse']);
        $this->assertFalse(results_filters::normalise(['inuse' => 0])['inuse']);
        $this->assertTrue(results_filters::normalise(['inuse' => 1])['inuse']);
    }
}
