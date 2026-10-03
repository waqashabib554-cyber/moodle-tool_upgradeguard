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
 * Normalises the request parameters of the results table.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

/**
 * Validates and normalises everything the results table reads from the URL.
 *
 * Every value that later lands in SQL comes out of here from a whitelist, so
 * the repository can trust its filters completely.
 */
final class results_filters {
    /**
     * Normalise the raw request parameters of the results table.
     *
     * @param array $raw The raw values, keyed like the request parameters.
     * @return array The normalised filters: search, status, type, inuse,
     *               sortby, dir and page.
     */
    public static function normalise(array $raw): array {
        $validstatuses = array_map(static fn($status) => $status->value, status::cases());

        $status = (string) ($raw['status'] ?? '');
        if (!in_array($status, $validstatuses, true)) {
            $status = '';
        }

        $sortby = (string) ($raw['sortby'] ?? 'status');
        if (!in_array($sortby, ['status', 'name', 'type'], true)) {
            $sortby = 'status';
        }

        $type = preg_replace('/[^a-z0-9_]/i', '', (string) ($raw['type'] ?? ''));

        return [
            'search' => trim((string) ($raw['search'] ?? '')),
            'status' => $status,
            'type' => $type,
            'inuse' => !empty($raw['inuse']),
            'sortby' => $sortby,
            'dir' => (string) ($raw['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            'page' => max(1, (int) ($raw['page'] ?? 1)),
        ];
    }
}
