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
 * Environment section data shared by the dashboard and the printable report.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\output;

use stdClass;
use tool_upgradeguard\local\repository\scan_repository;
use tool_upgradeguard\local\severity;
use tool_upgradeguard\local\target;

/**
 * Builds the environment card data.
 *
 * Current values come from the snapshot stored when the scan was queued.
 * Requirements come from the rule dataset. A row is marked as a problem only
 * when the scan itself reported a blocker for it, so that the card and the
 * findings never disagree.
 */
final class environment_context {
    /**
     * Build the template context of the environment card.
     *
     * @param target|null $target The target the scan was run against.
     * @param stdClass|null $scan The stored scan row.
     * @param array[] $sitefindings The site wide findings of the scan.
     * @return array The keys used by the environment template.
     */
    public static function build(?target $target, ?stdClass $scan, array $sitefindings): array {
        $rows = self::get_rows($target, $scan, $sitefindings);

        // The dataset stores a free text note per requirement, and a note is not
        // always a bare URL: it can be a sentence that only mentions one. Printing
        // the note and then linking the same text again produced the same address
        // twice, and a whole sentence used as a link text, so the note is split
        // into a readable label and, when it contains one, a link.
        $verificationsummary = '';
        $labels = [];
        $links = [];
        foreach ($rows as $row) {
            if (!$row['hassource']) {
                continue;
            }
            [$label, $url] = self::split_source($row['source']);
            if ($label === '') {
                $label = $url;
            }
            // A note that is nothing but a link is shown once, as the link.
            if ($label !== $url) {
                $labels[$label] = true;
            }
            if ($url !== '' && !in_array($url, $links, true)) {
                $links[] = $url;
            }
        }

        foreach ($rows as $row) {
            if ($row['unverifiednote'] !== '') {
                $verificationsummary = get_string('env_source_unverified', 'tool_upgradeguard');
            }
        }
        if ($verificationsummary === '' && $labels !== []) {
            $verificationsummary = get_string(
                'env_source_verified',
                'tool_upgradeguard',
                implode(' + ', array_keys($labels))
            );
        }

        $verificationsources = [];
        foreach ($links as $url) {
            $verificationsources[] = ['url' => $url, 'label' => $url];
        }

        return [
            'environment' => $rows,
            'hasenvironment' => !empty($rows),
            'environmentheading' => get_string('environmentheading', 'tool_upgradeguard'),
            'environmentnone' => get_string('environmentnone', 'tool_upgradeguard'),
            'verificationsummary' => $verificationsummary,
            'verificationsources' => $verificationsources,
        ];
    }

    /**
     * Split a source note into the part that reads as text and its first URL.
     *
     * @param string $source A source note from the rule dataset.
     * @return array{0: string, 1: string} The label and the URL, either may be empty.
     */
    private static function split_source(string $source): array {
        $url = '';
        if (preg_match('~https?://[^\s,;)]+~', $source, $matches)) {
            // The match can swallow the full stop that ends the sentence.
            $url = rtrim($matches[0], '.,;:');
        }

        $label = trim((string) preg_replace('~https?://\S+~', '', $source));
        // A note that only existed to carry the address often ends in a word that
        // pointed at it, and that word is left dangling once the address moves
        // into the link below.
        $label = (string) preg_replace('~\b(?:upstream|see|and|from|in|per)$~i', '', $label);
        $label = trim($label, " \t\n\r\0\x0B,;:.");

        return [$label, $url];
    }

