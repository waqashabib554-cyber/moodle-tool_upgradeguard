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
 * Tests for the rule dataset repository.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use advanced_testcase;
use tool_upgradeguard\local\target_repository;

/**
 * Tests reading and validating the shipped rule dataset.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_upgradeguard\local\target_repository
 * @covers     \tool_upgradeguard\local\target
 */
final class target_repository_test extends advanced_testcase {
    /**
     * A temporary directory to write a dataset file into.
     *
     * @return string
     */
    private function make_dataset_dir(): string {
        $dir = sys_get_temp_dir() . '/tool_upgradeguard_' . uniqid('', true);
        mkdir($dir, 0777, true);
        return $dir;
    }

    /**
     * The shipped dataset loads and is complete.
     *
     * @covers \tool_upgradeguard\local\target_repository::get_targets
     */
    public function test_shipped_dataset_loads(): void {
        $repository = new target_repository();
        $targets = $repository->get_targets();

        $this->assertNotEmpty($targets);
        $this->assertArrayHasKey('5.2', $targets);
        $this->assertGreaterThan(0, $repository->get_dataset_version());

        $target = $repository->get_target('5.2');
        $this->assertNotNull($target);
        $this->assertSame(502, $target->branch);
        $this->assertSame('8.3.0', $target->phpmin);
        $this->assertTrue($target->publiclayout);
    }

    /**
     * The newest branch is offered as the default target.
     *
     * @covers \tool_upgradeguard\local\target_repository::get_default_target
     */
    public function test_default_target_is_the_newest_branch(): void {
        $default = (new target_repository())->get_default_target();
        $this->assertNotNull($default);
        $this->assertSame(502, $default->branch);
    }

    /**
     * Targets can be looked up by branch as well as by version.
     *
     * @covers \tool_upgradeguard\local\target_repository::get_target_by_branch
     */
    public function test_get_target_by_branch(): void {
        $repository = new target_repository();

        $this->assertSame('5.1', $repository->get_target_by_branch(501)->version);
        $this->assertNull($repository->get_target_by_branch(123));
    }

    /**
     * The 5.3 dataset is future information, not a selectable target yet.
     *
     * @covers \tool_upgradeguard\local\target_repository::get_default_target
     * @covers \tool_upgradeguard\local\target_repository::get_upgrade_targets
     */
    public function test_5_3_is_known_but_not_a_selectable_target(): void {
        $repository = new target_repository();

        $this->assertNotNull($repository->get_target('5.3'));
        $this->assertSame('future', $repository->get_target('5.3')->status);
        $this->assertSame('5.2', $repository->get_default_target()->version);
        $this->assertSame([], $repository->get_upgrade_targets(502));
        $this->assertFalse($repository->is_upgrade_target('5.3', 502));
    }

    /**
     * The selector only offers released branches newer than the running site.
     *
     * @covers \tool_upgradeguard\local\target_repository::get_upgrade_targets
     * @covers \tool_upgradeguard\local\target_repository::is_upgrade_target
     */
    public function test_only_supported_newer_targets_are_offered(): void {
        $repository = new target_repository();

        $this->assertSame(['5.2'], array_keys($repository->get_upgrade_targets(501)));
        $this->assertSame([], $repository->get_upgrade_targets(502));
        $this->assertTrue($repository->is_upgrade_target('5.2', 501));
        $this->assertFalse($repository->is_upgrade_target('5.1', 502));
        $this->assertFalse($repository->is_upgrade_target('5.1', 502));
        $this->assertFalse($repository->is_upgrade_target('4.4', 405));
    }

    /**
     * Dotted Moodle version strings survive request cleaning and match the dataset.
     *
     * @covers \tool_upgradeguard\local\target_repository::get_target
     * @covers \tool_upgradeguard\local\target_repository::is_upgrade_target
     */
    public function test_dotted_version_survives_request_cleaning(): void {
        $version = clean_param('5.2', PARAM_RAW_TRIMMED);

        $this->assertSame('5.2', $version);
        $this->assertTrue((new target_repository())->is_upgrade_target($version, 501));
    }

    /**
     * The release status of every shipped target is the one moodledev reports.
     *
     * The dataset is a set of claims about other people's software, so the
     * claims are asserted rather than assumed. A branch that only receives
     * security fixes must be recorded as such, and no entry may claim to be
     * verified without saying where the verification came from.
     *
     * @covers \tool_upgradeguard\local\target_repository::get_targets
     * @dataProvider shipped_release_status_provider
     * @param string $version Target version.
     * @param string $status Expected release status.
     */
    public function test_shipped_release_status_matches_the_published_support_table(
        string $version,
        string $status
    ): void {
        $target = (new target_repository())->get_target($version);

        $this->assertNotNull($target, "The dataset is missing target {$version}.");
        $this->assertSame($status, $target->status, "Wrong release status for {$version}.");
        $this->assertNotSame('', $target->statussource, "The status of {$version} has no source.");
    }

