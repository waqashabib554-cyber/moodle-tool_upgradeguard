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
 * Fixture scenarios for declared compatibility and dependencies.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use basic_testcase;
use core\plugininfo\base as plugininfo_base;
use tool_upgradeguard\local\check\declared_compat_check;
use tool_upgradeguard\local\check\dependency_check;
use tool_upgradeguard\local\plugin_snapshot;
use tool_upgradeguard\local\scan_context;
use tool_upgradeguard\local\severity;
use tool_upgradeguard\local\target;

/**
 * Tests the declared-compatibility scenarios using in-memory plugin info.
 *
 * The core plugin info is a PHPUnit mock of the abstract base class, so no
 * plugin folder on disk is ever read.
 *
 * @package    tool_upgradeguard
 * @covers     \tool_upgradeguard\local\check\declared_compat_check
 * @covers     \tool_upgradeguard\local\check\dependency_check
 */
final class compatibility_scenarios_test extends basic_testcase {
    /**
     * Build the fixed target used by every fixture.
     *
     * @return target
     */
    private function target(): target {
        return new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified');
    }

    /**
     * Build one in-memory third-party plugin snapshot.
     *
     * @param array $declaration Version.php-like declaration fields.
     * @return plugin_snapshot
     */
    private function plugin(array $declaration = []): plugin_snapshot {
        $core = $this->getMockBuilder(plugininfo_base::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $core->component = 'mod_fixture';
        $core->type = 'mod';
        $core->name = 'fixture';
        $core->displayname = 'Fixture';
        $core->versiondisk = 2026042000;
        $core->versiondb = 2026042000;
        foreach ($declaration as $property => $value) {
            $core->{$property} = $value;
        }

        return new plugin_snapshot(
            'mod_fixture',
            'mod',
            'fixture',
            'Fixture',
            '2026042000',
            '2026042000',
            null,
            $core->versionrequires ?? null,
            isset($core->pluginsupported) && is_array($core->pluginsupported) ?
                implode('-', $core->pluginsupported) : '',
            $core->pluginincompatible ?? null,
            false,
            true,
            'mod/fixture',
            'mod/fixture',
            false,
            $core,
        );
    }

    /**
     * Make a context with the requested inventory.
     *
     * @param plugin_snapshot[] $plugins
     * @return scan_context
     */
    private function context(array $plugins): scan_context {
        return new scan_context($this->target(), $plugins, [], [], PHP_VERSION, true);
    }

    /**
     * Version.php declarations resolve to the intended severity and wording.
     *
     * @dataProvider declaration_provider
     * @covers \tool_upgradeguard\local\check\declared_compat_check::run
     * @param array $declaration In-memory version.php properties.
     * @param severity $expected Expected highest result severity.
     * @param string $expectedmessagekey Expected language string key.
     * @return void
     */
    public function test_declared_compatibility_scenarios(
        array $declaration,
        severity $expected,
        string $expectedmessagekey,
    ): void {
        $plugin = $this->plugin($declaration);
        $findings = (new declared_compat_check())->run($plugin, $this->context(['mod_fixture' => $plugin]));

        $this->assertNotEmpty($findings);
        $this->assertSame($expected, $findings[0]->severity);
        $this->assertSame($expectedmessagekey, $findings[0]->messagekey);
    }

    /**
     * Declarations cover ready, explicit incompatible, requires, and range cases.
     *
     * The "only requires" case keeps the informational severity but must use the
     * minimum-only wording: a declared floor is not a compatibility promise, so
     * it must never be reported with the supported-range message.
     *
     * @return array
     */
    public static function declaration_provider(): array {
        return [
            'all ready supported range' => [
                ['pluginsupported' => [501, 502]],
                severity::info,
                'finding_declared_compatible',
            ],
            'explicitly incompatible' => [
                ['pluginincompatible' => 502],
                severity::blocker,
                'finding_declared_incompatible',
            ],
            'requires newer target' => [
                ['versionrequires' => 2026042001],
                severity::blocker,
                'finding_declared_requires_newer_core',
            ],
            'only requires is compatible' => [
                ['versionrequires' => 2025100600],
                severity::info,
                'finding_declared_minimum_only',
            ],
            'supported range ends before target' => [
                ['pluginsupported' => [501, 501]],
                severity::caution,
                'finding_declared_support_range',
            ],
            'no declared support' => [
                [],
                severity::caution,
                'finding_declared_unknown',
            ],
        ];
    }

    /**
     * A missing dependency is a blocker without inspecting a plugin directory.
     *
     * @covers \tool_upgradeguard\local\check\dependency_check::run
     */
    public function test_missing_dependency_is_a_blocker(): void {
        $plugin = $this->plugin(['dependencies' => ['mod_required' => 2026042000]]);
        $findings = (new dependency_check())->run($plugin, $this->context(['mod_fixture' => $plugin]));

        $this->assertCount(1, $findings);
        $this->assertSame(severity::blocker, $findings[0]->severity);
        $this->assertSame('finding_dependency_missing', $findings[0]->messagekey);
    }

    /**
     * Standard plugins are omitted from third-party compatibility checks.
     *
     * @covers \tool_upgradeguard\local\check\declared_compat_check::is_applicable
     */
    public function test_core_plugin_is_not_a_third_party_compatibility_result(): void {
        $plugin = $this->plugin(['pluginsupported' => [501, 501]]);
        $standard = new plugin_snapshot(
            $plugin->component,
            $plugin->plugintype,
            $plugin->name,
            $plugin->displayname,
            $plugin->versiondisk,
            $plugin->versiondb,
            $plugin->release,
            $plugin->versionrequires,
            $plugin->supportedlist,
            $plugin->incompatible,
            true,
            true,
            $plugin->currentpath,
            $plugin->newpath,
            false,
            $plugin->coreplugin,
        );

        $this->assertFalse((new declared_compat_check())->is_applicable($standard, $this->context([])));
    }
}