    /**
     * The three environment rows: PHP, database and PHP extensions.
     *
     * @param target|null $target The target the scan was run against.
     * @param stdClass|null $scan The stored scan row.
     * @param array[] $sitefindings The site wide findings of the scan.
     * @return array[]
     */
    private static function get_rows(?target $target, ?stdClass $scan, array $sitefindings): array {
        if ($scan === null || $target === null) {
            return [];
        }

        $environment = scan_repository::get_environment($scan);
        $rows = [];

        $phpmessage = self::get_blocker_message($sitefindings, 'site_php');
        $rows[] = self::get_row(
            get_string('env_php', 'tool_upgradeguard'),
            $environment['php'],
            $target->phpmin,
            $phpmessage !== '',
            $phpmessage,
            '',
            $target->get_environment_source('php'),
            $target->is_environment_verified('php')
        );

        $vendor = $environment['dbvendor'];
        $dbmessage = self::get_blocker_message($sitefindings, 'environment_database');
        $required = $vendor === '' ? '' : (string) ($target->get_database_minimum($vendor) ?? '');
        $notexercised = $required !== '' && !$target->is_database_exercised($vendor);

        $rows[] = self::get_row(
            get_string('env_database', 'tool_upgradeguard') . ($vendor === '' ? '' : ' (' . $vendor . ')'),
            $environment['dbversion'],
            $required,
            $dbmessage !== '',
            $dbmessage,
            $notexercised ? get_string('env_database_not_exercised', 'tool_upgradeguard') : '',
            $target->get_environment_source('database'),
            $target->is_environment_verified('database')
        );

        $requiredextensions = $target->get_required_extensions();
        $missing = [];
        foreach ($requiredextensions as $extension) {
            if (!in_array(strtolower($extension), $environment['extensions'], true)) {
                $missing[] = $extension;
            }
        }

        $extensionscurrent = empty($missing)
            ? get_string('env_extensions_all', 'tool_upgradeguard', count($requiredextensions))
            : get_string('env_extensions_missing', 'tool_upgradeguard', implode(', ', $missing));
        $extensionsmessage = self::get_blocker_message($sitefindings, 'environment_extensions');

        $rows[] = self::get_row(
            get_string('env_extensions', 'tool_upgradeguard'),
            $extensionscurrent,
            get_string('env_extensions_all', 'tool_upgradeguard', count($requiredextensions)),
            $extensionsmessage !== '',
            $extensionsmessage,
            '',
            $target->get_environment_source('extensions'),
            $target->is_environment_verified('extensions')
        );

        return $rows;
    }

    /**
     * The message of the first blocker finding a check produced.
     *
     * @param array[] $sitefindings The site wide findings of the scan.
     * @param string $checkkey The check key, eg "environment_database".
     * @return string Empty string when the check reported no blocker.
     */
    private static function get_blocker_message(array $sitefindings, string $checkkey): string {
        foreach ($sitefindings as $finding) {
            if ($finding->checkkey === $checkkey && $finding->severity === severity::blocker) {
                return $finding->get_message();
            }
        }

        return '';
    }

    /**
     * One row of the environment card.
     *
     * @param string $label What is checked.
     * @param string $current What the scan recorded on this server.
     * @param string $required What the target needs, empty when unknown.
     * @param bool $problem Whether the scan reported a problem for this row.
     * @param string $problemtext The message of that problem.
     * @param string $note An extra note, eg "not exercised on this database".
     * @param string $source Where the requirement number comes from.
     * @param bool $verified Whether that number was verified.
     * @return array
     */
    private static function get_row(
        string $label,
        string $current,
        string $required,
        bool $problem,
        string $problemtext,
        string $note,
        string $source,
        bool $verified,
    ): array {
        $verified = $verified && $source !== '';
        $status = get_string($problem ? 'env_problem' : 'env_ok', 'tool_upgradeguard');
        $statusclass = $problem ? 'danger' : 'success';

        return [
            'label' => $label,
            'current' => $current,
            'hascurrent' => $current !== '',
            'required' => $required,
            'hasrequired' => $required !== '',
            'status' => $status,
            'statusclass' => $statusclass,
            'hasproblem' => $problemtext !== '',
            'problem' => $problemtext,
            'note' => $note,
            'hasnote' => $note !== '',
            'source' => $source,
            'hassource' => $source !== '',
            'verified' => $verified,
            'unverifiednote' => $verified ? '' : get_string('env_source_unverified', 'tool_upgradeguard'),
        ];
    }
}
