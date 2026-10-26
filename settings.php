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

// A folder holding the settings page and the connection and import pages.
$ADMIN->add('modsettings', new admin_category(
    'modmoodlereaderfolder',
    new lang_string('pluginname', 'mod_moodlereader'),
    $module->is_enabled() === false
));

$settings = new admin_settingpage($section, get_string('settings'), 'moodle/site:config', $module->is_enabled() === false);

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configtext(
        'mod_moodlereader/mreaderurl',
        get_string('mreaderurl', 'mod_moodlereader'),
        get_string('mreaderurl_desc', 'mod_moodlereader'),
        \mod_moodlereader\local\api\client::DEFAULT_BASEURL,
        PARAM_URL
    ));

    // Addresses the whole school shares, never compared by cheating detection.
    $settings->add(new \mod_moodlereader\local\admin_setting_allowlist(
        'mod_moodlereader/cheatallowlist',
        get_string('siteallowlist', 'mod_moodlereader'),
        get_string('siteallowlist_desc', 'mod_moodlereader'),
        '',
        PARAM_RAW_TRIMMED,
        40,
        5
    ));

    // Safe Exam Browser: teachers decide, or required for every quiz, or never.
    $settings->add(new admin_setting_heading(
        'mod_moodlereader/sebheading',
        get_string('seb', 'mod_moodlereader'),
        \mod_moodlereader\local\seb::available() ? '' : get_string('sebunavailable', 'mod_moodlereader')
    ));
    $settings->add(new admin_setting_configselect(
        'mod_moodlereader/sebmode',
        get_string('sebmode', 'mod_moodlereader'),
        get_string('sebmode_desc', 'mod_moodlereader'),
        \mod_moodlereader\local\seb::SITE_TEACHERS,
        [
            \mod_moodlereader\local\seb::SITE_TEACHERS => get_string('sebmode_teachers', 'mod_moodlereader'),
            \mod_moodlereader\local\seb::SITE_REQUIRED => get_string('sebmode_required', 'mod_moodlereader'),
            \mod_moodlereader\local\seb::SITE_NEVER => get_string('sebmode_never', 'mod_moodlereader'),
        ]
    ));
    // How a site-wide requirement is checked.
    $settings->add(new admin_setting_configselect(
        'mod_moodlereader/sebmethod',
        get_string('sebmethod', 'mod_moodlereader'),
        get_string('sebmethod_desc', 'mod_moodlereader'),
        \mod_moodlereader\local\seb::METHOD_CONFIG,
        [
            \mod_moodlereader\local\seb::METHOD_CONFIG => get_string('sebmethod_config', 'mod_moodlereader'),
            \mod_moodlereader\local\seb::METHOD_CLIENT => get_string('sebmethod_client', 'mod_moodlereader'),
        ]
    ));
    $settings->add(new \mod_moodlereader\local\admin_setting_sebkeys(
        'mod_moodlereader/sebkeys',
        get_string('sebkeys', 'mod_moodlereader'),
        get_string('sebkeys_help', 'mod_moodlereader'),
        ''
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'mod_moodlereader/sebquitpassword',
        get_string('sebquitpassword', 'mod_moodlereader'),
        get_string('sebquitpassword_help', 'mod_moodlereader'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'mod_moodlereader/sebdownloadlink',
        get_string('sebdownloadlink', 'mod_moodlereader'),
        get_string('sebdownloadlink_desc', 'mod_moodlereader'),
        'https://safeexambrowser.org/download_en.html',
        PARAM_URL
    ));
    foreach (['sebmethod', 'sebkeys', 'sebquitpassword'] as $name) {
        $settings->hide_if("mod_moodlereader/$name", 'mod_moodlereader/sebmode', 'neq', \mod_moodlereader\local\seb::SITE_REQUIRED);
    }

    // Connection state and link; the connection itself is managed on its own page.
    $state = \mod_moodlereader\local\connection::state();
    $settings->add(new admin_setting_description(
        'mod_moodlereader/connectionstatus',
        get_string('connection', 'mod_moodlereader'),
        get_string('connstate_' . ($state === '' ? 'none' : $state), 'mod_moodlereader') . ' ' .
            html_writer::link(
                new moodle_url('/mod/moodlereader/admin/connection.php'),
                get_string('connmanage', 'mod_moodlereader')
            )
    ));
}

$ADMIN->add('modmoodlereaderfolder', $settings);
$ADMIN->add('modmoodlereaderfolder', new admin_externalpage(
    'mod_moodlereader_connection',
    new lang_string('connection', 'mod_moodlereader'),
    new moodle_url('/mod/moodlereader/admin/connection.php'),
    'mod/moodlereader:manageremotesites',
    $module->is_enabled() === false
));
$ADMIN->add('modmoodlereaderfolder', new admin_externalpage(
    'mod_moodlereader_import',
    new lang_string('import', 'mod_moodlereader'),
    new moodle_url('/mod/moodlereader/admin/import.php'),
    'moodle/site:config',
    $module->is_enabled() === false
));

// The pages are already in the tree.
$settings = null;
