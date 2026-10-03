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
 * The summary status of a scanned plugin.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

// phpcs:ignore moodle.Commenting.InlineComment.DocBlock -- the moodle-cs sniff does not know enum docblocks yet, but this project requires them.
/**
 * The status shown for one plugin in a scan report.
 */
enum status: string {
    // Nothing bad found, the plugin should keep working after the upgrade.
    case ready = 'ready';

    // The plugin keeps working, but a newer version is available.
    case update = 'update';

    // Needs attention before the upgrade, but is not expected to break.
    case caution = 'caution';

    // Expected to break the upgrade.
    case blocker = 'blocker';

    // Could not be assessed, for example because no information was available.
    case unknown = 'unknown';

    /**
     * The language string key describing this status.
     *
     * @return string
     */
    public function get_string_key(): string {
        return 'status_' . $this->value;
    }

    /**
     * The Bootstrap background class that colours this status everywhere.
     *
     * Kept here so that the dashboard, the results table and the printable
     * report always show the same colour for the same status.
     *
     * @return string
     */
    public function get_css_class(): string {
        return match ($this) {
            self::blocker => 'danger',
            self::caution => 'warning',
            self::unknown => 'secondary',
            self::update => 'info',
            self::ready => 'success',
        };
    }

    /**
     * Rank for sorting, worst (most worrying) first.
     *
     * "Unknown" deliberately outranks "update" and "caution": something that
     * could not be checked is more worrying than something that was checked and
     * turned out to be fine.
     *
     * @return int
     */
    public function rank(): int {
        return match ($this) {
            self::ready => 0,
            self::update => 1,
            self::caution => 2,
            self::unknown => 3,
            self::blocker => 4,
        };
    }
}
