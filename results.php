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
 * The filterable and paginated results table of one scan.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use tool_upgradeguard\local\repository\scan_repository;
use tool_upgradeguard\local\results_filters;
use tool_upgradeguard\local\target_repository;
use tool_upgradeguard\output\results;

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

$filters = results_filters::normalise([
    'search' => optional_param('search', '', PARAM_TEXT),
    'status' => optional_param('status', '', PARAM_ALPHA),
    'type' => optional_param('type', '', PARAM_ALPHANUMEXT),
    'inuse' => optional_param('inuse', 0, PARAM_BOOL),
    'sortby' => optional_param('sortby', 'status', PARAM_ALPHA),
    'dir' => optional_param('dir', 'asc', PARAM_ALPHA),
    'page' => optional_param('page', 1, PARAM_INT),
]);

$total = $repository->count_plugins((int) $scan->id, $filters);
$pages = max(1, (int) ceil($total / scan_repository::RESULTS_PER_PAGE));
$filters['page'] = min($filters['page'], $pages);

$rows = $repository->search_plugins(
    (int) $scan->id,
    $filters,
    ['by' => $filters['sortby'], 'dir' => $filters['dir']],
    $filters['page']
);
$findings = $repository->get_findings_for_components((int) $scan->id, array_column($rows, 'component'));
$types = $repository->get_plugin_types((int) $scan->id);

// Like the dashboard and the report, this is a page of the Upgrade Guard admin
// section, so the navigation tree and the plugin stylesheet belong to it. The
// page sets its own heading, because the one the admin section would give it is
// the name of the tool, which says nothing about the screen.
admin_externalpage_setup('tool_upgradeguard');
$PAGE->set_url(new moodle_url('/admin/tool/upgradeguard/results.php', ['id' => (int) $scan->id]));
$PAGE->set_title(get_string('resultsheading', 'tool_upgradeguard'));
$PAGE->set_heading(get_string('resultsheading', 'tool_upgradeguard'));

$table = new results(
    $scan,
    $target,
    $filters,
    ['by' => $filters['sortby'], 'dir' => $filters['dir']],
    $rows,
    $findings,
    $total,
    $types
);

// The global $OUTPUT is only a real renderer after the header has been sent,
// so the renderable gets an explicit one.
$renderer = $PAGE->get_renderer('tool_upgradeguard');

echo $OUTPUT->header();
echo $renderer->render_from_template('tool_upgradeguard/results', $table->export_for_template($renderer));
echo $OUTPUT->footer();
