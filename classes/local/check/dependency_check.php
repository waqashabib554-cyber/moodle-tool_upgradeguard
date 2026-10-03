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
 * Checks the plugins that a plugin depends on.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local\check;

use coding_exception;
use tool_upgradeguard\local\confidence;
use tool_upgradeguard\local\finding;
use tool_upgradeguard\local\plugin_snapshot;
use tool_upgradeguard\local\scan_context;
use tool_upgradeguard\local\severity;

/**
 * Per plugin check of the other plugins it requires.
 *
 * A missing dependency is a blocker: the upgrade cannot be applied while a
 * plugin requires something that is not there.
 */
final class dependency_check implements check_interface {
    /**
     * Key of this check.
     *
     * @return string
     */
    public function get_key(): string {
        return 'dependency';
    }

    /**
     * Only third party plugins that core knows about can declare dependencies.
     *
     * @param plugin_snapshot|null $plugin The plugin to check.
     * @param scan_context $context Everything collected for this scan.
     * @return bool
     */
    public function is_applicable(?plugin_snapshot $plugin, scan_context $context): bool {
        return $plugin !== null && !$plugin->isstandard && $plugin->coreplugin !== null;
    }

    /**
     * Compare the declared dependencies with the plugins that are installed.
     *
     * @param plugin_snapshot|null $plugin The plugin to check.
     * @param scan_context $context Everything collected for this scan.
     * @return finding[]
     */
    public function run(?plugin_snapshot $plugin, scan_context $context): array {
        if ($plugin === null || $plugin->coreplugin === null) {
            return [];
        }

        try {
            $dependencies = $plugin->coreplugin->get_other_required_plugins();
        } catch (coding_exception $e) {
            debugging('tool_upgradeguard: ' . $plugin->component . ' declares dependencies incorrectly', DEBUG_DEVELOPER);
            return [];
        }

        $findings = [];

        foreach ($dependencies as $dependency => $requiredversion) {
            $dependency = (string) $dependency;
            $dependencyplugin = $context->get_plugin($dependency);

            if ($dependencyplugin === null || !$dependencyplugin->installed) {
                $findings[] = new finding(
                    'dependency',
                    severity::blocker,
                    'finding_dependency_missing',
                    ['dependency' => $dependency],
                    confidence::high,
                    false,
                    'action_install_dependency',
                );
                continue;
            }

            if ($requiredversion != ANY_VERSION && (int) $dependencyplugin->versiondisk < (int) $requiredversion) {
                $findings[] = new finding(
                    'dependency',
                    severity::caution,
                    'finding_dependency_outdated',
                    [
                        'dependency' => $dependency,
                        'required' => (string) $requiredversion,
                        'installed' => $dependencyplugin->versiondisk,
                    ],
                    confidence::high,
                    false,
                    'action_install_dependency',
                );
            }
        }

        return $findings;
    }
}
