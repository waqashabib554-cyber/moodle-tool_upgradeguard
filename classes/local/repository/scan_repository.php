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
 * All database access of Upgrade Guard.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local\repository;

use core\lock\lock_config;
use stdClass;
use Throwable;
use tool_upgradeguard\local\finding;
use tool_upgradeguard\local\target;

/**
 * Database access for scans, scanned plugins and findings.
 *
 * Every query of this plugin lives here, so that a later change (a new index, a
 * different ordering) only has to be made in one place.
 */
final class scan_repository {
    /** @var string The scan is queued and waits for a task to pick it up. */
    public const STATUS_QUEUED = 'queued';

    /** @var string A task is running the scan right now. */
    public const STATUS_RUNNING = 'running';

    /** @var string The scan finished and its results are stored. */
    public const STATUS_FINISHED = 'finished';

    /** @var string The scan stopped with an error. */
    public const STATUS_FAILED = 'failed';

    /** @var string Lock type used to serialise claiming a scan. */
    private const LOCK_TYPE = 'tool_upgradeguard';

    /**
     * Store a new scan as queued.
     *
     * @param target $target The Moodle version the scan is run against.
     * @param string $currentversion The Moodle release of this site.
     * @param int $currentbranch Branch number of this site.
     * @param int $datasetversion Version of the rule dataset in use.
     * @param int $userid The administrator who started the scan.
     * @param array $environment Environment snapshot: php, dbvendor, dbversion, extensions.
     * @return int Id of the new scan.
     */
    public function create_queued_scan(
        target $target,
        string $currentversion,
        int $currentbranch,
        int $datasetversion,
        int $userid,
        array $environment = [],
    ): int {
        global $DB;

        $record = (object) [
            'currentversion' => $currentversion,
            'currentbranch' => $currentbranch,
            'phpversion' => (string) ($environment['php'] ?? ''),
            'dbvendor' => (string) ($environment['dbvendor'] ?? ''),
            'dbversion' => (string) ($environment['dbversion'] ?? ''),
            'phpextensions' => self::encode_extensions($environment['extensions'] ?? []),
            'targetversion' => $target->version,
            'targetbranch' => $target->branch,
            'datasetversion' => $datasetversion,
            'status' => self::STATUS_QUEUED,
            'stage' => '',
            'progress' => 0,
            'userid' => $userid,
            'timecreated' => time(),
            'timestarted' => 0,
            'timefinished' => 0,
        ];

        return (int) $DB->insert_record('tool_upgradeguard_scan', $record);
    }

    /**
     * The environment snapshot that was stored with a scan.
     *
     * The values are captured when the scan is queued, so the report shows the
     * environment the site was actually serving with, and stays reproducible
     * after the server has been changed.
     *
     * @param stdClass $scan A record from tool_upgradeguard_scan.
     * @return array Keys: php, dbvendor, dbversion, extensions.
     */
    public static function get_environment(stdClass $scan): array {
        return [
            'php' => (string) ($scan->phpversion ?? ''),
            'dbvendor' => (string) ($scan->dbvendor ?? ''),
            'dbversion' => (string) ($scan->dbversion ?? ''),
            // PHP reports extension names with their own capitalisation ("SPL",
            // "SimpleXML"), and every comparison in this plugin is lowercase, so
            // the names are normalised once here on the way out of storage.
            'extensions' => array_map('strtolower', self::decode_extensions($scan->phpextensions ?? null)),
        ];
    }

    /**
     * Store a list of PHP extension names as JSON.
     *
     * @param array $extensions Extension names.
     * @return string|null Null when there is nothing to store.
     */
    private static function encode_extensions(array $extensions): ?string {
        $names = array_values(array_filter(array_map('strval', $extensions)));
        if (empty($names)) {
            return null;
        }

        return json_encode($names);
    }

