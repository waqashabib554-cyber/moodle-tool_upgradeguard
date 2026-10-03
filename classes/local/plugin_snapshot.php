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
 * What a scan knows about one plugin.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

/**
 * An immutable snapshot of one plugin, taken while the scan runs.
 *
 * The snapshot keeps everything that the checks need, so that checks never have
 * to talk to the plugin manager themselves.
 */
final class plugin_snapshot {
    /** @var string Frankenstyle component name, eg "mod_forum". */
    public readonly string $component;

    /** @var string Plugin type, eg "mod". */
    public readonly string $plugintype;

    /** @var string Plugin name within its type, eg "forum". */
    public readonly string $name;

    /** @var string Display name shown to the administrator. */
    public readonly string $displayname;

    /** @var string Version of the plugin on disk. */
    public readonly string $versiondisk;

    /** @var string Version recorded in the database, empty when not installed. */
    public readonly string $versiondb;

    /** @var string|null Release of the plugin on disk. */
    public readonly ?string $release;

    /** @var int|null Minimum Moodle version declared by the plugin. */
    public readonly ?int $versionrequires;

    /** @var string Declared supported branch range, eg "404-502", empty when not declared. */
    public readonly string $supportedlist;

    /** @var int|null First Moodle branch the plugin declares itself incompatible with. */
    public readonly ?int $incompatible;

    /** @var bool Whether the plugin ships with Moodle itself. */
    public readonly bool $isstandard;

    /** @var bool Whether the plugin is installed in the database. */
    public readonly bool $installed;

    /** @var string Where the plugin lives now, relative to the installation root. */
    public readonly string $currentpath;

    /** @var string Where the plugin has to live for the target, relative to the installation root. */
    public readonly string $newpath;

    /** @var bool Whether the plugin was only found on disk, outside the expected location. */
    public readonly bool $orphan;

    /** @var \core\plugininfo\base|null Live core plugin info, null for orphans. */
    public readonly ?\core\plugininfo\base $coreplugin;

    /**
     * Create a snapshot.
     *
     * @param string $component Frankenstyle component name.
     * @param string $plugintype Plugin type.
     * @param string $name Plugin name.
     * @param string $displayname Display name.
     * @param string $versiondisk Version on disk.
     * @param string $versiondb Version in the database.
     * @param string|null $release Declared release.
     * @param int|null $versionrequires Minimum Moodle version.
     * @param string $supportedlist Declared supported branch range.
     * @param int|null $incompatible First declared incompatible branch.
     * @param bool $isstandard Whether the plugin ships with Moodle.
     * @param bool $installed Whether the plugin is installed in the database.
     * @param string $currentpath Path relative to the installation root.
     * @param string $newpath Expected path relative to the installation root.
     * @param bool $orphan Whether the plugin was only found on disk.
     * @param \core\plugininfo\base|null $coreplugin Live core plugin info.
     */
    public function __construct(
        string $component,
        string $plugintype,
        string $name,
        string $displayname,
        string $versiondisk,
        string $versiondb,
        ?string $release,
        ?int $versionrequires,
        string $supportedlist,
        ?int $incompatible,
        bool $isstandard,
        bool $installed,
        string $currentpath,
        string $newpath,
        bool $orphan,
        ?\core\plugininfo\base $coreplugin,
    ) {
        $this->component = $component;
        $this->plugintype = $plugintype;
        $this->name = $name;
        $this->displayname = $displayname;
        $this->versiondisk = $versiondisk;
        $this->versiondb = $versiondb;
        $this->release = $release;
        $this->versionrequires = $versionrequires;
        $this->supportedlist = $supportedlist;
        $this->incompatible = $incompatible;
        $this->isstandard = $isstandard;
        $this->installed = $installed;
        $this->currentpath = $currentpath;
        $this->newpath = $newpath;
        $this->orphan = $orphan;
        $this->coreplugin = $coreplugin;
    }

    /**
     * Build a snapshot from a plugin that core knows about.
     *
     * @param \core\plugininfo\base $plugin Core plugin info.
     * @param target $target The Moodle version the scan is run against.
     * @return self
     */
    public static function from_core_plugin(\core\plugininfo\base $plugin, target $target): self {
        $supported = '';
        if (is_array($plugin->pluginsupported) && count($plugin->pluginsupported) === 2) {
            $supported = $plugin->pluginsupported[0] . '-' . $plugin->pluginsupported[1];
        }

        $currentpath = layout::to_root_relative((string) $plugin->get_dir());

        return new self(
            (string) $plugin->component,
            (string) $plugin->type,
            (string) $plugin->name,
            (string) $plugin->displayname,
            (string) $plugin->versiondisk,
            (string) $plugin->versiondb,
            empty($plugin->release) ? null : (string) $plugin->release,
            empty($plugin->versionrequires) ? null : (int) $plugin->versionrequires,
            $supported,
            empty($plugin->pluginincompatible) ? null : (int) $plugin->pluginincompatible,
            (bool) $plugin->is_standard(),
            !empty($plugin->versiondb),
            $currentpath,
            layout::get_expected_path($currentpath, $target),
            false,
            $plugin,
        );
    }

    /**
     * Build a snapshot for a plugin that was only found on disk.
     *
     * @param string $plugintype Plugin type, eg "theme".
     * @param string $name Plugin name, eg "edunity".
     * @param string $rootrelativepath Where the plugin folder is, relative to the installation root.
     * @param target $target The Moodle version the scan is run against.
     * @return self|null Null when the folder cannot be a plugin.
     */
    public static function from_orphan(
        string $plugintype,
        string $name,
        string $rootrelativepath,
        target $target,
    ): ?self {
        $component = $plugintype . '_' . $name;
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $component)) {
            return null;
        }

        $currentpath = layout::normalise_relative_path($rootrelativepath);

        return new self(
            $component,
            $plugintype,
            $name,
            $name,
            '',
            '',
            null,
            null,
            '',
            null,
            false,
            false,
            $currentpath,
            layout::get_expected_path($currentpath, $target),
            true,
            null,
        );
    }

    /**
     * Whether this plugin has to be moved for the target version.
     *
     * @return bool
     */
    public function needs_move(): bool {
        return $this->newpath !== $this->currentpath;
    }

    /**
     * The declared supported branch range as two integers.
     *
     * @return array|null [from, to] pairs, or null when support is not declared.
     */
    public function get_declared_supported_branches(): ?array {
        if ($this->supportedlist === '') {
            return null;
        }

        $parts = explode('-', $this->supportedlist);
        if (count($parts) !== 2) {
            return null;
        }

        return [(int) $parts[0], (int) $parts[1]];
    }
}
