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
 * Tests the structure of the settings page.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use basic_testcase;

/**
 * Reorganising the settings must not lose or duplicate any setting.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class settings_test extends basic_testcase {
    /**
     * The settings file as one string.
     *
     * @return string
     */
    private function settings(): string {
        return (string) file_get_contents(__DIR__ . '/../settings.php');
    }

    /**
     * The language file as one string.
     *
     * @return string
     */
    private function lang(): string {
        return (string) file_get_contents(__DIR__ . '/../lang/en/tool_upgradeguard.php');
    }

    /**
     * The five groups appear as headings, in the documented order.
     */
    public function test_settings_are_grouped_in_the_documented_order(): void {
        $settings = $this->settings();
        $position = 0;

        foreach (['headingscore', 'headingmultipliers', 'headingthresholds', 'headingupdates', 'headingdata'] as $heading) {
            $at = strpos($settings, 'tool_upgradeguard/' . $heading);
            $this->assertNotFalse($at, $heading . ' heading is missing');
            $this->assertGreaterThan($position, $at, $heading . ' heading is out of order');
            $position = $at;
        }
    }

    /**
     * Every configured setting is still registered exactly once.
     */
    public function test_every_setting_is_registered_once(): void {
        $settings = $this->settings();

        $keys = [
            'tool_upgradeguard/weightblocker',
            'tool_upgradeguard/weightcaution',
            'tool_upgradeguard/weightunknown',
            'tool_upgradeguard/weightupdate',
            "tool_upgradeguard/weight' . \$type",
            "tool_upgradeguard/usagemultiplier' . \$usage",
            'tool_upgradeguard/stopthreshold',
            'tool_upgradeguard/gothreshold',
            'tool_upgradeguard/checkremote',
            'tool_upgradeguard/remotecachettl',
            'tool_upgradeguard/rulesfeedurl',
            'tool_upgradeguard/retentiondays',
            'tool_upgradeguard/supportemail',
        ];
        foreach ($keys as $key) {
            $this->assertSame(1, substr_count($settings, $key), $key . ' is registered ' .
                substr_count($settings, $key) . ' times');
        }
    }

    /**
     * Every heading and its explanation is defined in the language file.
     */
    public function test_heading_strings_are_defined(): void {
        $lang = $this->lang();

        $headings = [
            'scoreheading', 'scoreheading_desc',
            'multipliersheading', 'multipliersheading_desc',
            'thresholdsheading', 'thresholdsheading_desc',
            'updatesheading', 'updatesheading_desc',
            'dataheading', 'dataheading_desc',
        ];
        foreach ($headings as $key) {
            $this->assertMatchesRegularExpression(
                "/^\\\$string\\['" . $key . "'\\]/m",
                $lang,
                $key . ' is not defined in the language file'
            );
        }
    }
}
