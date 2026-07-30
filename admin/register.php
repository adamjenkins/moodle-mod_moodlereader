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
 * Register this Moodle site with mreader.org.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/formslib.php');

use mod_moodlereader\local\mreader_site;

require_login();
$context = context_system::instance();
require_capability('mod/moodlereader:manageremotesites', $context);

$PAGE->set_context($context);
$PAGE->set_url('/mod/moodlereader/admin/register.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('registration', 'mod_moodlereader'));
$PAGE->set_heading(get_string('registration', 'mod_moodlereader'));

$mform = new \mod_moodlereader\form\register_form();

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/admin/settings.php', ['section' => 'modsettingmoodlereader']));
} else if ($data = $mform->get_data()) {
    $error = mreader_site::register($data->username, $data->password);
    if ($error === '') {
        redirect(
            new moodle_url('/admin/settings.php', ['section' => 'modsettingmoodlereader']),
            get_string('registrationok', 'mod_moodlereader'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    \core\notification::error($error);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('registration', 'mod_moodlereader'));
echo html_writer::div(get_string('registration_help', 'mod_moodlereader'), 'mb-3');
$mform->display();
echo $OUTPUT->footer();
