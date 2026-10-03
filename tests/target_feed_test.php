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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See
// the GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Tests for validating and applying target rules feeds.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use advanced_testcase;
use tool_upgradeguard\local\target_feed;
use tool_upgradeguard\local\target_repository;

/**
 * Tests target feed validation and last-known-good rule selection.
 *
 * @package    tool_upgradeguard
 * @covers     \tool_upgradeguard\local\target_feed
 * @covers     \tool_upgradeguard\local\target_repository
 */
final class target_feed_test extends advanced_testcase {
    /**
     * A published dataset is accepted.
     *
     * @return void
     */
    public function test_shipped_dataset_is_valid_as_a_feed(): void {
        global $CFG;

        $raw = file_get_contents($CFG->dirroot . '/admin/tool/upgradeguard/data/targets.json');
        $dataset = json_decode($raw, true);

        $this->assertIsArray($dataset);
        $this->assertSame($dataset, target_feed::validate_dataset($dataset));
    }

    /**
     * The default feed is active when unset, but an explicit blank disables it.
     *
     * @return void
     */
    public function test_default_feed_configuration_can_be_disabled(): void {
        $this->resetAfterTest();
        unset_config('rulesfeedurl', 'tool_upgradeguard');

        $this->assertTrue(target_feed::is_configured());
        $this->assertSame(
            'https://raw.githubusercontent.com/waqashabib554-cyber/moodle-tool_upgradeguard/main/data/targets.json',
            target_feed::DEFAULT_FEED_URL
        );

        set_config('rulesfeedurl', '', 'tool_upgradeguard');
        $this->assertFalse(target_feed::is_configured());
    }

    /**
     * Missing, malformed, duplicated, and unsupported feed records are rejected.
     *
     * @return void
     */
    public function test_invalid_datasets_are_rejected(): void {
        global $CFG;

        $raw = file_get_contents($CFG->dirroot . '/admin/tool/upgradeguard/data/targets.json');
        $dataset = json_decode($raw, true);

        $missingtargets = $dataset;
        unset($missingtargets['targets']);
        $this->assertNull(target_feed::validate_dataset($missingtargets));

        $duplicate = $dataset;
        $duplicate['targets'][] = $duplicate['targets'][0];
        $this->assertNull(target_feed::validate_dataset($duplicate));

        $invalidstatus = $dataset;
        $invalidstatus['targets'][0]['status'] = 'released-ish';
        $this->assertNull(target_feed::validate_dataset($invalidstatus));

        $invalidbranch = $dataset;
        $invalidbranch['targets'][0]['branch'] = 999;
        $this->assertNull(target_feed::validate_dataset($invalidbranch));
    }

    /**
     * The remote dataset replaces matching bundled targets without losing fallback entries.
     *
     * @return void
     */
    public function test_remote_dataset_overrides_matching_target_and_keeps_fallbacks(): void {
        global $CFG;
        $this->resetAfterTest();

        $path = sys_get_temp_dir() . '/tool_upgradeguard_' . uniqid('', true) . '.json';
        $raw = file_get_contents($CFG->dirroot . '/admin/tool/upgradeguard/data/targets.json');
        file_put_contents($path, $raw);
        $remote = json_decode($raw, true);
        $remote['datasetversion']++;
        $remote['updated'] = date('Y-m-d');
        foreach ($remote['targets'] as &$entry) {
            if ($entry['version'] === '5.3') {
                $entry['status'] = 'stable';
                $entry['statussource'] = 'Verified official Moodle release page.';
            }
        }
        unset($entry);
        set_config('rulesdataset', json_encode($remote), 'tool_upgradeguard');

        $repository = new target_repository($path);
        $this->assertSame($remote['datasetversion'], $repository->get_dataset_version());
        $this->assertSame('stable', $repository->get_target('5.3')->status);
        $this->assertNotNull($repository->get_target('5.2'));
        $this->assertSame(['5.3'], array_keys($repository->get_upgrade_targets(502)));

        unlink($path);
    }

    /**
     * A malformed cached feed is ignored, leaving shipped rules usable.
     *
     * @return void
     */
    public function test_malformed_cached_feed_keeps_bundled_dataset(): void {
        $this->resetAfterTest();
        set_config('rulesdataset', '{"datasetversion":999,"targets":[{"version":"9.9"}]}', 'tool_upgradeguard');

        $repository = new target_repository();
        $this->assertSame(5, $repository->get_dataset_version());
        $this->assertSame('future', $repository->get_target('5.3')->status);
    }
}
