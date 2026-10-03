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
 * Checks the PHP version of the server against the target Moodle version.
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
 * Site wide check: can this server even run the target Moodle version?
 *
 * The required PHP version of every target is part of the rule dataset, so this
 * finding is exact rather than a guess.
 */
final class site_php_check implements check_interface {
    /**
     * Key of this check.
     *
     * @return string
     */
    public function get_key(): string {
        return 'site_php';
    }

    /**
     * Only runs once per scan, not per plugin.
     *
     * @param plugin_snapshot|null $plugin Always null for this check.
     * @param scan_context $context Everything collected for this scan.
     * @return bool
     */
    public function is_applicable(?plugin_snapshot $plugin, scan_context $context): bool {
        return $plugin === null;
    }

    /**
     * Compare the PHP version of the server with the requirement of the target.
     *
     * @param plugin_snapshot|null $plugin Always null for this check.
     * @param scan_context $context Everything collected for this scan.
     * @return finding[]
     */
    public function run(?plugin_snapshot $plugin, scan_context $context): array {
        if ($context->is_php_new_enough()) {
            return [];
        }

        // A number that could not be read from an official source is reported as
        // low confidence, so the administrator knows to check it themselves.
        $verified = $context->target->is_environment_verified('php');

        return [
            new finding(
                'site_php',
                severity::blocker,
                $verified ? 'finding_site_php_too_old' : 'finding_site_php_too_old_unverified',
                [
                    'php' => $context->phpversion,
                    'target' => $context->target->version,
                    'phpmin' => $context->target->phpmin,
                ],
                $verified ? confidence::high : confidence::low,
                false,
                'action_upgrade_php',
            ),
        ];
    }
}
