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
 * Reads the environment of the server a scan is queued from.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local\collector;

use Throwable;

/**
 * Inspector for the runtime environment of the site.
 *
 * This is the only place that looks at the live server: the PHP version, the
 * database vendor and version, and the loaded PHP extensions. Everything else
 * receives those values through the scan context, which keeps the checks pure
 * and unit testable.
 *
 * The environment is captured when a scan is *queued*, in the web request that is
 * serving the site. A scan runs later in cron, and the command line PHP build can
 * differ from the web one, so reading it at queue time is what an administrator
 * means by "this site".
 */
final class environment_inspector {
    /**
     * Inspect the environment of this server.
     *
     * @return array Keys: php, dbvendor, dbversion, extensions.
     */
    public function inspect(): array {
        return [
            'php' => PHP_VERSION,
            'dbvendor' => $this->detect_database_vendor(),
            'dbversion' => $this->detect_database_version(),
            'extensions' => get_loaded_extensions(),
        ];
    }

    /**
     * Work out which database server this site runs on.
     *
     * Moodle reports MySQL and MariaDB as the same family, so the configured
     * driver and the server description are used to tell them apart: MariaDB
     * ends its version string with "-MariaDB". Aurora MySQL is reported by the
     * description as well, because its minimum version in the Moodle
     * requirements is expressed as a MySQL version.
     *
     * @return string Vendor key used by the rule dataset, eg "mysql".
     */
    private function detect_database_vendor(): string {
        global $CFG, $DB;

        $dbtype = strtolower((string) $CFG->dbtype);
        $family = strtolower((string) $DB->get_dbfamily());
        $description = strtolower($this->get_server_description());

        if ($dbtype === 'mariadb' || $family === 'mariadb' || str_contains($description, 'mariadb')) {
            return 'mariadb';
        }

        if ($dbtype === 'auroramysql' || str_contains($description, 'aurora')) {
            return 'auroramysql';
        }

        return $family;
    }

    /**
     * The version number of the database server, without any vendor suffix.
     *
     * @return string Empty string when the driver reports no usable version.
     */
    private function detect_database_version(): string {
        $description = trim($this->get_server_description());

        if (preg_match('/^[0-9]+(\.[0-9]+)*/', $description, $matches)) {
            return $matches[0];
        }

        return '';
    }

    /**
     * The description the database driver reports, eg "8.4.3" or "10.11.6-MariaDB".
     *
     * @return string Empty string when it cannot be read.
     */
    private function get_server_description(): string {
        global $DB;

        try {
            $info = $DB->get_server_info();
        } catch (Throwable $e) {
            debugging('tool_upgradeguard: could not read the database server version', DEBUG_DEVELOPER);
            return '';
        }

        if (!is_array($info)) {
            return '';
        }

        return (string) ($info['description'] ?? $info['version'] ?? '');
    }
}
