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
 * Regression tests for the defects of the live screen audit.
 *
 * Each test pins down one thing that was actually wrong on screen: a download
 * that arrived as "name.csv.csv", a checkbox with no name and a detail line
 * outside its label, a source note printed twice, a sentence that said "1
 * plugin is" for two plugins, a queued scan described as running, and a failed
 * scan with an empty red box.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use advanced_testcase;
use stdClass;
use tool_upgradeguard\local\checklist;
use tool_upgradeguard\local\plural;
use tool_upgradeguard\local\target;
use tool_upgradeguard\local\target_repository;
use tool_upgradeguard\local\usage_text;
use tool_upgradeguard\output\dashboard;
use tool_upgradeguard\output\environment_context;

/**
 * Pins down the defects the live screen audit found.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_upgradeguard\local\usage_text
 * @covers     \tool_upgradeguard\local\plural
 */
final class audit_regression_test extends advanced_testcase {
    /**
     * The download name has to stay extensionless.
     *
     * dataformat::download_data() appends ".csv" itself. A name that already
     * carried the extension was saved as "upgrade-guard-....csv.csv", which
     * every spreadsheet then refused to open on the first click.
     */
    public function test_the_csv_name_is_extensionless_so_it_is_not_doubled(): void {
        $name = get_string('csvfilename', 'tool_upgradeguard', '20260101-1200');

        $this->assertSame('upgrade-guard-20260101-1200', $name);
        $this->assertStringEndsNotWith('.csv', $name, 'dataformat adds the extension itself.');
        $this->assertSame('upgrade-guard-20260101-1200.csv', $name . '.csv');
        $this->assertStringNotContainsString('.csv.csv', $name);
    }

    /**
     * The JSON name carries its extension exactly once.
     */
    public function test_the_json_name_carries_one_extension(): void {
        $name = get_string('jsonfilename', 'tool_upgradeguard', 42);

        $this->assertSame('upgrade-guard-scan-42.json', $name);
        $this->assertStringNotContainsString('.json.json', $name);
    }

    /**
     * An unknown export format must not answer with a bare error page.
     *
     * The download is reached by following a link from the dashboard, so a

    /**
     * Every count sentence has to agree with its number.
     *
     * The words used to be picked in PHP and dropped into the sentence, which
     * is how the pages drifted apart and how "1 plugin is not used" turned up
     * for two plugins. They are strings now, chosen by one helper.
     */
    public function test_the_agreements_are_chosen_by_one_helper(): void {
        $this->assertSame('finding', plural::form(1, 'noun_finding', 'noun_findings'));
        $this->assertSame('findings', plural::form(2, 'noun_finding', 'noun_findings'));
        $this->assertSame('findings', plural::form(0, 'noun_finding', 'noun_findings'));
        $this->assertSame('is', plural::form(1, 'verb_is', 'verb_are'));
        $this->assertSame('are', plural::form(3, 'verb_is', 'verb_are'));
        $this->assertSame('it', plural::form(1, 'pronoun_it', 'pronoun_them'));
        $this->assertSame('them', plural::form(5, 'pronoun_it', 'pronoun_them'));

        // No English left behind in the code that builds a sentence.
        $checklist = (string) file_get_contents(__DIR__ . '/../classes/local/checklist.php');
        foreach (['noun', 'verb', 'pronoun'] as $param) {
            $this->assertStringNotContainsString(
                "'$param' => (int) \$item['params'] === 1",
                $checklist,
                'checklist.php must not pick the ' . $param . ' words in PHP.'
            );
        }
    }

    /**
     * One plugin with one use and the same plugin with two.
     */
    public function test_the_usage_sentence_agrees_with_the_count(): void {
        $this->assertSame(
            get_string('usagecount', 'tool_upgradeguard', (object) ['count' => 1, 'noun' => 'place']),
            usage_text::from_count(1)
        );
        $this->assertSame(
            get_string('usagecount', 'tool_upgradeguard', (object) ['count' => 4, 'noun' => 'places']),
            usage_text::from_count(4)
        );
    }

    /**
     * "Cannot be measured" is not the same claim as "not used".
     */
    public function test_an_unmeasured_plugin_is_not_reported_as_unused(): void {
        $this->assertSame(get_string('usageunknown', 'tool_upgradeguard'), usage_text::from_count(null));
        $this->assertSame(get_string('usagenone', 'tool_upgradeguard'), usage_text::from_count(0));
        $this->assertNotSame(usage_text::from_count(null), usage_text::from_count(0));

        $this->assertSame(usage_text::from_count(null), usage_text::from_plugin($this->plugin(null)));
        $this->assertSame(usage_text::from_count(0), usage_text::from_plugin($this->plugin(0)));
        $this->assertSame(usage_text::from_count(7), usage_text::from_plugin($this->plugin(7)));
    }

