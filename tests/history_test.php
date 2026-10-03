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
 * Tests the scan history page data.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use advanced_testcase;
use renderer_base;
use stdClass;
use tool_upgradeguard\local\repository\scan_repository;
use tool_upgradeguard\local\target;
use tool_upgradeguard\output\history;

/**
 * The history page lists every scan, paginated, with its owner.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class history_test extends advanced_testcase {
    /**
     * Queue a scan for the given user and return its id.
     *
     * @param int $userid The owner.
     * @return int
     */
    private function seedscan(int $userid): int {
        $target = new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified');

        return (new scan_repository())->create_queued_scan($target, 'Moodle 5.2', 502, 3, $userid);
    }

    /**
     * Seed many scans for one user.
     *
     * @param int $count How many scans.
     * @param int $userid The owner.
     * @return void
     */
    private function seedscans(int $count, int $userid): void {
        for ($i = 0; $i < $count; $i++) {
            $this->seedscan($userid);
        }
    }

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
     * Every history row shows the user who started the scan.
     */
    public function test_history_rows_carry_the_owner(): void {
        global $DB;

        $this->resetAfterTest();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $first = $this->seedscan((int) $user1->id);
        $second = $this->seedscan((int) $user2->id);

        $repository = new scan_repository();
        $rows = $repository->get_scans_page(25, 0);

        $this->assertCount(2, $rows);
        $this->assertSame($second, (int) reset($rows)->id);
        $this->assertSame($first, (int) end($rows)->id);

        $owner = $DB->get_record('user', ['id' => $user2->id]);
        $this->assertSame(fullname($owner), reset($rows)->ownername);
    }

    /**
     * Fifty five scans become three pages of 25, 25 and 5.
     */
    public function test_history_pagination(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->seedscans(55, (int) $user->id);

        $repository = new scan_repository();
        $this->assertSame(55, $repository->count_scans());
        $this->assertCount(25, $repository->get_scans_page(25, 0));
        $this->assertCount(25, $repository->get_scans_page(25, 25));
        $this->assertCount(5, $repository->get_scans_page(25, 50));
    }

    /**
     * The renderable prepares rows, links, the delete form and pagination.
     */
    public function test_history_context_is_prepared(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->seedscans(30, (int) $user->id);

        $repository = new scan_repository();
        $rows = $repository->get_scans_page(25, 0);
        $table = new history($rows, $repository->count_scans(), 1, true);
        $context = $table->export_for_template($this->renderer());

        $this->assertTrue($context['hasscans']);
        $this->assertCount(25, $context['scans']);
        $this->assertTrue($context['canmanage']);
        $this->assertNotSame('', $context['sesskey']);
        $this->assertStringContainsString('index.php', $context['deleteurl']);

        $row = $context['scans'][0];
        $this->assertSame('Queued', $row['statuslabel']);
        $this->assertNotSame('', $row['ownername']);
        $this->assertStringContainsString('results.php?id=', $row['resultsurl']);
        $this->assertStringContainsString('report.php?id=', $row['reporturl']);
        $this->assertStringContainsString('1', $context['pageof']);
        $this->assertCount(2, $context['paginationlinks']);
    }

    /**
     * Without the manage capability the delete form is not prepared.
     */
    public function test_history_hides_delete_without_the_capability(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->seedscans(2, (int) $user->id);

        $repository = new scan_repository();
        $table = new history($repository->get_scans_page(25, 0), 2, 1, false);
        $context = $table->export_for_template($this->renderer());

        $this->assertFalse($context['canmanage']);
    }

    /**
     * Every scan status has a history label, including the dynamic keys.
     */
    public function test_status_labels_resolve_for_every_status(): void {
        foreach (['queued', 'running', 'finished', 'failed'] as $status) {
            $label = get_string('historystatus_' . $status, 'tool_upgradeguard');
            $this->assertNotSame('', $label);
            $this->assertStringNotContainsString('[[' . 'historystatus', $label);
        }
    }
}
