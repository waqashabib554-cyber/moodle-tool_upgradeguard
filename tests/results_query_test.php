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
 * Tests the results table queries against a seeded scan.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use advanced_testcase;
use tool_upgradeguard\local\repository\scan_repository;
use tool_upgradeguard\local\results_filters;
use tool_upgradeguard\local\target;

/**
 * Search, filters, sorting, pagination and the query budget.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class results_query_test extends advanced_testcase {
    /**
     * Seed a finished scan with plugin rows and return its id.
     *
     * @param int $count How many plugin rows to insert.
     * @return int The scan id.
     */
    private function seed(int $count): int {
        global $DB, $USER;

        $target = new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified');
        $repository = new scan_repository();
        $scanid = $repository->create_queued_scan($target, 'Moodle 5.2', 502, 3, (int) $USER->id);
        $DB->update_record('tool_upgradeguard_scan', (object) ['id' => $scanid, 'status' => 'finished']);

        $statuses = ['blocker', 'caution', 'update', 'ready', 'unknown'];
        $types = ['mod', 'block', 'local'];
        $usages = [5, 0, null];
        $records = [];

        for ($i = 0; $i < $count; $i++) {
            $records[] = (object) [
                'scanid' => $scanid,
                'component' => 'mod_plugin' . $i,
                'plugintype' => $types[$i % 3],
                'name' => 'plugin' . $i,
                'displayname' => 'Plugin ' . $i,
                'versiondisk' => '2026010100',
                'versiondb' => '2026010100',
                'pluginrelease' => 'v1.0.' . $i,
                'isstandard' => 0,
                'installed' => 1,
                'currentpath' => '/mod/plugin' . $i,
                'newpath' => '/public/mod/plugin' . $i,
                'status' => $statuses[$i % 5],
                'usagecount' => $usages[$i % 3],
                'updateavailable' => 0,
                'timecreated' => time(),
            ];
        }

        $DB->insert_records('tool_upgradeguard_plugin', $records);

        return $scanid;
    }

    /**
     * Run one page of the results table and count the queries it needs.
     *
     * @param int $scanid The seeded scan.
     * @param array $overrides Raw filter overrides for the page.
     * @return array The rows, the total and the number of queries used.
     */
    private function runpage(int $scanid, array $overrides = []): array {
        global $DB;

        $repository = new scan_repository();
        $filters = results_filters::normalise($overrides);
        $sort = ['by' => $filters['sortby'], 'dir' => $filters['dir']];

        $before = $DB->perf_get_queries();
        $total = $repository->count_plugins($scanid, $filters);
        $rows = $repository->search_plugins($scanid, $filters, $sort, $filters['page']);
        $findings = $repository->get_findings_for_components($scanid, array_column($rows, 'component'));
        $queries = $DB->perf_get_queries() - $before;

        return [$rows, $total, $queries, $findings];
    }

    /**
     * Fifty five rows become three pages of 25, 25 and 5.
     */
    public function test_pagination_splits_the_rows(): void {
        $this->resetAfterTest();
        $scanid = $this->seed(55);

        [$first, $total] = $this->runpage($scanid, ['page' => 1]);
        [$second] = $this->runpage($scanid, ['page' => 2]);
        [$third] = $this->runpage($scanid, ['page' => 3]);

        $this->assertSame(55, $total);
        $this->assertCount(25, $first);
        $this->assertCount(25, $second);
        $this->assertCount(5, $third);
    }

    /**
     * A page number beyond the end clamps to the last page.
     *
     * The repository itself returns nothing for an out of range page; the
     * clamping is done by the page that knows the total first.
     */
    public function test_page_beyond_the_end_is_clamped(): void {
        $this->resetAfterTest();
        $scanid = $this->seed(30);
        $repository = new scan_repository();

        // Raw behaviour of the repository: an out of range page is empty.
        [$rawrows] = $this->runpage($scanid, ['page' => 99]);
        $this->assertCount(0, $rawrows);

        // What results.php does: count, compute pages, clamp, then read.
        $filters = results_filters::normalise(['page' => 99]);
        $total = $repository->count_plugins($scanid, $filters);
        $pages = max(1, (int) ceil($total / scan_repository::RESULTS_PER_PAGE));
        $filters['page'] = min($filters['page'], $pages);

        [$rows] = $this->runpage($scanid, ['page' => $filters['page']]);

        $this->assertSame(2, $filters['page']);
        $this->assertCount(5, $rows);
    }

    /**
     * The search matches the component and the display name.
     */
    public function test_search_matches_component_and_displayname(): void {
        $this->resetAfterTest();
        $scanid = $this->seed(55);

        [, $bycomponent] = $this->runpage($scanid, ['search' => 'plugin1']);
        [, $bydisplayname] = $this->runpage($scanid, ['search' => 'Plugin 5']);

        // Components mod_plugin1, mod_plugin10 ... mod_plugin19.
        $this->assertSame(11, $bycomponent);
        // Display names Plugin 5, Plugin 50 ... Plugin 54.
        $this->assertSame(6, $bydisplayname);
    }

    /**
     * The status filter only returns rows with that status.
     */
    public function test_status_filter(): void {
        $this->resetAfterTest();
        $scanid = $this->seed(55);

        [$rows, $total] = $this->runpage($scanid, ['status' => 'blocker']);

        $this->assertSame(11, $total);
        foreach ($rows as $row) {
            $this->assertSame('blocker', $row->status);
        }
    }

    /**
     * The type filter only returns rows with that plugin type.
     */
    public function test_type_filter(): void {
        $this->resetAfterTest();
        $scanid = $this->seed(55);

        [$rows, $total] = $this->runpage($scanid, ['type' => 'block']);

        $this->assertSame(18, $total);
        foreach ($rows as $row) {
            $this->assertSame('block', $row->plugintype);
        }
    }

    /**
     * The in use filter keeps only rows with a usage count above zero.
     */
    public function test_inuse_filter(): void {
        $this->resetAfterTest();
        $scanid = $this->seed(55);

        [$rows, $total] = $this->runpage($scanid, ['inuse' => 1]);

        $this->assertSame(19, $total);
        foreach ($rows as $row) {
            $this->assertGreaterThan(0, (int) $row->usagecount);
        }
    }

    /**
     * Status sorting runs worst first, no matter how the pages are split.
     */
    public function test_status_sort_is_worst_first(): void {
        $this->resetAfterTest();
        $scanid = $this->seed(55);

        $ranks = ['blocker' => 0, 'caution' => 1, 'unknown' => 2, 'update' => 3, 'ready' => 4];
        $sequence = [];
        for ($page = 1; $page <= 3; $page++) {
            [$rows] = $this->runpage($scanid, ['sortby' => 'status', 'dir' => 'asc', 'page' => $page]);
            foreach ($rows as $row) {
                $sequence[] = $ranks[$row->status];
            }
        }

        $sorted = $sequence;
        sort($sorted);
        $this->assertSame($sorted, $sequence);
        $this->assertSame(0, $sequence[0]);
        $this->assertSame(4, $sequence[54]);
    }

    /**
     * Name and type sorting use their own columns.
     */
    public function test_name_and_type_sort(): void {
        $this->resetAfterTest();
        $scanid = $this->seed(55);

        [$byname] = $this->runpage($scanid, ['sortby' => 'name']);
        [$bytype] = $this->runpage($scanid, ['sortby' => 'type']);

        $this->assertSame('Plugin 0', reset($byname)->displayname);
        $this->assertSame('block', reset($bytype)->plugintype);
    }

    /**
     * The findings of one page arrive in one grouped query.
     */
    public function test_findings_come_grouped_for_the_page(): void {
        global $DB;

        $this->resetAfterTest();
        $scanid = $this->seed(30);

        $pluginids = $DB->get_records('tool_upgradeguard_plugin', ['scanid' => $scanid], '', 'id, component');
        foreach ($pluginids as $pluginid => $plugin) {
            $DB->insert_record('tool_upgradeguard_finding', (object) [
                'scanid' => $scanid,
                'pluginid' => $pluginid,
                'checkkey' => 'public_location',
                'severity' => 'caution',
                'messagekey' => 'finding_legacy_location',
                'params' => json_encode(['oldpath' => $plugin->component, 'newpath' => 'public/x']),
                'confidence' => 'high',
                'fixedbyupdate' => 0,
                'actionkey' => 'action_move_plugin_to_public',
                'timecreated' => time(),
            ]);
        }

        [, , , $findings] = $this->runpage($scanid, ['page' => 1]);

        $this->assertCount(25, $findings);
        $this->assertCount(1, $findings['mod_plugin0']);
    }

    /**
     * The query count does not grow with the dataset size.
     */
    public function test_query_count_is_constant_for_big_datasets(): void {
        $this->resetAfterTest();
        $scanid = $this->seed(30);

        [, , $small] = $this->runpage($scanid);

        $scanid2 = $this->seed(100);
        [, , $big] = $this->runpage($scanid2);

        $this->assertSame($small, $big);
        $this->assertLessThanOrEqual(3, $big);
    }
}
