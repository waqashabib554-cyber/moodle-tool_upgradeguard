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
 * The overall verdict of a scan.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

// phpcs:ignore moodle.Commenting.InlineComment.DocBlock -- the moodle-cs sniff does not know enum docblocks yet, but this project requires them.
/**
 * The one line answer the whole scan boils down to.
 */
enum verdict: string {
    // Go ahead, the upgrade looks safe.
    case go = 'go';

    // Go ahead, but read the findings first.
    case careful = 'careful';

    // Do not upgrade yet, something is expected to break.
    case stop = 'stop';

    // No verdict yet, for example because the scan has not finished.
    case unknown = 'unknown';

    /**
     * The language string key describing this verdict.
     *
     * @return string
     */
    public function get_string_key(): string {
        return 'verdict_' . $this->value;
    }
}
