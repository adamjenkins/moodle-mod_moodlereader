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
 * Site administration settings for mod_moodlereader.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configtext(
        'mod_moodlereader/mreaderurl',
        get_string('mreaderurl', 'mod_moodlereader'),
        get_string('mreaderurl_desc', 'mod_moodlereader'),
        \mod_moodlereader\local\mreader_site::DEFAULT_BASEURL,
        PARAM_URL
    ));

    // Registration status and link. The id/key are written by the registration page.
    $siteid = get_config('mod_moodlereader', 'mreadersiteid');
    $registerurl = new moodle_url('/mod/moodlereader/admin/register.php');
    if ($siteid) {
        $status = get_string('registeredas', 'mod_moodlereader', s($siteid));
    } else {
        $status = get_string('notregistered', 'mod_moodlereader');
    }
    $settings->add(new admin_setting_description(
        'mod_moodlereader/registrationstatus',
        get_string('registration', 'mod_moodlereader'),
        $status . ' ' . html_writer::link($registerurl, get_string('registerlink', 'mod_moodlereader'))
    ));
}
