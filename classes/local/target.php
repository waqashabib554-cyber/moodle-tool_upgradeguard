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
 * A Moodle version that can be scanned against.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

/**
 * A target Moodle branch that a scan can be run against.
 *
 * Instances are built from the rule dataset, never by hand, so that all
 * factories can be validated in one place.
 */
final class target {
    /** @var string Human readable version, eg "5.2". */
    public readonly string $version;

    /** @var int Moodle branch number, eg 502. */
    public readonly int $branch;

    /** @var int Core version number of the branch, eg 2026042000. */
    public readonly int $requiresint;

    /** @var string Minimum PHP version the branch needs, eg "8.3.0". */
    public readonly string $phpmin;

    /** @var string Oldest Moodle version that can be upgraded from, eg "4.4". */
    public readonly string $minsource;

    /** @var bool Whether this branch only reads plugins from the public directory. */
    public readonly bool $publiclayout;

    /** @var string Release status of the branch, eg "security" or "stable". */
    public readonly string $status;

    /** @var string Where the release status was confirmed, eg a moodledev.io page. */
    public readonly string $statussource;

    /** @var string How the version number of the branch was obtained: "verified" or "derived". */
    public readonly string $requiresintsource;

    /** @var array Environment requirements of the branch, with their sources and verification flags. */
    public readonly array $environment;

    /**
     * Create a target.
     *
     * @param string $version Human readable version.
     * @param int $branch Branch number.
     * @param int $requiresint Core version number of the branch.
     * @param string $phpmin Minimum PHP version.
     * @param string $minsource Oldest Moodle version that can be upgraded from.
     * @param bool $publiclayout Whether the branch only reads plugins from public/.
     * @param string $status Release status of the branch.
     * @param string $statussource Where the release status was confirmed.
     * @param string $requiresintsource How the version number of the branch was obtained.
     * @param array $environment Environment requirements: php, database and extensions blocks.
     */
    public function __construct(
        string $version,
        int $branch,
        int $requiresint,
        string $phpmin,
        string $minsource,
        bool $publiclayout,
        string $status,
        string $requiresintsource,
        array $environment = [],
        string $statussource = '',
    ) {
        $this->version = $version;
        $this->branch = $branch;
        $this->requiresint = $requiresint;
        $this->phpmin = $phpmin;
        $this->minsource = $minsource;
        $this->publiclayout = $publiclayout;
        $this->status = $status;
        $this->statussource = $statussource;
        $this->requiresintsource = $requiresintsource;
        $this->environment = $environment + self::get_empty_environment();
    }

    /**
     * Whether the branch still receives general bug fixes, not only security fixes.
     *
     * A branch that only receives security fixes is still a legitimate upgrade
     * destination, but the tool must not present it as a fully supported one.
     *
     * @return bool
     */
    public function is_fully_supported(): bool {
        return $this->status === 'stable';
    }

    /**
     * Whether the branch is past general support and only receives security fixes.
     *
     * @return bool
     */
    public function is_security_only(): bool {
        return $this->status === 'security';
    }

    /**
     * Build a target from one entry of the rule dataset.
     *
     * @param array $data A single entry of the "targets" list.
     * @return self|null Null when the entry is not usable.
     */
    public static function from_dataset_entry(array $data): ?self {
        $required = ['version', 'branch', 'requiresint', 'phpmin', 'minsource', 'publiclayout', 'status'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                debugging('tool_upgradeguard: target entry is missing the "' . $key . '" key', DEBUG_DEVELOPER);
                return null;
            }
        }

        $branch = (int) $data['branch'];
        if ($branch <= 0) {
            debugging('tool_upgradeguard: target entry has an invalid branch number', DEBUG_DEVELOPER);
            return null;
        }

