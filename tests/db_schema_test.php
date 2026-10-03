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

namespace tool_upgradeguard;

use basic_testcase;

/**
 * The database definition and the upgrade steps have to agree, and the upgrade
 * steps have to use constants that exist.
 *
 * The 0.9.0 to 0.9.1 upgrade step used XMLDB_TYPE_INT. Moodle has no such
 * constant: install.xml maps the alias TYPE="int" to XMLDB_TYPE_INTEGER, but in
 * PHP the name has to be spelled out in full. The step therefore died with
 * "Undefined constant XMLDB_TYPE_INT" before it could add a single column, so
 * the plugin never finished installing on a site that already had 0.9.0, while
 * a fresh install worked perfectly and every unit test passed. Nothing else in
 * the suite loads db/upgrade.php, so nothing else could have seen it.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class db_schema_test extends basic_testcase {
    /**
     * Every XMLDB constant db/upgrade.php names must be a real constant.
     *
     * @coversNothing
     */
    public function test_the_upgrade_step_only_uses_defined_xmldb_constants(): void {
        $source = $this->read('db/upgrade.php');
        preg_match_all('/\b(XMLDB_[A-Z_0-9]+)\b/', $source, $matches);

        $this->assertNotEmpty($matches[1], 'db/upgrade.php mentions no XMLDB constants at all.');

        $undefined = [];
        foreach (array_unique($matches[1]) as $name) {
            if (!defined($name)) {
                $undefined[] = $name;
            }
        }

        $this->assertSame(
            [],
            $undefined,
            'db/upgrade.php uses constants that do not exist, so the upgrade step fatals: '
                . implode(', ', $undefined)
                . '. Note that install.xml accepts TYPE="int" as an alias for an integer column,'
                . ' but in PHP the constant is XMLDB_TYPE_INTEGER.'
        );
    }

    /**
     * install.xml must parse, and must be readable by the XMLDB parser.
     *
     * @coversNothing
     */
    public function test_install_xml_is_valid(): void {
        $path = $this->path('db/install.xml');
        $this->assertFileExists($path);

        // LoadXMLStructure returns false when the file does not parse, when an
        // attribute is invalid (an unknown TYPE, for instance) or when the
        // structure is not marked as loaded, which is exactly the set of
        // defects this test exists to catch.
        $xmlfile = new \xmldb_file($path);
        $this->assertTrue(
            $this->loadquietly($xmlfile),
            'install.xml is not a valid XMLDB file. Check it at '
                . 'https://moodle.org/admin/tool/xmldb/index.php'
        );
    }

    /**
     * The columns the 0.9.1 upgrade step adds must be declared in install.xml.
     *
     * A column that exists in only one of the two places gives a fresh install a
     * different schema from an upgraded one, and the two then drift silently.
     *
     * @coversNothing
     */
    public function test_the_new_environment_columns_are_declared_in_install_xml(): void {
        $xmlfile = new \xmldb_file($this->path('db/install.xml'));
        $this->loadquietly($xmlfile);
        $structure = $xmlfile->getStructure();

        $this->assertNotNull($structure, 'install.xml produced no XMLDB structure.');

        $table = $structure->getTable('tool_upgradeguard_scan');
        $this->assertNotNull($table, 'install.xml does not declare the tool_upgradeguard_scan table.');

        $declared = [];
        foreach ($table->getFields() as $field) {
            $declared[$field->getName()] = $field;
        }

        foreach (['environmentblockercount', 'environmentcautioncount'] as $name) {
            $this->assertArrayHasKey(
                $name,
                $declared,
                "install.xml does not declare {$name}; a fresh install would not have the column the upgrade step adds."
            );
            $this->assertSame(
                XMLDB_TYPE_INTEGER,
                $declared[$name]->getType(),
                "{$name} must be an integer in install.xml."
            );
            $this->assertTrue(
                $declared[$name]->getNotNull(),
                "{$name} must be NOT NULL in install.xml."
            );
            $this->assertSame(
                '0',
                (string) $declared[$name]->getDefault(),
                "{$name} must default to 0 in install.xml."
            );
        }
    }

    /**
     * Parse an XMLDB file with the parser's own advisories kept out of the log.
     *
     * XMLDB reports a few things as debugging messages that are not defects, the
     * most common being a CHAR NOT NULL column with an empty string as its
     * default, which the parser fixes by itself. Left alone, reading a whole
     * install.xml buries a real result in several hundred lines of advice.
     *
     * @param \xmldb_file $xmlfile The file to parse.
     * @return bool Whether the file is a valid XMLDB file.
     */
    private function loadquietly(\xmldb_file $xmlfile): bool {
        global $CFG;

        $debug = $CFG->debug ?? null;
        $debugdeveloper = $CFG->debugdeveloper ?? null;
        $CFG->debug = 0;
        $CFG->debugdeveloper = 0;

        try {
            return $xmlfile->loadXMLStructure();
        } finally {
            if ($debug !== null) {
                $CFG->debug = $debug;
            }
            if ($debugdeveloper !== null) {
                $CFG->debugdeveloper = $debugdeveloper;
            }
        }
    }

    /**
     * Read a plugin file.
     *
     * @param string $relative Path relative to the plugin root.
     * @return string
     */
    private function read(string $relative): string {
        $path = $this->path($relative);
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /**
     * Absolute path of a plugin file.
     *
     * @param string $relative Path relative to the plugin root.
     * @return string
     */
    private function path(string $relative): string {
        return dirname(__DIR__) . '/' . $relative;
    }
}
