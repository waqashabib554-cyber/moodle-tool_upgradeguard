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
 * The list of checks that a scan runs.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local\check;

use coding_exception;

/**
 * Registry of the checks that are run for every scan.
 *
 * Adding a check is a one line change here plus one new class.
 */
final class registry {
    /** @var string[] Class names of the checks, in the order they run. */
    private const CHECKS = [
        site_php_check::class,
        environment_database_check::class,
        environment_extensions_check::class,
        public_location_check::class,
        update_information_check::class,
        declared_compat_check::class,
        dependency_check::class,
        update_available_check::class,
        usage_check::class,
    ];

    /** @var check_interface[]|null Instantiated checks, built once per request. */
    private ?array $checks = null;

    /**
     * All checks, in the order they run.
     *
     * @return check_interface[]
     */
    public function get_checks(): array {
        if ($this->checks === null) {
            $this->checks = [];
            foreach (self::CHECKS as $class) {
                $this->checks[] = new $class();
            }
        }

        return $this->checks;
    }

    /**
     * One check by its key.
     *
     * @param string $key The check key, eg "declared_compat".
     * @return check_interface
     * @throws coding_exception When no check with that key is registered.
     */
    public function get_check(string $key): check_interface {
        foreach ($this->get_checks() as $check) {
            if ($check->get_key() === $key) {
                return $check;
            }
        }

        throw new coding_exception('Unknown Upgrade Guard check: ' . $key);
    }

    /**
     * The keys of all registered checks.
     *
     * @return string[]
     */
    public function get_keys(): array {
        $keys = [];
        foreach ($this->get_checks() as $check) {
            $keys[] = $check->get_key();
        }
        return $keys;
    }
}
