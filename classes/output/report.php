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
 * Data for the printable report of one scan.
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
use tool_upgradeguard\local\checklist;
use tool_upgradeguard\local\export_payload;
use tool_upgradeguard\local\finding;
use tool_upgradeguard\local\move_list;
use tool_upgradeguard\local\plural;
use tool_upgradeguard\local\severity;
use tool_upgradeguard\local\status;
use tool_upgradeguard\local\target;
use tool_upgradeguard\local\usage_text;
use tool_upgradeguard\local\verdict as verdict_value;

/**
 * The whole result of one scan, prepared for the printable report template.
 */
final class report implements renderable, templatable {
    /** @var target The target the scan was run against. */
    private target $target;

    /** @var stdClass The stored scan row. */
    private stdClass $scan;

    /** @var array The stored plugin rows of the scan. */
    private array $plugins;

    /** @var array Findings grouped by component, '' for site wide findings. */
    private array $findings;

    /** @var string Configured support email, empty when unset. */
    private string $supportemail;

    /** @var string Name of the site the scan was run on. */
    private string $sitename;

    /**
     * Create the report data.
     *
     * @param target $target The target the scan was run against.
     * @param stdClass $scan The stored scan row.
     * @param array $plugins Plugin rows of that scan.
     * @param array $findings Findings grouped by component.
     * @param string $supportemail Configured support email, empty when unset.
     * @param string $sitename Name of the site the scan was run on.
     */
    public function __construct(
        target $target,
        stdClass $scan,
        array $plugins,
        array $findings,
        string $supportemail,
        string $sitename,
    ) {
        $this->target = $target;
        $this->scan = $scan;
        $this->plugins = $plugins;
        $this->findings = $findings;
        $this->supportemail = $supportemail;
        $this->sitename = $sitename;
    }

    /**
     * Export the template context.
     *
     * @param renderer_base $output The renderer.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $verdict = verdict_value::tryFrom((string) $this->scan->verdict) ?? verdict_value::unknown;
        $movelist = move_list::build($this->target, $this->plugins);
        $checklist = checklist::build($this->target, $this->scan, $this->plugins, $this->findings[''] ?? []);
        $blockers = $this->get_blockers();

        $context = [
            'reportheading' => get_string('reportheading', 'tool_upgradeguard', (object) [
                'site' => $this->sitename,
                'target' => $this->scan->targetversion,
            ]),
            'meta' => get_string('reportmeta', 'tool_upgradeguard', (object) [
                'date' => userdate((int) ($this->scan->timefinished ?: $this->scan->timecreated)),
                'version' => (int) $this->scan->datasetversion,
            ]),
            'currentversion' => (string) $this->scan->currentversion,
            'targetversion' => (string) $this->scan->targetversion,
            'verdict' => get_string($verdict->get_string_key(), 'tool_upgradeguard'),
            'verdictclass' => $this->get_verdict_class($verdict),
            'hasscore' => $this->scan->score !== null,
            'score' => $this->scan->score === null ? 0 : (int) $this->scan->score,
            'readinessscore' => get_string('readinessscore', 'tool_upgradeguard'),
            'blockersheading' => get_string('reportblockersheading', 'tool_upgradeguard'),
            'blockers' => $blockers,
            'hasblockers' => $blockers !== [],
            'plugingroupsheading' => get_string('plugingroupsheading', 'tool_upgradeguard'),
            'plugingroups' => $this->get_plugin_groups(),
            'disclaimer' => get_string('disclaimer', 'tool_upgradeguard'),
            'datasetversion' => get_string('dataset_version', 'tool_upgradeguard', (int) $this->scan->datasetversion),
            'generated' => get_string('reportgenerated', 'tool_upgradeguard', userdate(time())),
            'haswrongresult' => false,
            'backurl' => (new moodle_url('/admin/tool/upgradeguard/index.php'))->out(false),
        ];

        $context += environment_context::build($this->target, $this->scan, $this->findings[''] ?? []);
        $context += $this->get_move_list_context($movelist);
        $context += $this->get_checklist_context($checklist);

        $wrongresult = export_payload::wrongresult_url($this->supportemail, (int) $this->scan->id);
        if ($wrongresult !== null) {
            $context['haswrongresult'] = true;
            $context['wrongresulturl'] = $wrongresult;
            $context['wrongresultlabel'] = get_string('reportwrongresult', 'tool_upgradeguard');
        }

        return $context;
    }

    /**
     * Every blocker finding of the scan, site wide findings first.
     *
     * @return array[] Rows with scope, message and confidence label.
     */
    private function get_blockers(): array {
        $blockers = [];

        $add = static function (string $scope, finding $finding) use (&$blockers): void {
            if ($finding->severity !== severity::blocker) {
                return;
            }
            $blockers[] = [
                'scope' => $scope,
                'message' => $finding->get_message(),
                'confidencelabel' => get_string($finding->confidence->get_string_key(), 'tool_upgradeguard'),
            ];
        };

        foreach ($this->findings[''] ?? [] as $finding) {
            $add(get_string('report_scope_site', 'tool_upgradeguard'), $finding);
        }
        foreach ($this->plugins as $plugin) {
            foreach ($this->findings[$plugin->component] ?? [] as $finding) {
                $add($plugin->component, $finding);
            }
        }

        return $blockers;
    }

