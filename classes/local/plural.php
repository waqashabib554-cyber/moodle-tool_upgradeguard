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
 * Translated forms of the words that change with a number.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

/**
 * Picks the singular or plural wording that matches a count.
 *
 * Every count in this plugin is shown inside a sentence, so the words around it
 * have to agree with it: "1 finding blocks" but "2 findings block", "1 plugin is
 * unused" but "2 plugins are unused". Building those words in PHP with a ternary
 * and dropping them into the language string as {$a->noun} would work, but it
 * scatters English into the code and leaves translators no way to reorder the
 * sentence. Instead each word is a string of its own, chosen here, and the
 * sentence is assembled in the language file.
 *
 * Strings needed: noun_action, noun_actions, noun_finding, noun_findings,
 * noun_place, noun_places, noun_plugin, noun_plugins, noun_scan, noun_scans,
 * verb_are, verb_is, verb_live, verb_lives, verb_report, verb_reports,
 * pronoun_it, pronoun_them.
 */
final class plural {
    /**
     * The singular or plural form of a word, depending on the count.
     *
     * @param int $count The number the word has to agree with.
     * @param string $one String key of the singular form, eg noun_finding.
     * @param string $many String key of the plural form, eg noun_findings.
     * @return string
     */
    public static function form(int $count, string $one, string $many): string {
        return get_string($count === 1 ? $one : $many, 'tool_upgradeguard');
    }
}
