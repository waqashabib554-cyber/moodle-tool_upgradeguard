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
 * Tests the environment checks: PHP, database server and PHP extensions.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use basic_testcase;
use tool_upgradeguard\local\check\environment_database_check;
use tool_upgradeguard\local\check\environment_extensions_check;
use tool_upgradeguard\local\check\registry;
use tool_upgradeguard\local\check\site_php_check;
use tool_upgradeguard\local\confidence;
use tool_upgradeguard\local\scan_context;
use tool_upgradeguard\local\severity;
use tool_upgradeguard\local\target;
use tool_upgradeguard\local\target_repository;

/**
 * Tests the environment scenarios against a hand built target and context.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_upgradeguard\local\check\site_php_check
 * @covers     \tool_upgradeguard\local\check\environment_database_check
 * @covers     \tool_upgradeguard\local\check\environment_extensions_check
 */
final class environment_check_test extends basic_testcase {
    /**
     * Build a target with the given environment data.
     *
     * @param array $overrides Keys: phpmin, verified, exercised, mysqlmin, postgresmin.
     * @return target
     */
    private function make_target(array $overrides = []): target {
        $verified = $overrides['verified'] ?? true;
        $environment = [
            'php' => ['source' => 'https://moodledev.io/general/releases/5.2', 'verified' => $verified],
            'database' => [
                'source' => 'https://moodledev.io/general/releases/5.2',
                'verified' => $verified,
                'exercised' => $overrides['exercised'] ?? ['mysql'],
                'minimums' => [
                    'mysql' => $overrides['mysqlmin'] ?? '8.4',
                    'postgres' => $overrides['postgresmin'] ?? '16',
                ],
            ],
            'extensions' => [
                'source' => 'admin/environment.xml',
                'verified' => $verified,
                'required' => ['iconv', 'mbstring', 'sodium'],
                'optional' => [],
            ],
        ];

        return new target(
            '5.2',
            502,
            2026042000,
            $overrides['phpmin'] ?? '8.3.0',
            '4.4',
            true,
            'stable',
            'verified',
            $environment
        );
    }

    /**
     * Build a context for the given server environment.
     *
     * @param array $server Keys: php, dbvendor, dbversion, extensions, targetoverrides.
     * @return scan_context
     */
    private function make_context(array $server): scan_context {
        return new scan_context(
            $this->make_target($server['targetoverrides'] ?? []),
            [],
            [],
            [],
            $server['php'],
            null,
            $server['dbvendor'],
            $server['dbversion'],
            $server['extensions']
        );
    }

    /**
     * A healthy server against this target produces no environment findings.
     *
     * Only the three environment checks run here. The other registry checks
     * look at data this hand built context does not carry; update_information
     * in particular reports a missing update check on purpose, and that
     * behaviour has its own tests in update_information_check_test.
     *
     * @covers \tool_upgradeguard\local\check\registry
     */
    public function test_everything_fine_produces_no_findings(): void {
        $context = $this->make_context([
            'php' => '8.3.30',
            'dbvendor' => 'mysql',
            'dbversion' => '8.4.3',
            'extensions' => ['iconv', 'mbstring', 'sodium'],
        ]);

        $environmentkeys = ['site_php', 'environment_database', 'environment_extensions'];

        foreach ((new registry())->get_checks() as $check) {
            if (!in_array($check->get_key(), $environmentkeys, true)) {
                continue;
            }
            $this->assertSame([], $check->run(null, $context), $check->get_key() . ' reported something');
        }
    }

