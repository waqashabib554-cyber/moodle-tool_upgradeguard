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
 * How serious a finding is.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

// phpcs:ignore moodle.Commenting.InlineComment.DocBlock -- the moodle-cs sniff does not know enum docblocks yet, but this project requires them.
/**
 * Severity of a single finding.
 *
 * The order of the cases is the order of importance: a blocker outranks a
 * caution, which outranks information.
 */
enum severity: string {
    // The upgrade is expected to break this plugin.
    case blocker = 'blocker';

    // The upgrade will probably work, but something needs attention.
    case caution = 'caution';

    // Worth knowing, no action required.
    case info = 'info';

    /**
     * Numeric rank of this severity, bigger means more serious.
     *
     * @return int
     */
    public function rank(): int {
        return match ($this) {
            self::info => 0,
            self::caution => 1,
            self::blocker => 2,
        };
    }

    /**
     * Whether this severity is at least as serious as the given one.
     *
     * @param severity $other The severity to compare with.
     * @return bool
     */
    public function is_at_least(self $other): bool {
        return $this->rank() >= $other->rank();
    }

    /**
     * The language string key describing this severity.
     *
     * @return string
     */
    public function get_string_key(): string {
        return 'severity_' . $this->value;
    }
}
