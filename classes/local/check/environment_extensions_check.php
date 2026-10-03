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
 * Checks the loaded PHP extensions against the target Moodle version.
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
 * Site wide check: does this server have the PHP extensions the target needs?
 *
 * The list of required extensions comes from the rule dataset, which was read
 * from core's own admin/environment.xml. Optional extensions are not reported:
 * Moodle works without them, and listing them would add noise rather than
 * information.
 */
final class environment_extensions_check implements check_interface {
    /**
     * Key of this check.
     *
     * @return string
     */
    public function get_key(): string {
        return 'environment_extensions';
    }

    /**
     * Runs once per scan when the target has a required-extension list.
     *
     * @param plugin_snapshot|null $plugin Always null for this check.
     * @param scan_context $context Everything collected for this scan.
     * @return bool
     */
    public function is_applicable(?plugin_snapshot $plugin, scan_context $context): bool {
        return $plugin === null
            && !empty($context->target->get_required_extensions());
    }

    /**
     * Report every required extension that is not loaded.
     *
     * One finding per extension, so the administrator can see exactly what to
     * install instead of one lumped together message.
     *
     * @param plugin_snapshot|null $plugin Always null for this check.
     * @param scan_context $context Everything collected for this scan.
     * @return finding[]
     */
    public function run(?plugin_snapshot $plugin, scan_context $context): array {
        $target = $context->target;

        if (!$context->has_extension_information()) {
            return [
                new finding(
                    'environment_extensions',
                    severity::caution,
                    'finding_environment_extensions_unavailable',
                    ['target' => $target->version],
                    confidence::low,
                ),
            ];
        }

        $verified = $target->is_environment_verified('extensions');

        $findings = [];
        foreach ($target->get_required_extensions() as $extension) {
            if ($context->has_extension($extension)) {
                continue;
            }

            $findings[] = new finding(
                'environment_extensions',
                severity::blocker,
                $verified ? 'finding_environment_extension_missing' : 'finding_environment_extension_missing_unverified',
                [
                    'extension' => $extension,
                    'target' => $target->version,
                ],
                $verified ? confidence::high : confidence::low,
                false,
                'action_install_php_extension',
            );
        }

        return $findings;
    }
}
