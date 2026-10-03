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
 * Admin settings and admin tree entry for Upgrade Guard.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // The dashboard itself. Kept as an external page so that it can enforce its
    // own capability and does not have to be part of the settings tree.
    $ADMIN->add(
        'tools',
        new admin_externalpage(
            'tool_upgradeguard',
            new lang_string('pluginname', 'tool_upgradeguard'),
            new moodle_url('/admin/tool/upgradeguard/index.php'),
            'tool/upgradeguard:view'
        )
    );

    $settings = new admin_settingpage('tool_upgradeguard_settings', new lang_string('settingsheading', 'tool_upgradeguard'));
    $ADMIN->add('tools', $settings);

    // Readiness score group: how much every finding lowers the score.
    $settings->add(new admin_setting_heading(
        'tool_upgradeguard/headingscore',
        new lang_string('scoreheading', 'tool_upgradeguard'),
        new lang_string('scoreheading_desc', 'tool_upgradeguard')
    ));

    $settings->add(new admin_setting_configtext(
        'tool_upgradeguard/weightblocker',
        new lang_string('weightblocker', 'tool_upgradeguard'),
        new lang_string('weightblocker_desc', 'tool_upgradeguard'),
        12,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'tool_upgradeguard/weightcaution',
        new lang_string('weightcaution', 'tool_upgradeguard'),
        new lang_string('weightcaution_desc', 'tool_upgradeguard'),
        4,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'tool_upgradeguard/weightunknown',
        new lang_string('weightunknown', 'tool_upgradeguard'),
        new lang_string('weightunknown_desc', 'tool_upgradeguard'),
        3,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'tool_upgradeguard/weightupdate',
        new lang_string('weightupdate', 'tool_upgradeguard'),
        new lang_string('weightupdate_desc', 'tool_upgradeguard'),
        1,
        PARAM_INT
    ));

    $typeweights = ['theme' => 3, 'auth' => 3, 'enrol' => 2, 'mod' => 2, 'local' => 2, 'editor' => 2, 'other' => 1];
    foreach ($typeweights as $type => $default) {
        $settings->add(new admin_setting_configtext(
            'tool_upgradeguard/weight' . $type,
            new lang_string('weighttype', 'tool_upgradeguard', $type),
            new lang_string('weighttype_desc', 'tool_upgradeguard'),
            $default,
            PARAM_FLOAT
        ));
    }

    // Usage multipliers group: scale the penalty by actual usage.
    $settings->add(new admin_setting_heading(
        'tool_upgradeguard/headingmultipliers',
        new lang_string('multipliersheading', 'tool_upgradeguard'),
        new lang_string('multipliersheading_desc', 'tool_upgradeguard')
    ));

    foreach (['used' => 1.5, 'unused' => 0.3, 'unknown' => 1.0] as $usage => $default) {
        $settings->add(new admin_setting_configtext(
            'tool_upgradeguard/usagemultiplier' . $usage,
            new lang_string('usagemultiplier', 'tool_upgradeguard', $usage),
            new lang_string('usagemultiplier_desc_' . $usage, 'tool_upgradeguard'),
            $default,
            PARAM_FLOAT
        ));
    }

    // Verdict thresholds group: when a score becomes Go, Careful or Stop.
    $settings->add(new admin_setting_heading(
        'tool_upgradeguard/headingthresholds',
        new lang_string('thresholdsheading', 'tool_upgradeguard'),
        new lang_string('thresholdsheading_desc', 'tool_upgradeguard')
    ));

    $settings->add(new admin_setting_configtext(
        'tool_upgradeguard/stopthreshold',
        new lang_string('stopthreshold', 'tool_upgradeguard'),
        new lang_string('stopthreshold_desc', 'tool_upgradeguard'),
        60,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'tool_upgradeguard/gothreshold',
        new lang_string('gothreshold', 'tool_upgradeguard'),
        new lang_string('gothreshold_desc', 'tool_upgradeguard'),
        85,
        PARAM_INT
    ));

    // Plugin updates group: the moodle.org lookup and its cache.
    $settings->add(new admin_setting_heading(
        'tool_upgradeguard/headingupdates',
        new lang_string('updatesheading', 'tool_upgradeguard'),
        new lang_string('updatesheading_desc', 'tool_upgradeguard')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'tool_upgradeguard/checkremote',
        new lang_string('checkremote', 'tool_upgradeguard'),
        new lang_string('checkremote_desc', 'tool_upgradeguard'),
        1
    ));

    $settings->add(new admin_setting_configduration(
        'tool_upgradeguard/remotecachettl',
        new lang_string('remotecachettl', 'tool_upgradeguard'),
        new lang_string('remotecachettl_desc', 'tool_upgradeguard'),
        6 * HOURSECS
    ));

    $settings->add(new admin_setting_configtext(
        'tool_upgradeguard/rulesfeedurl',
        new lang_string('rulesfeedurl', 'tool_upgradeguard'),
        new lang_string('rulesfeedurl_desc', 'tool_upgradeguard'),
        \tool_upgradeguard\local\target_feed::DEFAULT_FEED_URL,
        PARAM_URL
    ));

    // Data management group: retention and the wrong-result report link.
    $settings->add(new admin_setting_heading(
        'tool_upgradeguard/headingdata',
        new lang_string('dataheading', 'tool_upgradeguard'),
        new lang_string('dataheading_desc', 'tool_upgradeguard')
    ));

    $settings->add(new admin_setting_configtext(
        'tool_upgradeguard/retentiondays',
        new lang_string('retentiondays', 'tool_upgradeguard'),
        new lang_string('retentiondays_desc', 'tool_upgradeguard'),
        180,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'tool_upgradeguard/supportemail',
        new lang_string('supportemail', 'tool_upgradeguard'),
        new lang_string('supportemail_desc', 'tool_upgradeguard'),
        '',
        PARAM_EMAIL
    ));
}
