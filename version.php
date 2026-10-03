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
 * Upgrade Guard - version information.
 *
 * Upgrade Guard scans the third party plugins of a site and estimates what will
 * happen to them if the site is upgraded to a newer Moodle branch.
 *
 * Supported platform matrix for this release: Moodle 4.4 (including the 5.x
 * "public directory" layout introduced in 5.1) running on PHP 8.1+.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'tool_upgradeguard';
$plugin->version   = 2026100301;
// Moodle 4.4 (released 22 April 2024). The oldest branch this tool supports, see $plugin->supported.
$plugin->requires  = 2024042200;
$plugin->supported = [404, 502];
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '0.9.4';
