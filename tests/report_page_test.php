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
 * Renders the report page the way a browser reaches it.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use advanced_testcase;
use core\output\bootstrap_renderer;
use renderer_base;
use tool_upgradeguard\local\repository\scan_repository;
use tool_upgradeguard\local\target;

/**
 * The report page must survive a request that has not rendered its header yet.
 *
 * Before the header is printed $OUTPUT is a bootstrap_renderer, which proxies
 * method calls but is not a renderer_base. Handing that object to a
 * renderable's export_for_template() therefore throws a TypeError, exactly as
 * the report page did in the browser before this test existed.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_page_test extends advanced_testcase {
    /**
     * Seed a finished scan with one plugin and one finding.
     *
     * @return int The scan id.
     */
    private function seedfinishedscan(): int {
        global $DB, $USER;

        $target = new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified');
        $scanid = (new scan_repository())->create_queued_scan($target, 'Moodle 5.2', 502, 3, (int) $USER->id);
        $DB->update_record('tool_upgradeguard_scan', (object) [
            'id' => $scanid,
            'status' => 'finished',
            'score' => 63,
            'verdict' => 'careful',
            'plugincount' => 1,
            'blockercount' => 0,
            'cautioncount' => 1,
            'unknowncount' => 0,
            'timefinished' => time(),
        ]);

        $pluginid = $DB->insert_record('tool_upgradeguard_plugin', (object) [
            'scanid' => $scanid,
            'component' => 'mod_x',
            'plugintype' => 'mod',
            'name' => 'x',
            'displayname' => 'Plugin X',
            'versiondisk' => '2026010100',
            'versiondb' => '2026010100',
            'isstandard' => 0,
            'installed' => 1,
            'currentpath' => '/mod/x',
            'newpath' => '/public/mod/x',
            'status' => 'caution',
            'usagecount' => 3,
            'updateavailable' => 0,
            'timecreated' => time(),
        ]);

        $DB->insert_record('tool_upgradeguard_finding', (object) [
            'scanid' => $scanid,
            'pluginid' => $pluginid,
            'checkkey' => 'public_location',
            'severity' => 'caution',
            'messagekey' => 'finding_legacy_location',
            'params' => json_encode(['oldpath' => 'mod/x', 'newpath' => 'public/mod/x']),
            'confidence' => 'high',
            'fixedbyupdate' => 0,
            'actionkey' => 'action_move_plugin_to_public',
            'timecreated' => time(),
        ]);

        return $scanid;
    }

    /**
     * The report page is a normal Moodle page again, not a bare fragment.
     *
     * It used to print its own document with an inline stylesheet and no page
     * header or footer at all. That left it without the theme, without the
     * plugin stylesheet and without the navigation, and the print rules had to
     * reimplement what every other page gets for free.
     *
     * The body is asserted on the real rendered output. The document around it
     * is asserted on the source, because the theme only renders a full document
     * inside a web request and PHPUnit has no theme.
     */
    public function test_report_page_renders_inside_the_normal_page(): void {
        global $CFG, $OUTPUT, $PAGE, $SITE;

        $this->resetAfterTest();
        $this->setAdminUser();
        $scanid = $this->seedfinishedscan();

        $_GET['id'] = $scanid;
        ob_start();
        try {
            require(__DIR__ . '/../report.php');
        } finally {
            $html = ob_get_clean();
            unset($_GET['id']);
        }

        $this->assertStringContainsString('upgradeguard-report', $html);
        $this->assertStringContainsString('upgradeguard-verdict-warning', $html);
        $this->assertStringContainsString('upgradeguard-score-warning', $html);
        $this->assertStringContainsString('btn btn-outline-secondary', $html);
        $this->assertStringContainsString('Plugin X', $html);
        $this->assertStringNotContainsString('{{', $html);
        // The report adds no top level heading of its own: Moodle prints the page
        // heading from the page heading that report.php sets. In PHPUnit there is
        // no theme, so the count here is zero rather than one.
        $this->assertSame(0, preg_match_all('/<h1[\s>]/', $html), 'The template opens no h1.');
        // The inline stylesheet is gone: the report uses styles.css like every
        // other page of the plugin.
        $this->assertStringNotContainsString('<style', $html);
        $this->assertStringNotContainsString('@media print', $html);
    }

    /**
     * The page must go through Moodle's own page rendering.
     *
     * An embedded layout with no header and no footer is what produced a
     * document without the theme on the first screen audit.
     */
    public function test_the_report_page_uses_the_theme_page(): void {
        $source = (string) file_get_contents(__DIR__ . '/../report.php');

        $this->assertStringContainsString('admin_externalpage_setup(', $source);
        $this->assertStringContainsString('$OUTPUT->header()', $source);
        $this->assertStringContainsString('$OUTPUT->footer()', $source);
        $this->assertStringContainsString(
            '$PAGE->set_heading(',
            $source,
            'The page must name itself, or the theme shows the name of the tool.'
        );
        $this->assertStringNotContainsString("set_pagelayout('embedded')", $source);
    }

    /**
     * No page may hand the bootstrap proxy to a renderable.
     */
    public function test_pages_never_pass_the_bootstrap_renderer(): void {
        foreach (['report.php', 'results.php', 'history.php'] as $file) {
            $content = file_get_contents(__DIR__ . '/../' . $file);
            $this->assertStringNotContainsString(
                'export_for_template($OUTPUT)',
                $content,
                $file . ' hands $OUTPUT to export_for_template()'
            );
        }
    }

    /**
     * A real renderer satisfies the renderable, the proxy does not.
     */
    public function test_renderable_needs_a_real_renderer(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertNotInstanceOf(renderer_base::class, new bootstrap_renderer());
        $this->assertInstanceOf(renderer_base::class, $PAGE->get_renderer('tool_upgradeguard'));
    }
}
