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
 * Printable report of one scan.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use tool_upgradeguard\local\repository\scan_repository;
use tool_upgradeguard\local\target_repository;
use tool_upgradeguard\output\report;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$systemcontext = context_system::instance();
require_capability('tool/upgradeguard:view', $systemcontext);

$id = optional_param('id', 0, PARAM_INT);
$dashboardurl = new moodle_url('/admin/tool/upgradeguard/index.php');
$repository = new scan_repository();
$scan = $id > 0 ? $repository->get_scan($id) : $repository->get_latest_scan();

if ($scan === null) {
    redirect($dashboardurl, get_string('error_scannotfound', 'tool_upgradeguard'), null, notification::NOTIFY_ERROR);
}
if ($scan->status !== 'finished') {
    redirect($dashboardurl, get_string('error_reportnotfinished', 'tool_upgradeguard'), null, notification::NOTIFY_ERROR);
}

$target = (new target_repository())->get_target((string) $scan->targetversion);
if ($target === null) {
    redirect($dashboardurl, get_string('error_unknowntarget', 'tool_upgradeguard'), null, notification::NOTIFY_ERROR);
}

$reporturl = new moodle_url('/admin/tool/upgradeguard/report.php', ['id' => (int) $scan->id]);

// The report is a normal admin page: the theme, its stylesheet and the plugin
// stylesheet are what make it look like the rest of Upgrade Guard, and the print
// stylesheet needs the theme chrome to know what to hide.
admin_externalpage_setup('tool_upgradeguard');
$PAGE->set_url($reporturl);
$PAGE->set_title(get_string('reporttitle', 'tool_upgradeguard'));
$PAGE->set_heading(get_string('reporttitle', 'tool_upgradeguard'));

$plugins = $repository->get_plugins_for_scan((int) $scan->id);
$findings = $repository->get_findings_grouped((int) $scan->id);
$supportemail = (string) get_config('tool_upgradeguard', 'supportemail');

$report = new report($target, $scan, $plugins, $findings, $supportemail, $SITE->fullname);

// The global $OUTPUT is only a real renderer after the header has been sent,
// so the renderable gets an explicit one.
$renderer = $PAGE->get_renderer('tool_upgradeguard');

echo $OUTPUT->header();
echo $renderer->render_from_template('tool_upgradeguard/report', $report->export_for_template($renderer));
echo $OUTPUT->footer();
