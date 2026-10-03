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
 * Tests for CSV value safety.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use basic_testcase;
use tool_upgradeguard\local\csv_value;

/**
 * Tests CSV formula neutralisation.
 *
 * @package    tool_upgradeguard
 * @covers     \tool_upgradeguard\local\csv_value
 */
final class csv_value_test extends basic_testcase {
    /**
     * Formula-leading values are escaped and normal text is unchanged.
     *
     * @dataProvider neutralise_provider
     * @covers \tool_upgradeguard\local\csv_value::neutralise
     * @param string $input Input cell.
     * @param string $expected Safe cell.
     * @return void
     */
    public function test_neutralise(string $input, string $expected): void {
        $this->assertSame($expected, csv_value::neutralise($input));
    }

    /**
     * Values for CSV formula neutralisation coverage.
     *
     * @return array
     */
    public static function neutralise_provider(): array {
        return [
            'equals' => ['=1+1', "'=1+1"],
            'plus' => ['+1', "'+1"],
            'minus' => ['-1', "'-1"],
            'at' => ['@SUM(A1)', "'@SUM(A1)"],
            'ordinary text' => ['mod_example', 'mod_example'],
            'empty text' => ['', ''],
        ];
    }
}
