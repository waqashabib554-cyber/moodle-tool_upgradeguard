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
 * Answers from the plugins directory, cached between scans.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local\collector;

use core\update\info;
use Throwable;
use tool_upgradeguard\local\plugin_snapshot;

/**
 * Collects the available updates reported by core.
 *
 * Core caches the answers it gets from moodle.org, and this class adds a second
 * cache in front of that per plugin lookup, so that a scan of a site with
 * hundreds of plugins does not do hundreds of cache lookups. The cache key
 * contains the installed plugin versions, so the cache invalidates itself as
 * soon as anything is updated.
 */
final class update_checker {
    /** @var string Cache component area holding the answers. */
    private const CACHE_AREA = 'updates';

    /**
     * Whether the administrator allowed this site to ask moodle.org.
     *
     * @return bool
     */
    public function is_enabled(): bool {
        return (bool) get_config('tool_upgradeguard', 'checkremote');
    }

    /**
     * Whether a scan may treat the update information as available.
     *
     * A switched off remote check is not missing information: the administrator
     * turned it off on purpose, so the report must not tell them to click
     * "Check for updates now".
     *
     * When the check is on, an empty result set is reported as missing data.
     * That is a limitation of the core API, not a claim about the site: core's
     * available_updates() answers null both for "nothing newer exists" and for
     * "no data was ever fetched", so the two cannot be told apart here.
     *
     * @param array $updates Updates that were collected for this scan.
     * @return bool
     */
    public function is_information_available(array $updates): bool {
        if (!$this->is_enabled()) {
            return true;
        }

        return !empty($updates);
    }

    /**
     * Available updates for the given plugins, indexed by component.
     *
     * @param plugin_snapshot[] $snapshots Snapshots indexed by component.
     * @return array Component => info.
     */
    public function get_updates(array $snapshots): array {
        if (!$this->is_enabled() || empty($snapshots)) {
            return [];
        }

        $cache = \cache::make('tool_upgradeguard', self::CACHE_AREA);
        $cachekey = $this->get_cache_key($snapshots);

        $cached = $cache->get($cachekey);
        if (is_array($cached)) {
            return $cached;
        }

        $updates = [];
        foreach ($snapshots as $snapshot) {
            $update = $this->get_update_for_plugin($snapshot);
            if ($update !== null) {
                $updates[$snapshot->component] = $update;
            }
        }

        $cache->set($cachekey, $updates, $this->get_cache_ttl());
        return $updates;
    }

    /**
     * Ask core for the newest available update of one plugin.
     *
     * @param plugin_snapshot $snapshot The plugin to look up.
     * @return info|null Null when there is no update or no answer.
     */
    private function get_update_for_plugin(plugin_snapshot $snapshot): ?info {
        if ($snapshot->isstandard || !$snapshot->installed || $snapshot->coreplugin === null) {
            return null;
        }

        try {
            $available = $snapshot->coreplugin->available_updates();
        } catch (Throwable $e) {
            // A plugin that cannot answer must not break the scan.
            debugging(
                'tool_upgradeguard: no update information for ' . $snapshot->component . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return null;
        }

        if (empty($available) || !is_array($available)) {
            return null;
        }

        $newest = null;
        foreach ($available as $update) {
            if (!($update instanceof info)) {
                continue;
            }
            if ($newest === null || (int) $update->version > (int) $newest->version) {
                $newest = $update;
            }
        }

        return $newest;
    }

    /**
     * Cache key that changes as soon as a plugin version changes.
     *
     * @param plugin_snapshot[] $snapshots Snapshots indexed by component.
     * @return string
     */
    private function get_cache_key(array $snapshots): string {
        $fingerprint = [];
        foreach ($snapshots as $snapshot) {
            if ($snapshot->isstandard || !$snapshot->installed) {
                continue;
            }
            $fingerprint[] = $snapshot->component . ':' . $snapshot->versiondisk;
        }

        sort($fingerprint);
        return md5(implode('|', $fingerprint));
    }

    /**
     * How long answers may be reused.
     *
     * @return int Seconds.
     */
    private function get_cache_ttl(): int {
        $ttl = (int) get_config('tool_upgradeguard', 'remotecachettl');
        return $ttl > 0 ? $ttl : HOURSECS;
    }
}