    /**
     * Read a list of PHP extension names that was stored as JSON.
     *
     * @param string|null $json The stored value.
     * @return string[] Empty array when nothing was stored or the value is unusable.
     */
    private static function decode_extensions(?string $json): array {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $decoded)));
    }

    /**
     * Take ownership of a queued scan.
     *
     * Claiming is serialised with a lock and then re-checked against the stored
     * status, so two cron runners can never work on the same scan.
     *
     * @param int $scanid The scan to claim.
     * @return bool True when this process claimed the scan.
     */
    public function claim_scan(int $scanid): bool {
        global $DB;

        $factory = lock_config::get_lock_factory(self::LOCK_TYPE);
        $lock = $factory->get_lock('scan_' . $scanid, 0);
        if ($lock === false) {
            return false;
        }

        try {
            $scan = $this->get_scan($scanid);
            if ($scan === null || $scan->status !== self::STATUS_QUEUED) {
                return false;
            }

            $DB->set_field('tool_upgradeguard_scan', 'status', self::STATUS_RUNNING, ['id' => $scanid]);
            $DB->set_field('tool_upgradeguard_scan', 'timestarted', time(), ['id' => $scanid]);

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * One scan.
     *
     * @param int $scanid The scan id.
     * @return stdClass|null
     */
    public function get_scan(int $scanid): ?stdClass {
        global $DB;

        $scan = $DB->get_record('tool_upgradeguard_scan', ['id' => $scanid]);
        return $scan === false ? null : $scan;
    }

    /**
     * The most recent scan, whatever its status.
     *
     * @return stdClass|null
     */
    public function get_latest_scan(): ?stdClass {
        $records = $this->get_recent_scans(1);
        return empty($records) ? null : reset($records);
    }

    /**
     * Recent scans, newest first.
     *
     * @param int $limit How many scans to return.
     * @return stdClass[] Indexed by scan id.
     */
    public function get_recent_scans(int $limit = 20): array {
        global $DB;

        // Pagination is deliberate: a site that scans regularly accumulates
        // scans forever, so this list must never be unbounded.
        return $DB->get_records('tool_upgradeguard_scan', null, 'timecreated DESC, id DESC', '*', 0, $limit);
    }

    /**
     * The scan that is queued or running, if there is one.
     *
     * @return stdClass|null
     */
    public function get_active_scan(): ?stdClass {
        global $DB;

        $sql = "SELECT *
                  FROM {tool_upgradeguard_scan}
                 WHERE status = :queued OR status = :running
              ORDER BY timecreated ASC";
        $records = $DB->get_records_sql(
            $sql,
            ['queued' => self::STATUS_QUEUED, 'running' => self::STATUS_RUNNING],
            0,
            1
        );

        return empty($records) ? null : reset($records);
    }

    /**
     * Report progress of a running scan.
     *
     * @param int $scanid The scan id.
     * @param string $stage Language string key of the current stage.
     * @param int $progress Progress between 0 and 100.
     * @return void
     */
    public function update_progress(int $scanid, string $stage, int $progress): void {
        global $DB;

        $DB->set_field('tool_upgradeguard_scan', 'stage', $stage, ['id' => $scanid]);
        $DB->set_field('tool_upgradeguard_scan', 'progress', max(0, min(100, $progress)), ['id' => $scanid]);
    }

    /**
     * Mark a scan as finished and store its summary.
     *
     * @param int $scanid The scan id.
     * @param int $score Readiness score between 0 and 100.
     * @param string $verdict The verdict key.
     * @param array $counts Counts: plugincount, blockercount, cautioncount, unknowncount,
     *        environmentblockercount and environmentcautioncount.
     * @return void
     */
    public function finish_scan(int $scanid, int $score, string $verdict, array $counts): void {
        global $DB;

        $DB->update_record('tool_upgradeguard_scan', (object) [
            'id' => $scanid,
            'status' => self::STATUS_FINISHED,
            'stage' => '',
            'progress' => 100,
            'score' => max(0, min(100, $score)),
            'verdict' => $verdict,
            'plugincount' => (int) ($counts['plugincount'] ?? 0),
            'blockercount' => (int) ($counts['blockercount'] ?? 0),
            'cautioncount' => (int) ($counts['cautioncount'] ?? 0),
            'unknowncount' => (int) ($counts['unknowncount'] ?? 0),
            'environmentblockercount' => (int) ($counts['environmentblockercount'] ?? 0),
            'environmentcautioncount' => (int) ($counts['environmentcautioncount'] ?? 0),
            'timefinished' => time(),
        ]);
    }

    /**
     * Mark a scan as failed.
     *
     * @param int $scanid The scan id.
     * @param string $message A message that is safe to show to an administrator.
     * @return void
     */
    public function fail_scan(int $scanid, string $message): void {
        global $DB;

        $DB->update_record('tool_upgradeguard_scan', (object) [
            'id' => $scanid,
            'status' => self::STATUS_FAILED,
            'stage' => '',
            'progress' => 0,
            'errormessage' => substr($message, 0, 1000),
            'timefinished' => time(),
        ]);
    }

    /**
     * Put a scan back into the queue.
     *
     * Used when a scan was claimed but could not be started, for example
     * because another scan held the global lock.
     *
     * @param int $scanid The scan id.
     * @return void
     */
    public function requeue_scan(int $scanid): void {
        global $DB;

        $DB->update_record('tool_upgradeguard_scan', (object) [
            'id' => $scanid,
            'status' => self::STATUS_QUEUED,
            'stage' => '',
            'progress' => 0,
            'timestarted' => 0,
        ]);
    }

    /**
     * Fail scans whose ad hoc task appears to have stopped unexpectedly.
     *
     * A task that dies after claiming a scan leaves it in "running" forever,
     * which prevents the administrator from starting another scan. It is not
     * safe to rerun it automatically because its task may no longer exist, so
     * recover it to a visible failed state instead.
     *
     * @param int $timeout Maximum permitted running time in seconds.
     * @return int Number of recovered scans.
     */
    public function recover_stale_running_scans(int $timeout): int {
        global $DB;

        $cutoff = time() - max(0, $timeout);
        $scanids = $DB->get_fieldset_select(
            'tool_upgradeguard_scan',
            'id',
            'status = ? AND timestarted > ? AND timestarted < ?',
            [self::STATUS_RUNNING, 0, $cutoff]
        );

        foreach ($scanids as $scanid) {
            $this->fail_scan((int) $scanid, get_string('error_scan_stale', 'tool_upgradeguard'));
        }

        return count($scanids);
    }

    /**
     * Store the plugins and findings of a finished scan.
     *
     * Everything is written in one transaction, so an interrupted scan can never
     * leave a half filled report behind.
     *
     * @param int $scanid The scan id.
     * @param stdClass[] $pluginrecords Records for tool_upgradeguard_plugin.
     * @param array $findings Findings with their component: [['component' => string|null, 'finding' => finding]].
     * @return void
     */
    public function save_results(int $scanid, array $pluginrecords, array $findings): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        try {
            if (!empty($pluginrecords)) {
                // The scan id is stamped here, not by the caller, so that stored
                // rows can never end up belonging to the wrong scan.
                foreach ($pluginrecords as $record) {
                    $record->scanid = $scanid;
                }
                $DB->insert_records('tool_upgradeguard_plugin', $pluginrecords);
            }

            $pluginids = [];
            $rows = $DB->get_records('tool_upgradeguard_plugin', ['scanid' => $scanid], '', 'id, component');
            foreach ($rows as $row) {
                $pluginids[$row->component] = (int) $row->id;
            }

            $findingrecords = [];
            foreach ($findings as $entry) {
                $component = $entry['component'];
                $pluginid = $component === null ? 0 : ($pluginids[$component] ?? 0);
                $findingrecords[] = $entry['finding']->to_record($scanid, $pluginid);
            }

            if (!empty($findingrecords)) {
                $DB->insert_records('tool_upgradeguard_finding', $findingrecords);
            }

            $transaction->allow_commit();
        } catch (Throwable $e) {
            // The rollback() call rethrows, which is what the caller needs to see.
            $transaction->rollback($e);
        }
    }

    /**
     * The scanned plugins of a scan.
     *
     * @param int $scanid The scan id.
     * @return stdClass[] Indexed by plugin id.
     */
    public function get_plugins_for_scan(int $scanid): array {
        global $DB;

        return $DB->get_records('tool_upgradeguard_plugin', ['scanid' => $scanid], 'component ASC');
    }

    /**
     * The findings of a scan, grouped and sorted worst first.
     *
     * Site wide findings are grouped under an empty component key. A single
     * query is used for the whole report, however many plugins there are.
     *
     * @param int $scanid The scan id.
     * @return array Map of component (or '' for site findings) to finding[].
     */
    public function get_findings_grouped(int $scanid): array {
        global $DB;

        $sql = "SELECT f.*, p.component
                  FROM {tool_upgradeguard_finding} f
             LEFT JOIN {tool_upgradeguard_plugin} p ON p.id = f.pluginid
                 WHERE f.scanid = :scanid";

        $grouped = ['' => []];

        foreach ($DB->get_records_sql($sql, ['scanid' => $scanid]) as $record) {
            $component = empty($record->component) ? '' : $record->component;
            $grouped[$component][] = finding::from_record($record);
        }

        foreach ($grouped as $component => $findings) {
            usort($findings, [finding::class, 'compare']);
            $grouped[$component] = $findings;
        }

        return $grouped;
    }

    /**
     * Delete one scan with everything in it.
     *
     * @param int $scanid The scan id.
     * @return void
     */
    public function delete_scan(int $scanid): void {
        self::delete_scans([$scanid]);
    }

    /**
     * Whether a user has any recorded scan.
     *
     * @param int $userid The user id.
     * @return bool
     */
    public static function user_recorded(int $userid): bool {
        global $DB;

        return $DB->record_exists('tool_upgradeguard_scan', ['userid' => $userid]);
    }

    /**
     * Delete all scans started by one user.
     *
     * @param int $userid The user id.
     * @return void
     */
    public static function delete_scans_for_user(int $userid): void {
        global $DB;

        $scanids = $DB->get_fieldset_select('tool_upgradeguard_scan', 'id', 'userid = ?', [$userid]);
        self::delete_scans($scanids);
    }

    /**
     * Delete every scan.
     *
     * @return void
     */
    public static function delete_all_scans(): void {
        global $DB;

        $scanids = $DB->get_fieldset_select('tool_upgradeguard_scan', 'id', '1 = 1', []);
        self::delete_scans($scanids);
    }

    /**
     * Delete finished scans that are older than the retention period.
     *
     * @param int $days Retention in days, 0 keeps everything.
     * @return int Number of scans that were deleted.
     */
    public function delete_scans_older_than(int $days): int {
        global $DB;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = time() - ($days * DAYSECS);
        $sql = "timecreated < ? AND status <> ? AND status <> ?";
        $scanids = $DB->get_fieldset_select(
            'tool_upgradeguard_scan',
            'id',
            $sql,
            [$cutoff, self::STATUS_QUEUED, self::STATUS_RUNNING]
        );

        self::delete_scans($scanids);

        return count($scanids);
    }

    /**
     * Delete scans in chunks, in one transaction.
     *
     * @param array $scanids Ids of the scans to remove.
     * @return void
     */
    private static function delete_scans(array $scanids): void {
        global $DB;

        if (empty($scanids)) {
            return;
        }

        $transaction = $DB->start_delegated_transaction();

        try {
            // Chunked, so that a site with a long history never builds an
            // enormous IN clause.
            foreach (array_chunk($scanids, 200) as $chunk) {
                $DB->delete_records_list('tool_upgradeguard_finding', 'scanid', $chunk);
                $DB->delete_records_list('tool_upgradeguard_plugin', 'scanid', $chunk);
                $DB->delete_records_list('tool_upgradeguard_scan', 'id', $chunk);
            }

            $transaction->allow_commit();
        } catch (Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * The number of rows one page of the results table shows.
     */
    public const RESULTS_PER_PAGE = 25;

    /**
     * One page of the results table, filtered and sorted.
     *
     * @param int $scanid The scan to read from.
     * @param array $filters Normalised filters: search, status, type, inuse.
     * @param array $sort Normalised sort: by, dir.
     * @param int $page The 1-based page number.
     * @return stdClass[] The plugin rows of that page.
     */
    public function search_plugins(int $scanid, array $filters, array $sort, int $page): array {
        global $DB;

        [$where, $params] = self::build_filter_sql($scanid, $filters);
        $sql = "SELECT p.*
                  FROM {tool_upgradeguard_plugin} p
                 WHERE $where
                 " . self::build_order_sql($sort);

        return $DB->get_records_sql($sql, $params, ($page - 1) * self::RESULTS_PER_PAGE, self::RESULTS_PER_PAGE);
    }

    /**
     * How many plugin rows the current filters match in total.
     *
     * @param int $scanid The scan to read from.
     * @param array $filters Normalised filters: search, status, type, inuse.
     * @return int
     */
    public function count_plugins(int $scanid, array $filters): int {
        global $DB;

        [$where, $params] = self::build_filter_sql($scanid, $filters);

        return (int) $DB->count_records_sql(
            "SELECT COUNT(p.id) FROM {tool_upgradeguard_plugin} p WHERE $where",
            $params
        );
    }

    /**
     * The distinct plugin types a scan found, sorted.
     *
     * @param int $scanid The scan to read from.
     * @return string[]
     */
    public function get_plugin_types(int $scanid): array {
        global $DB;

        $types = $DB->get_fieldset_select('tool_upgradeguard_plugin', 'plugintype', 'scanid = ?', [$scanid]);
        $types = array_values(array_unique($types));
        sort($types);

        return $types;
    }

    /**
     * The findings of the given plugins, in one query.
     *
     * @param int $scanid The scan to read from.
     * @param string[] $components The components shown on one page.
     * @return finding[][] Findings grouped by component.
     */
    public function get_findings_for_components(int $scanid, array $components): array {
        global $DB;

        if (empty($components)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($components, SQL_PARAMS_NAMED, 'comp');
        $sql = "SELECT f.*, p.component
                  FROM {tool_upgradeguard_finding} f
                  JOIN {tool_upgradeguard_plugin} p ON p.id = f.pluginid
                 WHERE f.scanid = :scanid AND p.component $insql";
        $params = array_merge(['scanid' => $scanid], $inparams);

        $grouped = [];
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            $grouped[$record->component][] = finding::from_record($record);
        }

        return $grouped;
    }

    /**
     * The WHERE clause and parameters of the results table filters.
     *
     * Every value is bound as a parameter; the sort columns come from a
     * whitelist, so nothing user supplied ever lands in the SQL itself.
     *
     * @param int $scanid The scan to read from.
     * @param array $filters Normalised filters: search, status, type, inuse.
     * @return array The where clause and the named parameters.
     */
    private static function build_filter_sql(int $scanid, array $filters): array {
        global $DB;

        $where = 'p.scanid = :scanid';
        $params = ['scanid' => $scanid];

        if ($filters['search'] !== '') {
            $like = '%' . $DB->sql_like_escape($filters['search']) . '%';
            $where .= ' AND (' . $DB->sql_like('p.component', ':searchcomponent', false)
                . ' OR ' . $DB->sql_like('p.displayname', ':searchdisplayname', false) . ')';
            $params['searchcomponent'] = $like;
            $params['searchdisplayname'] = $like;
        }

        if ($filters['status'] !== '') {
            $where .= ' AND p.status = :filterstatus';
            $params['filterstatus'] = $filters['status'];
        }

        if ($filters['type'] !== '') {
            $where .= ' AND p.plugintype = :filtertype';
            $params['filtertype'] = $filters['type'];
        }

        if ($filters['inuse']) {
            $where .= ' AND p.usagecount > 0';
        }

        return [$where, $params];
    }

    /**
     * The ORDER BY clause of the results table.
     *
     * Status sorts worst first, matching the rank of the status enum.
     *
     * @param array $sort Normalised sort: by, dir.
     * @return string
     */
    private static function build_order_sql(array $sort): string {
        $columns = [
            'status' => "CASE p.status WHEN 'blocker' THEN 0 WHEN 'caution' THEN 1"
                . " WHEN 'unknown' THEN 2 WHEN 'update' THEN 3 ELSE 4 END",
            'name' => 'p.displayname',
            'type' => 'p.plugintype',
        ];

        $column = $columns[$sort['by']];
        $direction = $sort['dir'] === 'desc' ? 'DESC' : 'ASC';

        return "ORDER BY $column $direction, p.component ASC";
    }

    /**
     * How many scans have been recorded in total.
     *
     * @return int
     */
    public function count_scans(): int {
        global $DB;

        return (int) $DB->count_records('tool_upgradeguard_scan');
    }

    /**
     * One page of the scan history, newest first, with the owner's name.
     *
     * The user name is joined, so the table never runs one query per row. Every
     * column fullname() reads has to be selected: it warns about a missing name
     * field, and on a site running in developer mode that warning is turned into
     * a fatal error, which would take the whole history page down.
     *
     * @param int $limit Rows per page.
     * @param int $offset Offset of the page.
     * @return stdClass[] Scan rows with an ownername field added.
     */
    public function get_scans_page(int $limit, int $offset): array {
        global $DB;

        $namecolumns = ['firstname', 'lastname', 'firstnamephonetic', 'lastnamephonetic', 'middlename', 'alternatename'];
        $selected = [];
        foreach ($namecolumns as $column) {
            $selected[] = 'u.' . $column;
        }

        $sql = "SELECT s.*, " . implode(', ', $selected) . "
                  FROM {tool_upgradeguard_scan} s
             LEFT JOIN {user} u ON u.id = s.userid
          ORDER BY s.timecreated DESC, s.id DESC";
        $rows = $DB->get_records_sql($sql, null, $offset, $limit);

        foreach ($rows as $row) {
            // A scan whose owner has since been deleted has no name to print.
            $row->ownername = $row->userid === null || (int) $row->userid === 0
                ? ''
                : fullname($row);
        }

        return $rows;
    }
}
