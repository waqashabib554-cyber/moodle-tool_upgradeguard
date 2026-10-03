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
 * Checks whether a newer version of a plugin is available.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local\check;

use tool_upgradeguard\local\confidence;
use tool_upgradeguard\local\finding;
use tool_upgradeguard\local\plugin_snapshot;
use tool_upgradeguard\local\scan_context;
use tool_upgradeguard\local\severity;

/**
 * Per plugin informational tag for an available update.
 *
 * The update information comes from the core update checker, which only reports
 * updates that are newer than the version on disk. Core does not expose the
 * update's declared target Moodle support range, so this check never claims
 * that an update fixes a compatibility finding.
 */
final class update_available_check implements check_interface {
    /**
     * Key of this check.
     *
     * @return string
     */
    public function get_key(): string {
        return 'update_available';
    }

    /**
     * Only plugins with a known newer version are checked.
     *
     * @param plugin_snapshot|null $plugin The plugin to check.
     * @param scan_context $context Everything collected for this scan.
     * @return bool
     */
    public function is_applicable(?plugin_snapshot $plugin, scan_context $context): bool {
        if ($plugin === null || $plugin->isstandard) {
            return false;
        }

        return $context->get_update($plugin->component) !== null;
    }

    /**
     * Report the available update.
     *
     * @param plugin_snapshot|null $plugin The plugin to check.
     * @param scan_context $context Everything collected for this scan.
     * @return finding[]
     */
    public function run(?plugin_snapshot $plugin, scan_context $context): array {
        if ($plugin === null) {
            return [];
        }

        $update = $context->get_update($plugin->component);
        if ($update === null) {
            return [];
        }

        return [
            new finding(
                'update_available',
                severity::info,
                'finding_update_available',
                [
                    'version' => (string) $update->version,
                    'release' => (string) ($update->release ?? ''),
                    'target' => $context->target->version,
                ],
                confidence::low,
                false,
                'action_check_plugin_page',
                $update->url,
            ),
        ];
    }
}
