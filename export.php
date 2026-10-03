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
 * Exports a scan report.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\dataformat;
use core\output\notification;
use tool_upgradeguard\event\report_exported;
use tool_upgradeguard\local\csv_report;
use tool_upgradeguard\local\export_filename;
use tool_upgradeguard\local\export_payload;
use tool_upgradeguard\local\repository\scan_repository;
use tool_upgradeguard\local\target_repository;

require(__DIR__.'/../../../config.php'); // phpcs:ignore

require_login();
require_capability('tool/upgradeguard:export', context_system::instance());
require_sesskey();

$scanid = required_param('id', PARAM_INT);
$format = required_param('format', PARAM_ALPHA);

$dashboardurl = new moodle_url('/admin/tool/upgradeguard/index.php');

// A download is reached by following a link, so a bad request has to end on a
// page an administrator can read and act on. Throwing here would answer with a
// bare error page, far from the scan the link came from.
if (!in_array($format, ['csv', 'json'], true)) {
    redirect($dashboardurl, get_string('error_badformat', 'tool_upgradeguard'), null, notification::NOTIFY_ERROR);
}

$repository = new scan_repository();
$scan = $repository->get_scan($scanid);

if ($scan === null) {
    redirect($dashboardurl, get_string('error_scannotfound', 'tool_upgradeguard'), null, notification::NOTIFY_ERROR);
}

$target = (new target_repository())->get_target((string) $scan->targetversion);
if ($target === null) {
    redirect($dashboardurl, get_string('error_targetnotfound', 'tool_upgradeguard'), null, notification::NOTIFY_ERROR);
}

$plugins = $repository->get_plugins_for_scan($scanid);
$findings = $repository->get_findings_grouped($scanid);

report_exported::create([
    'context' => context_system::instance(),
    'objectid' => $scanid,
    'other' => ['format' => $format, 'targetversion' => (string) $scan->targetversion],
])->trigger();

if ($format === 'json') {
    $payload = export_payload::build(
        $target,
        $scan,
        $plugins,
        $findings,
        (string) get_config('tool_upgradeguard', 'supportemail')
    );

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . get_string('jsonfilename', 'tool_upgradeguard', $scanid) . '"');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// The report is flat: one row per plugin and finding, so that it can be
// filtered and sorted in any spreadsheet. A plugin without findings still gets
// a row, otherwise it would silently disappear from the report.
$report = csv_report::build($plugins, $findings);

$filename = export_filename::csv(time());
dataformat::download_data(
    $filename,
    'csv',
    array_values($report['columns']),
    new ArrayIterator($report['rows'])
);
