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
 * The form that starts a new scan.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\form;

use moodleform;
use tool_upgradeguard\local\target_repository;

defined('MOODLE_INTERNAL') || die();

// Moodleform is not autoloadable: the class lives in lib/formslib.php, so the
// file that defines a form has to include it explicitly.
global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Lets an administrator pick the Moodle version to scan against.
 *
 * The list of versions comes from the rule dataset, so a branch only appears
 * here once the dataset knows its PHP requirement, its version number and
 * whether it reads plugins from the public directory.
 */
class scan_form extends moodleform {
    /**
     * Define the form fields.
     *
     * @return void
     */
    protected function definition(): void {
        global $CFG;

        $repository = new target_repository();
        $currentbranch = (int) ($CFG->branch ?? 0);

        $options = [];
        foreach ($repository->get_upgrade_targets($currentbranch) as $target) {
            // A branch that only receives security fixes is still a valid
            // destination, but saying so stops an administrator from reading
            // "no general bug fixes any more" as "not really supported".
            $label = get_string('targetoption', 'tool_upgradeguard', (object) [
                'version' => $target->version,
                'phpmin' => $target->phpmin,
            ]);
            if ($target->is_security_only()) {
                $label .= ' — ' . get_string('targetoption_securityonly', 'tool_upgradeguard');
            }
            $options[$target->version] = $label;
        }

        if ($options === []) {
            // No released branch newer than this site is in the dataset. An empty
            // <select> would be a dead control that only looks broken, so the form
            // explains the situation instead of rendering one.
            $this->_form->addElement(
                'static',
                'notarget',
                get_string('targetversion', 'tool_upgradeguard'),
                get_string('targetversion_none', 'tool_upgradeguard', $this->format_branch($currentbranch))
            );
            return;
        }

        $this->_form->addElement('select', 'targetversion', get_string('targetversion', 'tool_upgradeguard'), $options);
        $this->_form->addElement('static', 'targetversiondesc', '', get_string('targetversion_desc', 'tool_upgradeguard'));
        $this->add_action_buttons(false, get_string('startscan', 'tool_upgradeguard'));

        // Default to the newest offered branch, which is the first key because the
        // repository sorts targets newest first.
        $this->set_data(['targetversion' => key($options)]);
    }

    /**
     * Turn a branch number into the version string a reader recognises.
     *
     * Moodle reports branches as integers (502), while the rule dataset and every
     * label in this plugin use "5.2". Showing 502 in the message would read like
     * a build number.
     *
     * @param int $branch Branch number, eg 502.
     * @return string
     */
    private function format_branch(int $branch): string {
        if ($branch <= 0) {
            return (string) $branch;
        }
        $major = intdiv($branch, 100);
        $minor = $branch % 100;
        return $major . '.' . $minor;
    }
}
