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
 * Checks whether a plugin is stored where the target version reads plugins from.
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
 * Site wide and per plugin check for the Moodle 5.1 public directory change.
 *
 * From Moodle 5.1 onwards plugins are only read from the public directory.
 * Two situations are reported:
 *
 * - A plugin was found outside the directory that core reads. It is already
 *   invisible on the running site, so this is a blocker.
 * - The site itself still uses the old layout and is being scanned against a
 *   target that needs the new one. Every plugin has to be moved by hand, which
 *   is a caution because it is work, not a breakage, as long as it is done.
 */
final class public_location_check implements check_interface {
    /**
     * Key of this check.
     *
     * @return string
     */
    public function get_key(): string {
        return 'public_location';
    }

    /**
     * Only relevant for third party plugins of a target that uses the public directory.
     *
     * @param plugin_snapshot|null $plugin The plugin to check.
     * @param scan_context $context Everything collected for this scan.
     * @return bool
     */
    public function is_applicable(?plugin_snapshot $plugin, scan_context $context): bool {
        if ($plugin === null || $plugin->isstandard) {
            return false;
        }

        if (!$context->target->publiclayout) {
            return false;
        }

        return $plugin->needs_move();
    }

    /**
     * Report the move that is needed.
     *
     * @param plugin_snapshot|null $plugin The plugin to check.
     * @param scan_context $context Everything collected for this scan.
     * @return finding[]
     */
    public function run(?plugin_snapshot $plugin, scan_context $context): array {
        if ($plugin === null) {
            return [];
        }

        $params = [
            'oldpath' => ltrim($plugin->currentpath, '/'),
            'newpath' => ltrim($plugin->newpath, '/'),
        ];

        if ($plugin->orphan) {
            return [
                new finding(
                    'public_location',
                    severity::blocker,
                    'finding_legacy_location',
                    $params,
                    confidence::high,
                    false,
                    'action_move_plugin_to_public',
                ),
            ];
        }

        return [
            new finding(
                'public_location',
                severity::caution,
                'finding_legacy_location',
                $params,
                confidence::high,
                false,
                'action_move_plugin_to_public',
            ),
        ];
    }
}
