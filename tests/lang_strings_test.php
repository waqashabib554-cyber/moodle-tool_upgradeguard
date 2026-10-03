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
 * Tests that every string key the code uses exists in the language file.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use basic_testcase;

/**
 * Scans the PHP code for tool_upgradeguard string keys and checks the lang file.
 *
 * Moodle renders a missing language string as [[key]] on the page instead of an
 * error, and reports it only as a developer debugging message, which is how a
 * mismatched settings key survived. This test reads the code the way a reviewer
 * would and fails when a key has no definition.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class lang_strings_test extends basic_testcase {
    /**
     * The plugin root.
     *
     * @return string
     */
    private function pluginroot(): string {
        return __DIR__ . '/..';
    }

    /**
     * Every key referenced for this component in the plugin's PHP code.
     *
     * Covers get_string() and lang_string() calls, plus the message and action
     * keys that checks pass around as plain strings.
     *
     * @return string[]
     */
    private function get_used_keys(): array {
        $used = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->pluginroot()));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if ($file->getPathname() === $this->pluginroot() . '/lang/en/tool_upgradeguard.php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            foreach (['get_string', 'lang_string'] as $call) {
                $pattern = '/' . $call . "\(([\'\"])([a-z_0-9:]+)\\1,\\s*[\'\"]tool_upgradeguard[\'\"]/";
                if (preg_match_all($pattern, $content, $matches)) {
                    foreach ($matches[2] as $key) {
                        $used[$key] = true;
                    }
                }
            }

            $pattern = '/\'(finding_[a-z_0-9]+|action_[a-z_0-9]+)\'/';
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $key) {
                    $used[$key] = true;
                }
            }
        }

        return array_keys($used);
    }

    /**
     * Every key defined in the language file.
     *
     * @return string[]
     */
    private function get_defined_keys(): array {
        $content = file_get_contents($this->pluginroot() . '/lang/en/tool_upgradeguard.php');
        preg_match_all("/^\\\$string\\['([^']+)'\\]/m", $content, $matches);
        return $matches[1];
    }

    /**
     * Every key the mustache templates ask for in the language file.
     *
     * A template asks for a string with {{#str}}key, tool_upgradeguard{{/str}},
     * which never appears in PHP, so the scan above cannot see it. An unknown
     * key there is the one place a user really does read [[key]] on the page,
     * because the template is the last step before the browser.
     *
     * @return string[] Keys, each prefixed with the template it came from.
     */
    private function get_used_template_keys(): array {
        $used = [];
        $templates = glob($this->pluginroot() . '/templates/*.mustache') ?: [];

        foreach ($templates as $template) {
            $content = (string) file_get_contents($template);

            // The character class is deliberately loose. A key with an upper case
            // letter or a stray character is not a real Moodle key, and a strict
            // [a-z0-9_] class would skip it and report a clean run instead of
            // the typo it was written to catch.
            if (
                preg_match_all(
                    '/\{\{#str\}\}\s*([A-Za-z0-9_]+)\s*,\s*tool_upgradeguard/',
                    $content,
                    $matches
                )
            ) {
                foreach ($matches[1] as $key) {
                    $used[basename($template) . ':' . $key] = $key;
                }
            }
        }

        return $used;
    }

    /**
     * Nothing in the code may use a key the language file does not define.
     *
     * @coversNothing
     */
    public function test_every_used_string_key_is_defined(): void {
        $defined = $this->get_defined_keys();
        $missing = [];

        foreach ($this->get_used_keys() as $key) {
            if (!in_array($key, $defined, true)) {
                $missing[] = $key;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'String keys used by the code but missing from lang/en: ' . implode(', ', $missing)
        );
    }

    /**
     * The settings page uses keys that must exist, checked by name.
     *
     * @coversNothing
     */
    public function test_settings_page_keys_are_defined(): void {
        $settings = file_get_contents($this->pluginroot() . '/settings.php');
        $defined = $this->get_defined_keys();
        $missing = [];

        if (preg_match_all("/lang_string\('([^']+)',\s*'tool_upgradeguard'\)/", $settings, $matches)) {
            foreach ($matches[1] as $key) {
                if (!in_array($key, $defined, true)) {
                    $missing[] = $key;
                }
            }
        }

        $this->assertSame([], $missing, 'Settings page uses missing keys: ' . implode(', ', $missing));
    }

    /**
     * Every key a template asks for has to exist as well.
     *
     * The scan above reads PHP only. The dashboard, the results page, the
     * report, the history and the shared partials all ask Moodle for strings
     * from inside mustache, and Moodle answers an unknown key with the literal
     * text [[key]] instead of failing. That is how [[notarget]] once reached
     * the overview. A test that cannot see the templates cannot catch it.
     *
     * @coversNothing
     */
    public function test_every_template_string_key_is_defined(): void {
        $defined = $this->get_defined_keys();
        $missing = [];

        foreach ($this->get_used_template_keys() as $where => $key) {
            if (!in_array($key, $defined, true)) {
                $missing[] = $where;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Templates ask for strings the language file does not define: ' . implode(', ', $missing)
        );
    }
}
