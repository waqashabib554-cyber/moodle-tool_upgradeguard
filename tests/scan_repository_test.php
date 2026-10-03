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
 * Tests for persistent scan state.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use advanced_testcase;
use tool_upgradeguard\local\repository\scan_repository;
use tool_upgradeguard\local\target;

/**
 * Tests recovery from an interrupted ad hoc scan task.
 *
 * @package    tool_upgradeguard
 * @covers     \tool_upgradeguard\local\repository\scan_repository
 */
final class scan_repository_test extends advanced_testcase {
    /**
     * A running scan older than the timeout is failed and no longer blocks a new scan.
     *
     * @covers \tool_upgradeguard\local\repository\scan_repository::recover_stale_running_scans
     */
    public function test_recover_stale_running_scan(): void {
        $this->resetAfterTest();
        global $DB, $USER;

        $target = new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified');
        $repository = new scan_repository();
        $scanid = $repository->create_queued_scan($target, 'Moodle 5.2', 502, 2, (int) $USER->id);
        $DB->update_record('tool_upgradeguard_scan', (object) [
            'id' => $scanid,
            'status' => scan_repository::STATUS_RUNNING,
            'timestarted' => time() - HOURSECS - 1,
        ]);

        $this->assertSame(1, $repository->recover_stale_running_scans(HOURSECS));

        $scan = $repository->get_scan($scanid);
        $this->assertSame(scan_repository::STATUS_FAILED, $scan->status);
        $this->assertSame(0, (int) $scan->progress);
        $this->assertNotEmpty($scan->errormessage);
        $this->assertNull($repository->get_active_scan());
    }

    /**
     * A recently started scan remains active.
     *
     * @covers \tool_upgradeguard\local\repository\scan_repository::recover_stale_running_scans
     */
    public function test_recovery_does_not_interrupt_a_recent_scan(): void {
        $this->resetAfterTest();
        global $DB, $USER;

        $target = new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified');
        $repository = new scan_repository();
        $scanid = $repository->create_queued_scan($target, 'Moodle 5.2', 502, 2, (int) $USER->id);
        $DB->update_record('tool_upgradeguard_scan', (object) [
            'id' => $scanid,
            'status' => scan_repository::STATUS_RUNNING,
            'timestarted' => time(),
        ]);

        $this->assertSame(0, $repository->recover_stale_running_scans(HOURSECS));
        $this->assertSame(scan_repository::STATUS_RUNNING, $repository->get_scan($scanid)->status);
    }

    /**
     * Two workers cannot both claim one queued scan.
     *
     * @covers \tool_upgradeguard\local\repository\scan_repository::claim_scan
     */
    public function test_only_one_worker_claims_a_queued_scan(): void {
        $this->resetAfterTest();
        global $USER;

        $target = new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified');
        $repository = new scan_repository();
        $scanid = $repository->create_queued_scan($target, 'Moodle 5.2', 502, 2, (int) $USER->id);

        $this->assertTrue($repository->claim_scan($scanid));
        $this->assertFalse($repository->claim_scan($scanid));
        $this->assertSame(scan_repository::STATUS_RUNNING, $repository->get_scan($scanid)->status);
    }
}
