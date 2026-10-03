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
 * Collects the plugins of this site.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local\collector;

use core_plugin_manager;
use RuntimeException;
use Throwable;
use tool_upgradeguard\local\plugin_snapshot;
use tool_upgradeguard\local\target;

/**
 * Reads the plugin inventory from the core plugin manager.
 */
final class plugin_inventory {
    /** @var core_plugin_manager The plugin manager to read from. */
    private core_plugin_manager $pluginman;

    /**
     * Create the collector.
     *
     * @param core_plugin_manager|null $pluginman Optional plugin manager, used by tests.
     */
    public function __construct(?core_plugin_manager $pluginman = null) {
        $this->pluginman = $pluginman ?? core_plugin_manager::instance();
    }

    /**
     * Take a snapshot of every plugin core knows about.
     *
     * Plugins of every type are included, including plugins that ship with
     * Moodle: checks decide themselves whether a plugin is relevant, and the
     * dependency check needs to see core plugins as well.
     *
     * @param target $target The Moodle version the scan is run against.
     * @return plugin_snapshot[] Indexed by component, ordered by component name.
     */
    public function collect(target $target): array {
        $snapshots = [];

        // The plugins are fetched without arguments on purpose: the optional
        // parameter only exists in Moodle 5.2 and later.
        foreach ($this->pluginman->get_plugins() as $plugins) {
            foreach ($plugins as $plugin) {
                try {
                    $snapshot = plugin_snapshot::from_core_plugin($plugin, $target);
                } catch (Throwable $e) {
                    // A partial inventory could produce a misleading ready verdict.
                    throw new RuntimeException(
                        'Could not inspect plugin ' . $plugin->component . ': ' . $e->getMessage(),
                        0,
                        $e
                    );
                }

                if ($snapshot->currentpath === '/') {
                    throw new RuntimeException(
                        'Could not locate plugin ' . $snapshot->component . ' on disk.'
                    );
                }

                $snapshots[$snapshot->component] = $snapshot;
            }
        }

        ksort($snapshots);
        return $snapshots;
    }
}
