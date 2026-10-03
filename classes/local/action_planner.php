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
 * Builds the "what to do next" list of a scan.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

/**
 * Groups the findings of a scan into an ordered list of actions.
 *
 * Administrators do not want a list of four hundred findings, they want to know
 * what to do. Findings that lead to the same action are merged, so that
 * "update these plugins" becomes one entry listing every plugin it applies to.
 */
final class action_planner {
    /**
     * Build the action list.
     *
     * Findings that need no action are left out on purpose: the action list is
     * for work, not for reassurance.
     *
     * @param array $grouped Map of component (or '' for site wide) to finding[].
     * @return array List of actions: [
     *     'action' => string,
     *     'actionurl' => string|null,
     *     'actionkey' => string,
     *     'severity' => severity,
     *     'components' => string[],
     * ].
     */
    public function plan(array $grouped): array {
        $actions = [];

        foreach ($grouped as $component => $findings) {
            foreach ($findings as $finding) {
                if ($finding->actionkey === '' || $finding->actionkey === 'action_none') {
                    continue;
                }

                $key = $this->get_action_key($finding);
                if (!isset($actions[$key])) {
                    $actions[$key] = [
                        'action' => $finding->get_action(),
                        'actionurl' => $finding->get_action_url(),
                        'actionkey' => $finding->actionkey,
                        'severity' => $finding->severity,
                        'components' => [],
                    ];
                }

                if ($finding->severity->rank() > $actions[$key]['severity']->rank()) {
                    $actions[$key]['severity'] = $finding->severity;
                }

                $label = $component === '' ? get_string('sitewidescope', 'tool_upgradeguard') : $component;
                if (!in_array($label, $actions[$key]['components'], true)) {
                    $actions[$key]['components'][] = $label;
                }
            }
        }

        $list = array_values($actions);
        usort($list, static function (array $a, array $b): int {
            if ($a['severity']->rank() !== $b['severity']->rank()) {
                return $b['severity']->rank() <=> $a['severity']->rank();
            }
            return count($b['components']) <=> count($a['components']);
        });

        return $list;
    }

    /**
     * Key that makes findings with the same action and the same message collapse into one.
     *
     * @param finding $finding The finding.
     * @return string
     */
    private function get_action_key(finding $finding): string {
        $params = $finding->params;
        ksort($params);
        return $finding->actionkey . '|' . md5(json_encode($params) ?: '');
    }
}
