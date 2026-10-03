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
 * Privacy provider for tool_upgradeguard.
 *
 * The only personal data stored by this plugin is the id of the administrator
 * who started a scan, plus the points in time when the scan ran.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;
use tool_upgradeguard\local\repository\scan_repository;

/**
 * Privacy provider for tool_upgradeguard.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection The collection to add metadata to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'tool_upgradeguard_scan',
            [
                'userid' => 'privacy:metadata:tool_upgradeguard_scan:userid',
                'timecreated' => 'privacy:metadata:tool_upgradeguard_scan:timecreated',
                'timestarted' => 'privacy:metadata:tool_upgradeguard_scan:timestarted',
                'timefinished' => 'privacy:metadata:tool_upgradeguard_scan:timefinished',
            ],
            'privacy:metadata:tool_upgradeguard_scan'
        );

        $collection->add_database_table(
            'tool_upgradeguard_plugin',
            [
                'component' => 'privacy:metadata:tool_upgradeguard_plugin:component',
                'status' => 'privacy:metadata:tool_upgradeguard_plugin:status',
                'usagecount' => 'privacy:metadata:tool_upgradeguard_plugin:usagecount',
            ],
            'privacy:metadata:tool_upgradeguard_plugin'
        );

        $collection->add_database_table(
            'tool_upgradeguard_finding',
            [
                'checkkey' => 'privacy:metadata:tool_upgradeguard_finding:checkkey',
                'severity' => 'privacy:metadata:tool_upgradeguard_finding:severity',
                'messagekey' => 'privacy:metadata:tool_upgradeguard_finding:messagekey',
            ],
            'privacy:metadata:tool_upgradeguard_finding'
        );

        return $collection;
    }

    /**
     * Get the list of contexts containing data for the specified user.
     *
     * @param int $userid The user to search for.
     * @return contextlist The list of contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (scan_repository::user_recorded($userid)) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Export all scan records created by the given user.
     *
     * @param approved_contextlist $contextlist The approved contexts to export.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $systemcontext = \context_system::instance();
        if (!self::is_system_context_approved($contextlist)) {
            return;
        }

        $sql = "SELECT s.*
                  FROM {tool_upgradeguard_scan} s
                 WHERE s.userid = :userid
              ORDER BY s.timecreated ASC";
        $records = $DB->get_records_sql($sql, ['userid' => $contextlist->get_user()->id]);

        $scans = [];
        foreach ($records as $record) {
            $scans[] = (object) [
                'targetversion' => $record->targetversion,
                'currentversion' => $record->currentversion,
                'status' => $record->status,
                'score' => $record->score,
                'verdict' => $record->verdict,
                'timecreated' => transform::datetime($record->timecreated),
                'timestarted' => $record->timestarted ? transform::datetime($record->timestarted) : null,
                'timefinished' => $record->timefinished ? transform::datetime($record->timefinished) : null,
            ];
        }

        if (empty($scans)) {
            return;
        }

        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:path:scans', 'tool_upgradeguard')],
            (object) ['scans' => $scans]
        );
    }

    /**
     * Delete all scans stored in the given context.
     *
     * All data of this plugin lives in the system context, so deleting the
     * system context means deleting every scan.
     *
     * @param \context $context The context to purge.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        scan_repository::delete_all_scans();
    }

    /**
     * Delete all scans created by the given user.
     *
     * @param approved_contextlist $contextlist The approved contexts to purge.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if (!self::is_system_context_approved($contextlist)) {
            return;
        }
        scan_repository::delete_scans_for_user($contextlist->get_user()->id);
    }

    /**
     * Whether the approved context list includes the system context.
     *
     * Context ids come back from the database as strings, so both sides are
     * compared as integers: a strict comparison would never match.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @return bool
     */
    private static function is_system_context_approved(approved_contextlist $contextlist): bool {
        $systemcontextid = (int) \context_system::instance()->id;
        foreach ($contextlist->get_contextids() as $contextid) {
            if ((int) $contextid === $systemcontextid) {
                return true;
            }
        }

        return false;
    }
}
