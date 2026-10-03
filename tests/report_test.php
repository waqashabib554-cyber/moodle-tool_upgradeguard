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
 * Tests the printable report context.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use basic_testcase;
use renderer_base;
use stdClass;
use tool_upgradeguard\local\confidence;
use tool_upgradeguard\local\finding;
use tool_upgradeguard\local\severity;
use tool_upgradeguard\local\target;
use tool_upgradeguard\output\report;

/**
 * The report context has to carry every section the template shows.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_test extends basic_testcase {
    /**
     * A target the scan was run against.
     *
     * @param bool $publiclayout Whether the target reads plugins from public.
     * @return target
     */
    private function target(bool $publiclayout = true): target {
        return new target('5.2', 502, 2026042000, '8.3.0', '4.4', $publiclayout, 'stable', 'verified');
    }

    /**
     * A stored scan row.
     *
     * @return stdClass
     */
    private function scan(): stdClass {
        $scan = new stdClass();
        $scan->id = 7;
        $scan->status = 'finished';
        $scan->currentversion = '5.2 (Build: 20260420)';
        $scan->targetversion = '5.2';
        $scan->score = 63;
        $scan->verdict = 'careful';
        $scan->datasetversion = 3;
        $scan->timecreated = 1758500000;
        $scan->timefinished = 1758500300;
        $scan->blockercount = 1;
        return $scan;
    }

    /**
     * One stored plugin row.
     *
     * @param string $component Frankenstyle component name.
     * @param string $status The stored status.
     * @param string $current Path the plugin lives in.
     * @param string $new Path the plugin has to live in.
     * @return stdClass
     */
    private function row(
        string $component = 'mod_x',
        string $status = 'blocker',
        string $current = '/mod/x',
        string $new = '/public/mod/x',
    ): stdClass {
        $row = new stdClass();
        $row->component = $component;
        $row->plugintype = 'mod';
        $row->name = 'x';
        $row->displayname = 'Plugin X';
        $row->status = $status;
        $row->versiondisk = '2026010100';
        $row->pluginrelease = 'v1.2.3';
        $row->usagecount = 3;
        $row->isstandard = 0;
        $row->currentpath = $current;
        $row->newpath = $new;
        return $row;
    }

    /**
     * One finding of the given plugin.
     *
     * @param string $checkkey The check that produced it.
     * @param severity $severity The severity.
     * @param confidence $confidence The confidence.
     * @return finding
     */
    private function finding(string $checkkey, severity $severity, confidence $confidence): finding {
        return new finding(
            $checkkey,
            $severity,
            'finding_declared_incompatible',
            ['component' => 'mod_x', 'target' => '5.2'],
            $confidence,
            false,
            'action_replace_or_ask_developer',
        );
    }

    /**
     * A renderer to pass to the renderable.
     *
     * @return renderer_base
     */
    private function renderer(): renderer_base {
        return $this->getMockBuilder(renderer_base::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
    }

    /**
     * The report context of the hand built scan.
     *
     * @param string $supportemail Configured support email, empty when unset.
     * @return array
     */
    private function context(string $supportemail = ''): array {
        $report = new report(
            $this->target(),
            $this->scan(),
            [$this->row(), $this->row('mod_y', 'ready', '/public/mod/y', '/public/mod/y')],
            [
                '' => [$this->finding('update_information', severity::info, confidence::low)],
                'mod_x' => [$this->finding('declared_compat', severity::blocker, confidence::high)],
            ],
            $supportemail,
            'Test site',
        );

        return $report->export_for_template($this->renderer());
    }

    /**
     * The report header carries the verdict, the site name and the data version.
     */
    public function test_report_shows_verdict_and_meta(): void {
        $context = $this->context();

        $this->assertStringContainsString('Test site', $context['reportheading']);
        $this->assertSame('Careful', $context['verdict']);
        $this->assertSame(63, $context['score']);
        $this->assertStringContainsString('3', $context['datasetversion']);
        $this->assertNotSame('', $context['meta']);
        $this->assertNotSame('', $context['disclaimer']);
    }

    /**
     * The blocker list contains site wide and plugin blockers, and nothing else.
     */
    public function test_blockers_list_site_and_plugin_findings(): void {
        $context = $this->context();

        $this->assertCount(1, $context['blockers']);
        $this->assertSame('mod_x', $context['blockers'][0]['scope']);
        $this->assertSame('High', $this->confidenceof($context['blockers'][0]));
        $this->assertTrue($context['hasblockers']);
    }

    /**
     * The plugin groups run worst first, carry their status badge and skip
     * empty statuses.
     */
    public function test_plugin_groups_are_worst_first(): void {
        $context = $this->context();

        $this->assertCount(2, $context['plugingroups']);
        $this->assertSame('danger', $context['plugingroups'][0]['statusclass']);
        $this->assertSame('success', $context['plugingroups'][1]['statusclass']);
        $this->assertNotSame('', $context['plugingroups'][0]['statuslabel']);
        $this->assertSame(1, $context['plugingroups'][0]['count']);
        $this->assertCount(1, $context['plugingroups'][0]['rows']);
        $this->assertSame('Plugin X', $context['plugingroups'][0]['rows'][0]['displayname']);
        $this->assertSame($context['plugingroups'][0]['statusclass'], $context['plugingroups'][0]['rows'][0]['statusclass']);
        $this->assertTrue($context['plugingroups'][0]['rows'][0]['hasfindings']);
        $this->assertFalse($context['plugingroups'][1]['rows'][0]['hasfindings']);
    }

    /**
     * The move list only appears for public layout targets, and it carries the
     * commands exactly while a plugin still has to move.
     */
    public function test_move_list_follows_the_target(): void {
        $context = $this->context();

        $this->assertArrayHasKey('hasmovelist', $context);
        $this->assertTrue($context['hasmoves']);
        $this->assertNotSame('', $context['movecommandstext']);

        $legacy = new report(
            $this->target(false),
            $this->scan(),
            [$this->row()],
            [],
            '',
            'Test site',
        );

        $this->assertArrayNotHasKey('hasmovelist', $legacy->export_for_template($this->renderer()));
    }

    /**
     * A report whose plugins already sit in the public layout keeps the table
     * and the CSV out: they would list identical paths on both sides.
     */
    public function test_report_without_moves_keeps_the_copy_material_out(): void {
        $report = new report(
            $this->target(),
            $this->scan(),
            [$this->row('mod_x', 'ready', '/public/mod/x', '/public/mod/x')],
            [],
            '',
            'Test site',
        );

        $context = $report->export_for_template($this->renderer());

        $this->assertArrayHasKey('hasmovelist', $context);
        $this->assertFalse($context['hasmoves']);
    }

    /**
     * The wrong result link appears only with a configured support email.
     */
    public function test_wrongresult_link_only_with_email(): void {
        $without = $this->context();
        $this->assertFalse($without['haswrongresult']);
        $this->assertArrayNotHasKey('wrongresulturl', $without);

        $with = $this->context('support@example.com');
        $this->assertTrue($with['haswrongresult']);
        $this->assertStringStartsWith('mailto:support@example.com', $with['wrongresulturl']);
        $this->assertNotSame('', $with['wrongresultlabel']);
    }

    /**
     * The confidence label of one blocker row.
     *
     * @param array $blocker The blocker row.
     * @return string
     */
    private function confidenceof(array $blocker): string {
        return $blocker['confidencelabel'] === '' ? '' : $blocker['confidencelabel'];
    }
}
