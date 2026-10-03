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
 * Tests the Upgrade Guard events.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use advanced_testcase;
use context_system;
use tool_upgradeguard\event\report_exported;
use tool_upgradeguard\event\scan_completed;
use tool_upgradeguard\event\scan_deleted;
use tool_upgradeguard\event\scan_started;
use tool_upgradeguard\local\scanner;
use tool_upgradeguard\local\target;

/**
 * The events carry who did what, when and with which scan.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class events_test extends advanced_testcase {
    /**
     * A target the scan was run against.
     *
     * @return target
     */
    private function target(): target {
        return new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified');
    }

    /**
     * Queueing a scan fires scan_started with the actor and the target.
     */
    public function test_scan_started_fires_when_a_scan_is_queued(): void {
        global $USER;

        $this->resetAfterTest();
        $sink = $this->redirectEvents();

        $scanid = (new scanner())->queue_scan($this->target(), (int) $USER->id);
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(scan_started::class, $event);
        $this->assertSame($scanid, $event->objectid);
        $this->assertSame((int) $USER->id, $event->userid);
        $this->assertSame('5.2', $event->other['targetversion']);
        $this->assertSame('c', $event->crud);
        $this->assertStringContainsString('index.php', $event->get_url()->out());
    }

    /**
     * The completed event carries the outcome of the scan.
     *
     * The firing itself lives in the scanner, which runs in cron; the event
     * contract is what every log consumer depends on.
     */
    public function test_scan_completed_carries_the_outcome(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();

        $event = scan_completed::create([
            'context' => context_system::instance(),
            'objectid' => 7,
            'userid' => 2,
            'other' => ['score' => 63, 'verdict' => 'careful', 'targetversion' => '5.2'],
        ]);
        $event->trigger();

        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(scan_completed::class, $events[0]);
        $this->assertSame(7, $events[0]->objectid);
        $this->assertSame(63, $events[0]->other['score']);
        $this->assertSame('careful', $events[0]->other['verdict']);
        $this->assertSame('5.2', $events[0]->other['targetversion']);
        $this->assertSame('u', $events[0]->crud);
    }

    /**
     * A plugin inventory can trigger a scan's standard log path, so the stored
     * event is verified directly rather than only through event redirection.
     */
    public function test_scan_completed_event_reaches_the_standard_log_store(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 1, 'logstore_standard');

        $event = scan_completed::create([
            'context' => context_system::instance(),
            'objectid' => 7,
            'userid' => (int) $USER->id,
            'other' => ['score' => 63, 'verdict' => 'careful', 'targetversion' => '5.2'],
        ]);
        $event->trigger();
        get_log_manager()->dispose();

        $records = $DB->get_records('logstore_standard_log', [
            'component' => 'tool_upgradeguard',
            'objectid' => 7,
        ]);

        $this->assertCount(1, $records);
        $this->assertSame('\\tool_upgradeguard\\event\\scan_completed', reset($records)->eventname);
    }

    /**
     * The exported event carries the format of the export.
     */
    public function test_report_exported_carries_the_format(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $sink = $this->redirectEvents();

        $event = report_exported::create([
            'context' => context_system::instance(),
            'objectid' => 7,
            'other' => ['format' => 'csv', 'targetversion' => '5.2'],
        ]);
        $event->trigger();

        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(report_exported::class, $events[0]);
        $this->assertSame(7, $events[0]->objectid);
        $this->assertSame('csv', $events[0]->other['format']);
        $this->assertSame('r', $events[0]->crud);
        $this->assertGreaterThan(0, $events[0]->userid);
    }

    /**
     * Every event name resolves to a real language string.
     */
    public function test_event_names_resolve(): void {
        foreach ([scan_started::class, scan_completed::class, report_exported::class, scan_deleted::class] as $class) {
            $this->assertNotSame('', $class::get_name()->out());
        }
    }

    /**
     * Every page of the plugin enforces the view capability.
     *
     * A missing capability check is invisible in unit tests and only shows up
     * when a random authenticated user opens the page, so the guard is checked
     * the way a reviewer would: by reading the pages.
     */
    public function test_pages_enforce_the_view_capability(): void {
        $root = __DIR__ . '/..';

        foreach (['index.php', 'results.php', 'report.php', 'history.php'] as $file) {
            $content = file_get_contents($root . '/' . $file);
            $this->assertStringContainsString(
                "require_capability('tool/upgradeguard:view'",
                $content,
                $file . ' does not enforce the view capability'
            );
        }

        $export = file_get_contents($root . '/export.php');
        $this->assertStringContainsString("require_capability('tool/upgradeguard:export'", $export);
    }
}
