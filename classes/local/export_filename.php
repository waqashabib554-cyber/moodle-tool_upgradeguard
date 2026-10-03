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
 * The file name a download is offered under.
 *
 * Deliberately a class instead of an inline expression in export.php, so the
 * format can be unit tested: a wrong name still downloads, and nothing reports
 * it until an administrator has a file on disk that sorts into the wrong place.
 *
 * The name is built from usergetdate() rather than from userdate(). userdate()
 * formats a timestamp for a reader, and its $fixday defaults to true, which
 * drops the leading zero from %d, so the first nine days of every month and
 * January to September produced a name like "upgrade-guard-2026101-0111". A name
 * is a machine identifier, so every field is zero padded to a fixed width and
 * the names sort in date order.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

/**
 * Builds the download name of the exported report.
 */
final class export_filename {
    /**
     * The CSV download name, without the extension.
     *
     * dataformat::download_data() appends ".csv" itself, so the name stays
     * extensionless here and the file is not saved as "....csv.csv".
     *
     * @param int $time The moment of the export, as a GMT timestamp.
     * @return string The name, ending in YYYYMMDD-HHMM in the user's timezone.
     */
    public static function csv(int $time): string {
        $parts = usergetdate($time);

        return get_string('csvfilename', 'tool_upgradeguard', sprintf(
            '%04d%02d%02d-%02d%02d',
            $parts['year'],
            $parts['mon'],
            $parts['mday'],
            $parts['hours'],
            $parts['minutes'],
        ));
    }
}
