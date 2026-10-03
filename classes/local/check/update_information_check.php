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
 * Reports when the scan had no plugin update information.
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
 * Site-wide notification for unavailable update information.
 *
 * A plugin's own declared compatibility remains valid evidence even while
 * update data is absent. This finding therefore informs the administrator at
 * site scope instead of turning every plugin into Unknown.
 */
final class update_information_check implements check_interface {
    /**
     * Get the stable check key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'update_information';
    }

    /**
     * Whether the site had no update information.
     *
     * @param plugin_snapshot|null $plugin The plugin, null for a site check.
     * @param scan_context $context The scan context.
     * @return bool
     */
    public function is_applicable(?plugin_snapshot $plugin, scan_context $context): bool {
        return $plugin === null && !$context->updateinformationavailable;
    }

    /**
     * Create the site-wide update-information notification.
     *
     * @param plugin_snapshot|null $plugin The plugin, null for a site check.
     * @param scan_context $context The scan context.
     * @return finding[]
     */
    public function run(?plugin_snapshot $plugin, scan_context $context): array {
        if (!$this->is_applicable($plugin, $context)) {
            return [];
        }

        return [new finding(
            'update_information',
            severity::info,
            'finding_update_information_missing',
            [],
            confidence::low,
            false,
            'action_check_updates_now',
        )];
    }
}
