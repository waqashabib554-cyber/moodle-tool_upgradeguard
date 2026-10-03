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
 * Tests that the environment of this server is read and stored correctly.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use advanced_testcase;
use tool_upgradeguard\local\collector\environment_inspector;
use tool_upgradeguard\local\repository\scan_repository;
use tool_upgradeguard\local\target;

/**
 * Tests the environment inspector and the stored environment snapshot.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_upgradeguard\local\collector\environment_inspector
 */
final class environment_inspector_test extends advanced_testcase {
    /**
     * The dataset vendors the inspector can report.
     *
     * @var string[]
     */
    private const VENDORS = ['mysql', 'mariadb', 'auroramysql', 'postgres', 'mssql', 'oracle'];

    /**
     * The inspector reports this server, with a usable version number.
     *
     * @covers \tool_upgradeguard\local\collector\environment_inspector::inspect
     */
    public function test_inspect_reports_this_server(): void {
        $environment = (new environment_inspector())->inspect();

        $this->assertSame(PHP_VERSION, $environment['php']);
        $this->assertContains($environment['dbvendor'], self::VENDORS);

        $this->assertMatchesRegularExpression('/^[0-9]+(\.[0-9]+)*$/', $environment['dbversion']);
        $this->assertNotEmpty($environment['extensions']);
        $this->assertContains('core', array_map('strtolower', $environment['extensions']));
    }

    /**
     * A queued scan keeps the environment it was queued with.
     *
     * This is what makes the report reproducible: the values are stored with the
     * scan, not read again when the report is displayed.
     *
     * @covers \tool_upgradeguard\local\repository\scan_repository::create_queued_scan
     * @covers \tool_upgradeguard\local\repository\scan_repository::get_environment
     */
    public function test_queued_scan_stores_the_environment_snapshot(): void {
        $this->resetAfterTest();

        $target = new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified');
        $repository = new scan_repository();

        $scanid = $repository->create_queued_scan($target, '5.2', 502, 3, (int) get_admin()->id, [
            'php' => '8.3.30',
            'dbvendor' => 'mysql',
            'dbversion' => '8.4.3',
            'extensions' => ['iconv', 'mbstring', 'sodium'],
        ]);

        $environment = scan_repository::get_environment($repository->get_scan($scanid));

        $this->assertSame('8.3.30', $environment['php']);
        $this->assertSame('mysql', $environment['dbvendor']);
        $this->assertSame('8.4.3', $environment['dbversion']);
        $this->assertSame(['iconv', 'mbstring', 'sodium'], $environment['extensions']);
    }
}
