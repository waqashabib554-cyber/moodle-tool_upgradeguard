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
 * Measures how much of the site actually uses each plugin.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local\collector;

use dml_exception;
use tool_upgradeguard\local\plugin_snapshot;

/**
 * Counts where on the site a plugin is used.
 *
 * Every supported plugin type is counted with a single grouped query, no matter
 * how many plugins of that type are installed, so a scan cannot turn into an
 * N+1 query storm on a large site.
 *
 * Plugin types that have no measurable usage (admin tools, for example) are
 * simply left out of the result, which the report shows as "cannot be
 * measured" rather than as "unused".
 */
final class usage_collector {
    /** @var string[] Plugin types with a known way of measuring usage. */
    private const MEASURABLE_TYPES = [
        'mod',
        'block',
        'filter',
        'auth',
        'enrol',
        'qtype',
        'repository',
        'theme',
        'format',
    ];

    /**
     * Measure usage for all given plugins.
     *
     * @param plugin_snapshot[] $snapshots Snapshots indexed by component.
     * @return array Usage per component: ['count' => int|null, 'detail' => array].
     */
    public function collect(array $snapshots): array {
        $namesbytype = [];

        foreach ($snapshots as $snapshot) {
            if ($snapshot->isstandard || !$snapshot->installed || $snapshot->orphan) {
                continue;
            }
            if (!in_array($snapshot->plugintype, self::MEASURABLE_TYPES, true)) {
                continue;
            }
            $namesbytype[$snapshot->plugintype][] = $snapshot->name;
        }

        $usage = [];
        foreach ($namesbytype as $plugintype => $names) {
            $usage += $this->collect_type($plugintype, $names);
        }

        return $usage;
    }

    /**
     * Measure usage for one plugin type.
     *
     * @param string $plugintype Plugin type, eg "mod".
     * @param string[] $names The plugin names of that type.
     * @return array Usage per component.
     */
    private function collect_type(string $plugintype, array $names): array {
        try {
            $counts = match ($plugintype) {
                'mod' => $this->count_modules(),
                'block' => $this->count_blocks(),
                'filter' => $this->count_filters(),
                'auth' => $this->count_auth_plugins(),
                'enrol' => $this->count_enrol_methods(),
                'qtype' => $this->count_question_types(),
                'repository' => $this->count_repositories(),
                'theme' => $this->count_themes(),
                'format' => $this->count_course_formats(),
                default => [],
            };
        } catch (dml_exception $e) {
            // Measuring usage must never break a scan.
            debugging('tool_upgradeguard: could not measure usage for ' . $plugintype, DEBUG_DEVELOPER);
            return [];
        }

        $usage = [];
        foreach ($names as $name) {
            $component = $plugintype . '_' . $name;
            $usage[$component] = [
                'count' => (int) ($counts[$component] ?? 0),
                'detail' => [],
            ];
        }

        return $usage;
    }

    /**
     * Count how many courses use each activity plugin.
     *
     * @return array Component => count.
     */
    private function count_modules(): array {
        global $DB;

        $sql = "SELECT m.name, COUNT(cm.id) AS usages
                  FROM {modules} m
             LEFT JOIN {course_modules} cm ON cm.module = m.id
              GROUP BY m.name";

        return $this->prefix_counts($DB->get_records_sql($sql), 'mod');
    }

    /**
     * Count how many block instances exist per block plugin.
     *
     * @return array Component => count.
     */
    private function count_blocks(): array {
        global $DB;

        $sql = "SELECT blockname AS name, COUNT(id) AS usages
                  FROM {block_instances}
              GROUP BY blockname";

        return $this->prefix_counts($DB->get_records_sql($sql), 'block');
    }

    /**
     * Count the contexts each filter is switched on in.
     *
     * @return array Component => count.
     */
    private function count_filters(): array {
        global $DB;

        $sql = "SELECT filter AS name, COUNT(id) AS usages
                  FROM {filter_active}
                 WHERE active > 0
              GROUP BY filter";

        return $this->prefix_counts($DB->get_records_sql($sql), 'filter');
    }

