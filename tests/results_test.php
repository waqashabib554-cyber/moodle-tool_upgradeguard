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
 * Tests the results table renderable context.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use moodle_url;
use basic_testcase;
use renderer_base;
use stdClass;
use tool_upgradeguard\local\target;
use tool_upgradeguard\local\confidence;
use tool_upgradeguard\local\finding;
use tool_upgradeguard\local\severity;
use tool_upgradeguard\output\results;

/**
 * The context keys the results template reads must all exist.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class results_test extends basic_testcase {
    /**
     * A renderer to pass to the renderable.
     *
     * @return renderer_base
     */
    private function renderer(): renderer_base {
        return $this->getMockBuilder(renderer_base::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
    }

    /**
     * The normalised filters of the hand built results table.
     *
     * @return array
     */
    private function filters(): array {
        return [
            'search' => '',
            'status' => '',
            'type' => '',
            'inuse' => false,
            'sortby' => 'status',
            'dir' => 'asc',
            'page' => 1,
        ];
    }

    /**
     * The context of the hand built results table.
     *
     * @return array
     */
    private function context(array $findings = []): array {
        $scan = new stdClass();
        $scan->id = 7;
        $scan->status = 'finished';
        $scan->currentversion = '5.2 (Build: 20260420)';
        $scan->targetversion = '5.2';
        $scan->score = 63;
        $scan->verdict = 'careful';

        $row = new stdClass();
        $row->component = 'mod_x';
        $row->plugintype = 'mod';
        $row->displayname = 'Plugin X';
        $row->status = 'blocker';
        $row->versiondisk = '2026010100';
        $row->pluginrelease = '';
        $row->usagecount = 3;

        $target = new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified');
        $results = new results(
            $scan,
            $target,
            $this->filters(),
            ['by' => 'status', 'dir' => 'asc'],
            [$row],
            $findings,
            30,
            ['mod', 'block']
        );

        return $results->export_for_template($this->renderer());
    }

    /**
     * Every sortable header gets its own named context key with a link.
     *
     * Regression: the sort links used to be indexed numerically, so the
     * template found neither sortname, sorttype nor sortstatus.
     */
    public function test_sort_header_keys_exist(): void {
        $context = $this->context();

        foreach (['sortname', 'sorttype', 'sortstatus'] as $key) {
            $this->assertArrayHasKey($key, $context);
            $this->assertArrayHasKey('url', $context[$key]);
            $this->assertArrayHasKey('label', $context[$key]);
        }

        $this->assertStringContainsString('sortby=name', $context['sortname']['url']);
        $this->assertStringContainsString('sortby=type', $context['sorttype']['url']);
        $this->assertStringContainsString('sortby=status', $context['sortstatus']['url']);
    }

    /**
     * The active sort column is marked and its link toggles the direction.
     */
    public function test_active_sort_column_toggles_direction(): void {
        $context = $this->context();

        $this->assertStringContainsString('▲', $context['sortstatus']['label']);
        $this->assertStringContainsString('dir=desc', $context['sortstatus']['url']);
        $this->assertStringContainsString('dir=asc', $context['sortname']['url']);
    }

    /**
     * The form, the reset link and the dropdowns are all prepared.
     */
    public function test_form_and_filter_keys_exist(): void {
        $context = $this->context();

        $this->assertStringContainsString('results.php', $context['formaction']);
        $this->assertStringContainsString('id=7', $context['reseturl']);
        $this->assertCount(6, $context['statusoptions']);
        $this->assertSame('All', $context['statusoptions'][0]['label']);
        $this->assertCount(3, $context['typeoptions']);
        $this->assertSame('', $context['statusoptions'][0]['value']);
    }

    /**
     * One row carries everything the collapse needs.
     */
    public function test_rows_carry_the_collapse_data(): void {
        $context = $this->context();

        $this->assertCount(1, $context['plugins']);
        $this->assertSame('mod_x', $context['plugins'][0]['component']);
        $this->assertSame('Blocker', $context['plugins'][0]['statuslabel']);
        $this->assertFalse($context['plugins'][0]['hasfindings']);
        $this->assertSame(0, $context['plugins'][0]['findingcount']);
        $this->assertNotSame('', $context['plugins'][0]['version']);
    }

    /**
     * Results findings expose the same core links as dashboard actions.
     */
    public function test_findings_carry_applicable_core_links(): void {
        $update = new finding(
            'update_available',
            severity::info,
            'finding_update_available',
            ['version' => '2026020100'],
            confidence::high,
            false,
            'action_update_plugin'
        );
        $remove = new finding(
            'unused_plugin',
            severity::caution,
            'finding_unused_plugin',
            [],
            confidence::high,
            false,
            'action_review_or_remove'
        );
        $context = $this->context(['mod_x' => [$update, $remove]]);
        $rowsbykey = [];
        foreach ($context['plugins'][0]['findings'] as $row) {
            $rowsbykey[$row['actionkey']] = $row;
        }

        $this->assertSame(
            (new moodle_url('/admin/tool/installaddon/index.php'))->out(false),
            $rowsbykey['action_update_plugin']['actionurl']
        );
        $this->assertTrue($rowsbykey['action_update_plugin']['hasactionurl']);
        $this->assertSame(
            (new moodle_url('/admin/plugins.php'))->out(false),
            $rowsbykey['action_review_or_remove']['actionurl']
        );
        $this->assertTrue($rowsbykey['action_review_or_remove']['hasactionurl']);
    }

    /**
     * The pagination is prepared from the total.
     */
    public function test_pagination_keys_exist(): void {
        $context = $this->context();

        $this->assertTrue($context['haspagination']);
        $this->assertStringContainsString('1', $context['pageof']);
        // 30 rows make two pages, so the window around page 1 has both.
        $this->assertCount(2, $context['paginationlinks']);
        $this->assertTrue($context['paginationlinks'][0]['iscurrent']);
        $this->assertFalse($context['paginationlinks'][1]['iscurrent']);
        $this->assertStringContainsString('page=2', $context['paginationlinks'][1]['url']);
    }
}