    /**
     * A stored plugin row with the given usage count.
     *
     * @param int|null $usagecount The usage count, null when it could not be measured.
     * @return stdClass
     */
    private function plugin(?int $usagecount): stdClass {
        return (object) [
            'component' => 'mod_x',
            'usagecount' => $usagecount,
        ];
    }

    /**
     * The report used to leave the usage cell empty where the dashboard said
     * "cannot be measured", which reads as a missing value rather than as a
     * limit of the measurement.
     */
    public function test_the_report_says_the_same_thing_about_usage_as_the_dashboard(): void {
        $source = (string) file_get_contents(__DIR__ . '/../classes/output/report.php');

        $this->assertStringContainsString('usage_text::from_plugin($plugin)', $source);
        $this->assertStringNotContainsString("usagecount === null ? '' :", $source);
    }

    /**
     * A checklist box needs a name, and its detail line belongs to its label.
     *
     * Without a name the input is not a form control, and with the detail line
     * outside the label, clicking the explanation did not tick the box.
     */
    public function test_the_checklist_boxes_are_labelled_form_controls(): void {
        $template = (string) file_get_contents(__DIR__ . '/../templates/checklist.mustache');

        $this->assertStringContainsString('type="checkbox"', $template);
        $this->assertStringContainsString('name="checklist[{{key}}]"', $template);
        $this->assertStringContainsString('id="upgradeguard-check-{{key}}"', $template);
        $this->assertStringContainsString('for="upgradeguard-check-{{key}}"', $template);

        // The detail has to sit inside the label element, not after it.
        $open = (int) strpos($template, '<label');
        $close = (int) strpos($template, '</label>');
        $detail = (int) strpos($template, '{{detail}}');
        $this->assertGreaterThan(0, $open);
        $this->assertGreaterThan($open, $close, 'The label is closed.');
        $this->assertGreaterThan($open, $detail, 'The detail line is inside the label.');
        $this->assertLessThan($close, $detail, 'The detail line is inside the label.');
    }

    /**
     * The checklist text agrees with its number.
     */
    public function test_the_checklist_text_agrees_with_its_number(): void {
        $target = new target('5.2', 502, 2026042000, '8.3.0', '4.4', false, 'stable', 'verified');

        $one = $this->checklist_details((object) ['status' => 'finished', 'blockercount' => 1, 'id' => 1], $target);
        $this->assertArrayHasKey('blockers', $one);
        $this->assertStringContainsString('1 finding', $one['blockers']);
        $this->assertStringNotContainsString('1 findings', $one['blockers']);

        $this->resetAfterTest();
        $this->setAdminUser();
        $two = $this->checklist_details((object) ['status' => 'finished', 'blockercount' => 2, 'id' => 1], $target);
        $this->assertStringContainsString('2 findings', $two['blockers']);
    }

    /**
     * The detail line of every checklist item, keyed by item key.
     *
     * @param stdClass $scan A finished scan row with the blocker count.
     * @param target $target The target the scan was run against.
     * @return array[]
     */
    private function checklist_details(stdClass $scan, target $target): array {
        $details = [];
        foreach (checklist::translate(checklist::build($target, $scan, [], [])) as $row) {
            $details[$row['key']] = $row['detail'];
        }

        return $details;
    }

    /**
     * A source note is a sentence, and a sentence is not a link.
     *
     * The environment footer used to print the whole note and then link the
     * very same text again, so every address appeared twice and the long 5.3
     * sentence was used as the link text.
     */
    public function test_a_source_note_is_split_into_a_label_and_one_link(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // The shipped dataset, because a hand built target carries no sources.
        $target = (new target_repository())->get_target('5.2');
        $this->assertNotNull($target);

        $context = environment_context::build(
            $target,
            (object) [
                'id' => 1,
                'targetversion' => '5.2',
                'currentversion' => '5.1',
                'phpversion' => '8.3.30',
                'dbvendor' => 'mysql',
                'dbversion' => '8.4.3',
                'phpextensions' => null,
            ],
            []
        );

        $this->assertNotEmpty($context['verificationsources']);
        $urls = [];
        foreach ($context['verificationsources'] as $source) {
            $this->assertStringStartsWith('http', $source['url'], 'Every source link points at a real address.');
            // The link text is the address, never a paragraph of prose.
            $this->assertLessThan(120, strlen($source['label']), 'A source link is not a sentence.');
            $this->assertSame($source['url'], $source['label']);
            $urls[] = $source['url'];
        }

        // The same address is linked once, not once per row that mentions it.
        $this->assertSame(array_values(array_unique($urls)), $urls, 'No address is listed twice.');

        $summary = (string) $context['verificationsummary'];
        $this->assertStringNotContainsString('https://', $summary, 'The summary carries the note, not the address.');
    }
    /**
     * A queued scan has not started yet.
     *
     * The dashboard showed "Scanning your plugins..." for a scan that was still
     * waiting in the cron queue, which is not what the reader is looking at.
     */
    public function test_a_queued_scan_is_not_described_as_running(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $queued = $this->render_dashboard_for_status('queued');
        $this->assertStringContainsString(get_string('scanqueued', 'tool_upgradeguard'), $queued);
        $this->assertStringNotContainsString(get_string('scan_running', 'tool_upgradeguard'), $queued);

        $running = $this->render_dashboard_for_status('running');
        $this->assertStringContainsString(get_string('scan_running', 'tool_upgradeguard'), $running);
        $this->assertStringNotContainsString(get_string('scanqueued', 'tool_upgradeguard'), $running);
    }

