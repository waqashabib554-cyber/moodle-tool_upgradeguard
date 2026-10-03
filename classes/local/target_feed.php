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
 * Fetches and validates the maintainer-published target rules.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

/**
 * Fetches a public GitHub raw JSON feed and stores only complete, newer datasets.
 *
 * Network failures never replace the bundled or last-known-good rules.
 */
final class target_feed {
    /** @var string Default public feed URL for new installations. */
    public const DEFAULT_FEED_URL = 'https://raw.githubusercontent.com/waqashabib554-cyber/moodle-tool_upgradeguard/main/data/targets.json';

    /** @var int Maximum accepted feed size. */
    private const MAXFEEDSIZE = 262144;

    /** @var int Minimum time between feed requests. */
    private const REFRESHINTERVAL = 6 * HOURSECS;

    /**
     * Whether a public feed URL has been configured.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        $configuredurl = get_config('tool_upgradeguard', 'rulesfeedurl');
        $url = $configuredurl === false ? self::DEFAULT_FEED_URL : (string) $configuredurl;
        return trim($url) !== '';
    }

    /**
     * Attempt to refresh the rules, retaining the last known good data on any failure.
     *
     * @param bool $force Skip the normal refresh interval.
     * @return bool True when a valid feed was fetched.
     */
    public function refresh(bool $force = false): bool {
        global $CFG;

        $configuredurl = get_config('tool_upgradeguard', 'rulesfeedurl');
        $url = trim($configuredurl === false ? self::DEFAULT_FEED_URL : (string) $configuredurl);
        if ($url === '') {
            return false;
        }

        $lastattempt = (int) get_config('tool_upgradeguard', 'ruleslastattempt');
        if (!$force && $lastattempt > 0 && time() - $lastattempt < self::REFRESHINTERVAL) {
            return false;
        }

        set_config('ruleslastattempt', time(), 'tool_upgradeguard');

        if (!$this->is_allowed_url($url)) {
            $this->record_failure('The rules feed URL must be an HTTPS raw.githubusercontent.com URL.');
            return false;
        }

        require_once($CFG->libdir . '/filelib.php');
        $response = download_file_content(
            $url,
            ['Accept' => 'application/json'],
            null,
            true,
            20,
            10,
            false
        );

        if (!is_object($response) || (int) ($response->status ?? 0) !== 200) {
            $this->record_failure('The rules feed could not be downloaded successfully.');
            return false;
        }

        $body = (string) ($response->results ?? '');
        if ($body === '' || strlen($body) > self::MAXFEEDSIZE) {
            $this->record_failure('The rules feed is empty or exceeds the size limit.');
            return false;
        }

        $dataset = json_decode($body, true);
        if (!is_array($dataset) || self::validate_dataset($dataset) === null) {
            $this->record_failure('The rules feed is not a valid Upgrade Guard dataset.');
            return false;
        }

        $currentversion = (new target_repository())->get_dataset_version();
        if ((int) $dataset['datasetversion'] < $currentversion) {
            $this->record_failure('The rules feed is older than the currently installed dataset.');
            return false;
        }

        if ((int) $dataset['datasetversion'] > $currentversion) {
            set_config('rulesdataset', json_encode($dataset), 'tool_upgradeguard');
        }

        set_config('ruleslastsuccess', time(), 'tool_upgradeguard');
        unset_config('ruleslasterror', 'tool_upgradeguard');
        return true;
    }

    /**
     * Validate the public feed URL to limit remote requests to raw GitHub content.
     *
     * @param string $url Feed URL.
     * @return bool
     */
    private function is_allowed_url(string $url): bool {
        $parts = parse_url($url);
        if (!is_array($parts)
                || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                || strtolower((string) ($parts['host'] ?? '')) !== 'raw.githubusercontent.com'
                || isset($parts['user'])
                || isset($parts['pass'])
                || (isset($parts['port']) && (int) $parts['port'] !== 443)
                || isset($parts['query'])
                || isset($parts['fragment'])) {
            return false;
        }

        return preg_match(
            '~^/[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})/moodle-tool_upgradeguard/(?:refs/heads/)?main/data/targets\.json$~',
            (string) ($parts['path'] ?? '')
        ) === 1;
    }

