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
 * Turns a score into the one line verdict.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

/**
 * Decides the verdict of a scan.
 *
 * The verdict follows the Section 6.5 rules: a used blocker or a low score
 * stops the upgrade; cautions and mid-range scores require care; a clean high
 * score is ready.
 */
final class verdict_calculator {
    /** @var int Score below which a scan must stop. */
    private const DEFAULT_STOP_THRESHOLD = 60;

    /** @var int Score from which a scan without cautions counts as ready. */
    private const DEFAULT_GO_THRESHOLD = 85;

    /**
     * Decide the verdict.
     *
     * @param int $score The readiness score of the scan.
     * @param bool $hasusedblocker Whether any blocker is used on this site.
     * @param bool $hascaution Whether any plugin or site-wide finding is a caution.
     * @param bool $hasenvironmentblocker Whether the server environment has a blocker.
     * @return verdict
     */
    public function calculate(
        int $score,
        bool $hasusedblocker,
        bool $hascaution,
        bool $hasenvironmentblocker = false
    ): verdict {
        if ($hasenvironmentblocker || $hasusedblocker || $score < $this->get_stop_threshold()) {
            return verdict::stop;
        }

        if ($hascaution || $score < $this->get_go_threshold()) {
            return verdict::careful;
        }

        return verdict::go;
    }

    /**
     * The score needed for a "ready to upgrade" verdict.
     *
     * @return int
     */
    private function get_go_threshold(): int {
        $configured = get_config('tool_upgradeguard', 'gothreshold');
        if ($configured === false || $configured === null || $configured === '') {
            return self::DEFAULT_GO_THRESHOLD;
        }

        return max(0, min(100, (int) $configured));
    }

    /**
     * Read the configured Stop threshold.
     *
     * @return int Score below which a scan stops.
     */
    private function get_stop_threshold(): int {
        $configured = get_config('tool_upgradeguard', 'stopthreshold');
        if ($configured === false || $configured === null || $configured === '') {
            return self::DEFAULT_STOP_THRESHOLD;
        }

        return max(0, min(100, (int) $configured));
    }
}
