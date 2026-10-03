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
 * UG-P7-006: an in-process screen review of every page the tool has.
 *
 * Every other test in this plugin reads the context a class exports, or asserts
 * one contract of one template. This file asks the question an administrator
 * asks instead: does the page actually work?
 *
 * Each page is reached the way a browser reaches it, by requiring the page
 * controller itself, and the HTML that comes back is audited for the four things
 * a page can be quietly wrong about:
 *
 * - a missing language string, which Moodle prints as the literal text [[key]]
 *   rather than as an error;
 * - a PHP warning, notice or exception raised while the page is being built;
 * - a link that goes nowhere, either an empty href or a URL whose file does not
 *   exist on disk;
 * - a table or a finding that shows the wrong data, or none at all.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use advanced_testcase;
use tool_upgradeguard\local\csv_report;
use tool_upgradeguard\local\export_payload;
use tool_upgradeguard\local\repository\scan_repository;
use tool_upgradeguard\local\target;

/**
 * Every page, rendered and audited.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class screen_audit_test extends advanced_testcase {
    /**
     * The target every fixture is scanned against: 5.2, which reads plugins from
     * the public directory, so the move list has something to say.
     *
     * @return target
     */
    private function target(): target {
        return new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified', [
            'php' => [
                'source' => 'https://moodledev.io/general/releases/5.2',
                'verified' => true,
            ],
            'database' => [
                'source' => 'https://moodle.org/environment',
                'verified' => true,
                'exercised' => ['mysql'],
                'minimums' => ['mysql' => '8.0'],
            ],
            'extensions' => [
                'source' => 'https://moodle.org/environment',
                'verified' => true,
                'required' => ['iconv', 'mbstring'],
                'optional' => [],
            ],
        ], 'https://moodledev.io/general/releases');
    }

    /**
     * Store one finished scan with a full environment snapshot.
     *
     * @param array $overrides Column values to override.
     * @return int The scan id.
     */
    private function seed_scan(array $overrides = []): int {
        global $DB, $USER;

        $scanid = (new scan_repository())->create_queued_scan(
            $this->target(),
            '5.1 (Build: 20250420)',
            501,
            3,
            (int) $USER->id,
            [
                'php' => '8.3.30',
                'dbvendor' => 'mysql',
                'dbversion' => '8.0.32',
                'extensions' => ['iconv', 'mbstring', 'sodium'],
            ]
        );

        $DB->update_record('tool_upgradeguard_scan', (object) array_merge([
            'id' => $scanid,
            'status' => 'finished',
            'score' => 63,
            'verdict' => 'careful',
            'plugincount' => 3,
            'blockercount' => 1,
            'cautioncount' => 1,
            'unknowncount' => 0,
            'environmentblockercount' => 2,
            'environmentcautioncount' => 0,
            'timefinished' => time(),
        ], $overrides));

        return $scanid;
    }

    /**
     * Store one scanned plugin.
     *
     * @param int $scanid The scan.
     * @param string $component Frankenstyle component name.
     * @param string $plugintype Plugin type, eg "mod".
     * @param string $status Stored status.
     * @param int|null $usagecount Usage count, null when it could not be measured.
     * @param int $updateavailable Whether an update is available.
     * @return int The plugin id.
     */
    private function seed_plugin(
        int $scanid,
        string $component,
        string $plugintype,
        string $status,
        ?int $usagecount,
        int $updateavailable = 0,
    ): int {
        global $DB;

        $path = str_replace('_', '/', $component);
        $shortname = substr($path, (int) strrpos($path, '/') + 1);

        return $DB->insert_record('tool_upgradeguard_plugin', (object) [
            'scanid' => $scanid,
            'component' => $component,
            'plugintype' => $plugintype,
            'name' => $shortname,
            'displayname' => ucfirst($plugintype) . ' ' . $shortname,
            'versiondisk' => '2026010100',
            'versiondb' => '2026010100',
            'pluginrelease' => '1.0.0',
            'isstandard' => 0,
            'installed' => 1,
            'currentpath' => '/' . $path,
            'newpath' => '/public/' . $path,
            'status' => $status,
            'usagecount' => $usagecount,
            'updateavailable' => $updateavailable,
            'timecreated' => time(),
        ]);
    }

    /**
     * Store one finding.
     *
     * @param int $scanid The scan.
     * @param int $pluginid The plugin, 0 for a site wide finding.
     * @param string $checkkey The check that produced it.
     * @param string $severity blocker, caution or info.
     * @param string $messagekey Message string key.
     * @param array $params Message and action parameters.
     * @param string $actionkey Action string key.
     * @return void
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
            'params' => json_encode($params),
            'confidence' => 'high',
            'fixedbyupdate' => 0,
            'actionkey' => $actionkey,
            'docsurl' => 'https://moodle.org/plugins',
            'timecreated' => time(),
        ]);
    }

    /**
     * A finished scan with three plugins, findings and two site wide blockers.
     *
     * One scenario is used by every page, so the pages can be compared with each
     * other: the same three plugins have to appear on the results page and in the
     * report, and the same two environment blockers have to reach the environment
     * card, the report and the checklist.
     *
     * @return int The scan id.
     */
    private function seed_scenario(): int {
        $scanid = $this->seed_scan();

        $forum = $this->seed_plugin($scanid, 'mod_forum', 'mod', 'blocker', 3);
        $legacy = $this->seed_plugin($scanid, 'local_legacy', 'local', 'caution', 0);
        $this->seed_plugin($scanid, 'mod_page', 'mod', 'ready', 12);

        $this->seed_finding(
            $scanid,
            $forum,
            'public_location',
            'blocker',
            'finding_legacy_location',
            ['oldpath' => 'mod/forum', 'newpath' => 'public/mod/forum'],
            'action_move_plugin_to_public'
        );
        $this->seed_finding(
            $scanid,
            $legacy,
            'usage_check',
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
            ['php' => '8.1.0', 'target' => '5.2', 'phpmin' => '8.3.0'],
            'action_upgrade_php'
        );
        $this->seed_finding(
            $scanid,
            0,
            'environment_database',
            'blocker',
            'finding_environment_database_too_old',
            ['vendor' => 'mysql', 'current' => '8.0.32', 'target' => '5.2', 'minimum' => '8.0'],
            'action_upgrade_database'
        );

        return $scanid;
    }

    // MARK: Rendering a page the way a browser reaches it.

    /**
     * Run one page controller and return the HTML it sends.
     *
     * This is the strongest in-process check available without a web server: the
     * page's own code runs, including the capability checks, the redirects it
     * would issue and the $OUTPUT header and footer, so a page that throws is
     * caught here rather than in a browser. The output is buffered, because the
     * page prints rather than returns.
     *
     * @param string $page File name inside the plugin root, eg "results.php".
     * @param array $get Query parameters the browser would send.
     * @return string The HTML the page produced.
     */
    private function render_page(string $page, array $get = []): string {
        // The page files are written for the global scope of a web request, so the
        // globals they read are made available here rather than created empty:
        // config.php has already run under the PHPUnit bootstrap and will not run
        // a second time, so a bare require() would leave $CFG undefined and the
        // page would fail on its very first line.
        global $CFG, $COURSE, $DB, $OUTPUT, $PAGE, $SCRIPT, $SITE, $USER;

        $savedget = $_GET;
        $savedrequest = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $savedscript = $SCRIPT;
        $_GET = $get;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $SCRIPT = '/admin/tool/upgradeguard/' . $page;

        // Every page calls admin_externalpage_setup(), which configures the page
        // from the admin tree. A page object left over from a previous render
        // would carry its old context and heading into this one.
        $PAGE = new \moodle_page();
        $PAGE->set_context(\context_system::instance());

        ob_start();
        try {
            require(__DIR__ . '/../' . $page);
        } finally {
            $html = (string) ob_get_clean();
            $_GET = $savedget;
            $_SERVER['REQUEST_METHOD'] = $savedrequest;
            $SCRIPT = $savedscript;
        }

        return $html;
    }

    /**
     * The settings page, built the way the admin tree builds it.
     *
     * settings.php is not a page a browser requests directly: the admin tree
     * includes it and calls add() on the "tools" node. A real admin_category
     * stands in for that node here, so the file adds its page exactly as it does
     * in production and the settings that end up on it are the ones an
     * administrator is shown. A stand-in object with only an add() method is not
     * enough: a setting renders itself through admin_get_root() to find its
     * parent group, so it has to be a real node.
     *
     * @return string The rendered settings markup.
     */
    private function render_settings(): string {
        global $ADMIN, $CFG, $hassiteconfig;

        $savedadmin = $ADMIN ?? null;
        $savedconfig = $hassiteconfig ?? null;

        require_once($CFG->dirroot . '/lib/adminlib.php');
        $tools = new \admin_category('tools', new \lang_string('tools', 'core_admin'));
        $ADMIN = $tools;
        $hassiteconfig = true;

        require(__DIR__ . '/../settings.php');

        $ADMIN = $savedadmin;
        $hassiteconfig = $savedconfig;

        $page = null;
        foreach ($tools->get_children() as $child) {
            if ($child instanceof \admin_settingpage && $child->name === 'tool_upgradeguard_settings') {
                $page = $child;
            }
        }
        $this->assertInstanceOf(
            \admin_settingpage::class,
            $page,
            'settings.php added no settings page to the admin tree.'
        );

        $html = '';
        foreach ($page->settings as $setting) {
            $html .= $setting->output_html($setting->get_setting());
        }

        return $html;
    }

    // MARK: The four audits.

    /**
     * Audit 1: no unresolved language string anywhere in the page.
     *
     * Moodle answers an unknown string with the literal text [[key]] instead of
     * an error, and only reports it as a developer debugging message, so a
     * mistyped key reaches an administrator as a broken looking notice. The
     * doubled bracket is what makes the match safe: a form control such as
     * name="checklist[backup]" carries a single bracket and is not a missing
     * string.
     *
     * @param string $page Page name, used in the failure message.
     * @param string $html The rendered page.
     * @return void
     */
    private function assert_no_missing_string(string $page, string $html): void {
        $matched = preg_match('/\[\[[a-z0-9_]+(,[^\]]*)?\]\]/i', $html, $found);
        $this->assertSame(
            0,
            $matched,
            $page . ' shows a raw language key: ' . ($matched ? substr($found[0], 0, 200) : '')
        );
    }

    /**
     * Audit 3: no link on the page points at a file that does not exist.
     *
     * A broken link is invisible in a rendered string test and obvious in a
     * browser, which is why it needs a real filesystem check rather than a
     * pattern match. Only local URLs are resolved; an external address, a
     * fragment and a mailto: link are left alone because this install cannot
     * speak for them.
     *
     * @param string $page Page name, used in the failure message.
     * @param string $html The rendered page.
     * @return void
     */
    private function assert_no_broken_link(string $page, string $html): void {
        global $CFG;

        if (!preg_match_all('/\b(?:href|src)="([^"]*)"/i', $html, $matches)) {
            return;
        }

        $checked = 0;
        foreach ($matches[1] as $url) {
            $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');

            if (trim($url) === '') {
                $this->fail($page . ' has a link with an empty href');
            }
            if (str_starts_with($url, '#') || str_starts_with($url, 'mailto:')) {
                continue;
            }
            if (str_starts_with($url, '//') || str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                // An absolute address on another host. The dataset cites
                // moodledev.io and moodle.org, which this install cannot verify.
                if (str_starts_with($url, $CFG->wwwroot)) {
                    $url = substr($url, strlen($CFG->wwwroot));
                } else {
                    continue;
                }
            }

            $path = parse_url($url, PHP_URL_PATH);
            if (!is_string($path) || $path === '' || !str_starts_with($path, '/')) {
                continue;
            }
            // A directory URL is served by its index.php.
            if (str_ends_with($path, '/')) {
                $path .= 'index.php';
            }

            $file = $CFG->dirroot . $path;
            $checked++;
            $this->assertFileExists(
                $file,
                $page . ' links to ' . $url . ', which does not exist on disk'
            );
        }

        $this->assertGreaterThan(0, $checked, $page . ' produced no local link to check at all');
    }

    /**
     * The visible text of the page, with the markup and scripts removed.
     *
     * Assertions about data are made against text, because a plugin name that
     * only appears inside an attribute is not shown to the reader.
     *
     * @param string $html The rendered page.
     * @return string
     */
    private function text_of(string $html): string {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $text = strip_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    /**
     * Every anchor and form target of the page, for the delete action checks.
     *
     * @param string $html The rendered page.
     * @return string[]
     */
    private function urls_in(string $html): array {
        preg_match_all('/\b(?:href|action)="([^"]*)"/i', $html, $matches);

        return array_map(
            static fn(string $url): string => html_entity_decode($url, ENT_QUOTES, 'UTF-8'),
            $matches[1]
        );
    }

    // MARK: The pages.

    /**
     * The dashboard, with all six tabs, renders and shows the recorded scan.
     *
     * The dashboard is the one page that is not reached through its own file in
     * this test: index.php queues a scan and deletes one before it renders, and
     * it builds the scan form, so it is rendered through the same code the
     * browser runs by requiring the file itself.
     */
    public function test_the_dashboard_renders_without_a_missing_key_or_a_broken_link(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->seed_scenario();

        $html = $this->render_page('index.php');

        $this->assertMatchesRegularExpression('/\S/', $html, 'The dashboard rendered nothing.');
        $this->assert_no_missing_string('The dashboard', $html);
        $this->assert_no_broken_link('The dashboard', $html);

        // Audit 4: the numbers the overview shows are the ones that were stored.
        $text = $this->text_of($html);
        $this->assertStringContainsString('5.1 (Build: 20250420)', $text);
        $this->assertStringContainsString('5.2', $text);
        $this->assertStringContainsString('63', $text, 'The stored readiness score is missing.');
        $this->assertStringContainsString('Careful', $text, 'The stored verdict is missing.');

        // Both site wide blockers have to reach the overview, not just the score.
        $this->assertStringContainsString('8.1.0', $text, 'The PHP blocker is missing from the overview.');
        $this->assertStringContainsString('8.0.32', $text, 'The database blocker is missing from the overview.');
    }

    /**
     * The results page lists every plugin, with its status and its findings.
     *
     * Filters, sorting and the reset link are part of the same screen, so they are
     * checked here too: a filter that is present but does not narrow the table is
     * a page that looks right and does nothing.
     */
    public function test_the_results_page_shows_every_scanned_plugin_and_narrows_when_filtered(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $scanid = $this->seed_scenario();

        $html = $this->render_page('results.php', ['id' => $scanid]);

        $this->assert_no_missing_string('The results page', $html);
        $this->assert_no_broken_link('The results page', $html);

        // Audit 4: every stored plugin appears, with its own status.
        $text = $this->text_of($html);
        $this->assertStringContainsString('mod_forum', $text);
        $this->assertStringContainsString('local_legacy', $text);
        $this->assertStringContainsString('mod_page', $text);
        $this->assertStringContainsString(
            get_string('status_blocker', 'tool_upgradeguard'),
            $text,
            'The blocker status label is missing.'
        );
        $this->assertStringContainsString(
            get_string('status_ready', 'tool_upgradeguard'),
            $text,
            'The ready status label is missing.'
        );
        $this->assertStringContainsString('12', $text, 'The recorded usage of mod_page is missing.');

        // The blocker finding of mod_forum has to be readable on the page, not
        // only counted: the message names the two paths it is about.
        $this->assertStringContainsString('mod/forum', $text);
        $this->assertStringContainsString('public/mod/forum', $text);

        // A filter that matches one plugin must hide the other two.
        $filtered = $this->render_page('results.php', ['id' => $scanid, 'status' => 'blocker']);
        $filteredtext = $this->text_of($filtered);
        $this->assertStringContainsString('mod_forum', $filteredtext);
        $this->assertNotContains(
            'local_legacy',
            $this->visible_component_names($filtered),
            'Filtering by a blocker still shows a plugin that is only a caution.'
        );

        // A search that matches nothing has to say so rather than show the lot.
        $empty = $this->render_page('results.php', ['id' => $scanid, 'search' => 'nosuchplugin']);
        $this->assertStringContainsString(
            get_string('noresults', 'tool_upgradeguard'),
            $this->text_of($empty),
            'A search with no match does not show the empty state.'
        );
    }

    /**
     * The component names of the rows a results page rendered.
     *
     * The component is printed in a muted line under the plugin name, so this
     * reads the table rather than the whole page: an unrelated link or a filter
     * option must not be mistaken for a row.
     *
     * @param string $html The rendered results page.
     * @return string[]
     */
    private function visible_component_names(string $html): array {
        preg_match_all('#<div class="text-muted small">([a-z0-9_]+)</div>#', $html, $matches);

        return $matches[1];
    }

    /**
     * The report carries the blockers, the plugin groups and the environment.
     *
     * The report is the artefact an administrator keeps, so it is also the page
     * that has to survive being read without the theme: it is rendered here
     * through its own controller, print rules included.
     */
    public function test_the_report_shows_the_blockers_the_plugins_and_the_environment(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $scanid = $this->seed_scenario();

        $html = $this->render_page('report.php', ['id' => $scanid]);

        $this->assert_no_missing_string('The report', $html);
        $this->assert_no_broken_link('The report', $html);

        $text = $this->text_of($html);
        $this->assertStringContainsString('63', $text, 'The readiness score is missing from the report.');
        $this->assertStringContainsString('Careful', $text);
        $this->assertStringContainsString('mod_forum', $text);
        $this->assertStringContainsString('local_legacy', $text);

        // Both site wide blockers belong in the blockers table.
        $this->assertStringContainsString('8.1.0', $text, 'The PHP blocker is missing from the report.');
        $this->assertStringContainsString('8.0.32', $text, 'The database blocker is missing from the report.');

        // The environment card of the report shows the recorded values.
        $this->assertStringContainsString('8.3.30', $text, 'The recorded PHP version is missing.');
        $this->assertStringContainsString('mysql', $text);

        // The move list is only meaningful for a target that uses /public, which
        // this one does, so the paths have to be on the report.
        $this->assertStringContainsString('public/mod/forum', $text);

        // The checklist is built from the scan, so it has to be there with its
        // stored counts rather than an empty card.
        $this->assertStringContainsString(
            get_string('cl_backup', 'tool_upgradeguard'),
            $text,
            'The checklist is missing from the report.'
        );
    }

    /**
     * The history page lists the scans and offers delete only as a POST.
     *
     * Delete is the only destructive action in the plugin, so the screen is
     * checked for the two things that make it safe: a confirmed POST form with a
     * session key, and no link anywhere that could delete from a GET.
     */
    public function test_the_history_page_lists_the_scans_and_deletes_only_by_post(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $scanid = $this->seed_scenario();
        $this->seed_scan(['status' => 'queued', 'timecreated' => time() - 3600]);

        $html = $this->render_page('history.php');

        $this->assert_no_missing_string('The history page', $html);
        $this->assert_no_broken_link('The history page', $html);

        // Audit 4: both recorded scans appear, with their own status.
        $text = $this->text_of($html);
        $this->assertStringContainsString(get_string('historystatus_finished', 'tool_upgradeguard'), $text);
        $this->assertStringContainsString(get_string('historystatus_queued', 'tool_upgradeguard'), $text);
        $this->assertStringContainsString('63', $text, 'The stored score is missing from the history.');
        $this->assertStringContainsString(
            fullname(get_admin()),
            $text,
            'The owner of the scan is missing from the history.'
        );

        // The delete control is a form, not a link, and it carries a sesskey.
        $this->assertMatchesRegularExpression(
            '/<form[^>]*method="post"[^>]*>.*?name="sesskey"/s',
            $html,
            'The history page has no confirmed POST delete form with a session key.'
        );
        foreach ($this->urls_in($html) as $url) {
            $this->assertStringNotContainsStringIgnoringCase(
                'deletescan',
                $url,
                'The history page offers a delete URL that a GET could follow.'
            );
        }
    }

    /**
     * The export answers both formats, and neither one can run a formula.
     *
     * The export is a download rather than a page, so it is exercised through the
     * payload and the CSV it produces rather than through its controller, which
     * would send headers and end the request.
     */
    public function test_the_export_produces_both_formats_and_stays_inert(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $scanid = $this->seed_scenario();

        $repository = new scan_repository();
        $scan = $repository->get_scan($scanid);
        $target = $this->target();
        $plugins = $repository->get_plugins_for_scan($scanid);
        $findings = $repository->get_findings_grouped($scanid);

        $payload = export_payload::build($target, $scan, $plugins, $findings, '');
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded, 'The JSON export could not be encoded.');
        $decoded = json_decode($encoded, true);
        $this->assertSame(3, $decoded['scan']['plugincount'], 'The JSON export miscounts the plugins.');
        $this->assertCount(2, $decoded['sitefindings'], 'The JSON export drops a site wide finding.');
        $this->assert_no_missing_string('The JSON export', $encoded);

        $csv = csv_report::build($plugins, $findings);
        $this->assertNotEmpty($csv['columns'], 'The CSV export has no columns.');

        // One row per plugin, plus a row per finding, and no row for a plugin
        // that has none: a plugin missing from the report is a plugin the
        // administrator cannot see at all.
        $this->assertGreaterThanOrEqual(
            count($plugins),
            count($csv['rows']),
            'The CSV export has fewer rows than there are plugins.'
        );

        // A cell that begins with =, +, - or @ is executed by a spreadsheet when
        // the file is opened, so the exporter has to neutralise it. The fixtures
        // carry no such value, so one is added to prove the exporter handles it.
        $hostile = clone reset($plugins);
        $hostile->displayname = '=cmd|/c calc';
        $hostilecsv = csv_report::build([$hostile], $findings);
        $flat = implode(' ', array_map('strval', array_merge(...array_values($hostilecsv['rows']))));
        $this->assertStringNotContainsString(
            '=cmd',
            $flat,
            'A CSV cell is left executable as a spreadsheet formula.'
        );
    }

    /**
     * The settings page renders every setting with a readable name.
     *
     * A setting whose name or description string is missing renders as [[key]]
     * beside an input box, which reads as a broken form rather than as a missing
     * translation, so the rendered markup is what is checked here.
     */
    public function test_the_settings_page_renders_every_setting_readably(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_settings();

        $this->assert_no_missing_string('The settings page', $html);
        $this->assert_no_broken_link('The settings page', $html);

        // Every setting the file registers has to reach the page. Moodle names the
        // input s_<plugin>_<setting> and the row around it admin-<setting>, so the
        // form control is what is looked for: a setting that never rendered has
        // no input at all.
        foreach ([
            'weightblocker',
            'weightcaution',
            'weightunknown',
            'weightupdate',
            'weighttheme',
            'weightauth',
            'usagemultiplierused',
            'usagemultiplierunused',
            'usagemultiplierunknown',
            'stopthreshold',
            'gothreshold',
            'checkremote',
            'remotecachettl',
            'retentiondays',
            'supportemail',
        ] as $setting) {
            $this->assertStringContainsString(
                'name="s_tool_upgradeguard_' . $setting,
                $html,
                'tool_upgradeguard/' . $setting . ' is registered but not rendered'
            );
        }

        // Every setting carries a label an administrator can read, and every
        // number carries the sentence that says what the number does.
        foreach ([
            get_string('weightblocker', 'tool_upgradeguard'),
            get_string('weightblocker_desc', 'tool_upgradeguard'),
            get_string('gothreshold_desc', 'tool_upgradeguard'),
            get_string('supportemail_desc', 'tool_upgradeguard'),
            get_string('usagemultiplier_desc_used', 'tool_upgradeguard'),
            get_string('usagemultiplier_desc_unused', 'tool_upgradeguard'),
            get_string('usagemultiplier_desc_unknown', 'tool_upgradeguard'),
            get_string('remotecachettl_desc', 'tool_upgradeguard'),
        ] as $text) {
            $this->assertStringContainsString($text, $html, 'A setting name or description is missing.');
        }

        // A heading explains each group, so an administrator knows what a number
        // in front of it means.
        $this->assertStringContainsString(get_string('scoreheading', 'tool_upgradeguard'), $html);
        $this->assertStringContainsString(get_string('thresholdsheading', 'tool_upgradeguard'), $html);
    }

    /**
     * No page of the plugin produces a PHP warning or an uncaught exception.
     *
     * PHPUnit converts a notice or a warning into a test failure on its own, and
     * an uncaught exception ends the test, so rendering every page in one test is
     * what turns "this page throws in developer mode" into a red run instead of a
     * surprise in a browser. The dashboard and the empty state are both included,
     * because the empty state builds the scan form and the overview does not.
     */
    public function test_no_page_raises_a_php_warning_or_an_exception(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // A site with no scan at all, which is the first screen a new install
        // shows and the state the scan form is built in.
        $this->render_page('index.php');

        $scanid = $this->seed_scenario();

        foreach (['index.php', 'results.php', 'report.php', 'history.php'] as $page) {
            $get = $page === 'history.php' ? [] : ['id' => $scanid];
            $html = $this->render_page($page, $get);
            $this->assertMatchesRegularExpression(
                '/\S/',
                $html,
                $page . ' produced no output at all, which means it failed early.'
            );
        }

        $this->assertMatchesRegularExpression(
            '/\S/',
            $this->render_settings(),
            'The settings page produced no output at all.'
        );
    }
}
