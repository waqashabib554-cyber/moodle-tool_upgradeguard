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
 * Cross page UI contracts: every button, every link and the shared styling.
 *
 * The unit tests check what one class exports. This file renders all four
 * screens through the real mustache engine and audits the HTML a browser
 * receives, because the defects it looks for are only visible in the rendered
 * markup: a button style that drifted onto one page only, a class the
 * stylesheet keys on that the template puts on the wrong element, or a link
 * that opens a new tab without severing the opener.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use advanced_testcase;
use tool_upgradeguard\local\action_planner;
use tool_upgradeguard\local\repository\scan_repository;
use tool_upgradeguard\local\results_filters;
use tool_upgradeguard\local\target;
use tool_upgradeguard\local\target_repository;
use tool_upgradeguard\output\dashboard;
use tool_upgradeguard\output\history;
use tool_upgradeguard\output\report;
use tool_upgradeguard\output\results;

/**
 * Renders every screen and audits the buttons, links and shared styling.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ui_consistency_test extends advanced_testcase {
    /**
     * Store a finished scan.
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
            'plugincount' => 1,
            'blockercount' => 0,
            'cautioncount' => 1,
            'unknowncount' => 0,
            'environmentblockercount' => 0,
            'environmentcautioncount' => 0,
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
     * @return int The plugin id.
     */
    private function seed_plugin(int $scanid, string $component, string $status): int {
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
            'currentpath' => '/' . $path,
            'newpath' => '/public/' . $path,
            'status' => $status,
            'usagecount' => 0,
            'updateavailable' => 0,
            'timecreated' => time(),
        ]);
    }

    /**
     * Store one finding.
     *
     * @param int $scanid The scan.
     * @param int $pluginid The plugin, 0 for a site wide finding.
     * @param array $params Message and action parameters. Distinct params produce
     *      distinct actions, because the planner merges on the pair.
     * @return void
     */
    private function seed_finding(int $scanid, int $pluginid, array $params = []): void {
        global $DB;

        $DB->insert_record('tool_upgradeguard_finding', (object) [
            'scanid' => $scanid,
            'pluginid' => $pluginid,
            'checkkey' => 'site_php_check',
            'severity' => 'caution',
            'messagekey' => 'site_php_check',
            'params' => $params === [] ? null : json_encode($params),
            'confidence' => 'high',
            'fixedbyupdate' => 0,
            'actionkey' => 'action_upgrade_php',
            'docsurl' => null,
            'timecreated' => time(),
        ]);
    }

    /**
     * Render the dashboard the way index.php does.
     *
     * @param int|null $scanid The scan, null for a site without scans.
     * @param bool $canexport Whether the viewer may export.
     * @return string The rendered HTML.
     */
    private function render_dashboard(?int $scanid, bool $canexport = true): string {
        global $PAGE;

        $repository = new scan_repository();
        $targets = new target_repository();
        $scan = $scanid === null ? null : $repository->get_scan($scanid);
        $findings = $scan === null ? ['' => []] : $repository->get_findings_grouped((int) $scan->id);
        $plugins = $scan === null ? [] : $repository->get_plugins_for_scan((int) $scan->id);
        $target = $scan === null ? null : $targets->get_target((string) $scan->targetversion);

        $dashboard = new dashboard(
            $scan,
            $plugins,
            $findings,
            (new action_planner())->plan($findings),
            $scan === null ? [] : $repository->get_recent_scans(10),
            '<form action="#"><input type="submit" value="Run scan"></form>',
            $targets->get_dataset_version(),
            true,
            $canexport,
            0,
            $target
        );

        return $PAGE->get_renderer('tool_upgradeguard')->render_dashboard($dashboard);
    }

    /**
     * Render the results page the way results.php does.
     *
     * @param int $scanid The scan.
     * @param int $page The 1-based page number.
     * @return string The rendered HTML.
     */
    private function render_results(int $scanid, int $page = 1): string {
        global $PAGE;

        $repository = new scan_repository();
        $scan = $repository->get_scan($scanid);
        $target = (new target_repository())->get_target((string) $scan->targetversion);

        $filters = results_filters::normalise([
            'search' => '',
            'status' => '',
            'type' => '',
            'inuse' => 0,
            'page' => $page,
        ]);
        $sort = ['by' => 'status', 'dir' => 'asc'];

        // Results.php passes the total of the whole filtered set, not the rows
        // of the current page: the page count and the window both come from it.
        $total = $repository->count_plugins($scanid, $filters);
        $rows = $repository->search_plugins($scanid, $filters, $sort, $page);
        $findings = $repository->get_findings_for_components($scanid, array_column($rows, 'component'));

        $table = new results(
            $scan,
            $target,
            $filters,
            $sort,
            $rows,
            $findings,
            $total,
            $repository->get_plugin_types($scanid)
        );

        $renderer = $PAGE->get_renderer('tool_upgradeguard');

        return $renderer->render_from_template('tool_upgradeguard/results', $table->export_for_template($renderer));
    }

    /**
     * Render the history page the way history.php does.
     *
     * @param int $page The 1-based page number.
     * @param bool $canmanage Whether the viewer may delete scans.
     * @return string The rendered HTML.
     */
    private function render_history(int $page = 1, bool $canmanage = true): string {
        global $PAGE;

        $repository = new scan_repository();
        $total = $repository->count_scans();
        $rows = $repository->get_scans_page(
            scan_repository::RESULTS_PER_PAGE,
            ($page - 1) * scan_repository::RESULTS_PER_PAGE
        );

        $table = new history($rows, $total, $page, $canmanage);

        $renderer = $PAGE->get_renderer('tool_upgradeguard');

        return $renderer->render_from_template('tool_upgradeguard/history', $table->export_for_template($renderer));
    }

    /**
     * Render the printable report the way report.php does.
     *
     * @param int $scanid The scan.
     * @return string The rendered HTML.
     */
    private function render_report(int $scanid): string {
        global $PAGE, $SITE;

        $repository = new scan_repository();
        $scan = $repository->get_scan($scanid);
        $target = (new target_repository())->get_target((string) $scan->targetversion);

        $renderable = new report(
            $target,
            $scan,
            $repository->get_plugins_for_scan($scanid),
            $repository->get_findings_grouped($scanid),
            '',
            $SITE->fullname
        );

        $renderer = $PAGE->get_renderer('tool_upgradeguard');

        return $renderer->render_from_template('tool_upgradeguard/report', $renderable->export_for_template($renderer));
    }

    /**
     * Every screen a reviewer can reach, rendered from one seeded scan.
     *
     * @return array[] Page name and HTML.
     */
    private function screens(): array {
        $scanid = $this->seed_scan();
        $pluginid = $this->seed_plugin($scanid, 'mod_x', 'caution');
        $this->seed_finding($scanid, $pluginid);
        $this->seed_finding($scanid, 0);

        return [
            'dashboard' => $this->render_dashboard($scanid),
            'results' => $this->render_results($scanid),
            'history' => $this->render_history(),
            'report' => $this->render_report($scanid),
        ];
    }

    /**
     * Every styled button of a screen, with its full class attribute.
     *
     * @param string $html The rendered HTML.
     * @return string[] Class attributes, in document order.
     */
    private function buttons(string $html): array {
        preg_match_all('/<a\b[^>]*\bclass="([^"]*\bbtn\b[^"]*)"[^>]*>/i', $html, $anchors);
        preg_match_all('/<button\b[^>]*\bclass="([^"]*\bbtn\b[^"]*)"[^>]*>/i', $html, $buttons);

        return array_merge($anchors[1], $buttons[1]);
    }

    /**
     * Every button on every page is a small button.
     *
     * The plugin grew its action buttons one page at a time and the size was
     * never part of that decision: the dashboard exports, the plugins tab, the
     * history tab and the results filters had all drifted to a full height
     * button while the row buttons, the page header and the pagination were
     * small. A reviewer saw a different control for the same kind of action
     * depending on the screen, so the size is now asserted everywhere.
     */
    public function test_every_button_on_every_page_is_a_small_button(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        foreach ($this->screens() as $name => $html) {
            $buttons = $this->buttons($html);
            $this->assertNotEmpty($buttons, $name . ' should render at least one button');

            foreach ($buttons as $class) {
                $classes = preg_split('/\s+/', trim($class));
                $this->assertContains(
                    'btn-sm',
                    $classes,
                    $name . ' has a button that is not btn-sm: ' . $class
                );
            }
        }
    }

    /**
     * Every button uses one of the plugin's allowed button styles.
     *
     * A full colour or a warning coloured action would be a third style that
     * only one screen uses, so the allowed set is deliberately closed.
     */
    public function test_every_button_uses_an_allowed_button_style(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $allowed = ['btn-primary', 'btn-outline-secondary', 'btn-outline-danger'];

        foreach ($this->screens() as $name => $html) {
            foreach ($this->buttons($html) as $class) {
                $classes = preg_split('/\s+/', trim($class));

                $this->assertContains(
                    'btn',
                    $classes,
                    $name . ' has a styled element without the base btn class: ' . $class
                );

                $variants = array_values(array_filter($classes, static function (string $candidate) use ($allowed): bool {
                    return str_starts_with($candidate, 'btn-') && $candidate !== 'btn-sm';
                }));

                $this->assertNotEmpty($variants, $name . ' has a button with no style: ' . $class);

                foreach ($variants as $variant) {
                    $this->assertContains(
                        $variant,
                        $allowed,
                        $name . ' uses the unapproved button style ' . $variant
                    );
                }
            }
        }
    }

    /**
     * A link that opens a new tab severs the opener.
     *
     * Without rel="noopener" the printable report, which opens in a new tab from
     * both the dashboard and the history, can reach back through window.opener
     * and navigate this page out from under the reviewer.
     */
    public function test_every_new_tab_link_severs_the_opener(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $found = false;
        foreach ($this->screens() as $name => $html) {
            preg_match_all('/<a\b[^>]*\btarget="_blank"[^>]*>/i', $html, $matches);

            foreach ($matches[0] as $tag) {
                $found = true;
                $this->assertStringContainsString(
                    'rel="noopener"',
                    $tag,
                    $name . ' opens a new tab without rel="noopener": ' . $tag
                );
            }
        }

        $this->assertTrue($found, 'The printable report should be linked somewhere.');
    }

    /**
     * The row buttons sit inside the cell the stylesheet scopes its spacing to.
     *
     * styles.css gives .upgradeguard-actions .btn its gap. The class used to sit
     * on the header cell while the buttons sat in the body cell, so the
     * descendant selector never matched and the three row buttons rendered
     * flush against each other.
     */
    public function test_the_action_buttons_sit_inside_the_styled_action_column(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->seed_scan();
        $html = $this->render_history();

        $this->assertMatchesRegularExpression(
            '/<td class="upgradeguard-actions">\s*<a class="btn btn-outline-secondary btn-sm"/',
            $html,
            'The row buttons should sit inside the cell the stylesheet scopes its spacing to.'
        );

        $this->assertStringNotContainsString(
            '<th scope="col" class="upgradeguard-actions"',
            $html,
            'The header cell holds no buttons, so the class there styles nothing.'
        );

        $css = (string) file_get_contents(__DIR__ . '/../styles.css');
        $this->assertStringContainsString('.tool-upgradeguard .upgradeguard-actions .btn', $css);
    }

    /**
     * Every plugin class a template uses is defined by a stylesheet.
     *
     * A typo in one of the upgradeguard-* classes leaves the element unstyled
     * with nothing to show for it, so the classes used in the templates are
     * compared against the classes the two stylesheets actually define.
     */
    public function test_every_plugin_class_used_in_a_template_is_defined(): void {
        $defined = [];
        $sources = [
            (string) file_get_contents(__DIR__ . '/../styles.css'),
            (string) file_get_contents(__DIR__ . '/../templates/report.mustache'),
        ];

        foreach ($sources as $source) {
            preg_match_all('/\.([a-z0-9-]*upgradeguard[a-z0-9-]*)/i', $source, $matches);
            foreach ($matches[1] as $class) {
                $defined[$class] = true;
            }
        }

        $this->assertNotEmpty($defined, 'The stylesheets should define the plugin classes.');

        // The report scopes its rules to the container class it renders itself,
        // so the two always change together and it is not a drift risk.
        $selfscoped = [
            'tool-upgradeguard-report',
            'tool-upgradeguard',
            'tool-upgradeguard-results',
            'tool-upgradeguard-history',
        ];

        foreach (glob(__DIR__ . '/../templates/*.mustache') as $path) {
            $name = basename($path, '.mustache');
            $used = [];

            preg_match_all('/class="([^"]*)"/', (string) file_get_contents($path), $matches);
            foreach ($matches[1] as $class) {
                // A mustache condition leaves a fragment such as {{#active}}active,
                // and a placeholder leaves a prefix such as upgradeguard-verdict-
                // whose real name only exists after rendering.
                $class = preg_replace('/\{\{[^}]*\}\}/', ' ', $class);
                foreach (preg_split('/\s+/', trim((string) $class)) as $candidate) {
                    if (str_contains($candidate, 'upgradeguard')) {
                        $used[$candidate] = true;
                    }
                }
            }

            foreach (array_keys($used) as $class) {
                if (in_array($class, $selfscoped, true)) {
                    continue;
                }

                // A name built from a placeholder is completed at render time,
                // so the prefix is what the stylesheet has to define.
                if (str_ends_with($class, '-')) {
                    $prefix = rtrim($class, '-');
                    $this->assertArrayHasKey(
                        $prefix,
                        $defined,
                        $name . ' builds the class ' . $class . ' from a placeholder, '
                            . 'but no stylesheet defines the ' . $prefix . ' rules'
                    );
                    continue;
                }

                $this->assertArrayHasKey(
                    $class,
                    $defined,
                    $name . ' uses the class ' . $class . ', which no stylesheet defines'
                );
            }
        }
    }

    /**
     * The summary card on the dashboard carries a class the stylesheet styles.
     *
     * upgradeguard-summary is on the verdict card, so without a rule for it the
     * class is an empty hook: it suggests a hook that no stylesheet implements,
     * and a theme author who targets it has nothing to inherit.
     */
    public function test_the_dashboard_summary_card_class_is_styled(): void {
        $css = (string) file_get_contents(__DIR__ . '/../styles.css');

        $this->assertStringContainsString(
            '.tool-upgradeguard .upgradeguard-summary',
            $css,
            'The summary card class should be styled, not left as an empty hook'
        );
    }

    /**
     * Every screen that loads the shared stylesheet renders inside its container.
     *
     * styles.css scopes every rule to .tool-upgradeguard. A screen that renders
     * without it loses the heading spacing, the tab styling and the print rules
     * with no visible error, so this is asserted on the rendered HTML and not
     * only in the template source.
     */
    public function test_every_shared_stylesheet_page_renders_inside_the_container(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $screens = $this->screens();

        foreach (['dashboard', 'results', 'history'] as $name) {
            $this->assertStringContainsString(
                'class="tool-upgradeguard',
                $screens[$name],
                $name . ' should render inside the shared .tool-upgradeguard container'
            );
        }

        // The report is a page of this plugin like the others, so it wears the
        // shared container and picks up the shared rules.
        $this->assertStringContainsString('tool-upgradeguard-report', $screens['report']);
    }

    /**
     * No screen prints a heading of its own.
     *
     * Moodle prints the page heading, and a template that printed a second one
     * would leave the document with two top level headings.
     */
    public function test_no_screen_adds_its_own_top_level_heading(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        foreach ($this->screens() as $name => $html) {
            $this->assertSame(
                0,
                preg_match_all('/<h1[\s>]/', $html),
                $name . ' must leave the one top level heading to Moodle'
            );
        }
    }

    /**
     * The report no longer carries a stylesheet of its own.
     *
     * It used to inline the whole palette so that it could be opened outside
     * the theme, at the cost of a document that had no site header, no footer
     * and no plugin stylesheet. It is an ordinary page now, so all of that has
     * to come from the theme and the print rules from styles.css.
     */
    public function test_the_report_is_a_page_again_and_not_a_standalone_document(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->screens()['report'];

        $this->assertStringNotContainsString('<style', $html, 'The report inlines no stylesheet.');
        $this->assertStringNotContainsString('@media print', $html);
        $this->assertStringContainsString('class="tool-upgradeguard tool-upgradeguard-report"', $html);
    }

    /**
     * The results and history screens render one shared page header.
     *
     * Two hand written headers drift: one grows a button the other does not, one
     * loses the verdict badge. Both must render the shared partial, and it must
     * be the only source of the page heading on those screens.
     */
    public function test_the_shared_header_is_rendered_consistently(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $screens = $this->screens();

        foreach (['results', 'history'] as $name) {
            // The container class, not the whole upgradeguard-pagehead prefix:
            // the title, the meta line and the score are all suffixed children.
            $this->assertSame(
                1,
                substr_count($screens[$name], 'class="upgradeguard-pagehead d-flex'),
                $name . ' should render exactly one shared page header'
            );

            $this->assertSame(
                1,
                substr_count($screens[$name], 'upgradeguard-pagehead-meta'),
                $name . ' should render exactly one context line'
            );
        }

        // One back button per screen, and it points at the dashboard.
        $backlabel = get_string('backtodashboard', 'tool_upgradeguard');
        foreach (['results', 'history'] as $name) {
            $this->assertSame(
                1,
                substr_count($screens[$name], $backlabel),
                $name . ' should offer exactly one way back to the dashboard'
            );
        }
    }

    /**
     * Pagination marks exactly one page as current, and announces it.
     *
     * Without aria-current a screen reader user cannot tell which page they
     * are on, because the visual active class is not exposed to assistive
     * technology.
     */
    public function test_pagination_marks_exactly_one_page_as_current(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $scanid = $this->seed_scan();
        for ($i = 0; $i < 30; $i++) {
            $this->seed_plugin($scanid, 'mod_p' . $i, 'caution');
        }

        $filters = results_filters::normalise(['search' => '', 'status' => '', 'type' => '', 'inuse' => 0]);
        $this->assertGreaterThan(
            scan_repository::RESULTS_PER_PAGE,
            (new scan_repository())->count_plugins($scanid, $filters),
            'This test needs a scan with more rows than one page holds.'
        );

        $html = $this->render_results($scanid);

        $this->assertSame(
            1,
            substr_count($html, 'aria-current="page"'),
            'Exactly one pagination link should be marked as the current page'
        );

        $this->assertMatchesRegularExpression(
            '/class="btn btn-outline-secondary btn-sm active"\s+href="[^"]*"\s+aria-current="page"/',
            $html,
            'The current page should be both visibly active and announced as current'
        );
    }

    /**
     * Deleting a scan is a confirmed POST that carries a sesskey.
     *
     * This is the only destructive action in the plugin. A GET delete could be
     * triggered by any link anywhere on the site, so the form, the sesskey and
     * the confirmation are all asserted on the rendered page.
     */
    public function test_the_delete_button_is_a_confirmed_post_with_a_sesskey(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->seed_scan();
        $html = $this->render_history();

        $this->assertMatchesRegularExpression(
            '/<form method="post" action="[^"]*upgradeguard\/index\.php" class="d-inline">/',
            $html,
            'The delete control should submit a POST form to index.php'
        );

        $this->assertStringContainsString('name="action" value="deletescan"', $html);
        $this->assertStringContainsString('name="scanid"', $html);
        $this->assertStringContainsString(
            '<input type="hidden" name="sesskey" value="' . sesskey() . '">',
            $html
        );

        $this->assertMatchesRegularExpression(
            '/<button type="submit" class="btn btn-outline-danger btn-sm"/',
            $html,
            'Delete should be the only destructive button style, and it should submit'
        );

        $this->assertStringContainsString('data-confirmation="modal"', $html);
        $this->assertStringContainsString('data-confirmation-type="delete"', $html);
        $this->assertStringContainsString('deletescanconfirm', $html);
    }

    /**
     * A viewer without the manage capability gets no delete control at all.
     *
     * Hiding the button is the first half of the guard; index.php checks the
     * capability again on submit. This asserts the first half.
     */
    public function test_a_viewer_without_manage_sees_no_delete_button(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->seed_scan();
        $html = $this->render_history(1, false);

        $this->assertStringNotContainsString('deletescan', $html);
        $this->assertStringNotContainsString('btn-outline-danger', $html);

        // The read only actions stay.
        $this->assertStringContainsString(get_string('viewresults', 'tool_upgradeguard'), $html);
    }

    /**
     * The export links carry a sesskey, because export.php demands one.
     *
     * Without it every export on the dashboard fails with an invalid session
     * message while the link still looks like a working button.
     */
    public function test_the_export_buttons_carry_a_sesskey(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $scanid = $this->seed_scan();
        $html = $this->render_dashboard($scanid);

        $this->assertStringContainsString('export.php', $html);
        $this->assertSame(
            2,
            substr_count($html, 'sesskey=' . sesskey()),
            'Both export links need the sesskey that export.php requires'
        );
        $this->assertStringContainsString('format=csv', $html);
        $this->assertStringContainsString('format=json', $html);
    }

    /**
     * A viewer without the export capability gets no export buttons.
     */
    public function test_a_viewer_without_export_sees_no_export_buttons(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $scanid = $this->seed_scan();
        $html = $this->render_dashboard($scanid, false);

        $this->assertStringNotContainsString('export.php', $html);

        // The printable report is not gated on export and stays available.
        $this->assertStringContainsString('report.php', $html);
    }

    /**
     * The empty dashboard is still a complete page.
     *
     * The empty state renders a different set of tabs and none of the actions,
     * so it is the state most likely to read a key the context no longer
     * exports. It must still offer every tab so the page does not look broken.
     */
    public function test_the_empty_dashboard_renders_without_errors(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_dashboard(null);

        $this->assertStringContainsString('tool-upgradeguard', $html);
        $this->assertStringNotContainsString('upgradeguard-actions', $html);
        $this->assertStringNotContainsString('export.php', $html);
        $this->assertSame(6, substr_count($html, 'data-bs-toggle="tab"'));
    }

    /**
     * Every dashboard tab points at a pane that exists.
     *
     * Bootstrap connects a trigger to its pane through the id in
     * data-bs-target, so a mismatch renders a tab that switches nothing.
     */
    public function test_every_dashboard_tab_points_at_a_pane_that_exists(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->seed_scan();
        $html = $this->render_dashboard($this->seed_scan());

        preg_match_all('/data-bs-target="#([^"]+)"/', $html, $matches);
        $targets = $matches[1];

        $this->assertCount(6, $targets);

        foreach ($targets as $target) {
            $this->assertStringContainsString(
                'id="' . $target . '"',
                $html,
                'The tab target ' . $target . ' has no matching pane'
            );
        }

        // One pane is shown by default, the rest wait for a click.
        $this->assertSame(1, substr_count($html, 'tab-pane fade show active'));
        $this->assertSame(5, substr_count($html, 'tab-pane fade"'));
    }

    /**
     * Every disclosure control names the region it opens.
     *
     * The findings of a plugin row and the hidden actions of the overview both
     * start collapsed, so both controls need aria-controls and aria-expanded
     * or a screen reader announces an unexplained toggle.
     */
    public function test_every_disclosure_control_names_its_region(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $scanid = $this->seed_scan();
        $pluginid = $this->seed_plugin($scanid, 'mod_x', 'caution');

        // The overview shows the first three actions and hides the rest, so the
        // "show all" control only exists once a scan produces enough actions to
        // be worth hiding. Distinct params keep the planner from merging them.
        for ($i = 0; $i < 7; $i++) {
            $this->seed_finding($scanid, $i === 0 ? $pluginid : 0, ['version' => '5.2.' . $i]);
        }

        $html = $this->render_dashboard($scanid);

        $this->assertStringContainsString(
            'id="ug-rest-actions"',
            $html,
            'Seven actions should overflow the three the overview shows'
        );

        // The order of the attributes differs between the two disclosure
        // controls, and Bootstrap accepts either href or data-bs-target, so the
        // pairs are read with several patterns and merged.
        $controls = [];
        $patterns = [
            '/data-bs-toggle="collapse"[^>]*href="#([^"]+)"/i',
            '/href="#([^"]+)"[^>]*data-bs-toggle="collapse"/i',
            '/data-bs-toggle="collapse"[^>]*data-bs-target="#([^"]+)"/i',
            '/data-bs-target="#([^"]+)"[^>]*data-bs-toggle="collapse"/i',
        ];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $html, $matches);
            foreach ($matches[1] as $control) {
                $controls[$control] = true;
            }
        }

        $this->assertNotEmpty($controls, 'The dashboard should have at least one disclosure control');

        foreach (array_keys($controls) as $control) {
            $this->assertStringContainsString('id="' . $control . '"', $html);
            $this->assertStringContainsString('aria-controls="' . $control . '"', $html);
        }

        $this->assertStringContainsString('aria-expanded="false"', $html);
    }

    /**
     * The verdict badge and the score carry the same colour class.
     *
     * Both are colour coded from the same verdict. If one of the two drops its
     * class the reviewer sees a coloured badge beside an uncoloured number.
     */
    public function test_the_verdict_badge_and_score_share_their_colour_class(): void {
        foreach (['go', 'careful', 'stop', 'unknown'] as $verdict) {
            $this->resetAfterTest();
            $this->setAdminUser();

            $scanid = $this->seed_scan([
                'verdict' => $verdict,
                'score' => $verdict === 'stop' ? 20 : ($verdict === 'go' ? 95 : 63),
            ]);

            $screens = [
                'dashboard' => $this->render_dashboard($scanid),
                'results' => $this->render_results($scanid),
                'history' => $this->render_history(),
                'report' => $this->render_report($scanid),
            ];

            foreach ($screens as $name => $html) {
                $this->assertMatchesRegularExpression(
                    '/badge upgradeguard-verdict upgradeguard-verdict-[a-z]+/',
                    $html,
                    $name . ' should render a verdict badge with its colour class'
                );
                $this->assertMatchesRegularExpression(
                    '/upgradeguard-score upgradeguard-score-[a-z]+/',
                    $html,
                    $name . ' should render the score with the matching colour class'
                );
            }
        }
    }

    /**
     * Every status badge uses a Bootstrap colour that actually exists.
     *
     * A status outside the known set renders as unstyled text, so the set is
     * closed: the four scan states and the four plugin states.
     */
    public function test_every_status_badge_uses_a_known_bootstrap_colour(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $known = ['info', 'warning', 'danger', 'success', 'secondary'];

        $scanid = $this->seed_scan();
        $this->seed_plugin($scanid, 'mod_x', 'caution');
        $this->seed_finding($scanid, 0);

        $screens = [
            'results' => $this->render_results($scanid),
            'history' => $this->render_history(),
        ];

        foreach ($screens as $name => $html) {
            preg_match_all('/class="badge bg-([a-z]+)"/', $html, $matches);
            $this->assertNotEmpty($matches[1], $name . ' should render status badges');

            foreach ($matches[1] as $colour) {
                $this->assertContains(
                    $colour,
                    $known,
                    $name . ' renders a badge with the unknown colour bg-' . $colour
                );
            }
        }
    }

    /**
     * Every rendered table stays inside a responsive wrapper.
     */
    public function test_every_rendered_table_is_inside_a_responsive_wrapper(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $scanid = $this->seed_scan();
        $pluginid = $this->seed_plugin($scanid, 'mod_x', 'caution');
        $this->seed_finding($scanid, $pluginid);
        $this->seed_finding($scanid, 0);

        $screens = [
            'dashboard' => $this->render_dashboard($scanid),
            'results' => $this->render_results($scanid),
            'history' => $this->render_history(),
        ];

        foreach ($screens as $name => $html) {
            $tables = substr_count($html, '<table');
            $this->assertGreaterThan(0, $tables, $name . ' should render a table');
            $this->assertGreaterThanOrEqual(
                $tables,
                substr_count($html, 'table-responsive'),
                $name . ' should wrap every table for narrow screens'
            );
        }
    }

    /**
     * The verdict card must not be able to clip its own text.
     *
     * The card packs the verdict, the version pair, the score and the summary
     * badges into two flex rows. A flex item refuses to shrink below the width
     * of its longest unbreakable text, so when that text did not fit the row
     * pushed past the right edge of the card and the tail of the sentence was
     * cut off: the score lost its "/ 100 Readiness score" label and the
     * "Score after removing unused plugins" line lost its number. Every direct
     * child of a row now carries min-width: 0 so it can shrink, and the text
     * blocks that hold a sentence are allowed to break, which is what the
     * stylesheet does in .upgradeguard-summary-row.
     *
     * @covers ::output\dashboard
     */
    public function test_the_verdict_card_lets_its_text_shrink_instead_of_clipping(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $scanid = $this->seed_scan();
        $pluginid = $this->seed_plugin($scanid, 'mod_x', 'caution');
        $this->seed_finding($scanid, $pluginid);

        $html = $this->render_dashboard($scanid);

        foreach ($this->summary_rows($html) as $row) {
            $this->assertStringNotContainsString(
                'ms-auto',
                $row,
                'a verdict card row should not push itself with a margin, which clips at the card edge'
            );
        }

        $this->assertMatchesRegularExpression(
            '/text-break/',
            $html,
            'the verdict card should let its sentences break instead of overflowing'
        );
    }

    /**
     * Split the verdict card rows out of the rendered dashboard.
     *
     * @param string $html The rendered dashboard.
     * @return string[] The inner markup of every verdict card row.
     */
    private function summary_rows(string $html): array {
        $this->assertSame(
            2,
            preg_match_all('/<div class="[^"]*upgradeguard-summary-row[^"]*">(.*?)<\/div>\s*<\/div>/s', $html, $matches),
            'the verdict card should carry exactly two rows'
        );

        return $matches[1];
    }

    /**
     * The overview sections use h3 and never skip to h4.
     *
     * Moodle prints the page title as the only h1, so the sections of the
     * overview pane are h3. They were h4, which skipped a level: a screen
     * reader hearing the section list was told about a level that nothing in
     * the document had introduced. The history pane already used h2 for its
     * own single heading, so the levels are deliberately not uniform across
     * panes, and this test pins only what the overview pane owns.
     *
     * @covers ::output\dashboard
     */
    public function test_the_overview_sections_use_h3_and_never_skip_to_h4(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $scanid = $this->seed_scan();
        $pluginid = $this->seed_plugin($scanid, 'mod_x', 'caution');
        $this->seed_finding($scanid, $pluginid);

        $html = $this->render_dashboard($scanid);

        $this->assertStringContainsString(
            '<h3>' . get_string('actions', 'tool_upgradeguard') . '</h3>',
            $html,
            '"What to do next" should be an h3, the level of an overview section'
        );
        $this->assertStringContainsString(
            '<h3>' . get_string('sitefindingsheading', 'tool_upgradeguard') . '</h3>',
            $html,
            '"Site wide findings" should be an h3, the level of an overview section'
        );
        $this->assertStringNotContainsString(
            '<h4>',
            $html,
            'the dashboard should not jump from the page heading to an h4'
        );
    }
}
