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
 * Reads the shipped rule dataset of target Moodle versions.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

/**
 * Repository for the rule dataset in data/targets.json.
 *
 * The dataset is a plain file shipped with the plugin. It is read at most once
 * per request (static cache) and never written to. A malformed dataset never
 * throws at an administrator: it is logged for developers and treated as empty,
 * so the tool degrades into "no target versions available" instead of a fatal
 * error on a live site.
 */
final class target_repository {
    /** @var string Path of the rule dataset, relative to the plugin directory. */
    private const DATASET_FILE = '/data/targets.json';

    /** @var array Targets per dataset path, cached for this request. */
    private static array $targetsbypath = [];

    /** @var array Dataset versions per dataset path, cached for this request. */
    private static array $versionsbypath = [];

    /** @var array Last updated dates per dataset path, cached for this request. */
    private static array $updatedbypath = [];

    /** @var string Absolute path of the rule dataset. */
    private string $path;

    /**
     * Create the repository.
     *
     * @param string|null $path Optional alternative dataset path, used by tests.
     */
    public function __construct(?string $path = null) {
        $this->path = $path ?? dirname(__DIR__, 2) . self::DATASET_FILE;
    }

    /**
     * All known targets, newest branch first.
     *
     * @return target[] Indexed by version string.
     */
    public function get_targets(): array {
        $this->load();
        return self::$targetsbypath[$this->path];
    }

    /**
     * Targets that are a valid upgrade direction from the current branch.
     *
     * A scan may only target a supported branch newer than the site it runs on.
     * The current branch is read by the caller and passed here so this class
     * stays independent of global request state.
     *
     * @param int $currentbranch Branch number of the running site, eg 502.
     * @return target[] Indexed by version string, newest branch first.
     */
    public function get_upgrade_targets(int $currentbranch): array {
        return array_filter($this->get_targets(), static function (target $target) use ($currentbranch): bool {
            return $target->status !== 'unsupported'
                && $target->status !== 'future'
                && $target->branch > $currentbranch;
        });
    }

    /**
     * Whether a target is a valid upgrade direction for a running site.
     *
     * @param string $version Target version string.
     * @param int $currentbranch Branch number of the running site, eg 502.
     * @return bool
     */
    public function is_upgrade_target(string $version, int $currentbranch): bool {
        $target = $this->get_target($version);
        return $target !== null
            && $target->status !== 'unsupported'
            && $target->status !== 'future'
            && $target->branch > $currentbranch;
    }

    /**
     * One target by version string.
     *
     * @param string $version The version string, eg "5.2".
     * @return target|null
     */
    public function get_target(string $version): ?target {
        $this->load();
        return self::$targetsbypath[$this->path][$version] ?? null;
    }

    /**
     * One target by branch number.
     *
     * @param int $branch The branch number, eg 502.
     * @return target|null
     */
    public function get_target_by_branch(int $branch): ?target {
        foreach ($this->get_targets() as $target) {
            if ($target->branch === $branch) {
                return $target;
            }
        }
        return null;
    }

    /**
     * The newest released target in the dataset.
     *
     * Future branches are deliberately excluded: a rule dataset may describe
     * an upcoming release, but an administrator must not scan against a branch
     * that is not released yet.
     *
     * @return target|null Null when the dataset could not be read.
     */
    public function get_default_target(): ?target {
        foreach ($this->get_targets() as $target) {
            if ($target->status !== 'future' && $target->status !== 'unsupported') {
                return $target;
            }
        }

        return null;
    }

    /**
     * Version of the loaded dataset, used to stamp scans.
     *
     * @return int
     */
    public function get_dataset_version(): int {
        $this->load();
        return self::$versionsbypath[$this->path];
    }

    /**
     * Date of the active rules dataset.
     *
     * @return string
     */
    public function get_dataset_updated(): string {
        $this->load();
        return self::$updatedbypath[$this->path];
    }

    /**
     * When the remote feed was last checked successfully.
     *
     * @return int Unix timestamp, or zero when no successful check has occurred.
     */
    public function get_last_refresh(): int {
        return (int) get_config('tool_upgradeguard', 'ruleslastsuccess');
    }

    /**
     * Load the dataset once per path and request.
     *
     * @return void
     */
    private function load(): void {
        if (array_key_exists($this->path, self::$targetsbypath)) {
            return;
        }

        self::$targetsbypath[$this->path] = [];
        self::$versionsbypath[$this->path] = 0;
        self::$updatedbypath[$this->path] = '';

        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            debugging('tool_upgradeguard: could not read the rule dataset at ' . $this->path, DEBUG_DEVELOPER);
            return;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['targets']) || !is_array($decoded['targets'])) {
            debugging('tool_upgradeguard: the rule dataset is malformed', DEBUG_DEVELOPER);
            return;
        }

        self::$versionsbypath[$this->path] = (int) ($decoded['datasetversion'] ?? 0);
        self::$updatedbypath[$this->path] = (string) ($decoded['updated'] ?? '');

        foreach ($decoded['targets'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $target = target::from_dataset_entry($entry);
            if ($target !== null) {
                self::$targetsbypath[$this->path][$target->version] = $target;
            }
        }

        $remotedataset = json_decode((string) get_config('tool_upgradeguard', 'rulesdataset'), true);
        if (is_array($remotedataset) && target_feed::validate_dataset($remotedataset) !== null
                && (int) $remotedataset['datasetversion'] >= self::$versionsbypath[$this->path]) {
            self::$versionsbypath[$this->path] = (int) $remotedataset['datasetversion'];
            self::$updatedbypath[$this->path] = (string) $remotedataset['updated'];

            foreach ($remotedataset['targets'] as $entry) {
                $target = target::from_dataset_entry($entry);
                if ($target !== null) {
                    self::$targetsbypath[$this->path][$target->version] = $target;
                }
            }
        }

        // Newest branch first, so that the default target is the newest one.
        uasort(self::$targetsbypath[$this->path], static fn(target $a, target $b): int => $b->branch <=> $a->branch);
    }
}
