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
 * Data for the results table of one scan.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\output;

use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;
use tool_upgradeguard\local\plural;
use tool_upgradeguard\local\repository\scan_repository;
use tool_upgradeguard\local\severity;
use tool_upgradeguard\local\status;
use tool_upgradeguard\local\target;
use tool_upgradeguard\local\usage_text;
use tool_upgradeguard\local\verdict as verdict_value;

/**
 * The filterable, sortable and paginated plugin table of one scan.
 */
final class results implements renderable, templatable {
    /** @var stdClass The stored scan row. */
    private stdClass $scan;

    /** @var target The target the scan was run against. */
    private target $target;

    /** @var array Normalised filters: search, status, type, inuse. */
    private array $filters;

    /** @var array Normalised sort: by, dir. */
    private array $sort;

    /** @var stdClass[] The plugin rows of the current page. */
    private array $rows;

    /** @var finding[][] Findings of the current page, grouped by component. */
    private array $findings;

    /** @var int Total number of matching rows across all pages. */
    private int $total;

    /** @var string[] The distinct plugin types of the scan. */
    private array $types;

    /**
     * Create the results table data.
     *
     * @param stdClass $scan The stored scan row.
     * @param target $target The target the scan was run against.
     * @param array $filters Normalised filters.
     * @param array $sort Normalised sort.
     * @param array $rows The plugin rows of the current page.
     * @param array $findings Findings of the current page, grouped by component.
     * @param int $total Total number of matching rows.
     * @param string[] $types The distinct plugin types of the scan.
     */
    public function __construct(
        stdClass $scan,
        target $target,
        array $filters,
        array $sort,
        array $rows,
        array $findings,
        int $total,
        array $types,
    ) {
        $this->scan = $scan;
        $this->target = $target;
        $this->filters = $filters;
        $this->sort = $sort;
        $this->rows = $rows;
        $this->findings = $findings;
        $this->total = $total;
        $this->types = $types;
    }

    /**
     * Export the template context.
     *
     * @param renderer_base $output The renderer.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $verdict = verdict_value::tryFrom((string) $this->scan->verdict) ?? verdict_value::unknown;
        $pages = (int) ceil($this->total / scan_repository::RESULTS_PER_PAGE);

        $context = [
            'scanid' => (int) $this->scan->id,
            'currentversion' => (string) $this->scan->currentversion,
            'targetversion' => (string) $this->scan->targetversion,
            'verdict' => get_string($verdict->get_string_key(), 'tool_upgradeguard'),
            'verdictclass' => $this->get_verdict_class($verdict),
            'hasscore' => $this->scan->score !== null,
            'score' => $this->scan->score === null ? 0 : (int) $this->scan->score,
            'backurl' => (new moodle_url('/admin/tool/upgradeguard/index.php'))->out(false),
            'formaction' => (new moodle_url('/admin/tool/upgradeguard/results.php'))->out(false),
            'reseturl' => (new moodle_url('/admin/tool/upgradeguard/results.php', ['id' => (int) $this->scan->id]))->out(false),
            'search' => $this->filters['search'],
            'statusoptions' => $this->get_status_options(),
            'typeoptions' => $this->get_type_options(),
            'inuse' => $this->filters['inuse'],
            'sortby' => $this->sort['by'],
            'dir' => $this->sort['dir'],
            'resultcount' => get_string('resultcount', 'tool_upgradeguard', (object) [
                'count' => $this->total,
                'noun' => plural::form($this->total, 'noun_plugin', 'noun_plugins'),
            ]),
            'plugins' => $this->get_rows(),
            'hasplugins' => $this->rows !== [],
            'noresults' => get_string('noresults', 'tool_upgradeguard'),
            'haspagination' => $pages > 1,
            'pageof' => get_string('pageof', 'tool_upgradeguard', (object) [
                'page' => min($this->filters['page'], max(1, $pages)),
                'pages' => $pages,
            ]),
            'paginationlinks' => $this->get_pagination_links($pages),
        ];

        foreach ($this->get_sort_links() as $key => $link) {
            $context['sort' . $key] = $link;
        }

        return $context;
    }

    /**
     * The status dropdown options, All first.
     *
     * @return array[]
     */
    private function get_status_options(): array {
        $options = [
            [
                'value' => '',
                'label' => get_string('filterall', 'tool_upgradeguard'),
                'selected' => $this->filters['status'] === '',
            ],
        ];
        foreach (status::cases() as $status) {
            $options[] = [
                'value' => $status->value,
                'label' => get_string($status->get_string_key(), 'tool_upgradeguard'),
                'selected' => $this->filters['status'] === $status->value,
            ];
        }

        return $options;
    }

