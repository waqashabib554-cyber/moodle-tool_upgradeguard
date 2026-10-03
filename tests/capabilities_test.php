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
 * Tests for the capabilities of this tool.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use advanced_testcase;
use context_system;

/**
 * Tests that the tool stays admin only, and that granting one capability does not
 * silently grant the others.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class capabilities_test extends advanced_testcase {
    /** @var string[] The capability short names defined by this plugin. */
    private const CAPABILITIES = ['view', 'runscan', 'export', 'manage'];

    /**
     * Build a full capability name.
     *
     * @param string $shortname The short name, eg "export".
     * @return string
     */
    private function capability(string $shortname): string {
        return 'tool/upgradeguard:' . $shortname;
    }

    /**
     * Every capability is defined at system level with the expected risk flags.
     *
     * @coversNothing
     */
    public function test_capability_definitions(): void {
        global $DB;

        foreach (self::CAPABILITIES as $shortname) {
            $record = $DB->get_record('capabilities', ['name' => $this->capability($shortname)], '*', MUST_EXIST);
            $this->assertSame(CONTEXT_SYSTEM, (int) $record->contextlevel, $shortname . ' is not a system capability');
        }

        $manage = $DB->get_record('capabilities', ['name' => $this->capability('manage')], '*', MUST_EXIST);
        $this->assertSame(RISK_DATALOSS, (int) $manage->riskbitmask & RISK_DATALOSS);

        $view = $DB->get_record('capabilities', ['name' => $this->capability('view')], '*', MUST_EXIST);
        $this->assertSame(0, (int) $view->riskbitmask);
    }

    /**
     * A powerful non-admin role has none of the capabilities by default.
     *
     * @coversNothing
     */
    public function test_manager_is_denied_by_default(): void {
        global $DB;
        $this->resetAfterTest();

        $context = context_system::instance();
        $managerrole = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        $manager = $this->getDataGenerator()->create_user();
        role_assign($managerrole, $manager->id, $context->id);

        foreach (self::CAPABILITIES as $shortname) {
            $this->assertFalse(
                has_capability($this->capability($shortname), $context, $manager->id),
                'a manager must not have tool/upgradeguard:' . $shortname
            );
        }
    }

    /**
     * A site administrator can do everything the tool offers.
     *
     * @coversNothing
     */
    public function test_site_administrator_is_allowed(): void {
        $context = context_system::instance();
        $admin = get_admin();

        foreach (self::CAPABILITIES as $shortname) {
            $this->assertTrue(has_capability($this->capability($shortname), $context, $admin->id));
        }
    }

    /**
     * Granting view does not grant scanning, exporting or managing.
     *
     * This is the least privilege rule: the dashboard can be shown to somebody
     * without letting them start scans, download the plugin inventory or delete
     * stored scans.
     *
     * @coversNothing
     */
    public function test_granting_view_does_not_grant_the_others(): void {
        global $DB;
        $this->resetAfterTest();

        $context = context_system::instance();
        $managerrole = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        assign_capability($this->capability('view'), CAP_ALLOW, $managerrole, $context->id, true);

        $manager = $this->getDataGenerator()->create_user();
        role_assign($managerrole, $manager->id, $context->id);

        $this->assertTrue(has_capability($this->capability('view'), $context, $manager->id));
        $this->assertFalse(has_capability($this->capability('runscan'), $context, $manager->id));
        $this->assertFalse(has_capability($this->capability('export'), $context, $manager->id));
        $this->assertFalse(has_capability($this->capability('manage'), $context, $manager->id));
    }

    /**
     * Exporting is its own capability, so a view only user cannot download data.
     *
     * @coversNothing
     */
    public function test_export_capability_is_separate(): void {
        global $DB;
        $this->resetAfterTest();

        $context = context_system::instance();
        $managerrole = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        assign_capability($this->capability('view'), CAP_ALLOW, $managerrole, $context->id, true);
        assign_capability($this->capability('export'), CAP_PROHIBIT, $managerrole, $context->id, true);

        $manager = $this->getDataGenerator()->create_user();
        role_assign($managerrole, $manager->id, $context->id);

        $this->assertFalse(has_capability($this->capability('export'), $context, $manager->id));
        $this->assertTrue(has_capability($this->capability('view'), $context, $manager->id));
    }
}