    /**
     * The plugin list grouped by status, worst first.
     *
     * @return array[] Groups with their status badge and the plugin rows.
     */
    private function get_plugin_groups(): array {
        $order = [status::blocker, status::caution, status::update, status::ready, status::unknown];
        $grouped = [];
        foreach ($this->plugins as $plugin) {
            $status = status::tryFrom((string) $plugin->status) ?? status::unknown;
            $grouped[$status->value][] = $plugin;
        }

        $groups = [];
        foreach ($order as $status) {
            $rows = $grouped[$status->value] ?? [];
            if ($rows === []) {
                continue;
            }

            $grouprows = [];
            foreach ($rows as $plugin) {
                $findingrows = [];
                foreach ($this->findings[$plugin->component] ?? [] as $finding) {
                    $findingrows[] = [
                        'message' => $finding->get_message(),
                        'confidencelabel' => get_string($finding->confidence->get_string_key(), 'tool_upgradeguard'),
                    ];
                }

                $grouprows[] = [
                    'component' => $plugin->component,
                    'displayname' => $plugin->displayname,
                    'version' => $plugin->versiondisk === '' ? $plugin->pluginrelease : $plugin->versiondisk,
                    'usage' => usage_text::from_plugin($plugin),
                    'statuslabel' => get_string($status->get_string_key(), 'tool_upgradeguard'),
                    'statusclass' => $status->get_css_class(),
                    'findings' => $findingrows,
                    'hasfindings' => $findingrows !== [],
                ];
            }

            $groups[] = [
                'statuslabel' => get_string($status->get_string_key(), 'tool_upgradeguard'),
                'statusclass' => $status->get_css_class(),
                'count' => count($grouprows),
                'rows' => $grouprows,
            ];
        }

        return $groups;
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

    /**
     * The move list section, empty for targets before the layout change.
     *
     * @param array|null $build The built move list, null when not applicable.
     * @return array
     */
    private function get_move_list_context(?array $build): array {
        if ($build === null) {
            return [];
        }

        return [
            'hasmovelist' => true,
            'moveheading' => get_string('moveheading', 'tool_upgradeguard'),
            'movenote' => get_string('movenote', 'tool_upgradeguard'),
            'movesummary' => get_string('movesummary', 'tool_upgradeguard', (object) [
                'moved' => $build['movecount'],
                'total' => $build['plugincount'],
                'noun' => plural::form($build['movecount'], 'noun_plugin', 'noun_plugins'),
            ]),
            'moveplugins' => move_list::to_rows($build),
            'hasmoves' => $build['movecount'] > 0,
            'movecsvtext' => $build['csvtext'],
            'movecommandstext' => $build['commandstext'],
            'hasmovecommands' => $build['commandstext'] !== '',
            'movenocommands' => get_string('movenocommands', 'tool_upgradeguard'),
            'movedisclaimer' => get_string('movedisclaimer', 'tool_upgradeguard'),
        ];
    }

    /**
     * The checklist section, empty when the scan has nothing to build it from.
     *
     * @param array[] $items The built checklist items.
     * @return array
     */
    private function get_checklist_context(array $items): array {
        if (empty($items)) {
            return [];
        }

        return [
            'haschecklist' => true,
            'checklistheading' => get_string('checklistheading', 'tool_upgradeguard'),
            'checklistnote' => get_string('checklistnote', 'tool_upgradeguard'),
            'checklistitems' => checklist::translate($items),
        ];
    }
}
