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
 * Upgrade steps for Upgrade Guard.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion The version the site is upgrading from.
 * @return bool
 */
function xmldb_tool_upgradeguard_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026092101) {
        // The release string of a site ("5.2 (Build: 20260420)") does not fit in
        // a 20 character column, so it was widened.
        $table = new xmldb_table('tool_upgradeguard_scan');
        $field = new xmldb_field('currentversion', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '', 'id');

        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026092101, 'tool', 'upgradeguard');
    }

    if ($oldversion < 2026092102) {
        // The word release is a reserved word in MySQL, and the database layer
        // does not quote column names when it builds bulk inserts, so the column
        // is renamed to something that works on every supported database.
        $table = new xmldb_table('tool_upgradeguard_plugin');
        $field = new xmldb_field('release', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'versiondb');

        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'pluginrelease');
        }

        upgrade_plugin_savepoint(true, 2026092102, 'tool', 'upgradeguard');
    }

    if ($oldversion < 2026092200) {
        // Preserve deliberately customised values while migrating the shipped
        // flat finding weights to the Section 6.5 plugin scoring defaults.
        if ((string) get_config('tool_upgradeguard', 'weightblocker') === '25') {
            set_config('weightblocker', 12, 'tool_upgradeguard');
        }
        if ((string) get_config('tool_upgradeguard', 'weightcaution') === '8') {
            set_config('weightcaution', 4, 'tool_upgradeguard');
        }

        upgrade_plugin_savepoint(true, 2026092200, 'tool', 'upgradeguard');
    }

    if ($oldversion < 2026092202) {
        // The environment checks compare PHP, the database server and the loaded
        // PHP extensions against the target branch. Those four values are captured
        // when a scan is queued (the web request the site actually serves with) and
        // stored with the scan, so the report stays reproducible.
        $table = new xmldb_table('tool_upgradeguard_scan');

        $fields = [
            new xmldb_field('phpversion', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'currentbranch'),
            new xmldb_field('dbvendor', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'phpversion'),
            new xmldb_field('dbversion', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'dbvendor'),
            new xmldb_field('phpextensions', XMLDB_TYPE_TEXT, null, null, null, null, null, 'dbversion'),
        ];

        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026092202, 'tool', 'upgradeguard');
    }

    if ($oldversion < 2026092500) {
        // Environment findings now contribute to the stored score summary and
        // verdict. Keep their counts separately so the dashboard can explain
        // why a scan is not green.
        $table = new xmldb_table('tool_upgradeguard_scan');
        $fields = [
            new xmldb_field('environmentblockercount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'unknowncount'),
            new xmldb_field(
                'environmentcautioncount',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0',
                'environmentblockercount'
            ),
        ];

        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026092500, 'tool', 'upgradeguard');
    }

    return true;
}
