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
 * Tests for the scan-deleted audit event.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\event;

use advanced_testcase;
use context_system;

/**
 * Tests audit metadata carried by the scan deletion event.
 *
 * @package    tool_upgradeguard
 * @covers     \tool_upgradeguard\event\scan_deleted
 */
final class scan_deleted_test extends advanced_testcase {
    /**
     * The event records the actor and the scan owner.
     *
     * @covers \tool_upgradeguard\event\scan_deleted::get_description
     */
    public function test_event_contains_scan_owner(): void {
        $this->resetAfterTest();
        $actor = self::getDataGenerator()->create_user();
        $owner = self::getDataGenerator()->create_user();

        $event = scan_deleted::create([
            'context' => context_system::instance(),
            'userid' => $actor->id,
            'objectid' => 42,
            'other' => ['scanownerid' => $owner->id],
        ]);

        $data = $event->get_data();
        $this->assertSame('d', $data['crud']);
        $this->assertSame(42, $event->objectid);
        $this->assertSame($owner->id, $event->other['scanownerid']);
    }
}
