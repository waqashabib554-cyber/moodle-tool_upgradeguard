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
 * Privacy tests for Upgrade Guard.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\privacy;

use context_system;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use tool_upgradeguard\local\repository\scan_repository;
use tool_upgradeguard\local\target;

/**
 * Tests that scans made by a user can be exported and deleted.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_upgradeguard\privacy\provider
 */
final class provider_test extends provider_testcase {
    /**
     * Store a finished scan for a user.
     *
     * @param int $userid The user the scan belongs to.
     * @return int The scan id.
     */
    private function create_scan_for_user(int $userid): int {
        $target = new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified');
        $repository = new scan_repository();

        $scanid = $repository->create_queued_scan($target, '5.2', 502, 2, $userid);
        $repository->finish_scan($scanid, 100, 'go', ['plugincount' => 0]);

        return $scanid;
    }

    /**
     * The metadata lists the tables that hold personal data.
     *
     * @covers \tool_upgradeguard\privacy\provider::get_metadata
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('tool_upgradeguard'));
        $items = $collection->get_collection();

        $this->assertCount(3, $items);
        $this->assertSame('tool_upgradeguard_scan', $items[0]->get_name());
    }

    /**
     * A scan is only offered to the administrator who ran it.
     *
     * @covers \tool_upgradeguard\privacy\provider::get_contexts_for_userid
     */
    public function test_get_contexts_for_userid(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $this->assertCount(0, provider::get_contexts_for_userid((int) $user->id)->get_contextids());

        $this->create_scan_for_user((int) $user->id);

        $this->assertCount(1, provider::get_contexts_for_userid((int) $user->id)->get_contextids());
        $this->assertCount(0, provider::get_contexts_for_userid((int) $other->id)->get_contextids());
    }

    /**
     * Exporting a user writes their scans.
     *
     * @covers \tool_upgradeguard\privacy\provider::export_user_data
     */
    public function test_export_user_data(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->create_scan_for_user((int) $user->id);

        $contextlist = provider::get_contexts_for_userid((int) $user->id);
        provider::export_user_data(new approved_contextlist($user, 'tool_upgradeguard', $contextlist->get_contextids()));

        $writer = writer::with_context(context_system::instance());
        $this->assertTrue($writer->has_any_data());
    }

    /**
     * Deleting a user removes their scans.
     *
     * @covers \tool_upgradeguard\privacy\provider::delete_data_for_user
     */
    public function test_delete_data_for_user(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $this->create_scan_for_user((int) $user->id);
        $this->create_scan_for_user((int) $other->id);

        $contextlist = provider::get_contexts_for_userid((int) $user->id);
        provider::delete_data_for_user(new approved_contextlist($user, 'tool_upgradeguard', $contextlist->get_contextids()));

        $this->assertFalse(scan_repository::user_recorded((int) $user->id));
        $this->assertTrue(scan_repository::user_recorded((int) $other->id));
    }

    /**
     * Deleting the system context removes every scan.
     *
     * @covers \tool_upgradeguard\privacy\provider::delete_data_for_all_users_in_context
     */
    public function test_delete_data_for_all_users_in_context(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->create_scan_for_user((int) $user->id);

        provider::delete_data_for_all_users_in_context(context_system::instance());

        $this->assertFalse(scan_repository::user_recorded((int) $user->id));
    }
}
