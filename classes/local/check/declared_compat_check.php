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
 * Checks what a plugin declares about the Moodle versions it supports.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local\check;

use coding_exception;
use core_plugin_manager;
use tool_upgradeguard\local\confidence;
use tool_upgradeguard\local\finding;
use tool_upgradeguard\local\plugin_snapshot;
use tool_upgradeguard\local\scan_context;
use tool_upgradeguard\local\severity;

/**
 * Per plugin check of the compatibility that a plugin declares itself.
 *
 * This check does not guess. It uses the same core methods that Moodle's own
 * "Plugins check" page uses, so a blocker reported here is the same blocker
 * core reports after the upgrade.
 */
final class declared_compat_check implements check_interface {
    /**
     * Key of this check.
     *
     * @return string
     */
    public function get_key(): string {
        return 'declared_compat';
    }

    /**
     * Only third party plugins are checked.
     *
     * @param plugin_snapshot|null $plugin The plugin to check.
     * @param scan_context $context Everything collected for this scan.
     * @return bool
     */
    public function is_applicable(?plugin_snapshot $plugin, scan_context $context): bool {
        return $plugin !== null && !$plugin->isstandard;
    }

    /**
     * Compare what the plugin declares with the target Moodle version.
     *
     * @param plugin_snapshot|null $plugin The plugin to check.
     * @param scan_context $context Everything collected for this scan.
     * @return finding[]
     */
    public function run(?plugin_snapshot $plugin, scan_context $context): array {
        if ($plugin === null) {
            return [];
        }

        $target = $context->target;
        $core = $plugin->coreplugin;

        if ($core === null) {
            // The plugin is only present on disk, so there is nothing to read.
            return [$this->get_unverified_finding($target->version)];
        }

        try {
            $supported = $this->get_declared_support($core, $target->branch);
            $dependencyok = (bool) $core->is_core_dependency_satisfied($target->requiresint);
        } catch (coding_exception $e) {
            // A plugin with a malformed version.php must not break the scan.
            debugging('tool_upgradeguard: ' . $plugin->component . ' declares support incorrectly', DEBUG_DEVELOPER);
            return [$this->get_unverified_finding($target->version)];
        }

        $findings = [];

        if (!$dependencyok) {
            $findings[] = new finding(
                'declared_compat',
                severity::blocker,
                'finding_declared_requires_newer_core',
                [
                    'requires' => (string) $plugin->versionrequires,
                    'target' => $target->version,
                ],
                $this->get_confidence($context),
                false,
                'action_update_plugin',
            );
        }

        if (
            $supported === core_plugin_manager::VERSION_NOT_SUPPORTED
            && $plugin->incompatible !== null
            && $target->branch >= $plugin->incompatible
        ) {
            $findings[] = new finding(
                'declared_compat',
                severity::blocker,
                'finding_declared_incompatible',
                ['target' => $target->version],
                confidence::high,
                false,
                'action_replace_or_ask_developer',
            );
        } else if ($supported === core_plugin_manager::VERSION_NOT_SUPPORTED) {
            // A supported range is a positive compatibility declaration, not an
            // assertion that every later branch is broken. It needs testing,
            // but only an explicit incompatible declaration is a blocker.
            //
            // Deliberate difference from core: check_explicitly_supported()
            // returns VERSION_NOT_SUPPORTED both for a range that ends before the
            // target and for an explicit incompatible declaration, so core treats
            // the two the same. We keep the range case as a caution because a
            // declared window is weaker evidence than "the author says it breaks".
            $findings[] = new finding(
                'declared_compat',
                severity::caution,
                'finding_declared_support_range',
                ['target' => $target->version],
                confidence::high,
                false,
                'action_check_plugin_page',
            );
        }

        if (!empty($findings)) {
            return $findings;
        }

        if ($supported === core_plugin_manager::VERSION_SUPPORTED) {
            $findings[] = new finding(
                'declared_compat',
                severity::info,
                'finding_declared_compatible',
                ['target' => $target->version],
                confidence::high,
            );
        } else if ($plugin->versionrequires !== null) {
            // Only a minimum Moodle version is declared. That is evidence that
            // the plugin is not too new for the target, but it says nothing about
            // branches above that minimum: "requires" is a floor, not a promise.
            // The wording therefore states exactly that, instead of claiming
            // compatibility the plugin never declared.
            $findings[] = new finding(
                'declared_compat',
                severity::info,
                'finding_declared_minimum_only',
                [
                    'requires' => (string) $plugin->versionrequires,
                    'target' => $target->version,
                ],
                confidence::medium,
            );
        } else {
            $findings[] = $this->get_unverified_finding($target->version);
        }

        return $findings;
    }

    /**
     * Ask core what the plugin declares about the given Moodle branch.
     *
     * Returns null when core is too old to answer, which is treated the same way
     * as "the plugin declares nothing".
     *
     * @param \core\plugininfo\base $plugin The core plugin info.
     * @param int $branch The Moodle branch to check against.
     * @return string|null One of the core_plugin_manager::VERSION_* constants, or null.
     */
    private function get_declared_support(\core\plugininfo\base $plugin, int $branch): ?string {
        $pluginman = core_plugin_manager::instance();

        if (!method_exists($pluginman, 'check_explicitly_supported')) {
            return null;
        }

        return $pluginman->check_explicitly_supported($plugin, $branch);
    }

    /**
     * Finding used when nothing could be verified about a plugin.
     *
     * @param string $targetversion The target Moodle version.
     * @return finding
     */
    private function get_unverified_finding(string $targetversion): finding {
        return new finding(
            'declared_compat',
            severity::caution,
            'finding_declared_unknown',
            ['target' => $targetversion],
            confidence::low,
            false,
            'action_check_plugin_page',
        );
    }

    /**
     * Confidence for a check that depends on the version number of the target.
     *
     * When the version number of a target branch had to be derived instead of
     * read from that branch, the answer is less certain and says so.
     *
     * @param scan_context $context Everything collected for this scan.
     * @return confidence
     */
    private function get_confidence(scan_context $context): confidence {
        if ($context->target->requiresintsource === 'derived') {
            return confidence::medium;
        }
        return confidence::high;
    }
}
