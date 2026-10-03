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
 * Tests the shared status palette.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use basic_testcase;
use tool_upgradeguard\local\status;

/**
 * Every status has exactly one colour, the same one on every page.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class status_test extends basic_testcase {
    /**
     * Each status maps to its documented Bootstrap colour.
     */
    public function test_every_status_has_its_documented_colour(): void {
        $expected = [
            'blocker' => 'danger',
            'caution' => 'warning',
            'update' => 'info',
            'ready' => 'success',
            'unknown' => 'secondary',
        ];

        foreach (status::cases() as $status) {
            $this->assertSame($expected[$status->value], $status->get_css_class(), $status->value);
        }
    }

    /**
     * Every status has a label and a colour that the report stylesheet knows.
     */
    public function test_status_labels_and_classes_are_usable(): void {
        $knownclasses = ['danger', 'warning', 'info', 'success', 'secondary'];

        foreach (status::cases() as $status) {
            $this->assertNotSame('', $status->get_string_key());
            $this->assertContains($status->get_css_class(), $knownclasses);
        }
    }

    /**
     * The report prints the status palette.
     *
     * Every row and every group is marked with the Bootstrap background class
     * of its status, so the report inherits the palette from the theme instead
     * of restating it. Paper has no background by default, so the shared
     * stylesheet has to ask the browser to print the colours anyway.
     */
    public function test_report_stylesheet_covers_the_palette_and_prints_it(): void {
        $template = (string) file_get_contents(__DIR__ . '/../templates/report.mustache');
        $styles = (string) file_get_contents(__DIR__ . '/../styles.css');

        $this->assertStringContainsString('<span class="badge bg-{{statusclass}}">', $template);
        $this->assertStringContainsString('<h3 class="h5 mt-3"><span class="badge bg-{{statusclass}}">', $template);
        $this->assertStringNotContainsString('.bg-danger', $template, 'The report must not restate the palette.');

        $this->assertStringContainsString('print-color-adjust: exact', $styles);
        $this->assertStringContainsString('.tool-upgradeguard .badge', $styles);
    }
}
