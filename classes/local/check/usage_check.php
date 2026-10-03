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
 * Checks whether a plugin is actually used anywhere.
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
 * Per plugin check for plugins that nothing on the site uses.
 *
 * This is a caution rather than a blocker: an unused plugin cannot break an
 * upgrade if it is removed first, and only a human can decide whether it is
 * really unused (the usage count only covers configured places, not, for
 * example, a plugin that sends data to an external system).
 */
final class usage_check implements check_interface {
    /**
     * Key of this check.
     *
     * @return string
     */
    public function get_key(): string {
        return 'usage';
    }

    /**
     * Only checked when usage could be measured for this plugin type.
     *
     * @param plugin_snapshot|null $plugin The plugin to check.
     * @param scan_context $context Everything collected for this scan.
     * @return bool
     */
    public function is_applicable(?plugin_snapshot $plugin, scan_context $context): bool {
        if ($plugin === null || $plugin->isstandard || !$plugin->installed) {
            return false;
        }

        $usage = $context->get_usage($plugin->component);
        return $usage !== null && array_key_exists('count', $usage);
    }

    /**
     * Report plugins that are not used anywhere.
     *
     * @param plugin_snapshot|null $plugin The plugin to check.
     * @param scan_context $context Everything collected for this scan.
     * @return finding[]
     */
    public function run(?plugin_snapshot $plugin, scan_context $context): array {
        if ($plugin === null) {
            return [];
        }

        $usage = $context->get_usage($plugin->component);
        if ($usage === null || !isset($usage['count']) || (int) $usage['count'] > 0) {
            return [];
        }

        return [
            new finding(
                'usage',
                severity::caution,
                'finding_unused_plugin',
                [],
                confidence::medium,
                false,
                'action_review_or_remove',
            ),
        ];
    }
}
