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
 * Knows where Moodle keeps plugin code, before and after the 5.1 restructure.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

/**
 * Helpers for the directory layout of the Moodle installation.
 *
 * Moodle 5.1 moved all web accessible code into a public directory. From 5.1
 * onwards, a plugin that still sits above that directory is simply not found any
 * more: it disappears from the site. All path handling for that problem lives
 * here, so that it can be unit tested without a real installation.
 */
final class layout {
    /** @var string Name of the directory holding web accessible code. */
    private const PUBLIC_DIR = 'public';

    /**
     * Absolute path of the directory that is served by the web server.
     *
     * This is $CFG->dirroot, which on a public layout site already points at the
     * public directory.
     *
     * @return string
     */
    public static function get_dirroot(): string {
        global $CFG;
        return rtrim($CFG->dirroot, '/\\');
    }

    /**
     * Absolute path of the installation root, above the public directory.
     *
     * @return string
     */
    public static function get_root(): string {
        global $CFG;

        if (!empty($CFG->root)) {
            return rtrim($CFG->root, '/\\');
        }

        $dirroot = self::get_dirroot();
        if (self::ends_with_public_directory($dirroot)) {
            return dirname($dirroot);
        }

        return $dirroot;
    }

    /**
     * Whether this site already runs the public directory layout.
     *
     * @return bool
     */
    public static function is_public_layout_site(): bool {
        return self::ends_with_public_directory(self::get_dirroot());
    }

    /**
     * Convert a path relative to $CFG->dirroot into one relative to the installation root.
     *
     * Core reports plugin paths relative to $CFG->dirroot, which on a public
     * layout site is the public directory itself.
     *
     * @param string $dirrootrelativepath Path as reported by \core\plugininfo\base::get_dir().
     * @return string Relative path with a leading slash.
     */
    public static function to_root_relative(string $dirrootrelativepath): string {
        $path = self::normalise_relative_path($dirrootrelativepath);

        if (self::is_public_layout_site() && !self::is_inside_public_directory($path)) {
            return '/' . self::PUBLIC_DIR . $path;
        }

        return $path;
    }

    /**
     * Where a plugin has to live for the given target version.
     *
     * @param string $rootrelativepath Plugin path relative to the installation root.
     * @param target $target The Moodle version the site would be upgraded to.
     * @return string Relative path with a leading slash.
     */
    public static function get_expected_path(string $rootrelativepath, target $target): string {
        $withoutpublic = self::remove_public_prefix($rootrelativepath);

        if ($target->publiclayout) {
            return '/' . self::PUBLIC_DIR . $withoutpublic;
        }

        return $withoutpublic;
    }

    /**
     * Remove the leading "/public" from a path relative to the installation root.
     *
     * @param string $path Path relative to the installation root.
     * @return string
     */
    public static function remove_public_prefix(string $path): string {
        $normalised = self::normalise_relative_path($path);
        $prefix = '/' . self::PUBLIC_DIR;

        if (str_starts_with(strtolower($normalised), strtolower($prefix) . '/')) {
            return substr($normalised, strlen($prefix));
        }

        return $normalised;
    }

    /**
     * Whether a plugin path relative to the installation root is inside the public directory.
     *
     * @param string $path Relative path, with or without a leading slash.
     * @return bool
     */
    public static function is_inside_public_directory(string $path): bool {
        $normalised = strtolower(self::normalise_relative_path($path));
        return str_starts_with($normalised, '/' . self::PUBLIC_DIR . '/');
    }

    /**
     * Whether a plugin has to be moved for the given target version.
     *
     * Both paths are relative to the installation root.
     *
     * @param string $rootrelativepath Where the plugin lives right now.
     * @param target $target The Moodle version the site would be upgraded to.
     * @return bool
     */
    public static function needs_move(string $rootrelativepath, target $target): bool {
        return self::get_expected_path($rootrelativepath, $target) !== self::normalise_relative_path($rootrelativepath);
    }

    /**
     * Normalise a relative path to forward slashes with exactly one leading slash.
     *
     * @param string $path The path to normalise.
     * @return string
     */
    public static function normalise_relative_path(string $path): string {
        $normalised = rtrim(str_replace('\\', '/', trim($path)), '/');

        if ($normalised === '') {
            return '/';
        }

        return '/' . ltrim($normalised, '/');
    }

    /**
     * Whether the given absolute path ends with the public directory.
     *
     * @param string $path Absolute path.
     * @return bool
     */
    private static function ends_with_public_directory(string $path): bool {
        $normalised = strtolower(str_replace('\\', '/', rtrim($path, '/\\')));
        return str_ends_with($normalised, '/' . self::PUBLIC_DIR);
    }
}
