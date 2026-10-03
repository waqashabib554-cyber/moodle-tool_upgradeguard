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
 * Derives the status of one plugin from its findings.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

/**
 * Derives the status shown for a plugin from the findings about it.
 *
 * The rules are ordered by how much they matter:
 *
 * - an unresolved blocker always wins, the plugin would break;
 * - otherwise a blocker or caution fixed by an available update gives the
 *   status "update available";
 * - otherwise a caution wins, something needs attention;
 * - a plugin nobody could assess is "unknown";
 * - everything else is "ready".
 */
final class status_calculator {
    /**
     * Work out the status of a plugin.
     *
     * @param finding[] $findings The findings about this plugin.
     * @return status
     */
    public function calculate(array $findings): status {
        if (empty($findings)) {
            // No check had anything to say, which means nothing was verified.
            return status::unknown;
        }

        $hasunresolvedblocker = false;
        $hasunresolvedcaution = false;
        $hasupdate = false;

        foreach ($findings as $finding) {
            if ($finding->severity === severity::blocker) {
                if (!$finding->fixedbyupdate) {
                    $hasunresolvedblocker = true;
                }
                $hasupdate = $hasupdate || $finding->fixedbyupdate;
                continue;
            }

            if ($finding->severity === severity::caution) {
                if (!$finding->fixedbyupdate) {
                    $hasunresolvedcaution = true;
                }
                $hasupdate = $hasupdate || $finding->fixedbyupdate;
                continue;
            }

            if ($finding->fixedbyupdate) {
                $hasupdate = true;
            }
        }

        if ($hasunresolvedblocker) {
            return status::blocker;
        }

        if ($hasunresolvedcaution) {
            return status::caution;
        }

        if ($hasupdate) {
            return status::update;
        }

        return $hasupdate ? status::update : status::ready;
    }
}
