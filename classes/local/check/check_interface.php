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
 * The contract every check implements.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local\check;

use tool_upgradeguard\local\finding;
use tool_upgradeguard\local\plugin_snapshot;
use tool_upgradeguard\local\scan_context;

/**
 * A single thing that can be checked.
 *
 * Checks are stateless and side effect free: they receive what they need as
 * arguments and return findings. Adding a new check means adding one class and
 * registering it in the registry, nothing else in the plugin has to change.
 */
interface check_interface {
    /**
     * Stable key of this check, stored with every finding it produces.
     *
     * @return string
     */
    public function get_key(): string;

    /**
     * Whether this check has anything to say about the given subject.
     *
     * @param plugin_snapshot|null $plugin The plugin to check, or null for a site wide check.
     * @param scan_context $context Everything collected for this scan.
     * @return bool
     */
    public function is_applicable(?plugin_snapshot $plugin, scan_context $context): bool;

    /**
     * Run the check.
     *
     * @param plugin_snapshot|null $plugin The plugin to check, or null for a site wide check.
     * @param scan_context $context Everything collected for this scan.
     * @return finding[] The findings, ordered as the check wants them shown.
     */
    public function run(?plugin_snapshot $plugin, scan_context $context): array;
}
