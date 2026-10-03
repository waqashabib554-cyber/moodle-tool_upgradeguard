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
 * Finds plugin folders that Moodle no longer reads.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local\collector;

use core_component;
use RuntimeException;
use tool_upgradeguard\local\layout;
use tool_upgradeguard\local\plugin_snapshot;
use tool_upgradeguard\local\target;

/**
 * Scans the installation root for plugins that sit outside the directory core reads.
 *
 * After the 5.1 restructure, Moodle only reads plugins from the public
 * directory. A plugin that is still in the old location is not part of the
 * inventory any more, so it has to be found on disk instead.
 */
final class orphan_scanner {
    /** @var string Absolute path of the installation root. */
    private string $root;

    /** @var string Absolute path of the directory core reads plugins from. */
    private string $dirroot;

    /**
     * Create the scanner.
     *
     * @param string|null $root Optional installation root, used by tests.
     * @param string|null $dirroot Optional dirroot, used by tests.
     */
    public function __construct(?string $root = null, ?string $dirroot = null) {
        $this->root = rtrim($root ?? layout::get_root(), '/\\');
        $this->dirroot = rtrim($dirroot ?? layout::get_dirroot(), '/\\');
    }

    /**
     * Find plugins that are stored outside the directory Moodle reads.
     *
     * @param target $target The Moodle version the scan is run against.
     * @return plugin_snapshot[] Indexed by component, ordered by component name.
     */
    public function collect(target $target): array {
        $orphans = [];

        if (strtolower($this->root) === strtolower($this->dirroot)) {
            // Old style installation: plugins above the web root are the normal
            // location here, so nothing can be out of place.
            return $orphans;
        }

        foreach (core_component::get_all_plugin_types() as $plugintype => $typedir) {
            $relative = $this->get_relative_type_dir($typedir);
            if ($relative === null) {
                continue;
            }

            $candidate = $this->root . $relative;
            if (!is_dir($candidate)) {
                continue;
            }

            foreach ($this->find_plugin_dirs($candidate) as $name) {
                $snapshot = plugin_snapshot::from_orphan(
                    (string) $plugintype,
                    $name,
                    $relative . '/' . $name,
                    $target
                );
                if ($snapshot !== null) {
                    $orphans[$snapshot->component] = $snapshot;
                }
            }
        }

        ksort($orphans);
        return $orphans;
    }

    /**
     * The type directory relative to the directory core reads, or null when it is outside.
     *
     * @param string $typedir Absolute type directory reported by core.
     * @return string|null Relative path with a leading slash.
     */
    private function get_relative_type_dir(string $typedir): ?string {
        $normaliseddirroot = strtolower(str_replace('\\', '/', $this->dirroot));
        $normalisedtype = strtolower(str_replace('\\', '/', rtrim($typedir, '/\\')));

        if (!str_starts_with($normalisedtype, $normaliseddirroot)) {
            return null;
        }

        $relative = substr(rtrim($typedir, '/\\'), strlen($this->dirroot));
        if ($relative === '' || $relative === false) {
            return null;
        }

        return '/' . trim(str_replace('\\', '/', $relative), '/');
    }

    /**
     * Names of the plugin folders inside a type directory.
     *
     * Only folders that contain a version.php are considered, which is the same
     * requirement core uses to recognise a plugin on disk.
     *
     * @param string $typedir Absolute type directory.
     * @return string[] Plugin folder names.
     */
    private function find_plugin_dirs(string $typedir): array {
        $names = [];
        $entries = @scandir($typedir);

        if ($entries === false) {
            throw new RuntimeException('Could not read the plugin type directory ' . $typedir . '.');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $typedir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && is_readable($path . DIRECTORY_SEPARATOR . 'version.php')) {
                $names[] = $entry;
            }
        }

        return $names;
    }
}
