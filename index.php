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
 * Dashboard of Upgrade Guard.
 *
 * The page only orchestrates: it validates input, queues or deletes a scan and
 * hands the assembled data to the dashboard templates. All real work happens in
 * the scanner, which runs in cron.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use core\task\manager;
use tool_upgradeguard\form\scan_form;
use tool_upgradeguard\event\scan_deleted;
use tool_upgradeguard\local\action_planner;
use tool_upgradeguard\local\collector\environment_inspector;
use tool_upgradeguard\local\repository\scan_repository;
use tool_upgradeguard\local\request_guard;
use tool_upgradeguard\local\scanner;
use tool_upgradeguard\local\target_repository;
use tool_upgradeguard\output\dashboard;
use tool_upgradeguard\task\scan_task;

require(__DIR__.'/../../../config.php'); // phpcs:ignore
require_once($CFG->libdir . '/adminlib.php');

require_login();
admin_externalpage_setup('tool_upgradeguard');

$systemcontext = context_system::instance();
require_capability('tool/upgradeguard:view', $systemcontext);

$canrunscan = has_capability('tool/upgradeguard:runscan', $systemcontext);
$canexport = has_capability('tool/upgradeguard:export', $systemcontext);
$canmanage = has_capability('tool/upgradeguard:manage', $systemcontext);

$repository = new scan_repository();
$repository->recover_stale_running_scans(HOURSECS);
$action = optional_param('action', '', PARAM_ALPHA);
$dashboardurl = new moodle_url('/admin/tool/upgradeguard/index.php');

if ($action === 'startscan') {
    require_capability('tool/upgradeguard:runscan', $systemcontext);
    require_sesskey();

    $targetrepository = new target_repository();
    // Moodle's alphanumeric parameter type strips the dot from versions such
    // as "5.2". The exact repository lookup below is the allowlist check.
    $target = $targetrepository->get_target(required_param('targetversion', PARAM_RAW_TRIMMED));
    $currentbranch = (int) ($CFG->branch ?? 0);
    if ($target === null || !$targetrepository->is_upgrade_target($target->version, $currentbranch)) {
        redirect($dashboardurl, get_string('error_targetnotupgrade', 'tool_upgradeguard'), null, notification::NOTIFY_ERROR);
    }

    if ($repository->get_active_scan() !== null) {
        redirect($dashboardurl, get_string('scanalreadyqueued', 'tool_upgradeguard'), null, notification::NOTIFY_ERROR);
    }

    // The scan itself runs in cron: it reads the whole plugin inventory, the
    // whole user table and possibly moodle.org, which must not happen inside a
    // web request.
    // The environment is captured here, in the web request that serves the site,
    // because the scan itself runs in cron later and the command line PHP build
    // can differ from the web one.
    $environment = (new environment_inspector())->inspect();

    $scanid = (new scanner($repository))->queue_scan($target, (int) $USER->id, $environment);

    $task = new scan_task();
    $task->set_custom_data(['scanid' => $scanid]);
    manager::queue_adhoc_task($task);

    redirect(
        new moodle_url($dashboardurl, ['id' => $scanid]),
        get_string('scanqueued', 'tool_upgradeguard'),
        null,
        notification::NOTIFY_SUCCESS
    );
}

if ($action === 'deletescan') {
    require_capability('tool/upgradeguard:manage', $systemcontext);
    require_sesskey();

    // A GET request must never be able to delete a stored scan: deleting is a
    // POST only action, because any page could link to a GET URL.
    request_guard::require_post();

    $scanid = required_param('scanid', PARAM_INT);
    $scan = $repository->get_scan($scanid);
    if ($scan === null) {
        throw new moodle_exception('error_scannotfound', 'tool_upgradeguard');
    }

    $repository->delete_scan($scanid);
    scan_deleted::create([
        'context' => $systemcontext,
        'objectid' => $scanid,
        'other' => ['scanownerid' => (int) $scan->userid],
    ])->trigger();

    redirect($dashboardurl, get_string('deletedscan', 'tool_upgradeguard'), null, notification::NOTIFY_SUCCESS);
}

$showid = optional_param('id', 0, PARAM_INT);
$scan = $showid > 0 ? $repository->get_scan($showid) : $repository->get_latest_scan();

if ($showid > 0 && $scan === null) {
    redirect($dashboardurl, get_string('error_scannotfound', 'tool_upgradeguard'), null, notification::NOTIFY_ERROR);
}

$PAGE->set_url($dashboardurl);
$PAGE->set_title(get_string('pluginname', 'tool_upgradeguard'));
$PAGE->set_heading(get_string('pluginname', 'tool_upgradeguard'));

if ($scan !== null && in_array($scan->status, [scan_repository::STATUS_QUEUED, scan_repository::STATUS_RUNNING], true)) {
    // A scan runs in cron, so the page refreshes itself instead of relying on
    // JavaScript that would have to be built and shipped.
    $PAGE->set_periodic_refresh_delay(10);
}

// Rendering a moodleform registers core_form/changechecker's watchFormById for
// that form's id. The template only prints the form when there is something to
// pick, so building it unconditionally left the JavaScript watching a <form>
// that was never sent to the browser, and the change checker crashed on
// getElementById() returning null. The form is therefore only built when the
// template is going to print it.
$targetrepository = new target_repository();
$hastargets = $targetrepository->get_upgrade_targets((int) ($CFG->branch ?? 0)) !== [];
$formhtml = '';
if ($canrunscan && $hastargets) {
    $form = new scan_form(new moodle_url($dashboardurl, ['action' => 'startscan']));
    $formhtml = $form->render();
}

$findings = $scan === null ? ['' => []] : $repository->get_findings_grouped((int) $scan->id);
$plugins = $scan === null ? [] : $repository->get_plugins_for_scan((int) $scan->id);
$target = $scan === null ? null : $targetrepository->get_target((string) $scan->targetversion);
$PAGE->requires->js_call_amd('tool_upgradeguard/dashboard', 'init');

$dashboard = new dashboard(
    $scan,
    $plugins,
    $findings,
    (new action_planner())->plan($findings),
    $repository->get_recent_scans(10),
    $formhtml,
    $targetrepository->get_dataset_version(),
    $canrunscan,
    $canexport,
    (int) get_config('tool_task', 'lastcronstart'),
    $target
);

echo $OUTPUT->header();
echo $PAGE->get_renderer('tool_upgradeguard')->render_dashboard($dashboard);
echo $OUTPUT->footer();