    /**
     * Validate the dataset envelope and every target before it can be stored.
     *
     * @param array $dataset Decoded feed.
     * @return array|null The validated dataset, or null when invalid.
     */
    public static function validate_dataset(array $dataset): ?array {
        if (!isset($dataset['datasetversion'], $dataset['updated'], $dataset['targets'])
                || !is_int($dataset['datasetversion'])
                || $dataset['datasetversion'] < 1
                || !is_string($dataset['updated'])
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataset['updated'])
                || !is_array($dataset['targets'])
                || $dataset['targets'] === []
                || count($dataset['targets']) > 64) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dataset['updated']);
        if ($date === false || $date->format('Y-m-d') !== $dataset['updated']
                || $date > new \DateTimeImmutable('tomorrow')) {
            return null;
        }

        $versions = [];
        $branches = [];
        if (!array_is_list($dataset['targets'])) {
            return null;
        }
        foreach ($dataset['targets'] as $entry) {
            if (!is_array($entry)) {
                return null;
            }

            foreach (['version', 'branch', 'requiresint', 'requiresintsource', 'phpmin', 'minsource',
                    'publiclayout', 'status', 'statussource', 'environment'] as $required) {
                if (!array_key_exists($required, $entry)) {
                    return null;
                }
            }
            if (!is_string($entry['version'])
                    || !is_int($entry['branch'])
                    || !is_int($entry['requiresint'])
                    || !is_string($entry['requiresintsource'])
                    || !in_array($entry['requiresintsource'], ['verified', 'derived'], true)
                    || !is_string($entry['minsource'])
                    || !preg_match('/^\d+\.\d+(?:\.\d+)?$/', $entry['minsource'])
                    || !is_bool($entry['publiclayout'])
                    || !is_string($entry['status'])
                    || !is_string($entry['statussource'])
                    || !is_array($entry['environment'])
                    || !self::is_valid_environment($entry['environment'])) {
                return null;
            }

            $target = target::from_dataset_entry($entry);
            if ($target === null || !preg_match('/^\d+\.\d+$/', $target->version)) {
                return null;
            }
            [$major, $minor] = array_map('intval', explode('.', $target->version));
            if ($target->branch !== ($major * 100 + $minor)
                    || $target->requiresint <= 0
                    || !preg_match('/^\d+\.\d+\.\d+$/', $target->phpmin)
                    || !in_array($target->status, ['stable', 'security', 'future', 'unsupported'], true)
                    || trim($target->statussource) === ''
                    || isset($versions[$target->version])
                    || isset($branches[$target->branch])) {
                return null;
            }

            $versions[$target->version] = true;
            $branches[$target->branch] = true;
        }

        return $dataset;
    }

    /**
     * Validate all source and requirement fields used by environment checks.
     *
     * @param array $environment Environment metadata from one target.
     * @return bool
     */
    private static function is_valid_environment(array $environment): bool {
        foreach (['php', 'database', 'extensions'] as $section) {
            if (!isset($environment[$section]) || !is_array($environment[$section])
                    || !isset($environment[$section]['source'], $environment[$section]['verified'])
                    || !is_string($environment[$section]['source'])
                    || !is_bool($environment[$section]['verified'])) {
                return false;
            }
        }

        $database = $environment['database'];
        $extensions = $environment['extensions'];
        if (!isset($database['minimums'], $database['exercised'])
                || !is_array($database['minimums'])
                || !is_array($database['exercised'])
                || !array_is_list($database['exercised'])
                || !isset($extensions['required'], $extensions['optional'])
                || !is_array($extensions['required'])
                || !is_array($extensions['optional'])
                || !array_is_list($extensions['required'])
                || !array_is_list($extensions['optional'])) {
            return false;
        }

        foreach ($database['minimums'] as $vendor => $version) {
            if (!is_string($vendor) || !is_string($version) || $version === '') {
                return false;
            }
        }
        foreach (array_merge($database['exercised'], $extensions['required'], $extensions['optional']) as $value) {
            if (!is_string($value) || $value === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Record a safe diagnostic while preserving the last known good dataset.
     *
     * @param string $message Diagnostic for logs and the scheduled-task output.
     * @return void
     */
    private function record_failure(string $message): void {
        set_config('ruleslasterror', $message, 'tool_upgradeguard');
        debugging('tool_upgradeguard: ' . $message, DEBUG_DEVELOPER);
    }
}
