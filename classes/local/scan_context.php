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
 * Everything a check needs to know about the scan it runs in.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

/**
 * Input for the checks of a single scan.
 *
 * All data is collected up front and passed in, so that checks stay pure, cheap
 * and easy to test: they never query the database or the network themselves.
 */
final class scan_context {
    /** @var target The Moodle version the scan is run against. */
    public readonly target $target;

    /** @var plugin_snapshot[] All plugins, indexed by component. */
    public readonly array $plugins;

    /** @var array Usage per component: ['count' => int|null, 'detail' => array]. */
    public readonly array $usage;

    /** @var array Available updates per component. */
    public readonly array $updates;

    /** @var bool Whether update information was available for this scan. */
    public readonly bool $updateinformationavailable;

    /** @var string PHP version of the server. */
    public readonly string $phpversion;

    /** @var string Database vendor of the site, eg "mysql", "mariadb" or "postgres". */
    public readonly string $dbvendor;

    /** @var string Version of the database server, eg "8.4.3". Empty when it could not be read. */
    public readonly string $dbversion;

    /** @var string[] Names of the loaded PHP extensions, lowercased. */
    public readonly array $extensions;

    /**
     * Create the context.
     *
     * @param target $target The Moodle version the scan is run against.
     * @param plugin_snapshot[] $plugins All plugins, indexed by component.
     * @param array $usage Usage per component.
     * @param array $updates Available updates per component.
     * @param string $phpversion PHP version of the server.
     * @param bool|null $updateinformationavailable Whether update data was available. Null derives it from updates.
     * @param string $dbvendor Database vendor of the site.
     * @param string $dbversion Version of the database server.
     * @param string[] $extensions Names of the loaded PHP extensions.
     */
    public function __construct(
        target $target,
        array $plugins,
        array $usage,
        array $updates,
        string $phpversion,
        ?bool $updateinformationavailable = null,
        string $dbvendor = '',
        string $dbversion = '',
        array $extensions = [],
    ) {
        $this->target = $target;
        $this->plugins = $plugins;
        $this->usage = $usage;
        $this->updates = $updates;
        $this->phpversion = $phpversion;
        $this->updateinformationavailable = $updateinformationavailable ?? !empty($updates);
        $this->dbvendor = strtolower($dbvendor);
        $this->dbversion = $dbversion;
        $this->extensions = array_values(array_unique(array_map('strtolower', array_map('strval', $extensions))));
    }

    /**
     * One plugin of the inventory.
     *
     * @param string $component Frankenstyle component name.
     * @return plugin_snapshot|null
     */
    public function get_plugin(string $component): ?plugin_snapshot {
        return $this->plugins[$component] ?? null;
    }

    /**
     * Usage information for a component.
     *
     * @param string $component Frankenstyle component name.
     * @return array|null Null when usage could not be measured.
     */
    public function get_usage(string $component): ?array {
        return $this->usage[$component] ?? null;
    }

    /**
     * Available update for a component.
     *
     * @param string $component Frankenstyle component name.
     * @return \core\update\info|null Null when there is no known update.
     */
    public function get_update(string $component): ?\core\update\info {
        return $this->updates[$component] ?? null;
    }

    /**
     * Whether the server runs a PHP version the target Moodle needs.
     *
     * @return bool
     */
    public function is_php_new_enough(): bool {
        return version_compare($this->phpversion, $this->target->phpmin, '>=');
    }

    /**
     * Whether the database server was identified for this scan.
     *
     * @return bool
     */
    public function has_database_information(): bool {
        return $this->dbvendor !== '' && $this->dbversion !== '';
    }

    /**
     * Whether the loaded PHP extensions were reported for this scan.
     *
     * @return bool
     */
    public function has_extension_information(): bool {
        return !empty($this->extensions);
    }

    /**
     * Whether a PHP extension is loaded on this server.
     *
     * @param string $extension Extension name, eg "mbstring".
     * @return bool
     */
    public function has_extension(string $extension): bool {
        return in_array(strtolower($extension), $this->extensions, true);
    }
}
