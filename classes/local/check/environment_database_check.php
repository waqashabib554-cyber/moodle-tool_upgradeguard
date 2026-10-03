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
 * Checks the database server against the target Moodle version.
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
use tool_upgradeguard\local\target;

/**
 * Site wide check: can the database server of this site run the target branch?
 *
 * Every minimum comes from the rule dataset, which records the source of each
 * number and whether it was verified. A vendor the dataset has no number for is
 * reported as an unknown requirement with low confidence, never as a guess.
 */
final class environment_database_check implements check_interface {
    /**
     * Key of this check.
     *
     * @return string
     */
    public function get_key(): string {
        return 'environment_database';
    }

    /**
     * Runs once per scan, including when the database could not be identified.
     *
     * @param plugin_snapshot|null $plugin Always null for this check.
     * @param scan_context $context Everything collected for this scan.
     * @return bool
     */
    public function is_applicable(?plugin_snapshot $plugin, scan_context $context): bool {
        return $plugin === null;
    }

    /**
     * Compare the database server version with the target requirement.
     *
     * @param plugin_snapshot|null $plugin Always null for this check.
     * @param scan_context $context Everything collected for this scan.
     * @return finding[]
     */
    public function run(?plugin_snapshot $plugin, scan_context $context): array {
        $target = $context->target;
        $vendor = $context->dbvendor;

        if (!$context->has_database_information()) {
            return [
                new finding(
                    'environment_database',
                    severity::caution,
                    'finding_environment_database_unavailable',
                    ['target' => $target->version],
                    confidence::low,
                ),
            ];
        }

        $minimum = $target->get_database_minimum($vendor);

        if ($minimum === null) {
            // Some vendors appear and disappear between branches: Oracle is
            // listed for 4.4 and 4.5 but no longer for 5.0 and later. Saying
            // "unknown requirement" is better than inventing a number.
            return [
                new finding(
                    'environment_database',
                    severity::info,
                    'finding_environment_database_unknown',
                    [
                        'vendor' => $vendor,
                        'current' => $context->dbversion,
                        'target' => $target->version,
                    ],
                    confidence::low,
                ),
            ];
        }

        if (version_compare($context->dbversion, $minimum, '>=')) {
            return [];
        }

        $verified = $target->is_environment_verified('database');

        return [
            new finding(
                'environment_database',
                severity::blocker,
                $verified ? 'finding_environment_database_too_old' : 'finding_environment_database_too_old_unverified',
                [
                    'vendor' => $vendor,
                    'current' => $context->dbversion,
                    'minimum' => $minimum,
                    'target' => $target->version,
                ],
                $this->get_confidence($target, $vendor, $verified),
                false,
                'action_upgrade_database',
            ),
        ];
    }

    /**
     * How much the comparison can be trusted.
     *
     * Low when the dataset number itself is not verified, medium when the number
     * is official but this plugin was never exercised on that database vendor,
     * high when both hold.
     *
     * @param target $target The target being scanned against.
     * @param string $vendor Database vendor of this site.
     * @param bool $verified Whether the dataset number is verified.
     * @return confidence
     */
    private function get_confidence(target $target, string $vendor, bool $verified): confidence {
        if (!$verified) {
            return confidence::low;
        }

        return $target->is_database_exercised($vendor) ? confidence::high : confidence::medium;
    }
}
