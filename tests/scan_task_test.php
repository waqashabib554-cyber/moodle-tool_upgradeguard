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

namespace tool_upgradeguard;

use tool_upgradeguard\local\confidence;
use tool_upgradeguard\local\finding;
use tool_upgradeguard\local\repository\scan_repository;
use tool_upgradeguard\local\scanner;
use tool_upgradeguard\local\severity;
use tool_upgradeguard\local\target;
use tool_upgradeguard\local\verdict;
use tool_upgradeguard\task\scan_task;

/**
 * Tests for the Upgrade Guard adhoc scan task.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_upgradeguard\task\scan_task
 */
final class scan_task_test extends \advanced_testcase {
    /**
     * A site-wide environment blocker forces a non-green result even when every
     * scanned plugin is ready.
     *
     * @covers \tool_upgradeguard\local\scanner::calculate_result
     */
    public function test_environment_blocker_forces_stop_in_the_scan_pipeline(): void {
        $plugin = (object) [
            'component' => 'mod_x',
            'plugintype' => 'mod',
            'status' => 'ready',
            'usagecount' => 0,
        ];
        $sitefinding = new finding(
            'site_php',
            severity::blocker,
            'finding_site_php_too_old',
            ['php' => '8.1.0', 'target' => '5.2', 'phpmin' => '8.3'],
            confidence::high,
            false,
            'action_upgrade_php'
        );

        $result = (new scanner())->calculate_result(
            [$plugin],
            [['component' => null, 'finding' => $sitefinding]]
        );

        $this->assertSame(75, $result['score']);
        $this->assertSame(verdict::stop, $result['verdict']);
        $this->assertSame(1, $result['counts']['blockers']);
        $this->assertSame(1, $result['counts']['environmentblockers']);
    }

    /**
     * A clean environment must not invent counts, penalties or a stop verdict.
     *
     * The counterpart of the test above. Without it, a scanner that always added
     * an environment penalty would pass the blocker test while quietly lowering
     * the score of every healthy site.
     *
     * @covers \tool_upgradeguard\local\scanner::calculate_result
     */
    public function test_a_clean_environment_scores_a_clean_site_as_ready(): void {
        $plugin = (object) [
            'component' => 'mod_x',
            'plugintype' => 'mod',
            'status' => 'ready',
            'usagecount' => 3,
        ];

        $result = (new scanner())->calculate_result([$plugin], []);

        $this->assertSame(100, $result['score']);
        $this->assertSame(verdict::go, $result['verdict']);
        $this->assertSame(0, $result['counts']['environmentblockers']);
        $this->assertSame(0, $result['counts']['environmentcautions']);
        $this->assertSame(0, $result['counts']['blockers']);
        $this->assertSame(0, $result['counts']['cautions']);
        $this->assertFalse($result['counts']['hasusedblocker']);
    }

    /**
     * An environment caution is a caution, not a blocker.
     *
     * @covers \tool_upgradeguard\local\scanner::calculate_result
     */
    public function test_environment_caution_is_counted_without_forcing_a_stop(): void {
        $plugin = (object) [
            'component' => 'mod_x',
            'plugintype' => 'mod',
            'status' => 'ready',
            'usagecount' => 3,
        ];
        $caution = new finding(
            'environment_database',
            severity::caution,
            'finding_environment_database_unknown',
            ['vendor' => 'mysql', 'current' => '8.4.3', 'target' => '5.2'],
            confidence::medium,
            false,
            'action_upgrade_database'
        );

        $result = (new scanner())->calculate_result(
            [$plugin],
            [['component' => null, 'finding' => $caution]]
        );

        $this->assertSame(95, $result['score']);
        $this->assertSame(verdict::careful, $result['verdict']);
        $this->assertSame(0, $result['counts']['environmentblockers']);
        $this->assertSame(1, $result['counts']['environmentcautions']);
        $this->assertSame(1, $result['counts']['cautions']);
    }

    /**
     * Missing environment measurements cannot yield a green verdict.
     *
     * @covers \tool_upgradeguard\local\scanner::calculate_result
     */
    public function test_missing_environment_measurements_require_careful_verdict(): void {
        $plugin = (object) [
            'component' => 'mod_x',
            'plugintype' => 'mod',
            'status' => 'ready',
            'usagecount' => 3,
        ];
        $database = new finding(
            'environment_database',
            severity::caution,
            'finding_environment_database_unavailable',
            ['target' => '5.2'],
            confidence::low
        );
        $extensions = new finding(
            'environment_extensions',
            severity::caution,
            'finding_environment_extensions_unavailable',
            ['target' => '5.2'],
            confidence::low
        );

        $result = (new scanner())->calculate_result(
            [$plugin],
            [
                ['component' => null, 'finding' => $database],
                ['component' => null, 'finding' => $extensions],
            ]
        );

        $this->assertSame(verdict::careful, $result['verdict']);
        $this->assertSame(2, $result['counts']['environmentcautions']);
        $this->assertSame(2, $result['counts']['cautions']);
    }

