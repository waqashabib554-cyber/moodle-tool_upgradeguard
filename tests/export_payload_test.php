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
 * Tests the JSON export payload.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use basic_testcase;
use stdClass;
use tool_upgradeguard\local\confidence;
use tool_upgradeguard\local\csv_report;
use tool_upgradeguard\local\export_payload;
use tool_upgradeguard\local\finding;
use tool_upgradeguard\local\severity;
use tool_upgradeguard\local\target;

/**
 * The JSON export has to be complete and machine readable.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class export_payload_test extends basic_testcase {
    /**
     * A target the scan was run against.
     *
     * @return target
     */
    private function target(): target {
        return new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified', [
            'php' => [
                'source' => 'https://moodledev.io/general/releases/5.2',
                'verified' => true,
            ],
        ]);
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
        $scan->currentbranch = 502;
        $scan->targetversion = '5.2';
        $scan->targetbranch = 502;
        $scan->score = 63;
        $scan->verdict = 'careful';
        $scan->plugincount = 2;
        $scan->blockercount = 1;
        $scan->cautioncount = 1;
        $scan->unknowncount = 0;
        $scan->timecreated = 1758500000;
        $scan->timefinished = 1758500300;
        $scan->datasetversion = 3;
        $scan->phpversion = '8.3.30';
        $scan->dbvendor = 'mysql';
        $scan->dbversion = '8.4.3';
        $scan->phpextensions = '["curl","spl"]';
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
        $row->versiondb = '2026010100';
        $row->versionrequires = '2024042200';
        $row->supportedlist = '404-502';
        $row->currentpath = $current;
        $row->newpath = $new;
        $row->usagecount = 3;
        $row->updateavailable = 0;
        $row->updateversion = null;
        $row->isstandard = 0;
        $row->installed = 1;
        return $row;
    }

    /**
     * One blocker finding of the given plugin.
     *
     * @param string $component The component the finding belongs to.
     * @return finding
     */
    private function blockerfinding(string $component): finding {
        return new finding(
            'declared_compat',
            severity::blocker,
            'finding_declared_incompatible',
            ['component' => $component, 'target' => '5.2'],
            confidence::high,
            false,
            'action_replace_or_ask_developer',
        );
    }

    /**
     * The payload of the hand built scan.
     *
     * @param string $supportemail Configured support email, empty when unset.
     * @return array
     */
    private function payload(string $supportemail = ''): array {
        $plugins = [$this->row(), $this->row('mod_y', 'caution', '/public/mod/y', '/public/mod/y')];
        $findings = ['mod_x' => [$this->blockerfinding('mod_x')]];

        return export_payload::build($this->target(), $this->scan(), $plugins, $findings, $supportemail);
    }

    /**
     * The CSV export includes site-wide findings and keeps the column shape.
     *
     * @covers \tool_upgradeguard\local\csv_report::build
     */
    public function test_csv_export_includes_site_findings(): void {
        $sitefinding = new finding(
            'site_php',
            severity::blocker,
            'finding_site_php_too_old',
            ['php' => '8.1.0', 'target' => '5.2', 'phpmin' => '8.3'],
            confidence::high,
            false,
            'action_upgrade_php'
        );
        $plugin = (object) [
            'component' => 'mod_x',
            'plugintype' => 'mod',
            'status' => 'ready',
            'versiondisk' => '2026010100',
            'usagecount' => 0,
            'updateversion' => null,
        ];

        $report = csv_report::build([$plugin], ['' => [$sitefinding]]);

        $this->assertCount(11, $report['columns']);
        $this->assertCount(2, $report['rows']);
        $this->assertSame('This site', $report['rows'][0][$report['columns'][0]]);
        $this->assertSame('mod_x', $report['rows'][1][$report['columns'][0]]);
    }

    /**
     * The payload carries the scan facts and the target facts.
     */
    public function test_payload_carries_the_scan_and_target_facts(): void {
        $payload = $this->payload();

        $this->assertSame(3, $payload['generator']['datasetversion']);
        $this->assertSame(7, $payload['generator']['scanid']);
        $this->assertSame(63, $payload['scan']['score']);
        $this->assertSame('careful', $payload['scan']['verdict']);
        $this->assertSame('8.3.30', $payload['environment']['phpversion']);
        $this->assertSame(['curl', 'spl'], $payload['environment']['phpextensions']);
        $this->assertTrue($payload['target']['publiclayout']);
        $this->assertSame('verified', $payload['target']['requiresintsource']);
        $this->assertSame('https://moodledev.io/general/releases/5.2', $payload['target']['environment']['php']['source']);
    }

    /**
     * Every finding carries its confidence, and moved plugins are marked.
     */
    public function test_every_finding_carries_its_confidence(): void {
        $payload = $this->payload();

        $this->assertCount(2, $payload['plugins']);
        $this->assertTrue($payload['plugins'][0]['needsmove']);
        $this->assertFalse($payload['plugins'][1]['needsmove']);
        $this->assertSame('blocker', $payload['plugins'][0]['findings'][0]['severity']);
        $this->assertSame('high', $payload['plugins'][0]['findings'][0]['confidence']);
        $this->assertNotSame('', $payload['plugins'][0]['statuslabel']);
    }

    /**
     * The payload survives a JSON encode and decode round trip.
     */
    public function test_json_round_trip_is_valid(): void {
        $encoded = json_encode($this->payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $decoded = json_decode($encoded, true);

        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded['plugins']);
        $this->assertSame(63, $decoded['scan']['score']);
        $this->assertSame('blocker', $decoded['plugins'][0]['status']);
        $this->assertSame(1, $decoded['scan']['blockercount']);
    }

    /**
     * The wrong result link depends on the support email setting.
     */
    public function test_support_link_depends_on_the_setting(): void {
        $this->assertNull($this->payload()['support']['reportwrongresult']);
        $this->assertNull(export_payload::wrongresult_url('', 7));
        $this->assertNull(export_payload::wrongresult_url(null, 7));

        $link = export_payload::wrongresult_url('support@example.com', 7);
        $this->assertSame('mailto:support@example.com?subject=Upgrade%20Guard%3A%20wrong%20result%20%28scan%207%29', $link);
        $this->assertSame($link, $this->payload('support@example.com')['support']['reportwrongresult']);
    }

    public function test_site_findings_are_exported_separately(): void {
        $sitefinding = new finding(
            'site_php',
            severity::blocker,
            'finding_site_php_too_old',
            ['php' => '8.1.0', 'target' => '5.2', 'phpmin' => '8.3'],
            confidence::high,
            false,
            'action_upgrade_php'
        );
        $payload = export_payload::build(
            $this->target(),
            $this->scan(),
            [$this->row()],
            ['' => [$sitefinding]],
            ''
        );

        $this->assertCount(1, $payload['sitefindings']);
        $this->assertSame('site_php', $payload['sitefindings'][0]['checkkey']);
        $this->assertSame('blocker', $payload['sitefindings'][0]['severity']);
        $this->assertStringContainsString('8.1.0', $payload['sitefindings'][0]['message']);

        // The breakdown carries one entry per plugin plus one per site-wide
        // finding. The order is worst penalty first, and the used mod_x blocker
        // outweighs the environment blocker, so the site entry is asserted by
        // value rather than by position.
        $this->assertCount(2, $payload['scorebreakdown']);
        $bycomponent = array_column($payload['scorebreakdown'], null, 'component');
        $this->assertArrayHasKey('site', $bycomponent);
        $this->assertArrayHasKey('mod_x', $bycomponent);
        $this->assertSame(25.0, (float) $bycomponent['site']['penalty']);
        $this->assertSame(36.0, (float) $bycomponent['mod_x']['penalty']);
        $this->assertSame(
            ['mod_x', 'site'],
            array_column($payload['scorebreakdown'], 'component')
        );
    }

    /**
     * The target block carries the release status and where it was read.
     *
     * @covers \tool_upgradeguard\local\export_payload::build
     */
    public function test_target_status_is_exported_with_its_source(): void {
        $payload = $this->payload();

        $this->assertSame('stable', $payload['target']['status']);
        $this->assertTrue($payload['target']['fullysupported']);
        $this->assertFalse($payload['target']['securityonly']);
        $this->assertArrayHasKey('statussource', $payload['target']);
    }

    /**
     * The score breakdown is numeric so that consumers can recompute it.
     */
    public function test_score_breakdown_is_numeric(): void {
        $breakdown = $this->payload()['scorebreakdown'];
        $this->assertNotEmpty($breakdown);

        foreach ($breakdown as $entry) {
            $this->assertArrayHasKey('component', $entry);
            $this->assertArrayHasKey('penalty', $entry);
            $this->assertGreaterThanOrEqual(0, $entry['penalty']);
        }
    }
}
