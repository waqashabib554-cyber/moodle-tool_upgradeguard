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
 * The scan history page.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_upgradeguard\local\repository\scan_repository;
use tool_upgradeguard\output\history;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$systemcontext = context_system::instance();
require_capability('tool/upgradeguard:view', $systemcontext);

$repository = new scan_repository();
$total = $repository->count_scans();
$pages = max(1, (int) ceil($total / scan_repository::RESULTS_PER_PAGE));
$page = min(max(1, optional_param('page', 1, PARAM_INT)), $pages);

// Like the dashboard and the report, this is a page of the Upgrade Guard admin
// section, so the navigation tree and the plugin stylesheet belong to it. The
// page sets its own heading, because the one the admin section would give it is
// the name of the tool, which says nothing about the screen.
admin_externalpage_setup('tool_upgradeguard');
$PAGE->set_url(new moodle_url('/admin/tool/upgradeguard/history.php', ['page' => $page]));
$PAGE->set_title(get_string('historyheading', 'tool_upgradeguard'));
$PAGE->set_heading(get_string('historyheading', 'tool_upgradeguard'));

$rows = $repository->get_scans_page(scan_repository::RESULTS_PER_PAGE, ($page - 1) * scan_repository::RESULTS_PER_PAGE);
$canmanage = has_capability('tool/upgradeguard:manage', $systemcontext);

$table = new history($rows, $total, $page, $canmanage);

// The global $OUTPUT is only a real renderer after the header has been sent,
// so the renderable gets an explicit one.
$renderer = $PAGE->get_renderer('tool_upgradeguard');

echo $OUTPUT->header();
echo $renderer->render_from_template('tool_upgradeguard/history', $table->export_for_template($renderer));
echo $OUTPUT->footer();
