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
 * Renders the dashboard against a real database.
 *
 * The unit tests check the context the dashboard exports; this test renders the
 * whole template through Moodle's mustache engine, which is the only way to
 * notice that a partial reads a key the context does not export. That mismatch
 * left the "Site wide findings" heading without its table in the browser.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use advanced_testcase;
use moodle_url;
use tool_upgradeguard\local\action_planner;
use tool_upgradeguard\local\repository\scan_repository;
use tool_upgradeguard\local\target;
use tool_upgradeguard\local\target_repository;
use tool_upgradeguard\output\dashboard;

/**
 * The dashboard HTML as a browser receives it.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dashboard_page_test extends advanced_testcase {
    /**
     * Store a scan in the given state.
     *
     * @param array $overrides Column values to override.
     * @return int The scan id.
     */
    private function seed_scan(array $overrides = []): int {
        global $DB, $USER;

        $target = new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified');
        $scanid = (new scan_repository())->create_queued_scan($target, 'Moodle 5.2', 502, 3, (int) $USER->id);

        $DB->update_record('tool_upgradeguard_scan', (object) array_merge([
            'id' => $scanid,
            'status' => 'finished',
            'score' => 63,
            'verdict' => 'careful',
            'plugincount' => 2,
            'blockercount' => 1,
            'cautioncount' => 3,
            'unknowncount' => 0,
            'timefinished' => time(),
        ], $overrides));

        return $scanid;
    }

    /**
     * Store one scanned plugin.
     *
     * @param int $scanid The scan.
     * @param string $component Component name.
     * @param string $status Plugin status.
     * @param int|null $usagecount Usage count, null when it could not be measured.
     * @param string|null $currentpath Path the plugin lives in, null for the legacy layout.
     * @return int The plugin id.
     */
    private function seed_plugin(
        int $scanid,
        string $component,
        string $status,
        ?int $usagecount,
        ?string $currentpath = null,
    ): int {
        global $DB;

        $path = str_replace('_', '/', $component);

        return $DB->insert_record('tool_upgradeguard_plugin', (object) [
            'scanid' => $scanid,
            'component' => $component,
            'plugintype' => 'mod',
            'name' => substr($component, 4),
            'displayname' => 'Plugin ' . $component,
            'versiondisk' => '2026010100',
            'versiondb' => '2026010100',
            'isstandard' => 0,
            'installed' => 1,
            'currentpath' => $currentpath ?? '/' . $path,
            'newpath' => '/public/' . $path,
            'status' => $status,
            'usagecount' => $usagecount,
            'updateavailable' => 0,
            'timecreated' => time(),
        ]);
    }

    /**
     * Store one finding, 0 for a site wide finding.
     *
     * @param int $scanid The scan.
     * @param int $pluginid The plugin, 0 for the site.
     * @param string $checkkey Check that produced the finding.
     * @param string $severity Finding severity.
     * @param string $messagekey Language string key of the message.
     * @param array $params Message and action parameters.
     * @param string $actionkey Language string key of the suggested action.
     */
    private function seed_finding(
        int $scanid,
        int $pluginid,
        string $checkkey,
        string $severity,
        string $messagekey,
        array $params,
        string $actionkey,
    ): void {
        global $DB;

        $DB->insert_record('tool_upgradeguard_finding', (object) [
            'scanid' => $scanid,
            'pluginid' => $pluginid,
            'checkkey' => $checkkey,
            'severity' => $severity,
            'messagekey' => $messagekey,
            'params' => $params === [] ? null : json_encode($params),
            'confidence' => 'high',
            'fixedbyupdate' => 0,
            'actionkey' => $actionkey,
            'docsurl' => null,
            'timecreated' => time(),
        ]);
    }

    /**
     * Render the dashboard the way index.php does.
     *
     * @param int|null $scanid The scan to show, null for a site without scans.
     * @return string The rendered HTML.
     */
    private function render(?int $scanid, bool $canrunscan = true): string {
        global $PAGE;

        $repository = new scan_repository();
        $targets = new target_repository();
        $scan = $scanid === null ? $repository->get_latest_scan() : $repository->get_scan($scanid);
        $findings = $scan === null ? ['' => []] : $repository->get_findings_grouped((int) $scan->id);
        $plugins = $scan === null ? [] : $repository->get_plugins_for_scan((int) $scan->id);
        $target = $scan === null ? null : $targets->get_target((string) $scan->targetversion);

        $dashboard = new dashboard(
            $scan,
            $plugins,
            $findings,
            (new action_planner())->plan($findings),
            $repository->get_recent_scans(10),
            '<form action="#"><input type="submit" value="Run scan"></form>',
            $targets->get_dataset_version(),
            $canrunscan,
            true,
            0,
            $target
        );

        return $PAGE->get_renderer('tool_upgradeguard')->render_dashboard($dashboard);
    }

    /**
     * The overview lists the site wide findings and every planned action.
     */
    public function test_overview_shows_the_site_wide_findings_and_every_action(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $scanid = $this->seed_scan();
        $modx = $this->seed_plugin($scanid, 'mod_x', 'caution', 3);
        $mody = $this->seed_plugin($scanid, 'mod_y', 'blocker', 0);

        $this->seed_finding(
            $scanid,
            $modx,
            'public_location',
            'caution',
            'finding_legacy_location',
            ['oldpath' => 'mod/x', 'newpath' => 'public/mod/x'],
            'action_move_plugin_to_public'
        );
        $this->seed_finding(
            $scanid,
            $modx,
            'update_available',
            'caution',
            'finding_update_available',
            ['version' => '2026020100'],
            'action_update_plugin'
        );
        $this->seed_finding(
            $scanid,
            $mody,
            'dependency_missing',
            'blocker',
            'finding_dependency_missing',
            ['dependency' => 'mod_z'],
            'action_install_dependency'
        );
        $this->seed_finding(
            $scanid,
            $mody,
            'unused_plugin',
            'caution',
            'finding_unused_plugin',
            [],
            'action_review_or_remove'
        );
        $this->seed_finding(
            $scanid,
            0,
            'site_php',
            'blocker',
            'finding_site_php_too_old',
            ['php' => '8.1.0', 'target' => '5.2', 'phpmin' => '8.3'],
            'action_upgrade_php'
        );
        // Two more actions take the total past the point where the overview hides
        // rows, so the collapse under test is actually rendered. Both use real
        // language keys: an unknown one would render as [[action_...]] and hide
        // the breakage behind a passing assertion.
        $this->seed_finding(
            $scanid,
            0,
            'environment_database',
            'blocker',
            'finding_environment_database_too_old',
            ['vendor' => 'MariaDB', 'current' => '10.6.0', 'target' => '5.2', 'minimum' => '10.11.0'],
            'action_upgrade_database'
        );
        $this->seed_finding(
            $scanid,
            0,
            'environment_extension',
            'caution',
            'finding_environment_extension_missing',
            ['extension' => 'intl', 'target' => '5.2'],
            'action_install_php_extension'
        );

        $html = $this->render($scanid);

        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('[[', $html);

        // The rows have to reach the shared findings table, which the overview
        // renders between the "Site wide findings" heading and the disclaimer.
        $start = (int) strpos($html, get_string('sitefindingsheading', 'tool_upgradeguard'));
        $end = (int) strpos($html, get_string('disclaimer', 'tool_upgradeguard'));
        $this->assertGreaterThan(0, $start);
        $this->assertGreaterThan($start, $end, 'The disclaimer follows the findings section.');
        $section = substr($html, $start, $end - $start);
        $this->assertStringContainsString('table table-sm mb-0', $section, 'The findings table is rendered.');
        $this->assertStringContainsString('8.1.0', $section, 'The site wide finding is listed.');

        // Seven findings ask for seven different actions: three lead the
        // overview, the other four wait behind the collapse. The link counts
        // what it reveals, so four, not seven. Applicable actions link to
        // Moodle's existing core administration pages.
        $this->assertStringContainsString(
            get_string('showallactions', 'tool_upgradeguard', (object) ['count' => 4, 'noun' => 'actions']),
            $html
        );
        $this->assertStringNotContainsString(
            get_string('showallactions', 'tool_upgradeguard', (object) ['count' => 7, 'noun' => 'actions']),
            $html,
            'The collapse must not count the three actions that are already visible.'
        );
        // The control names the action that closes the list again, because
        // Bootstrap shows and hides the rows without ever rewriting its label.
        $this->assertStringContainsString(
            get_string('hideactions', 'tool_upgradeguard', (object) ['count' => 4, 'noun' => 'actions']),
            $html
        );
        $this->assertStringContainsString('/admin/tool/installaddon/index.php', $html);
        $this->assertStringContainsString('/admin/plugins.php', $html);
        $this->assertStringContainsString('data-bs-toggle="collapse"', $html);
        $this->assertStringContainsString('id="ug-rest-actions"', $html);
        $this->assertStringContainsString(
            'href="' . (new moodle_url('/admin/tool/installaddon/index.php'))->out(false) . '"',
            $html
        );
        $this->assertStringContainsString(
            'href="' . (new moodle_url('/admin/plugins.php'))->out(false) . '"',
            $html
        );
        // A stored scan has an environment snapshot, so the card is rendered.
        $this->assertStringContainsString('upgradeguard-environment', $html);
    }

    /**
     * A site without scans still gets a page without broken links.
     */
    public function test_the_page_without_any_scan_has_no_broken_links(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render(null);

        $this->assertStringNotContainsString('href=""', $html);
        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('[[', $html);
        $this->assertStringContainsString(get_string('scanresultsnone', 'tool_upgradeguard'), $html);
        // The Plugins tab explains the state instead of offering a results page
        // that has nothing to show yet.
        $this->assertStringNotContainsString(get_string('pluginspagetext', 'tool_upgradeguard'), $html);
        $this->assertStringNotContainsString(get_string('viewallplugins', 'tool_upgradeguard'), $html);
        // The environment card needs a stored scan, so it is replaced by an
        // explanation instead of rendering an empty table.
        $this->assertStringNotContainsString('upgradeguard-environment', $html);
        $this->assertStringContainsString(get_string('environmentnone', 'tool_upgradeguard'), $html);
        // Both pages belong to a scan, so no tab may offer them in this state.
        $this->assertStringNotContainsString('results.php', $html);
        $this->assertStringNotContainsString('history.php', $html);
    }

    /**
     * A site without scans must still be able to start the first one.
     *
     * The empty state says "start one", and it used to do that without offering
     * a form: the start form was rendered only once a scan was stored, so a new
     * install had no way to produce the report the page is asking for.
     */
    public function test_a_site_without_scans_is_offered_the_start_scan_form(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        // The branch decides whether an upgrade target exists at all, and the
        // test site is already on the newest released one.
        $originalbranch = (int) $CFG->branch;
        $CFG->branch = 501;
        set_config('checkremote', 0, 'tool_upgradeguard');

        try {
            $html = $this->render(null);

            $this->assertStringContainsString(get_string('welcomeheading', 'tool_upgradeguard'), $html);
            $this->assertStringContainsString(s(get_string('welcomewhat', 'tool_upgradeguard')), $html);
            $this->assertStringContainsString(s(get_string('welcomedatalocal', 'tool_upgradeguard')), $html);
            // The form and the line that points at it, so the page never
            // promises a button it does not render.
            $this->assertStringContainsString(s(get_string('welcomestartscan', 'tool_upgradeguard')), $html);
            $this->assertStringContainsString('value="Run scan"', $html);
            // The welcome text is real content, not a leftover template tag.
            $this->assertStringNotContainsString('{{', $html);
        } finally {
            $CFG->branch = $originalbranch;
        }
    }

    /**
     * Do not tell administrators to start a scan when there is no target or permission.
     */
    public function test_empty_state_only_prompts_users_who_can_start_a_scan(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        $originalbranch = (int) $CFG->branch;
        set_config('checkremote', 0, 'tool_upgradeguard');

        try {
            $CFG->branch = 503;
            $notargethtml = $this->render(null);
            $this->assertStringNotContainsString(get_string('noscansyet', 'tool_upgradeguard'), $notargethtml);
            $this->assertStringContainsString(get_string('scanresultsnone', 'tool_upgradeguard'), $notargethtml);
            $this->assertStringContainsString(get_string('notarget', 'tool_upgradeguard', (object) [
                'version' => '5.3',
                'datasetversion' => (new target_repository())->get_dataset_version(),
            ]), $notargethtml);
            $this->assertStringNotContainsString('value="Run scan"', $notargethtml);

            $CFG->branch = 501;
            $noaccesshtml = $this->render(null, false);
            $this->assertStringNotContainsString(get_string('noscansyet', 'tool_upgradeguard'), $noaccesshtml);
            $this->assertStringContainsString(get_string('scanresultsnone', 'tool_upgradeguard'), $noaccesshtml);
            $this->assertStringNotContainsString(get_string('welcomestartscan', 'tool_upgradeguard'), $noaccesshtml);
            $this->assertStringNotContainsString('value="Run scan"', $noaccesshtml);
        } finally {
            $CFG->branch = $originalbranch;
        }
    }

    /**
     * The claim about staying on this site has to follow the update check.
     *
     * With the check on, a scan does ask moodle.org, so the page must not tell
     * the administrator that nothing leaves the site.
     */
    public function test_the_local_data_claim_follows_the_update_check_setting(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('checkremote', 1, 'tool_upgradeguard');

        $html = $this->render(null);

        $this->assertStringContainsString(s(get_string('welcomedataremote', 'tool_upgradeguard')), $html);
        $this->assertStringNotContainsString(s(get_string('welcomedatalocal', 'tool_upgradeguard')), $html);
    }

    /**
     * A scan whose plugins already sit in the target layout keeps the Files tab
     * short: the summary and the success line, without the table and the CSV
     * that would list identical paths on both sides.
     */
    public function test_a_scan_with_nothing_to_move_keeps_the_files_tab_short(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $scanid = $this->seed_scan();
        // The only plugin already sits in the layout the target expects.
        $this->seed_plugin($scanid, 'mod_x', 'ready', 3, '/public/mod/x');

        $html = $this->render($scanid);

        $this->assertStringContainsString(get_string('movenocommands', 'tool_upgradeguard'), $html);
        $this->assertStringNotContainsString(get_string('movecol_current', 'tool_upgradeguard'), $html);
        $this->assertStringNotContainsString(get_string('movecsv', 'tool_upgradeguard'), $html);
        $this->assertStringNotContainsString('component,type,current_path,new_path', $html);
    }

    /**
     * The page must never build a form that the template will not print.
     *
     * Rendering a moodleform adds a core_form/changechecker watchFormById() call
     * for that form's element id. The overview only prints the scan form when
     * the user may run a scan and the dataset offers a newer release, so
     * building the form unconditionally left the browser watching a <form> that
     * was never sent, and the change checker threw "Cannot read properties of
     * null (reading 'closest')" on every load of a site that is already up to
     * date.
     *
     * The dashboard template is rendered directly by the other tests in this
     * file, so this checks the page script itself: the form has to be built
     * inside a condition that also decides whether it is printed.
     */
    public function test_index_only_builds_the_form_when_it_prints_it(): void {
        global $CFG;

        $index = file_get_contents($CFG->dirroot . '/admin/tool/upgradeguard/index.php');
        $this->assertNotEmpty($index, 'The dashboard page script could not be read.');

        // The build has to be guarded, and the guard has to be the same one the
        // template uses, so an unprinted form can never be rendered.
        $this->assertMatchesRegularExpression(
            '/if \(\$canrunscan && \$hastargets\) \{\s*\$form = new scan_form/',
            $index,
            'The scan form is built outside the condition that decides whether it is printed.'
        );

        // Rendering must not happen on its own, outside that same block.
        $this->assertSame(
            1,
            preg_match_all('/new scan_form/', $index),
            'The scan form is built more than once.'
        );
    }

    /**
     * A site that is already up to date gets a readable explanation, not a raw
     * language key and not a disabled control.
     */
    public function test_the_no_target_notice_is_readable(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();

        $context = (new dashboard(
            null,
            [],
            ['' => []],
            [],
            [],
            '',
            5,
            true,
            true,
            0,
            null
        ))->export_for_template($PAGE->get_renderer('tool_upgradeguard'));

        $this->assertFalse($context['hastargets'], 'This site has no newer release, so hastargets must be false.');
        $this->assertArrayHasKey('notargetmessage', $context);
        $this->assertStringNotContainsString('[[', $context['notargetmessage']);
        $this->assertStringContainsString('5.2', $context['notargetmessage']);
    }

    /**
     * No rendered state may show a raw language key to the reader.
     *
     * Moodle answers an unknown language string with the literal text [[key]]
     * rather than with an error, and it reports that only as a developer
     * debugging message. On the dashboard that reached a reader as the notice
     * "[[notarget]]" and it looked like a broken tool rather than a stale
     * cache, and the matching key test in this file only covers that one
     * string.
     *
     * This renders the states the overview actually has and checks the whole
     * document, so any unresolved string on the page fails here: a key that
     * was renamed, a key that only some states use, or a key that a partial
     * asks for and the context does not supply.
     *
     * @covers \tool_upgradeguard\output\dashboard::export_for_template
     */
    public function test_no_state_of_the_dashboard_shows_a_raw_language_key(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // A site with no scan at all, and a site with a finished scan, are the
        // two shapes the overview takes. Both have to be free of [[key]].
        //
        // The empty site is rendered before the scan is stored, because
        // render(null) falls back to the latest stored scan. Seeding first
        // would make both states render the same page and leave the empty
        // state untested.
        $rendered = ['no scan' => $this->render(null)];

        $scanid = $this->seed_scan();
        $this->seed_plugin($scanid, 'mod_x', 'blocker', 3, '/mod/x');
        $rendered['finished scan'] = $this->render($scanid);

        foreach ($rendered as $where => $html) {
            $this->assertMatchesRegularExpression(
                '/\S/',
                $html,
                'The dashboard should render something in the "' . $where . '" state.'
            );
            // Moodle writes an unknown string as [[key]], and a plugin string as
            // [[component_key]]. The doubled bracket is what makes this safe: a
            // form control such as name="checklist[backup]" carries a single
            // bracket and is not a missing string.
            $matched = preg_match('/\[\[[a-z0-9_:]+(,[^\]]*)?\]\]/i', $html, $found);
            $this->assertSame(
                0,
                $matched,
                'The dashboard shows a raw language key in the "' . $where . '" state: '
                    . ($matched ? substr($found[0], 0, 200) : '')
            );
        }
    }
}