        return new self(
            (string) $data['version'],
            $branch,
            (int) $data['requiresint'],
            (string) $data['phpmin'],
            (string) $data['minsource'],
            (bool) $data['publiclayout'],
            (string) $data['status'],
            // Anything that does not say the version number was verified is
            // treated as derived, so that findings based on it are less certain.
            (string) ($data['requiresintsource'] ?? 'derived'),
            self::read_environment($data),
            (string) ($data['statussource'] ?? ''),
        );
    }

    /**
     * The branch number as a string, as used in URLs and capabilities.
     *
     * @return string
     */
    public function get_string_branch(): string {
        return (string) $this->branch;
    }

    /**
     * The requirement block of every environment fact, used when a target has none.
     *
     * @return array
     */
    private static function get_empty_environment(): array {
        return [
            'php' => ['source' => '', 'verified' => false],
            'database' => ['source' => '', 'verified' => false, 'exercised' => [], 'minimums' => []],
            'extensions' => ['source' => '', 'verified' => false, 'required' => [], 'optional' => []],
        ];
    }

    /**
     * Read and normalise the environment block of a dataset entry.
     *
     * A missing or malformed block is not an error: the environment checks then
     * have nothing to compare against and stay quiet, and the dashboard says the
     * requirement is unverified rather than pretending to know it.
     *
     * @param array $data A single entry of the "targets" list.
     * @return array
     */
    private static function read_environment(array $data): array {
        $environment = isset($data['environment']) && is_array($data['environment']) ? $data['environment'] : [];
        $php = isset($environment['php']) && is_array($environment['php']) ? $environment['php'] : [];
        $database = isset($environment['database']) && is_array($environment['database']) ? $environment['database'] : [];
        $extensions = isset($environment['extensions']) && is_array($environment['extensions']) ? $environment['extensions'] : [];

        $minimums = [];
        foreach ((array) ($database['minimums'] ?? []) as $vendor => $version) {
            if (is_string($vendor) && is_scalar($version) && (string) $version !== '') {
                $minimums[$vendor] = (string) $version;
            }
        }

        return [
            'php' => [
                'source' => (string) ($php['source'] ?? ''),
                'verified' => (bool) ($php['verified'] ?? false),
            ],
            'database' => [
                'source' => (string) ($database['source'] ?? ''),
                'verified' => (bool) ($database['verified'] ?? false),
                'exercised' => array_values(array_filter(array_map('strval', (array) ($database['exercised'] ?? [])))),
                'minimums' => $minimums,
            ],
            'extensions' => [
                'source' => (string) ($extensions['source'] ?? ''),
                'verified' => (bool) ($extensions['verified'] ?? false),
                'required' => array_values(array_filter(array_map('strval', (array) ($extensions['required'] ?? [])))),
                'optional' => array_values(array_filter(array_map('strval', (array) ($extensions['optional'] ?? [])))),
            ],
        ];
    }

    /**
     * Whether a requirement of this target was read from an official source.
     *
     * @param string $fact One of "php", "database" or "extensions".
     * @return bool False when the dataset has no verified number for that fact.
     */
    public function is_environment_verified(string $fact): bool {
        return (bool) ($this->environment[$fact]['verified'] ?? false);
    }

    /**
     * Where the number of an environment fact comes from.
     *
     * @param string $fact One of "php", "database" or "extensions".
     * @return string Empty string when the dataset states no source.
     */
    public function get_environment_source(string $fact): string {
        return (string) ($this->environment[$fact]['source'] ?? '');
    }

    /**
     * The minimum version this target needs for a database vendor.
     *
     * @param string $vendor Database vendor, eg "mysql" or "postgres".
     * @return string|null Null when the target states no minimum for that vendor.
     */
    public function get_database_minimum(string $vendor): ?string {
        return $this->environment['database']['minimums'][$vendor] ?? null;
    }

    /**
     * Whether the database comparison was ever exercised against this vendor.
     *
     * The numbers are official for every vendor, but this plugin has only been
     * run against the vendors listed here, so findings for other vendors say so.
     *
     * @param string $vendor Database vendor.
     * @return bool
     */
    public function is_database_exercised(string $vendor): bool {
        return in_array($vendor, $this->environment['database']['exercised'], true);
    }

    /**
     * The PHP extensions this target needs.
     *
     * @return string[]
     */
    public function get_required_extensions(): array {
        return $this->environment['extensions']['required'];
    }

    /**
     * The PHP extensions this target can use but does not require.
     *
     * @return string[]
     */
    public function get_optional_extensions(): array {
        return $this->environment['extensions']['optional'];
    }
}
