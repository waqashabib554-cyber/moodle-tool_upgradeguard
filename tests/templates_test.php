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
 * Tests the narrow screen contracts of the plugin templates.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use basic_testcase;

/**
 * Wide tables have to stay usable on a phone and when the page is printed.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class templates_test extends basic_testcase {
    /**
     * Templates that render a data table.
     *
     * @return array List of absolute template paths.
     */
    private function templates(): array {
        $names = ['dashboard', 'results', 'report', 'environment', 'movelist', 'findings', 'history'];
        $paths = [];
        foreach ($names as $name) {
            $paths[] = __DIR__ . '/../templates/' . $name . '.mustache';
        }

        return $paths;
    }

    /**
     * Every data table ships inside Bootstrap's responsive wrapper, so a wide
     * table scrolls within its card on a 375px screen instead of stretching
     * the whole page sideways.
     */
    public function test_every_data_table_is_wrapped_for_narrow_screens(): void {
        foreach ($this->templates() as $template) {
            $name = basename($template);
            $content = (string) file_get_contents($template);
            $tables = substr_count($content, '<table');
            $wrappers = substr_count($content, 'class="table-responsive"');

            $this->assertGreaterThan(0, $tables, $name . ' should render a table');
            $this->assertSame(
                $tables,
                $wrappers,
                $name . ' should wrap every table in a table-responsive container'
            );
        }
    }

    /**
     * The move list prints long directory paths, which have to wrap instead of
     * forcing the table wider than the viewport.
     */
    public function test_move_list_paths_are_breakable(): void {
        $content = (string) file_get_contents(__DIR__ . '/../templates/movelist.mustache');

        $this->assertStringContainsString('class="text-break">{{currentpath}}', $content);
        $this->assertStringContainsString('class="text-break">{{newpath}}', $content);
    }

    /**
     * Printing has nothing to scroll, so the responsive wrapper must release
     * the table instead of clipping it. One rule in the shared stylesheet now
     * covers every page, the report included.
     */
    public function test_responsive_wrapper_releases_the_table_when_printing(): void {
        $styles = (string) file_get_contents(__DIR__ . '/../styles.css');
        $print = $this->print_block($styles);
        $this->assertStringContainsString('.tool-upgradeguard .table-responsive', $print);
        $this->assertStringContainsString('overflow-x: visible', $print);

        // The report must not carry a second copy of the rule.
        $report = (string) file_get_contents(__DIR__ . '/../templates/report.mustache');
        $this->assertStringNotContainsString('overflow-x: visible', $report);
    }

    /**
     * Every page that loads the shared stylesheet carries the same container
     * class, so its rules (heading spacing, badge colours when printing) reach
     * all of those pages instead of the dashboard only.
     */
    public function test_pages_share_the_container_class_of_the_stylesheet(): void {
        foreach (['dashboard', 'results', 'history'] as $name) {
            $content = (string) file_get_contents(__DIR__ . '/../templates/' . $name . '.mustache');

            $this->assertMatchesRegularExpression(
                '/class="tool-upgradeguard( |")/',
                $content,
                $name . ' should carry the shared .tool-upgradeguard container class'
            );
        }
    }

    /**
     * The results table and the history page start with the same header
     * partial instead of two hand written copies that can drift apart.
     */
    public function test_results_and_history_share_the_page_header_partial(): void {
        foreach (['results', 'history'] as $name) {
            $content = (string) file_get_contents(__DIR__ . '/../templates/' . $name . '.mustache');

            $this->assertStringContainsString('{{> tool_upgradeguard/pagehead }}', $content);
            $this->assertStringNotContainsString('justify-content-between', $content);
        }

        $partial = (string) file_get_contents(__DIR__ . '/../templates/pagehead.mustache');
        $this->assertStringContainsString('class="upgradeguard-pagehead d-flex', $partial);
        $this->assertStringContainsString('class="badge upgradeguard-verdict upgradeguard-verdict-{{verdictclass}}"', $partial);
        $this->assertStringContainsString('upgradeguard-score-{{verdictclass}}', $partial);
    }

    /**
     * Both pages feed the shared header the keys it reads, and neither keeps a
     * private copy of anything the header already provides.
     */
    public function test_the_shared_header_context_is_exported(): void {
        $partial = (string) file_get_contents(__DIR__ . '/../templates/pagehead.mustache');
        preg_match_all('/\{\{#(\w+)\}\}/', $partial, $m);
        // The mustache helper {{#str}} translates a string; it is not a key.
        $keys = array_values(array_diff(array_unique($m[1]), ['str']));

        $classes = '';
        foreach (['results', 'history'] as $name) {
            $classes .= (string) file_get_contents(__DIR__ . '/../classes/output/' . $name . '.php');
        }

        foreach ($keys as $key) {
            // The keys are optional, so a page fills in what it has: the results
            // page has the verdict and the score, the history page the count. A
            // key neither of them exports would be a dead mustache reference.
            $this->assertStringContainsString(
                "'$key' =>",
                $classes,
                'The shared header reads ' . $key . ', which neither page exports.'
            );
        }

        foreach (['results', 'history'] as $name) {
            $template = (string) file_get_contents(__DIR__ . '/../templates/' . $name . '.mustache');
            $this->assertStringNotContainsString('heading}}', $template);
        }
    }

    /**
     * The spacing of the shared header lives in the stylesheet rather than in
     * utility classes copied into each template.
     */
    public function test_shared_stylesheet_styles_the_page_header(): void {
        $css = (string) file_get_contents(__DIR__ . '/../styles.css');

        $selectors = [
            '.tool-upgradeguard .upgradeguard-pagehead {',
            '.tool-upgradeguard .upgradeguard-pagehead-title {',
            '.tool-upgradeguard .upgradeguard-pagehead-meta > span + span {',
        ];
        foreach ($selectors as $selector) {
            $this->assertStringContainsString($selector, $css);
        }
    }

    /**
     * The dashboard visual identity stays plugin-scoped and derives from Boost
     * tokens instead of introducing a second spacing or colour scale.
     */
    public function test_dashboard_visual_identity_uses_boost_tokens(): void {
        $css = (string) file_get_contents(__DIR__ . '/../styles.css');
        $dashboard = (string) file_get_contents(__DIR__ . '/../templates/dashboard.mustache');

        $contracts = [
            '--ug-accent: var(--bs-link-color)',
            '--ug-accent-bg-subtle: var(--bs-primary-bg-subtle)',
            '--ug-accent-border-subtle: var(--bs-primary-border-subtle)',
            '--ug-border: var(--bs-border-color)',
            'var(--bs-spacer-1)',
            '.upgradeguard-score-success',
            '.upgradeguard-score-warning',
            '.upgradeguard-score-danger',
            '.upgradeguard-score-secondary',
            '.upgradeguard-disclosure-link',
        ];
        foreach ($contracts as $contract) {
            $this->assertStringContainsString($contract, $css);
        }

        $this->assertStringContainsString('upgradeguard-verdict-{{verdictclass}}', $dashboard);
        $this->assertStringContainsString('upgradeguard-score-{{verdictclass}}', $dashboard);
        $this->assertStringContainsString('<i class="{{icon}}" aria-hidden="true"></i>', $dashboard);
    }

    /**
     * Primary actions stay visually dominant while exports, reports, filters and
     * navigation are clearly secondary.
     */
    public function test_dashboard_action_hierarchy_uses_bootstrap_button_variants(): void {
        $dashboard = (string) file_get_contents(__DIR__ . '/../templates/dashboard.mustache');
        $results = (string) file_get_contents(__DIR__ . '/../templates/results.mustache');
        $pagehead = (string) file_get_contents(__DIR__ . '/../templates/pagehead.mustache');

        $this->assertStringContainsString('class="btn btn-primary btn-sm me-1"', $results);
        $this->assertSame(0, substr_count($dashboard, 'btn btn-primary'));
        $this->assertSame(0, substr_count($results, 'class="btn btn-secondary"'));
        $this->assertSame(0, substr_count($pagehead, 'btn btn-secondary'));

        $secondaryactions = [
            'exportcsvurl',
            'exportjsonurl',
            'reporturl',
            'resultsurl',
            'historyurl',
        ];
        foreach ($secondaryactions as $urlkey) {
            $this->assertMatchesRegularExpression(
                '/class="btn btn-outline-secondary btn-sm" href="\{\{' . $urlkey . '\}\}"/',
                $dashboard
            );
        }
        $this->assertStringContainsString('upgradeguard-disclosure-link', $dashboard);
        $this->assertStringContainsString('upgradeguard-disclosure-link', $results);

        $history = (string) file_get_contents(__DIR__ . '/../templates/history.mustache');
        $this->assertStringContainsString('upgradeguard-score-{{verdictclass}}', $history);
        $this->assertStringContainsString('upgradeguard-verdict-{{verdictclass}}', $history);
    }

    /**
     * The contents of the first "@media print" block of a stylesheet.
     *
     * @param string $css Stylesheet contents.
     * @return string The block body, or an empty string when there is none.
     */
    private function print_block(string $css): string {
        $start = strpos($css, '@media print');
        if ($start === false) {
            return '';
        }

        $open = strpos($css, '{', $start);
        if ($open === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($css);
        for ($i = $open; $i < $length; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } else if ($css[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($css, $open + 1, $i - $open - 1);
                }
            }
        }

        return '';
    }

    /**
     * The bodies of every "@media print" block of a stylesheet.
     *
     * A stylesheet can hold more than one print block, and a rule that only
     * matters on paper can sit in any of them.
     *
     * @param string $css Stylesheet contents.
     * @return string The concatenated block bodies.
     */
    private function all_print_blocks(string $css): string {
        $blocks = '';
        $offset = 0;
        while (($start = strpos($css, '@media print', $offset)) !== false) {
            $blocks .= $this->print_block(substr($css, $start));
            $offset = $start + 1;
        }

        return $blocks;
    }

    /**
     * The printable report is an ordinary page of the plugin.
     *
     * It used to open its own document and carry a stylesheet inline, which
     * meant it never loaded styles.css, never got the theme, and had to
     * reimplement the palette. It now renders inside the normal page and takes
     * its styling from the shared stylesheet.
     */
    public function test_report_uses_the_shared_page_and_stylesheet(): void {
        $report = (string) file_get_contents(__DIR__ . '/../templates/report.mustache');

        $this->assertStringNotContainsString('<style', $report, 'The report carries no inline stylesheet.');
        $this->assertStringNotContainsString('@media print', $report);
        $this->assertStringContainsString('class="tool-upgradeguard tool-upgradeguard-report"', $report);
        $this->assertStringContainsString('upgradeguard-print-hide', $report);

        $print = $this->all_print_blocks((string) file_get_contents(__DIR__ . '/../styles.css'));
        $this->assertStringContainsString('.tool-upgradeguard-report', $print, 'The report print rules live in styles.css.');
        $this->assertStringContainsString('.upgradeguard-print-hide', $print);
        $this->assertStringContainsString('page-break-inside: avoid', $print);
    }

    /**
     * The move list keeps the table and the CSV inside the hasmoves section, so
     * a scan where every plugin already sits in the target layout renders the
     * summary and the success line instead of rows of identical paths.
     */
    public function test_move_list_keeps_the_copy_material_in_the_hasmoves_section(): void {
        $template = (string) file_get_contents(__DIR__ . '/../templates/movelist.mustache');

        $start = (int) strpos($template, '{{#hasmoves}}');
        $end = (int) strpos($template, '{{/hasmoves}}');
        $this->assertGreaterThan(0, $start, 'The move list guards its copy material.');
        $this->assertGreaterThan($start, $end, 'The guarded section is closed.');

        $guarded = substr($template, $start, $end - $start);
        $needles = [
            '<table',
            '{{movecommandstext}}',
            '{{movecsvtext}}',
            'movecommands, tool_upgradeguard',
            'movecsv, tool_upgradeguard',
        ];
        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $guarded, $needle . ' is copy material.');
        }

        $empty = substr($template, $end);
        $this->assertStringContainsString('{{^hasmoves}}', $empty);
        $this->assertStringContainsString('{{movenocommands}}', $empty);
        $this->assertStringNotContainsString('<table', $empty, 'Nothing to move renders no table.');
    }

    /**
     * The dashboard loads the small AMD module which restores and remembers
     * its selected tab. Both source and generated build must ship with the
     * plugin so production JavaScript caching does not leave the page stale.
     */
    public function test_dashboard_loads_the_tab_state_amd_module(): void {
        $index = (string) file_get_contents(__DIR__ . '/../index.php');
        $source = __DIR__ . '/../amd/src/dashboard.js';
        $build = __DIR__ . '/../amd/build/dashboard.min.js';

        $this->assertStringContainsString(
            "\$PAGE->requires->js_call_amd('tool_upgradeguard/dashboard', 'init');",
            $index
        );
        $this->assertFileExists($source);
        $this->assertFileExists($build);
        $this->assertFileExists($build . '.map');
        $this->assertStringContainsString('shown.bs.tab', (string) file_get_contents($source));
        $this->assertStringContainsString('window.location.hash', (string) file_get_contents($source));
        $this->assertStringContainsString(
            'define("tool_upgradeguard/dashboard"',
            (string) file_get_contents($build)
        );
    }

    /**
     * The report container and the shared rules have to agree.
     *
     * A rename on either side silently drops the rules, and the status badges
     * and the verdict colours print as plain black text on paper.
     */
    public function test_report_stylesheet_scope_matches_its_container(): void {
        $report = (string) file_get_contents(__DIR__ . '/../templates/report.mustache');
        $styles = (string) file_get_contents(__DIR__ . '/../styles.css');

        $this->assertStringContainsString('tool-upgradeguard-report', $report);
        $this->assertStringContainsString('upgradeguard-verdict-{{verdictclass}}', $report);
        $this->assertStringContainsString('upgradeguard-score-{{verdictclass}}', $report);
        $this->assertStringContainsString('class="btn btn-outline-secondary btn-sm"', $report);

        // The verdict and score colours are shared with every other page.
        $this->assertStringContainsString('.tool-upgradeguard .upgradeguard-verdict-success', $styles);
        $this->assertStringContainsString('.tool-upgradeguard .upgradeguard-score-warning', $styles);
        $this->assertStringContainsString('.tool-upgradeguard .upgradeguard-pagehead-title', $styles);
        $this->assertDoesNotMatchRegularExpression(
            '/(?<![a-z-])\.upgradeguard-report\b/',
            $styles,
            'every report rule must be scoped to the container class the template carries'
        );
    }

    /**
     * Exactly one heading of the top level, and Moodle prints it.
     *
     * A page that adds a heading of its own next to the one the theme prints
     * leaves the document with two top level headings, which hides the page
     * structure from a screen reader instead of describing it. So no template
     * of this plugin may open one, and every page has to name itself through
     * the page heading, otherwise the theme falls back to the name of the tool
     * and the screen says nothing about where the reader is.
     */
    public function test_the_pages_let_moodle_print_the_only_top_level_heading(): void {
        $pagehead = (string) file_get_contents(__DIR__ . '/../templates/pagehead.mustache');
        $this->assertSame(0, preg_match_all('/<h1[\s>]/', $pagehead), 'The shared header prints no h1.');

        foreach (['dashboard', 'results', 'history', 'report'] as $template) {
            $content = (string) file_get_contents(__DIR__ . '/../templates/' . $template . '.mustache');
            $this->assertSame(
                0,
                preg_match_all('/<h1[\s>]/', $content),
                $template . ' must let the theme print the only h1.'
            );
        }

        foreach (['results.php', 'history.php', 'report.php'] as $page) {
            $source = (string) file_get_contents(__DIR__ . '/../' . $page);
            $this->assertStringContainsString(
                '$PAGE->set_heading(',
                $source,
                $page . ' must name itself, or the theme shows the name of the tool instead.'
            );
        }
    }

    /**
     * The shared header carries only what the theme cannot know.
     */
    public function test_the_shared_page_header_carries_the_context_not_a_title(): void {
        $pagehead = (string) file_get_contents(__DIR__ . '/../templates/pagehead.mustache');

        $this->assertStringContainsString('upgradeguard-pagehead-meta', $pagehead);
        $this->assertStringContainsString('{{#verdict}}', $pagehead);
        $this->assertStringContainsString('{{#hasscore}}', $pagehead);
        // The back button is optional: the dashboard is the page it would go to.
        $this->assertStringContainsString('{{#backurl}}', $pagehead);
    }
}