    /**
     * A finished scan offers neither progress notice.
     */
    public function test_a_finished_scan_offers_no_progress_notice(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_dashboard_for_status('finished');

        $this->assertStringNotContainsString(get_string('scan_running', 'tool_upgradeguard'), $html);
        $this->assertStringNotContainsString(get_string('scanqueued', 'tool_upgradeguard'), $html);
    }

    /**
     * A failed scan with no recorded reason still has to say something.
     *
     * The stored message was printed as it was, so a row without one rendered
     * an empty red box that named no problem at all.
     */
    public function test_a_failed_scan_always_explains_itself(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $withreason = $this->render_dashboard_for_status('failed', 'The database refused the connection.');
        $this->assertStringContainsString('The database refused the connection.', $withreason);
        $this->assertStringContainsString(get_string('scan_failed', 'tool_upgradeguard'), $withreason);

        $withoutreason = $this->render_dashboard_for_status('failed', '');
        $this->assertStringContainsString(get_string('scan_failed_unknown', 'tool_upgradeguard'), $withoutreason);
    }

    /**
     * A queued or running scan offers no export, because there is nothing to
     * export yet.
     */
    public function test_an_unfinished_scan_offers_no_export(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        foreach (['queued', 'running'] as $status) {
            $html = $this->render_dashboard_for_status($status);
            $this->assertStringNotContainsString('export.php', $html, $status . ' must not offer an export link.');
        }

        $this->assertStringContainsString('export.php', $this->render_dashboard_for_status('finished'));
    }

    /**
     * The history list of the dashboard must not print an empty score.
     *
     * A scan that has not finished has no score, and an empty cell in a column
     * of numbers reads as a score of nothing.
     */
    public function test_the_dashboard_history_marks_a_missing_score(): void {
        $source = (string) file_get_contents(__DIR__ . '/../classes/output/dashboard.php');
        $template = (string) file_get_contents(__DIR__ . '/../templates/dashboard.mustache');

        $this->assertStringContainsString("'hasscore' => \$scan->score !== null", $source);
        $this->assertStringContainsString('{{#hasscore}}', $template);
        $this->assertStringContainsString('{{^hasscore}}', $template);
    }

    /**
     * Render the dashboard for a scan in a given state.
     *
     * @param string $status The stored status.
     * @param string $errormessage The stored failure message, may be empty.
     * @return string
     */
    private function render_dashboard_for_status(string $status, string $errormessage = ''): string {
        global $PAGE, $USER;

        $scan = (object) [
            'id' => 900001,
            'status' => $status,
            'verdict' => 'unknown',
            'score' => $status === 'finished' ? 80 : null,
            'currentversion' => '5.1',
            'targetversion' => '5.2',
            'blockercount' => 0,
            'cautioncount' => 0,
            'unknowncount' => 0,
            'errormessage' => $errormessage,
            'timecreated' => time(),
            'userid' => (int) $USER->id,
        ];

        $dashboard = new dashboard(
            $scan,
            [],
            ['' => []],
            [],
            [],
            '',
            1,
            true,
            true,
            0,
            new target('5.2', 502, 2026042000, '8.3.0', '4.4', true, 'stable', 'verified')
        );

        return $PAGE->get_renderer('tool_upgradeguard')->render_dashboard($dashboard);
    }



    /**
     * An unknown export format must not answer with a bare error page.
     *
     * The download is reached by following a link from the dashboard, so a
     * refusal has to land back on the dashboard with a message an administrator
     * can read and act on.
     */
    public function test_an_unknown_format_ends_on_the_dashboard_not_on_a_bare_exception(): void {
        $content = (string) file_get_contents(__DIR__ . '/../export.php');

        $this->assertStringNotContainsString(
            'throw new moodle_exception(',
            $content,
            'export.php must not answer a bad link with a bare error page'
        );
        $this->assertStringContainsString("get_string('error_badformat', 'tool_upgradeguard')", $content);
        $this->assertStringContainsString('redirect($dashboardurl', $content);
        // The message itself must exist, or the notification would show a
        // placeholder such as [[error_badformat]] to the administrator.
        $this->assertNotSame('[[error_badformat]]', get_string('error_badformat', 'tool_upgradeguard'));
    }
}