    /**
     * Count the users that log in with each authentication plugin.
     *
     * This is a single grouped query, but on a very large site it still reads a
     * lot of rows. It is deliberately not skipped: knowing that an
     * authentication plugin is in use is important before an upgrade.
     *
     * @return array Component => count.
     */
    private function count_auth_plugins(): array {
        global $DB;

        $sql = "SELECT auth AS name, COUNT(id) AS usages
                  FROM {user}
                 WHERE deleted = 0
              GROUP BY auth";

        return $this->prefix_counts($DB->get_records_sql($sql), 'auth');
    }

    /**
     * Count the enrolment instances per enrolment method.
     *
     * @return array Component => count.
     */
    private function count_enrol_methods(): array {
        global $DB;

        $sql = "SELECT enrol AS name, COUNT(id) AS usages
                  FROM {enrol}
              GROUP BY enrol";

        return $this->prefix_counts($DB->get_records_sql($sql), 'enrol');
    }

    /**
     * Count the questions per question type.
     *
     * @return array Component => count.
     */
    private function count_question_types(): array {
        global $DB;

        $sql = "SELECT qtype AS name, COUNT(id) AS usages
                  FROM {question}
              GROUP BY qtype";

        return $this->prefix_counts($DB->get_records_sql($sql), 'qtype');
    }

    /**
     * Count the configured instances per repository plugin.
     *
     * @return array Component => count.
     */
    private function count_repositories(): array {
        global $DB;

        $sql = "SELECT type AS name, COUNT(id) AS usages
                  FROM {repository}
              GROUP BY type";

        return $this->prefix_counts($DB->get_records_sql($sql), 'repository');
    }

    /**
     * Count the courses using each course format.
     *
     * @return array Component => count.
     */
    private function count_course_formats(): array {
        global $DB;

        $sql = "SELECT format AS name, COUNT(id) AS usages
                  FROM {course}
              GROUP BY format";

        return $this->prefix_counts($DB->get_records_sql($sql), 'format');
    }

    /**
     * Estimate how many places use each theme.
     *
     * Courses without their own theme follow the site theme, so those are
     * counted for whichever theme the site uses. Categories and users can
     * override the inherited theme too, and must be included before declaring a
     * custom theme unused.
     *
     * @return array Component => count.
     */
    private function count_themes(): array {
        global $CFG, $DB;

        $coursesql = "SELECT theme AS name, COUNT(id) AS usages
                  FROM {course}
              GROUP BY theme";
        $courserecords = $DB->get_records_sql($coursesql);
        $counts = $this->prefix_counts($courserecords, 'theme');

        $sitethemes = 0;
        foreach ($courserecords as $record) {
            if ($record->name === '' || $record->name === 'site') {
                $sitethemes += (int) $record->usages;
            }
        }

        $sitecomponent = 'theme_' . $CFG->theme;
        $counts[$sitecomponent] = ($counts[$sitecomponent] ?? 0) + $sitethemes;

        $categorysql = "SELECT theme AS name, COUNT(id) AS usages
                          FROM {course_categories}
                         WHERE theme <> ?
                      GROUP BY theme";
        $this->add_counts($counts, $this->prefix_counts($DB->get_records_sql($categorysql, ['']), 'theme'));

        $usersql = "SELECT theme AS name, COUNT(id) AS usages
                      FROM {user}
                     WHERE deleted = 0 AND theme <> ?
                  GROUP BY theme";
        $this->add_counts($counts, $this->prefix_counts($DB->get_records_sql($usersql, ['']), 'theme'));

        return $counts;
    }

    /**
     * Add one set of component counts to another.
     *
     * @param array &$target Counts to update.
     * @param array $additional Counts to add.
     * @return void
     */
    private function add_counts(array &$target, array $additional): void {
        foreach ($additional as $component => $count) {
            $target[$component] = ($target[$component] ?? 0) + $count;
        }
    }

    /**
     * Add the plugin type prefix to a counted column.
     *
     * @param iterable $records Records with "name" and "usages" properties.
     * @param string $plugintype Plugin type, eg "mod".
     * @return array Component => count.
     */
    private function prefix_counts(iterable $records, string $plugintype): array {
        $counts = [];
        foreach ($records as $record) {
            $counts[$plugintype . '_' . $record->name] = (int) $record->usages;
        }
        return $counts;
    }
}
