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
 * How trustworthy a finding is.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

// phpcs:ignore moodle.Commenting.InlineComment.DocBlock -- the moodle-cs sniff does not know enum docblocks yet, but this project requires them.
/**
 * Confidence in a single finding.
 *
 * Checks that can only guess report a lower confidence, so that administrators
 * know which findings to double check themselves.
 */
enum confidence: string {
    // The check had to guess, verify this one yourself.
    case low = 'low';

    // The check had partial information.
    case medium = 'medium';

    // The check had all the information it needed.
    case high = 'high';

    /**
     * Numeric rank of this confidence, bigger means more trustworthy.
     *
     * @return int
     */
    public function rank(): int {
        return match ($this) {
            self::low => 0,
            self::medium => 1,
            self::high => 2,
        };
    }

    /**
     * The language string key describing this confidence.
     *
     * @return string
     */
    public function get_string_key(): string {
        return 'confidence_' . $this->value;
    }
}
