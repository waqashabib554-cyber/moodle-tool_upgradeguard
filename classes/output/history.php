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
 * Data for the scan history page.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\output;

use moodle_url;
use renderable;
use renderer_base;
use templatable;
use tool_upgradeguard\local\plural;
use tool_upgradeguard\local\repository\scan_repository;
use tool_upgradeguard\local\verdict as verdict_value;

/**
 * Every recorded scan, newest first, with links and the delete action.
 */
final class history implements renderable, templatable {
    /** @var array The scan rows of the current page. */
    private array $rows;

    /** @var int Total number of recorded scans. */
    private int $total;

    /** @var int The 1-based page number. */
    private int $page;

    /** @var bool Whether the current user may delete scans. */
    private bool $canmanage;

    /**
     * Create the history data.
     *
     * @param array $rows The scan rows of the current page.
     * @param int $total Total number of recorded scans.
     * @param int $page The 1-based page number.
     * @param bool $canmanage Whether the current user may delete scans.
     */
    public function __construct(array $rows, int $total, int $page, bool $canmanage) {
        $this->rows = $rows;
        $this->total = $total;
        $this->page = $page;
        $this->canmanage = $canmanage;
    }

    /**
     * Export the template context.
     *
     * @param renderer_base $output The renderer.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $pages = (int) ceil($this->total / scan_repository::RESULTS_PER_PAGE);

        return [
            // The shared page header takes the context line; the title of the
            // page is printed by Moodle from the page heading.
            'meta' => get_string('scancount', 'tool_upgradeguard', (object) [
                'count' => $this->total,
                'noun' => plural::form($this->total, 'noun_scan', 'noun_scans'),
            ]),
            'backurl' => (new moodle_url('/admin/tool/upgradeguard/index.php'))->out(false),
            'hasscans' => $this->rows !== [],
            'empty' => get_string('historyempty', 'tool_upgradeguard'),
            'canmanage' => $this->canmanage,
            'sesskey' => sesskey(),
            'deleteurl' => (new moodle_url('/admin/tool/upgradeguard/index.php'))->out(false),
            'scans' => $this->get_rows(),
            'haspagination' => $pages > 1,
            'pageof' => get_string('pageof', 'tool_upgradeguard', (object) [
                'page' => min($this->page, max(1, $pages)),
                'pages' => $pages,
            ]),
            'paginationlinks' => $this->get_pagination_links($pages),
        ];
    }

    /**
     * The scan rows of the current page, prepared for the table.
     *
     * @return array[]
     */
    private function get_rows(): array {
        $rows = [];
        foreach ($this->rows as $scan) {
            $verdict = verdict_value::tryFrom((string) $scan->verdict) ?? verdict_value::unknown;

            $rows[] = [
                'scanid' => (int) $scan->id,
                'timecreated' => userdate((int) $scan->timecreated),
                'targetversion' => (string) $scan->targetversion,
                'score' => $scan->score === null ? '' : (int) $scan->score,
                'hasscore' => $scan->score !== null,
                'verdict' => get_string($verdict->get_string_key(), 'tool_upgradeguard'),
                'verdictclass' => $this->get_verdict_class($verdict),
                'ownername' => $scan->ownername,
                'statuslabel' => get_string('historystatus_' . $scan->status, 'tool_upgradeguard'),
                'statusclass' => $this->get_status_class((string) $scan->status),
                'resultsurl' => (new moodle_url('/admin/tool/upgradeguard/results.php', ['id' => (int) $scan->id]))->out(false),
                'reporturl' => (new moodle_url('/admin/tool/upgradeguard/report.php', ['id' => (int) $scan->id]))->out(false),
            ];
        }

        return $rows;
    }

    /**
     * The numbered pagination links around the current page.
     *
     * @param int $pages Total number of pages.
     * @return array[]
     */
    private function get_pagination_links(int $pages): array {
        $window = [];
        $start = max(1, $this->page - 2);
        $end = min($pages, $this->page + 2);

        for ($page = $start; $page <= $end; $page++) {
            $window[] = [
                'page' => $page,
                'url' => (new moodle_url('/admin/tool/upgradeguard/history.php', ['page' => $page]))->out(false),
                'iscurrent' => $page === $this->page,
            ];
        }

        return $window;
    }

    /**
     * Bootstrap background class for a scan status.
     *
     * @param string $status The stored status: queued, running, finished or failed.
     * @return string
     */
    private function get_status_class(string $status): string {
        return match ($status) {
            'queued' => 'info',
            'running' => 'warning',
            'failed' => 'danger',
            default => 'success',
        };
    }

    /**
     * Bootstrap background class for a verdict.
     *
     * @param verdict_value $verdict The verdict.
     * @return string
     */
    private function get_verdict_class(verdict_value $verdict): string {
        return match ($verdict) {
            verdict_value::go => 'success',
            verdict_value::careful => 'warning',
            verdict_value::stop => 'danger',
            verdict_value::unknown => 'secondary',
        };
    }
}