    /**
     * The expected release status of every shipped target.
     *
     * @return array[]
     */
    public static function shipped_release_status_provider(): array {
        return [
            // The moodledev 4.4 page says the branch receives no security fixes.
            '4.4 is out of support entirely' => ['4.4', 'unsupported'],
            // The current LTS, but general support ended 6 October 2025.
            '4.5 is the LTS in security-only support' => ['4.5', 'security'],
            // The moodledev 5.0 page says it is past general bug fixes.
            '5.0 is in security-only support' => ['5.0', 'security'],
            '5.1 is a current stable' => ['5.1', 'stable'],
            '5.2 is a current stable' => ['5.2', 'stable'],
            // The moodledev 5.3 page says it has not been released yet.
            '5.3 has not been released' => ['5.3', 'future'],
        ];
    }

    /**
     * Only a branch with full support is presented as a first-class target.
     *
     * @covers \tool_upgradeguard\local\target::is_fully_supported
     * @covers \tool_upgradeguard\local\target::is_security_only
     */
    public function test_support_helpers_separate_full_from_security_only(): void {
        $repository = new target_repository();

        $this->assertTrue($repository->get_target('5.2')->is_fully_supported());
        $this->assertFalse($repository->get_target('5.2')->is_security_only());

        $this->assertFalse($repository->get_target('5.0')->is_fully_supported());
        $this->assertTrue($repository->get_target('5.0')->is_security_only());

        $this->assertFalse($repository->get_target('5.3')->is_fully_supported());
        $this->assertFalse($repository->get_target('5.3')->is_security_only());
    }

    /**
     * A branch that only receives security fixes is still a legal destination.
     *
     * Moodle keeps 4.5 and 5.0 in security support, so a site may legitimately
     * plan an upgrade to them. Excluding them would remove real advice; labelling
     * them as fully supported would be wrong. This test pins the middle ground.
     *
     * @covers \tool_upgradeguard\local\target_repository::get_upgrade_targets
     * @covers \tool_upgradeguard\local\target_repository::is_upgrade_target
     */
    public function test_security_only_branches_remain_selectable_but_unsupported_branches_do_not(): void {
        $repository = new target_repository();

        // From 4.4 the released, still-maintained branches are 4.5, 5.0, 5.1, 5.2.
        $this->assertSame(
            ['5.2', '5.1', '5.0', '4.5'],
            array_keys($repository->get_upgrade_targets(404))
        );
        $this->assertTrue($repository->is_upgrade_target('5.0', 405));
        $this->assertTrue($repository->is_upgrade_target('4.5', 404));

        // 4.4 receives no support at all, so it is never a destination.
        $this->assertFalse($repository->is_upgrade_target('4.4', 403));
    }

    /**
     * The unreleased 5.3 branch may not claim verified extension data.
     *
     * 5.3 has no admin/environment.xml until it ships, so the extension list is
     * a copy of 5.2's. Presenting that copy as verified would be a claim the
     * project cannot support.
     *
     * @covers \tool_upgradeguard\local\target::from_dataset_entry
     */
    public function test_unreleased_target_does_not_claim_verified_extensions(): void {
        $target = (new target_repository())->get_target('5.3');

        $this->assertNotNull($target);
        $this->assertFalse(
            $target->environment['extensions']['verified'],
            'Moodle 5.3 is unreleased, so its extension list must not be marked verified.'
        );
        $this->assertNotSame('', $target->environment['extensions']['source']);
        // The published PHP and database minimums of 5.3 are documented, so
        // those two blocks may still be marked verified.
        $this->assertTrue($target->environment['php']['verified']);
        $this->assertTrue($target->environment['database']['verified']);
    }

    /**
     * A broken dataset never breaks the site: it is treated as empty.
     *
     * @covers \tool_upgradeguard\local\target_repository::get_targets
     */
    public function test_malformed_dataset_is_ignored(): void {
        $path = $this->make_dataset_dir() . '/broken.json';
        file_put_contents($path, '{not json at all');

        $repository = new target_repository($path);

        $this->assertSame([], $repository->get_targets());
        $this->assertNull($repository->get_target('5.2'));
        $this->assertSame(0, $repository->get_dataset_version());
    }

    /**
     * Dataset entries without the required keys are skipped.
     *
     * @covers \tool_upgradeguard\local\target_repository::get_targets
     */
    public function test_incomplete_dataset_entries_are_skipped(): void {
        $path = $this->make_dataset_dir() . '/partial.json';
        file_put_contents($path, json_encode([
            'datasetversion' => 7,
            'targets' => [
                ['version' => '9.9', 'branch' => 999],
                [
                    'version' => '9.8',
                    'branch' => 998,
                    'requiresint' => 2098040100,
                    'phpmin' => '8.1.0',
                    'minsource' => '4.1.2',
                    'publiclayout' => true,
                    'status' => 'stable',
                ],
            ],
        ]));

        $repository = new target_repository($path);

        $this->assertSame(['9.8'], array_keys($repository->get_targets()));
        $this->assertSame(7, $repository->get_dataset_version());
        $this->assertSame('derived', $repository->get_target('9.8')->requiresintsource);
    }
}
