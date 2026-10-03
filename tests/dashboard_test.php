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
 * Tests the tabbed dashboard context.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use basic_testcase;
use moodle_url;
use renderer_base;
use stdClass;
use tool_upgradeguard\local\action_planner;
use tool_upgradeguard\local\confidence;
use tool_upgradeguard\local\finding;
use tool_upgradeguard\local\severity;
use tool_upgradeguard\local\target;
use tool_upgradeguard\local\target_repository;
use tool_upgradeguard\output\dashboard;

/**
 * The tabbed dashboard: tabs, state messages and the action limit.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dashboard_test extends basic_testcase {
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
     * A stored scan row in the given state.
     *
     * @param string $status Scan status.
     * @param string $verdict Verdict code.
     * @return stdClass
     */
    private function scan(string $status = 'finished', string $verdict = 'careful'): stdClass {
        $scan = new stdClass();
        $scan->id = 7;
        $scan->status = $status;
        $scan->currentversion = '5.2 (Build: 20260420)';
        $scan->currentbranch = 502;
        $scan->targetversion = '5.2';
        $scan->targetbranch = 502;
        $scan->score = 63;
        $scan->verdict = $verdict;
        $scan->plugincount = 2;
        $scan->blockercount = 1;
        $scan->cautioncount = 1;
        $scan->unknowncount = 0;
        $scan->timecreated = 1758500000;
        $scan->timefinished = $status === 'finished' ? 1758500300 : 0;
        $scan->errormessage = 'The scan failed: boom.';
        return $scan;
    }

    /**
     * One prepared action row as the action planner returns them.
     *
     * @param string $text The action text.
     * @return array
     */
    private function action(string $text): array {
        return [
            'action' => $text,
            'severity' => severity::blocker,
            'components' => ['mod_x'],
        ];
    }

    /**
     * A finding about the site rather than about a single plugin.
     *
     * @return finding
     */
    private function sitefinding(): finding {
        return new finding(
            checkkey: 'site_php',
            severity: severity::blocker,
            messagekey: 'finding_site_php_too_old',
            params: ['php' => '8.1.0', 'target' => '5.2', 'phpmin' => '8.3'],
            confidence: confidence::high,
            actionkey: 'action_upgrade_php',
        );
    }

    /**
     * The dashboard context for a scan in the given state.
     *
     * @param stdClass|null $scan The scan, null for the empty state.
     * @param array|null $actions Prepared action rows.
     * @param array|null $sitefindings Site wide findings of the scan.
     * @param array|null $plugins Stored plugin rows of the scan.
     * @return array
     */
    private function context(
        ?stdClass $scan = null,
        ?array $actions = null,
        ?array $sitefindings = null,
        ?array $plugins = null,
    ): array {
        $target = new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified');
        $dashboard = new dashboard(
            $scan,
            $plugins ?? [],
            ['' => $sitefindings ?? []],
            $actions ?? [],
            [],
            '',
            3,
            true,
            true,
            0,
            $target
        );

        return $dashboard->export_for_template($this->renderer());
    }

    /**
     * One stored plugin row, with the columns the plugin table carries.
     *
     * @param string $current Relative path the plugin lives in.
     * @param string $new Relative path the target expects it in.
     * @return stdClass
     */
    private function pluginrow(string $current, string $new): stdClass {
        $row = new stdClass();
        $row->id = 11;
        $row->scanid = 7;
        $row->component = 'mod_x';
        $row->plugintype = 'mod';
        $row->name = 'x';
        $row->displayname = 'Plugin X';
        $row->versiondisk = '2026010100';
        $row->versiondb = '2026010100';
        $row->pluginrelease = 'v1.2.3';
        $row->versionrequires = '2024100700';
        $row->supportedlist = '404-502';
        $row->incompatiblebranch = '';
        $row->isstandard = 0;
        $row->installed = 1;
        $row->currentpath = $current;
        $row->newpath = $new;
        $row->status = 'caution';
        $row->usagecount = 1;
        $row->usagedetail = null;
        $row->updateavailable = 0;
        $row->updateversion = null;
        $row->updaterelease = null;
        $row->timecreated = 1758500000;

        return $row;
    }

    /**
     * The six tabs are declared in the documented order, Overview first.
     */
    public function test_tabs_are_declared_in_the_documented_order(): void {
        $context = $this->context($this->scan());

        $this->assertSame(
            ['overview', 'plugins', 'environment', 'files', 'checklist', 'history'],
            array_column($context['tabs'], 'id')
        );
        $this->assertSame(
            [
                'fa fa-gauge-high',
                'fa fa-plug',
                'fa fa-server',
                'fa fa-folder-tree',
                'fa fa-list-check',
                'fa fa-clock-rotate-left',
            ],
            array_column($context['tabs'], 'icon')
        );
        $this->assertTrue($context['tabs'][0]['active']);
        $this->assertFalse($context['tabs'][1]['active']);
    }

    /**
     * The overview leads with the three most important actions.
     */
    public function test_overview_leads_with_three_actions(): void {
        $actions = [
            $this->action('Fix one'),
            $this->action('Fix two'),
            $this->action('Fix three'),
            $this->action('Fix four'),
            $this->action('Fix five'),
            $this->action('Fix six'),
            $this->action('Fix seven'),
        ];

        $context = $this->context($this->scan(), $actions);

        $this->assertTrue($context['hasoverviewactions']);
        $this->assertCount(3, $context['overviewactions']);
        $this->assertSame('Fix one', $context['overviewactions'][0]['action']);
    }

    /**
     * A list too short to need a disclosure is shown whole.
     *
     * Hiding the fourth and fifth action behind a button that says "Show all 2
     * actions" made the overview longer than the whole list would have been
     * and still hid two of the five problems the reviewer has to act on. The
     * limit only pays off once enough rows are hidden to be worth the control.
     */
    public function test_a_short_action_list_is_shown_in_full(): void {
        $actions = [
            $this->action('Fix one'),
            $this->action('Fix two'),
            $this->action('Fix three'),
            $this->action('Fix four'),
            $this->action('Fix five'),
        ];

        $context = $this->context($this->scan(), $actions);

        $this->assertCount(5, $context['overviewactions']);
        $this->assertSame([], $context['restactions']);
        $this->assertFalse($context['hasrestactions']);
        // With nothing hidden there is nothing to show, so the control is absent
        // and the page carries no unread count of work that is already on screen.
        $this->assertSame(
            get_string('showallactions', 'tool_upgradeguard', (object) ['count' => 0, 'noun' => 'actions']),
            $context['showallactions']
        );
    }

    /**
     * The boundary case still needs a click, and the click offers the reverse.
     */
    public function test_the_shortest_list_that_still_collapses_can_be_reopened(): void {
        $actions = [];
        for ($i = 1; $i <= 7; $i++) {
            $actions[] = $this->action('Fix ' . $i);
        }

        $context = $this->context($this->scan(), $actions);

        $this->assertCount(3, $context['overviewactions']);
        $this->assertCount(4, $context['restactions']);
        $this->assertTrue($context['hasrestactions']);
        // The same control opens and closes the list, so the two wordings have
        // to be different or the reader is never told it can be closed again.
        $this->assertNotSame($context['showallactions'], $context['hideactions']);
    }

    /**
     * The action rows carry a direct link only when the action maps to a core page.
     */
    public function test_action_rows_carry_only_applicable_core_links(): void {
        $update = new finding(
            'update_available',
            severity::info,
            'finding_update_available',
            ['version' => '2026020100'],
            confidence::high,
            false,
            'action_update_plugin',
        );
        $remove = new finding(
            'unused_plugin',
            severity::caution,
            'finding_unused_plugin',
            [],
            confidence::high,
            false,
            'action_review_or_remove',
        );
        $move = new finding(
            'public_location',
            severity::caution,
            'finding_legacy_location',
            ['oldpath' => 'mod/x', 'newpath' => 'public/mod/x'],
            confidence::high,
            false,
            'action_move_plugin_to_public',
        );

        $actions = (new action_planner())->plan([
            'mod_update' => [$update],
            'mod_remove' => [$remove],
            'mod_move' => [$move],
        ]);
        $context = $this->context($this->scan(), $actions);
        $rows = array_merge($context['overviewactions'], $context['restactions']);

        $this->assertCount(3, $rows);
        $rowsbykey = [];
        foreach ($rows as $row) {
            $rowsbykey[$row['actionkey']] = $row;
        }
        $this->assertSame(
            (new moodle_url('/admin/tool/installaddon/index.php'))->out(false),
            $rowsbykey['action_update_plugin']['actionurl']
        );
        $this->assertTrue($rowsbykey['action_update_plugin']['hasactionurl']);
        $this->assertSame(
            (new moodle_url('/admin/plugins.php'))->out(false),
            $rowsbykey['action_review_or_remove']['actionurl']
        );
        $this->assertTrue($rowsbykey['action_review_or_remove']['hasactionurl']);
        $this->assertSame('', $rowsbykey['action_move_plugin_to_public']['actionurl']);
        $this->assertFalse($rowsbykey['action_move_plugin_to_public']['hasactionurl']);
    }

    /**
     * The remaining actions are one click away instead of missing.
     *
     * The overview used to slice the list to three rows and leave the rest to a
     * plugins tab that only linked to the results page.
     */
    public function test_overview_keeps_the_remaining_actions_behind_a_collapse(): void {
        $actions = [];
        for ($i = 1; $i <= 7; $i++) {
            $actions[] = $this->action('Fix ' . $i);
        }

        $context = $this->context($this->scan(), $actions);

        $this->assertCount(4, $context['restactions']);
        $this->assertTrue($context['hasrestactions']);
        $this->assertSame('Fix 4', $context['restactions'][0]['action']);
        // The link reveals four hidden actions, so it has to say four. Counting the
        // whole list told the reader that seven were still to come.
        $this->assertSame(
            get_string('showallactions', 'tool_upgradeguard', (object) ['count' => 4, 'noun' => 'actions']),
            $context['showallactions']
        );

        $template = (string) file_get_contents(__DIR__ . '/../templates/dashboard.mustache');
        $this->assertStringContainsString('{{#restactions}}', $template);
        $this->assertStringContainsString('{{showallactions}}', $template);
        $this->assertStringContainsString('data-bs-target="#ug-rest-actions"', $template);
        $this->assertStringContainsString('id="ug-rest-actions"', $template);
        $this->assertStringContainsString('data-bs-toggle="collapse"', $template);
        // The control carries both wordings so the same press can close the list
        // again, and Bootstrap never rewrites a toggle's label by itself.
        $this->assertStringContainsString('data-showlabel="{{showallactions}}"', $template);
        $this->assertStringContainsString('data-hidelabel="{{hideactions}}"', $template);
        $this->assertStringContainsString('aria-controls="ug-rest-actions"', $template);
    }

    /**
     * A short action list needs no collapse.
     */
    public function test_three_actions_need_no_collapse(): void {
        $actions = [
            $this->action('Fix one'),
            $this->action('Fix two'),
            $this->action('Fix three'),
        ];

        $context = $this->context($this->scan(), $actions);

        $this->assertTrue($context['hasoverviewactions']);
        $this->assertSame([], $context['restactions']);
        $this->assertFalse($context['hasrestactions']);
    }

    /**
     * A site on the newest released branch says so near the top.
     *
     * The notice that there is nothing to scan sat at the bottom of the page,
     * under a version selector that is deliberately empty, so it read as a
     * control that had failed rather than as a site that is up to date.
     */
    public function test_a_site_with_no_upgrade_target_is_told_so(): void {
        global $CFG;

        $context = $this->context($this->scan(), [$this->action('Fix one')]);

        // The suite runs on a site whose only newer dataset target is an
        // unreleased branch, so the released-target list is empty. Asserting the
        // flag against the repository rather than hardcoding false keeps the test
        // honest when a real release eventually becomes a target.
        $targets = (new target_repository())->get_upgrade_targets((int) $CFG->branch);
        $this->assertSame($targets === [], !$context['hastargets']);

        if ($targets === []) {
            $this->assertArrayHasKey('notargetmessage', $context);
            $this->assertNotSame('', $context['notargetmessage']);
            // The message names the version the site is on, so an administrator on
            // an unusual build is not left guessing which branch was meant.
            $this->assertStringContainsString(
                '5.2',
                $context['notargetmessage'],
                'The branch 502 is shown to the administrator as 5.2.'
            );
        }
    }

    /**
     * A finished scan shows the verdict and the score.
     */
    public function test_finished_scan_shows_the_verdict_and_score(): void {
        $context = $this->context($this->scan(), [$this->action('Fix one')]);

        $this->assertSame('Careful', $context['verdict']);
        $this->assertSame(63, $context['score']);
        $this->assertTrue($context['hasscore']);
        $this->assertTrue($context['hasoverviewactions']);
    }

    /**
     * The empty state tells the administrator to start the first scan.
     *
     * Every tab still has to render a working page in that state, so the links
     * the tabs use are exported before the empty state returns.
     */
    public function test_no_scan_state(): void {
        $context = $this->context(null);

        $this->assertFalse($context['hasscan']);
        $this->assertNotSame('', $context['noscansyet']);
        $this->assertArrayHasKey('tabs', $context);
        $this->assertNotSame('', $context['historyurl']);
        $this->assertNotSame('', $context['pluginspagetext']);
        $this->assertNotSame('', $context['filenoneeded']);
        $this->assertNotSame('', $context['checklistnotready']);
        // The results page belongs to one scan, so the Plugins tab must not link
        // to it while the site has no scan at all.
        $this->assertArrayNotHasKey('resultsurl', $context);
    }

    /**
     * The first-run screen explains the tool before asking for a scan.
     *
     * A new site only knows the tool from this page, so it has to say what the
     * tool does and where the results of a scan come from.
     */
    public function test_the_first_run_screen_welcomes_the_administrator(): void {
        $context = $this->context(null);

        $this->assertNotSame('', $context['welcomeheading']);
        $this->assertNotSame('', $context['welcomewhat']);
        $this->assertNotSame('', $context['welcomedata']);
        // Shared with the state that has a scan, because the same partial is
        // rendered there.
        $this->assertNotSame('', $context['welcomestartscan']);
    }

    /**
     * The form that starts a scan must not be locked away behind a stored scan.
     *
     * The form used to live inside the "hasscan" section only, so a site that had
     * never been scanned was told to "start one" by a page that offered no way
     * to start anything. The guidance line is rendered by the same partial as
     * the form, so it cannot appear without the button it describes.
     */
    public function test_the_start_scan_form_is_offered_before_any_scan_exists(): void {
        $pane = $this->pane('overview');

        $this->assertStringContainsString('{{> tool_upgradeguard/startscan }}', $pane);

        // The partial itself is where the form and its label live, guarded
        // together so the label never promises a button that is missing.
        $partial = (string) file_get_contents(__DIR__ . '/../templates/startscan.mustache');
        $this->assertStringContainsString('{{{formhtml}}}', $partial);
        $this->assertStringContainsString('{{welcomestartscan}}', $partial);
        $this->assertStringContainsString('{{#canrunscan}}', $partial);
        $this->assertStringContainsString('{{#hastargets}}', $partial);
    }

    /**
     * The template markup of one dashboard tab pane.
     *
     * @param string $id Pane id without the "ug-pane-" prefix.
     * @return string
     */
    private function pane(string $id): string {
        $template = (string) file_get_contents(__DIR__ . '/../templates/dashboard.mustache');

        foreach (explode('<div class="tab-pane', $template) as $pane) {
            if (str_contains($pane, 'id="ug-pane-' . $id . '"')) {
                return $pane;
            }
        }

        $this->fail('The dashboard has no ' . $id . ' tab pane.');

        return '';
    }

    /**
     * Every tab renders something useful on a site without scans.
     *
     * The Plugins and History panes used to sit outside the "hasscan" section:
     * their buttons had an empty href, which reloaded the dashboard, and the
     * Plugins pane showed an empty paragraph.
     */
    public function test_every_tab_explains_itself_without_a_scan(): void {
        foreach (['plugins', 'files', 'checklist', 'history'] as $id) {
            $pane = $this->pane($id);

            $this->assertStringContainsString('{{^hasscan}}', $pane, $id . ' explains the empty state');
            $this->assertStringContainsString('{{noscansyet}}', $pane, $id . ' points at the first scan');
            $this->assertStringContainsString('{{#hasscan}}', $pane, $id . ' guards its own content');
        }

        // The environment rows come from the stored scan, so that tab explains
        // itself as well instead of rendering an empty card.
        $environment = $this->pane('environment');
        $this->assertStringContainsString('{{#hasenvironment}}', $environment);
        $this->assertStringContainsString('{{> tool_upgradeguard/environment }}', $environment);
        $this->assertStringContainsString('{{environmentnone}}', $environment);
    }

    /**
     * A scan that has not finished leaves the checklist with an explanation.
     */
    public function test_a_running_scan_explains_the_missing_checklist(): void {
        $context = $this->context($this->scan('running'));

        $this->assertArrayNotHasKey('haschecklist', $context);
        $this->assertStringContainsString('{{checklistnotready}}', $this->pane('checklist'));
    }

    /**
     * A failed scan surfaces the stored error message.
     */
    public function test_failed_scan_state(): void {
        $scan = $this->scan('failed', 'stop');
        $scan->score = null;

        $context = $this->context($scan);

        $this->assertTrue($context['isfailed']);
        $this->assertSame('The scan failed: boom.', $context['errormessage']);
        $this->assertFalse($context['hasscore']);
    }

    /**
     * A running scan shows the cron message when cron has not run recently.
     */
    public function test_running_scan_state(): void {
        $context = $this->context($this->scan('running'));

        $this->assertTrue($context['isrunning']);
        $this->assertTrue($context['cronrequired']);
        $this->assertNotSame('', $context['cronmessage']);
        $this->assertFalse($context['canexport']);
    }

    /**
     * The site wide findings reach the shared findings partial.
     *
     * That partial iterates over a plain "findings" list, so this is the key the
     * dashboard has to export. Exporting the rows under any other name renders
     * the heading with nothing under it, which is exactly what the screenshots
     * of the tabbed dashboard showed.
     */
    public function test_site_wide_findings_are_exported_for_the_findings_partial(): void {
        $partial = (string) file_get_contents(__DIR__ . '/../templates/findings.mustache');
        $this->assertStringContainsString('{{#findings}}', $partial);

        $context = $this->context($this->scan(), null, [$this->sitefinding()]);

        $this->assertArrayHasKey('findings', $context);
        $this->assertArrayNotHasKey('sitefindings', $context);
        $this->assertTrue($context['hassitefindings']);
        $this->assertCount(1, $context['findings']);
        $this->assertSame('site_php', $context['findings'][0]['checkkey']);
        $this->assertSame('danger', $context['findings'][0]['severityclass']);
        $this->assertStringContainsString('8.1.0', $context['findings'][0]['message']);
        $this->assertStringContainsString('8.3', $context['findings'][0]['action']);
    }

    /**
     * A scan without site wide findings explains the empty section.
     *
     * The heading stays visible in that case, so the section does not silently
     * disappear and leave the reader wondering whether it is broken.
     */
    public function test_a_scan_without_site_wide_findings_shows_an_empty_state(): void {
        $context = $this->context($this->scan());

        $this->assertFalse($context['hassitefindings']);
        $this->assertSame([], $context['findings']);

        $template = (string) file_get_contents(__DIR__ . '/../templates/dashboard.mustache');
        $this->assertStringContainsString('{{> tool_upgradeguard/findings }}', $template);
        $this->assertStringContainsString('{{^hassitefindings}}', $template);
        $this->assertStringContainsString('sitenofindings, tool_upgradeguard', $template);
        $this->assertLessThan(
            strpos($template, '{{#hassitefindings}}'),
            strpos($template, 'sitefindingsheading'),
            'The heading is outside the findings section, so the empty state has a heading too.'
        );
    }

    /**
     * The Files pane only offers the copy-paste material while something has to
     * move. Rendering the table and the CSV for a scan where every plugin is
     * already in place is what left the tab 2400px tall with nothing to do in
     * it.
     */
    public function test_move_list_hides_the_copy_material_when_nothing_has_to_move(): void {
        $settled = $this->context($this->scan(), null, null, [$this->pluginrow('/public/mod/x', '/public/mod/x')]);
        $this->assertArrayHasKey('hasmovelist', $settled);
        $this->assertFalse($settled['hasmoves'], 'Every plugin is in place, so there is nothing to copy.');

        $pending = $this->context($this->scan(), null, null, [$this->pluginrow('/mod/x', '/public/mod/x')]);
        $this->assertTrue($pending['hasmoves'], 'One plugin still has to move, so the list stays.');
    }

    /**
     * The template ships a single primary button and the shared stylesheet.
     */
    public function test_template_has_one_primary_button_and_shared_styles(): void {
        $template = (string) file_get_contents(__DIR__ . '/../templates/dashboard.mustache');

        // The only primary button on the page is the scan form's submit,
        // which moodleforms renders; the template itself adds none.
        $this->assertSame(0, substr_count($template, 'btn-primary'));

        $styles = (string) file_get_contents(__DIR__ . '/../styles.css');
        $this->assertStringContainsString('flex-wrap', $styles);
        $this->assertStringContainsString('print-color-adjust: exact', $styles);
        $this->assertStringContainsString('.tool-upgradeguard', $styles);
    }
}
