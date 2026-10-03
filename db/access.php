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
 * Capability definitions for Upgrade Guard.
 *
 * All capabilities are defined at system level only: the tool reports on the
 * whole Moodle installation, so there is no meaningful lower context.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // View the dashboard, past scans and their findings.
    'tool/upgradeguard:view' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],

    // Start a new scan. Writes only to this plugin's own tables.
    'tool/upgradeguard:runscan' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
        'clonepermissionsfrom' => 'tool/upgradeguard:view',
    ],

    // Export a scan report. The report contains the site's plugin inventory.
    'tool/upgradeguard:export' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
        'clonepermissionsfrom' => 'tool/upgradeguard:view',
    ],

    // Change settings and delete stored scans (deleting scans loses data).
    'tool/upgradeguard:manage' => [
        'riskbitmask' => RISK_DATALOSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
        'clonepermissionsfrom' => 'tool/upgradeguard:view',
    ],
];