    /**
     * A PHP version below the target minimum is a verified blocker.
     *
     * @covers \tool_upgradeguard\local\check\site_php_check::run
     */
    public function test_php_too_old_is_a_blocker(): void {
        $context = $this->make_context([
            'php' => '8.2.5',
            'dbvendor' => 'mysql',
            'dbversion' => '8.4.3',
            'extensions' => ['iconv', 'mbstring', 'sodium'],
        ]);

        $findings = (new site_php_check())->run(null, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(severity::blocker, $findings[0]->severity);
        $this->assertSame('finding_site_php_too_old', $findings[0]->messagekey);
        $this->assertSame(confidence::high, $findings[0]->confidence);
        $this->assertSame('8.2.5', $findings[0]->params['php']);
        $this->assertSame('8.3.0', $findings[0]->params['phpmin']);
    }

    /**
     * Without a verified PHP minimum the same situation says so.
     *
     * @covers \tool_upgradeguard\local\check\site_php_check::run
     */
    public function test_php_too_old_without_a_verified_source_says_so(): void {
        $context = $this->make_context([
            'php' => '8.2.5',
            'dbvendor' => 'mysql',
            'dbversion' => '8.4.3',
            'extensions' => ['iconv', 'mbstring', 'sodium'],
            'targetoverrides' => ['verified' => false],
        ]);

        $findings = (new site_php_check())->run(null, $context);

        $this->assertCount(1, $findings);
        $this->assertSame('finding_site_php_too_old_unverified', $findings[0]->messagekey);
        $this->assertSame(confidence::low, $findings[0]->confidence);
    }

    /**
     * A database server below the target minimum is a blocker.
     *
     * @covers \tool_upgradeguard\local\check\environment_database_check::run
     */
    public function test_database_too_old_is_a_blocker(): void {
        $context = $this->make_context([
            'php' => '8.3.30',
            'dbvendor' => 'mysql',
            'dbversion' => '8.0.36',
            'extensions' => ['iconv', 'mbstring', 'sodium'],
        ]);

        $findings = (new environment_database_check())->run(null, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(severity::blocker, $findings[0]->severity);
        $this->assertSame('finding_environment_database_too_old', $findings[0]->messagekey);
        $this->assertSame(confidence::high, $findings[0]->confidence);
        $this->assertSame('8.0.36', $findings[0]->params['current']);
        $this->assertSame('8.4', $findings[0]->params['minimum']);
        $this->assertSame('action_upgrade_database', $findings[0]->actionkey);
    }

    /**
     * A vendor this plugin was never exercised on is reported with less certainty.
     *
     * The PostgreSQL number itself is official; only the comparison has not been
     * run against a real PostgreSQL server by this plugin.
     *
     * @covers \tool_upgradeguard\local\check\environment_database_check::run
     */
    public function test_database_too_old_on_an_unexercised_vendor_is_medium_confidence(): void {
        $context = $this->make_context([
            'php' => '8.3.30',
            'dbvendor' => 'postgres',
            'dbversion' => '14.5',
            'extensions' => ['iconv', 'mbstring', 'sodium'],
        ]);

        $findings = (new environment_database_check())->run(null, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(severity::blocker, $findings[0]->severity);
        $this->assertSame(confidence::medium, $findings[0]->confidence);
        $this->assertSame('postgres', $findings[0]->params['vendor']);
        $this->assertSame('16', $findings[0]->params['minimum']);
    }

    /**
     * A vendor without a requirement in the dataset is unknown, not guessed.
     *
     * Oracle has a minimum for 4.4 and 4.5 but none from 5.0 on.
     *
     * @covers \tool_upgradeguard\local\check\environment_database_check::run
     */
    public function test_unknown_vendor_is_reported_as_unknown(): void {
        $context = $this->make_context([
            'php' => '8.3.30',
            'dbvendor' => 'oracle',
            'dbversion' => '19.10',
            'extensions' => ['iconv', 'mbstring', 'sodium'],
        ]);

        $findings = (new environment_database_check())->run(null, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(severity::info, $findings[0]->severity);
        $this->assertSame(confidence::low, $findings[0]->confidence);
        $this->assertSame('finding_environment_database_unknown', $findings[0]->messagekey);
    }

    /**
     * Missing database details are reported rather than silently skipped.
     *
     * @covers \tool_upgradeguard\local\check\environment_database_check::is_applicable
     * @covers \tool_upgradeguard\local\check\environment_database_check::run
     */
    public function test_missing_database_information_is_a_caution(): void {
        $context = $this->make_context([
            'php' => '8.3.30',
            'dbvendor' => '',
            'dbversion' => '',
            'extensions' => ['iconv', 'mbstring', 'sodium'],
        ]);
        $check = new environment_database_check();

        $this->assertTrue($check->is_applicable(null, $context));
        $findings = $check->run(null, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(severity::caution, $findings[0]->severity);
        $this->assertSame(confidence::low, $findings[0]->confidence);
        $this->assertSame('finding_environment_database_unavailable', $findings[0]->messagekey);
    }

    /**
     * A missing required extension is a blocker with the install action.
     *
     * @covers \tool_upgradeguard\local\check\environment_extensions_check::run
     */
    public function test_missing_extension_is_a_blocker(): void {
        $context = $this->make_context([
            'php' => '8.3.30',
            'dbvendor' => 'mysql',
            'dbversion' => '8.4.3',
            'extensions' => ['iconv', 'mbstring'],
        ]);

        $findings = (new environment_extensions_check())->run(null, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(severity::blocker, $findings[0]->severity);
        $this->assertSame('finding_environment_extension_missing', $findings[0]->messagekey);
        $this->assertSame(confidence::high, $findings[0]->confidence);
        $this->assertSame('sodium', $findings[0]->params['extension']);
        $this->assertSame('action_install_php_extension', $findings[0]->actionkey);
    }

    /**
     * Without a verified extension list the same situation says so.
     *
     * @covers \tool_upgradeguard\local\check\environment_extensions_check::run
     */
    public function test_missing_extension_without_a_verified_list_says_so(): void {
        $context = $this->make_context([
            'php' => '8.3.30',
            'dbvendor' => 'mysql',
            'dbversion' => '8.4.3',
            'extensions' => ['iconv', 'mbstring'],
            'targetoverrides' => ['verified' => false],
        ]);

        $findings = (new environment_extensions_check())->run(null, $context);

        $this->assertCount(1, $findings);
        $this->assertSame('finding_environment_extension_missing_unverified', $findings[0]->messagekey);
        $this->assertSame(confidence::low, $findings[0]->confidence);
    }

    /**
     * Missing runtime extension information is reported rather than skipped.
     *
     * @covers \tool_upgradeguard\local\check\environment_extensions_check::is_applicable
     * @covers \tool_upgradeguard\local\check\environment_extensions_check::run
     */
    public function test_missing_extension_information_is_a_caution(): void {
        $context = $this->make_context([
            'php' => '8.3.30',
            'dbvendor' => 'mysql',
            'dbversion' => '8.4.3',
            'extensions' => [],
        ]);
        $check = new environment_extensions_check();

        $this->assertTrue($check->is_applicable(null, $context));
        $findings = $check->run(null, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(severity::caution, $findings[0]->severity);
        $this->assertSame(confidence::low, $findings[0]->confidence);
        $this->assertSame('finding_environment_extensions_unavailable', $findings[0]->messagekey);
    }

    /**
     * A target without environment data is checked honestly: unknown, not guessed.
     *
     * @covers \tool_upgradeguard\local\check\environment_database_check::run
     * @covers \tool_upgradeguard\local\check\environment_extensions_check::is_applicable
     */
    public function test_target_without_environment_data_stays_honest(): void {
        $context = new scan_context(
            new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified'),
            [],
            [],
            [],
            '8.3.30',
            null,
            'mysql',
            '8.4.3',
            ['iconv', 'mbstring', 'sodium']
        );

        $findings = (new environment_database_check())->run(null, $context);
        $this->assertCount(1, $findings);
        $this->assertSame('finding_environment_database_unknown', $findings[0]->messagekey);

        // No requirement list at all: nothing to compare, so nothing is reported.
        $this->assertFalse((new environment_extensions_check())->is_applicable(null, $context));
        $this->assertSame([], (new environment_extensions_check())->run(null, $context));
    }

    /**
     * The shipped dataset carries verified environment numbers with sources.
     *
     * A released branch must have all three environment blocks verified. A
     * branch that Moodle has not released yet cannot: it has no
     * `admin/environment.xml` to read, so only the requirements the published
     * release notes state are verified and the rest carries an explanation
     * instead of a promise.
     *
     * @covers \tool_upgradeguard\local\target_repository::get_targets
     * @covers \tool_upgradeguard\local\target::is_environment_verified
     */
    public function test_shipped_dataset_has_verified_environment_data(): void {
        $repository = new target_repository();
        $unreleased = 0;

        foreach ($repository->get_targets() as $version => $target) {
            $this->assertTrue(
                $target->is_environment_verified('php'),
                $version . ': PHP minimum is not marked as verified'
            );
            $this->assertTrue(
                $target->is_environment_verified('database'),
                $version . ': database minimums are not marked as verified'
            );
            $this->assertNotSame('', $target->get_environment_source('php'), $version . ': no PHP source');
            $this->assertNotSame('', $target->get_environment_source('database'), $version . ': no database source');
            $this->assertNotEmpty($target->get_required_extensions(), $version . ': no required extensions');
            $this->assertNotSame(
                '',
                $target->get_environment_source('extensions'),
                $version . ': the extension source must explain itself, verified or not'
            );

            if ($target->status === 'future') {
                // Moodle has not shipped this branch, so there is no
                // environment.xml behind the list. Anything that was copied
                // from another branch has to say so instead of claiming to be
                // verified.
                $this->assertFalse(
                    $target->is_environment_verified('extensions'),
                    $version . ': an unreleased branch must not claim verified extensions'
                );
                $unreleased++;
                continue;
            }

            $this->assertTrue(
                $target->is_environment_verified('extensions'),
                $version . ': the extension list is not marked as verified'
            );
        }

        $this->assertGreaterThan(0, $unreleased, 'The dataset is expected to know at least one unreleased branch.');
    }
}