    /**
     * The type dropdown options, All first.
     *
     * @return array[]
     */
    private function get_type_options(): array {
        $options = [
            [
                'value' => '',
                'label' => get_string('filterall', 'tool_upgradeguard'),
                'selected' => $this->filters['type'] === '',
            ],
        ];
        foreach ($this->types as $type) {
            $options[] = ['value' => $type, 'label' => $type, 'selected' => $this->filters['type'] === $type];
        }

        return $options;
    }

    /**
     * The sortable column headers with their toggle links.
     *
     * @return array[]
     */
    private function get_sort_links(): array {
        $labels = [
            'name' => get_string('col_component', 'tool_upgradeguard'),
            'type' => get_string('col_type', 'tool_upgradeguard'),
            'status' => get_string('col_status', 'tool_upgradeguard'),
        ];

        $links = [];
        foreach ($labels as $key => $label) {
            $dir = 'asc';
            $arrow = '';
            if ($this->sort['by'] === $key) {
                $dir = $this->sort['dir'] === 'asc' ? 'desc' : 'asc';
                $arrow = $this->sort['dir'] === 'asc' ? ' ▲' : ' ▼';
            }

            $links[$key] = [
                'url' => $this->filter_url(['sortby' => $key, 'dir' => $dir]),
                'label' => $label . $arrow,
            ];
        }

        return $links;
    }

    /**
     * The plugin rows of the current page, findings attached.
     *
     * @return array[]
     */
    private function get_rows(): array {
        $rows = [];
        foreach ($this->rows as $row) {
            $status = status::tryFrom((string) $row->status) ?? status::unknown;
            $findings = $this->findings[$row->component] ?? [];

            $findingrows = [];
            foreach ($findings as $finding) {
                $actionurl = $finding->get_action_url();
                $findingrows[] = [
                    'message' => $finding->get_message(),
                    'action' => $finding->get_action(),
                    'actionkey' => $finding->actionkey,
                    'actionurl' => $actionurl ?? '',
                    'hasactionurl' => !empty($actionurl),
                    'severity' => get_string($finding->severity->get_string_key(), 'tool_upgradeguard'),
                    'severityclass' => $this->get_severity_class($finding->severity),
                    'confidence' => get_string($finding->confidence->get_string_key(), 'tool_upgradeguard'),
                ];
            }

            $rows[] = [
                'component' => $row->component,
                'displayname' => $row->displayname,
                'type' => $row->plugintype,
                'statuslabel' => get_string($status->get_string_key(), 'tool_upgradeguard'),
                'statusclass' => $status->get_css_class(),
                'version' => $row->versiondisk === '' ? $row->pluginrelease : $row->versiondisk,
                'usage' => usage_text::from_plugin($row),
                'findingcount' => count($findingrows),
                'hasfindings' => $findingrows !== [],
                'findings' => $findingrows,
            ];
        }

        return $rows;
    }

    /**
     * The numbered pagination links around the current page.
     *
     * @param int $pages Total number of pages.
     * @return array[] Links with page, url and iscurrent.
     */
    private function get_pagination_links(int $pages): array {
        $window = [];
        $current = $this->filters['page'];
        $start = max(1, $current - 2);
        $end = min($pages, $current + 2);

        for ($page = $start; $page <= $end; $page++) {
            $window[] = [
                'page' => $page,
                'url' => $this->filter_url(['page' => $page]),
                'iscurrent' => $page === $current,
            ];
        }

        return $window;
    }

    /**
     * The URL of the results table with the given parameters changed.
     *
     * @param array $overrides The parameters to set on top of the current filters.
     * @return string
     */
    private function filter_url(array $overrides): string {
        return (new moodle_url('/admin/tool/upgradeguard/results.php', array_merge([
            'id' => (int) $this->scan->id,
            'search' => $this->filters['search'],
            'status' => $this->filters['status'],
            'type' => $this->filters['type'],
            'inuse' => $this->filters['inuse'] ? 1 : null,
            'sortby' => $this->sort['by'],
            'dir' => $this->sort['dir'],
            'page' => $this->filters['page'],
        ], $overrides)))->out(false);
    }

    /**
     * Bootstrap background class for a severity.
     *
     * @param severity $severity The severity.
     * @return string
     */
    private function get_severity_class(severity $severity): string {
        return match ($severity) {
            severity::blocker => 'danger',
            severity::caution => 'warning',
            severity::info => 'info',
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
