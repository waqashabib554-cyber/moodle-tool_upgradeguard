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
 * Tests for the export download name.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use advanced_testcase;
use tool_upgradeguard\local\export_filename;

/**
 * Tests the file name an exported report is offered under.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_upgradeguard\local\export_filename
 */
final class export_filename_test extends advanced_testcase {
    /**
     * Pin the timezone every other test assumes.
     *
     * export_filename builds the name in the timezone of the user who asked for
     * the export, and the expectations below are all written in UTC. A site
     * whose server runs in, say, Australia/Sydney would otherwise fail every
     * one of them, so UTC is set before each test and resetAfterTest() puts the
     * original setting back for the tests that follow.
     */
    protected function setUp(): void {
        global $USER;

        parent::setUp();

        // The $USER object is rebuilt between tests, so a timezone set here cannot leak.
        $USER->timezone = 'UTC';
    }

    /**
     * Builds the name as a user in the given timezone would see it.
     *
     * @param string $timezone The timezone the administrator is in.
     * @param int $time The moment of the export, as a GMT timestamp.
     * @return string The name that administrator would be offered.
     */
    private function name_in_timezone(string $timezone, int $time): string {
        global $USER;

        $USER->timezone = $timezone;

        return export_filename::csv($time);
    }

    /**
     * The name is built with a fixed number of zero padded digits.
     *
     * The page used to build it with userdate(time(), '%Y%m%d-%H%M'), whose
     * $fixday argument defaults to true and drops the leading zero from %d. A
     * download made on the first day of a month was therefore offered as
     * "upgrade-guard-2026101-0111", seven digits where the date needs eight, so
     * the file sorted as 21 October beside 1 October and no two names were the
     * same length.
     *
     * @dataProvider padded_name_provider
     * @param int $time The moment of the export, as a GMT timestamp.
     * @param string $expected The name expected for that moment.
     */
    public function test_the_name_pads_every_field_to_a_fixed_width(int $time, string $expected): void {
        $this->assertSame($expected, export_filename::csv($time));
    }

    /**
     * Moments that have a single digit month, day, hour or minute.
     *
     * @return array[] The timestamp and the name expected for it.
     */
    public static function padded_name_provider(): array {
        return [
            'first of october, one minute past midnight' => [
                make_timestamp(2026, 10, 1, 0, 1, 0, 'UTC'),
                'upgrade-guard-20261001-0001',
            ],
            'first of january' => [
                make_timestamp(2026, 1, 1, 0, 0, 0, 'UTC'),
                'upgrade-guard-20260101-0000',
            ],
            'ninth of march at five past nine' => [
                make_timestamp(2026, 3, 9, 9, 5, 0, 'UTC'),
                'upgrade-guard-20260309-0905',
            ],
            'second of april at four past three' => [
                make_timestamp(2026, 4, 2, 3, 4, 0, 'UTC'),
                'upgrade-guard-20260402-0304',
            ],
            'a date that needs no padding at all' => [
                make_timestamp(2026, 12, 31, 23, 59, 0, 'UTC'),
                'upgrade-guard-20261231-2359',
            ],
        ];
    }

    /**
     * Whatever the moment, the name is always the same length.
     *
     * This is what actually sorts the downloads, so it is asserted across a
     * whole year rather than on a handful of examples.
     */
    public function test_the_name_is_always_the_same_length(): void {
        $length = null;
        $start = make_timestamp(2026, 1, 1, 0, 0, 0, 'UTC');
        $end = make_timestamp(2027, 1, 1, 0, 0, 0, 'UTC');

        for ($time = $start; $time < $end; $time += 3600) {
            $name = export_filename::csv($time);

            if ($length === null) {
                $length = strlen($name);
                continue;
            }

            $this->assertSame($length, strlen($name), "Unexpected length for the name {$name}.");
        }

        $this->assertNotNull($length, 'The loop produced no name at all.');
    }

    /**
     * The date field of the name is the date the export was made on.
     *
     * @dataProvider moment_provider
     * @param int $time The moment of the export, as a GMT timestamp.
     * @param string $expected The YYYYMMDD-HHMM field expected for that moment.
     */
    public function test_the_date_field_is_the_date_of_the_export(int $time, string $expected): void {
        $this->assertStringEndsWith($expected, export_filename::csv($time));
    }

    /**
     * Moments whose date field is written out longhand.
     *
     * @return array[] The timestamp and the field expected for it.
     */
    public static function moment_provider(): array {
        return [
            'a leap day' => [make_timestamp(2028, 2, 29, 12, 0, 0, 'UTC'), '20280229-1200'],
            'new year 2030' => [make_timestamp(2030, 1, 1, 0, 0, 0, 'UTC'), '20300101-0000'],
        ];
    }

    /**
     * The name is built in the timezone of the administrator who asked for it.
     *
     * The export is a report for a person, so the moment in its name is that
     * person's wall clock, not the server's. The two disagree often enough to
     * be worth pinning.
     */
    public function test_the_name_follows_the_users_timezone(): void {
        // 23:30 UTC on 1 October is already 2 October in Auckland, and still
        // 1 October in Los Angeles, so the two must produce different days.
        $time = make_timestamp(2026, 10, 1, 23, 30, 0, 'UTC');

        $auckland = $this->name_in_timezone('Pacific/Auckland', $time);
        $losangeles = $this->name_in_timezone('America/Los_Angeles', $time);

        $this->assertStringEndsWith('20261002-1230', $auckland);
        $this->assertStringEndsWith('20261001-1630', $losangeles);
    }

    /**
     * The name stays extensionless, so the extension is not doubled.
     *
     * dataformat::download_data() appends ".csv" itself, so a name that already
     * carried the extension was saved as "upgrade-guard-....csv.csv", which
     * every spreadsheet then refused to open.
     */
    public function test_the_name_is_extensionless_so_the_extension_is_not_doubled(): void {
        $name = export_filename::csv(make_timestamp(2026, 1, 1, 12, 0, 0, 'UTC'));

        $this->assertStringEndsNotWith('.csv', $name, 'dataformat adds the extension itself.');
        $this->assertSame('upgrade-guard-20260101-1200.csv', $name . '.csv');
        $this->assertStringNotContainsString('.csv.csv', $name);
    }
}
