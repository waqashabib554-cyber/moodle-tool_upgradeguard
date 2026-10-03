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
 * Tests the form that starts an Upgrade Guard scan.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use advanced_testcase;
use moodle_url;
use tool_upgradeguard\form\scan_form;

/**
 * The scan form must keep the primary action visually dominant.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_upgradeguard\form\scan_form
 */
final class scan_form_test extends advanced_testcase {
    /**
     * On the current release there is no newer released target yet, so the form
     * must explain that instead of rendering a dead selector or a Start scan
     * button that could never work.
     */
    public function test_no_newer_released_target_explains_the_empty_selector(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        $originalbranch = (int) $CFG->branch;
        $CFG->branch = 502;

        try {
            $form = new scan_form(new moodle_url('/admin/tool/upgradeguard/index.php', ['action' => 'startscan']));
            $html = preg_replace('/\s+/', ' ', $form->render());

            $this->assertStringContainsString('the rule dataset has no released branch newer than that yet', $html);
            // The branch of the running site is named as "5.2", not as 502.
            $this->assertStringContainsString('This site runs Moodle 5.2', $html);
            $this->assertStringNotContainsString('name="submitbutton"', $html);
            $this->assertStringNotContainsString(
                '<select',
                $html,
                'An empty <select> is a dead control and must not be rendered.'
            );
            $this->assertStringNotContainsString('<option', $html);
        } finally {
            $CFG->branch = $originalbranch;
        }
    }

    /**
     * The branch of the running site must actually be read.
     *
     * `definition()` runs in a method scope, so `$CFG` is invisible there unless
     * the method imports it. Without the import the branch silently reads as 0
     * and the selector offers the site's own branch and every older one, which
     * is exactly what the tool must never propose.
     *
     * @covers \tool_upgradeguard\form\scan_form
     */
    public function test_older_branches_are_not_offered_on_a_newer_site(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        $originalbranch = (int) $CFG->branch;
        $CFG->branch = 501;

        try {
            $form = new scan_form(new moodle_url('/admin/tool/upgradeguard/index.php', ['action' => 'startscan']));
            $html = preg_replace('/\s+/', ' ', $form->render());

            $this->assertStringContainsString('name="submitbutton"', $html);
            $this->assertStringContainsString('value="5.2"', $html, '5.2 is newer than 5.1 and offered.');
            $this->assertStringNotContainsString('value="5.1"', $html, 'The running branch is not offered.');
            $this->assertStringNotContainsString('value="4.5"', $html, 'An older branch is not offered.');
            $this->assertSame(
                1,
                substr_count($html, '<option'),
                'Exactly one target is offered to a 5.1 site.'
            );
        } finally {
            $CFG->branch = $originalbranch;
        }
    }

    /**
     * A branch that only receives security fixes says so in the selector.
     *
     * @covers \tool_upgradeguard\form\scan_form
     */
    public function test_security_only_targets_are_labelled(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        $originalbranch = (int) $CFG->branch;
        $CFG->branch = 404;

        try {
            $form = new scan_form(new moodle_url('/admin/tool/upgradeguard/index.php', ['action' => 'startscan']));
            $html = preg_replace('/\s+/', ' ', $form->render());

            $label = get_string('targetoption_securityonly', 'tool_upgradeguard');
            $this->assertStringContainsString('Moodle 5.0 (needs PHP 8.2.0) — ' . $label, $html);
            $this->assertStringContainsString('Moodle 5.2 (needs PHP 8.3.0)</option>', $html);
            // Only released branches newer than 4.4, and never 4.4 itself.
            $this->assertSame(4, substr_count($html, '<option'));
            $this->assertStringNotContainsString('value="4.4"', $html);
        } finally {
            $CFG->branch = $originalbranch;
        }
    }
}