    /**
     * The environment counts have to survive all the way into the stored row.
     *
     * `calculate_result()` names its keys `environmentblockers` and
     * `environmentcautions`, while the database columns and the repository
     * parameter are `environmentblockercount` and `environmentcautioncount`.
     * That rename is the seam where a counted blocker could silently become a
     * stored zero, so the whole aggregation-to-persistence path is exercised
     * here against a real database rather than mocked.
     *
     * @covers \tool_upgradeguard\local\repository\scan_repository::finish_scan
     * @covers \tool_upgradeguard\local\repository\scan_repository::get_scan
     * @covers \tool_upgradeguard\local\repository\scan_repository::save_results
     */
    public function test_environment_counts_are_persisted_with_the_verdict(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $repository = new scan_repository();
        $scanner = new scanner($repository);

        $scanid = $scanner->queue_scan($this->target(), (int) $USER->id);
        $this->assertGreaterThan(0, $scanid);

        $plugin = (object) [
            'component' => 'mod_x',
            'name' => 'Example',
            'displayname' => 'Example',
            'plugintype' => 'mod',
            'status' => 'ready',
            'versiondisk' => '2026010100',
            'versiondb' => '2026010100',
            'isstandard' => 0,
            'installed' => 1,
            'usagecount' => 3,
            'updateavailable' => 0,
            'updateversion' => null,
        ];
        $blocker = new finding(
            'site_php',
            severity::blocker,
            'finding_site_php_too_old',
            ['php' => '8.1.0', 'target' => '5.2', 'phpmin' => '8.3'],
            confidence::high,
            false,
            'action_upgrade_php'
        );
        $caution = new finding(
            'environment_extensions',
            severity::caution,
            'finding_environment_extension_missing_unverified',
            ['extension' => 'exif', 'target' => '5.2'],
            confidence::medium,
            false,
            'action_install_php_extension'
        );

        $pluginrecords = [$plugin];
        $findings = [
            ['component' => null, 'finding' => $blocker],
            ['component' => null, 'finding' => $caution],
        ];

        $result = $scanner->calculate_result($pluginrecords, $findings);
        $repository->save_results($scanid, $pluginrecords, $findings);
        $repository->finish_scan($scanid, $result['score'], $result['verdict']->value, [
            'plugincount' => count($pluginrecords),
            'blockercount' => $result['counts']['blockers'],
            'cautioncount' => $result['counts']['cautions'],
            'unknowncount' => $result['counts']['unknowns'],
            'environmentblockercount' => $result['counts']['environmentblockers'],
            'environmentcautioncount' => $result['counts']['environmentcautions'],
        ]);

        $stored = $repository->get_scan($scanid);
        $this->assertNotNull($stored);

        $this->assertSame(verdict::stop->value, $stored->verdict, 'An environment blocker must not be stored as ready.');
        $this->assertSame(70, (int) $stored->score, 'Blocker 25 plus caution 5 are both persisted in the score.');
        $this->assertSame(1, (int) $stored->environmentblockercount);
        $this->assertSame(1, (int) $stored->environmentcautioncount);
        $this->assertSame(1, (int) $stored->blockercount, 'The environment blocker is in the plugin-facing total too.');
        $this->assertSame(1, (int) $stored->cautioncount);

        // The site findings are stored under the empty component and come back
        // grouped under the empty key, which is what the exports read.
        $grouped = $repository->get_findings_grouped($scanid);
        $this->assertArrayHasKey('', $grouped, 'Site wide findings are stored under the empty component.');
        $this->assertCount(2, $grouped['']);

        $keys = array_map(static fn(finding $f): string => $f->checkkey, $grouped['']);
        sort($keys);
        $this->assertSame(['environment_extensions', 'site_php'], $keys);

        $this->assertCount(1, $DB->get_records('tool_upgradeguard_plugin', ['scanid' => $scanid]));

        // Site-wide findings are stored with pluginid 0, the documented
        // "this finding belongs to no plugin" marker, which is what
        // get_findings_grouped() turns back into the empty component key.
        $this->assertCount(
            2,
            $DB->get_records('tool_upgradeguard_finding', ['scanid' => $scanid, 'pluginid' => 0])
        );
    }

    /**
     * A clean environment persists zeroes rather than nulls or stale numbers.
     *
     * @covers \tool_upgradeguard\local\repository\scan_repository::finish_scan
     */
    public function test_a_clean_scan_stores_zero_environment_counts(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $repository = new scan_repository();
        $scanner = new scanner($repository);
        $scanid = $scanner->queue_scan($this->target(), (int) $USER->id);

        $result = $scanner->calculate_result([], []);
        $repository->finish_scan($scanid, $result['score'], $result['verdict']->value, [
            'plugincount' => 0,
            'blockercount' => $result['counts']['blockers'],
            'cautioncount' => $result['counts']['cautions'],
            'unknowncount' => $result['counts']['unknowns'],
            'environmentblockercount' => $result['counts']['environmentblockers'],
            'environmentcautioncount' => $result['counts']['environmentcautions'],
        ]);

        $stored = $repository->get_scan($scanid);
        $this->assertNotNull($stored);
        $this->assertSame(verdict::go->value, $stored->verdict);
        $this->assertSame(100, (int) $stored->score);
        $this->assertSame(0, (int) $stored->environmentblockercount);
        $this->assertSame(0, (int) $stored->environmentcautioncount);

        // The empty component key is always present, so the pages and exports
        // can read it without guarding for a missing group. It is simply empty.
        $grouped = $repository->get_findings_grouped($scanid);
        $this->assertArrayHasKey('', $grouped);
        $this->assertSame([], $grouped['']);
    }

    /**
     * A target the scan can be queued against.
     *
     * @return target
     */
    private function target(): target {
        return new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified');
    }

    /**
     * The scan task must load cleanly and keep one-scan-at-a-time concurrency.
     */
    public function test_scan_task_uses_single_task_concurrency(): void {
        $task = new scan_task();

        $this->assertSame(1, $task->get_concurrency_limit());
    }
}
