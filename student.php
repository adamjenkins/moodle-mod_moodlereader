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
 * A student's reading page as the student sees it, for their teacher, without logging in as them.
 *
 * The book search lists every book of the activity, and for each says whether this student
 * can take its quiz now, and why not, as a badge rather than a button.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_moodlereader\local\attempt_admin;
use mod_moodlereader\local\reader;

$id = required_param('id', PARAM_INT);
$userid = required_param('userid', PARAM_INT);

$reader = reader::from_cmid($id);
require_login($reader->get_course(), true, $reader->get_cm());
$reader->require_cap('viewreports');

// Only someone in this teacher's reports (their groups in separate-groups mode).
if (!attempt_admin::visible_userids($reader, [$userid])) {
    throw new moodle_exception('notyourstudent', 'mod_moodlereader', $reader->view_url());
}
$student = core_user::get_user($userid, '*', MUST_EXIST);

$PAGE->set_url('/mod/moodlereader/student.php', ['id' => $id, 'userid' => $userid]);
$PAGE->set_context($reader->get_context());
$PAGE->set_activity_record($reader->get_instance());
$PAGE->set_title(get_string('readingpagefor', 'mod_moodlereader', fullname($student)));
$PAGE->set_heading(format_string($reader->get_course()->fullname));

/** @var \mod_moodlereader\output\renderer $output */
$output = $PAGE->get_renderer('mod_moodlereader');

echo $output->header();
echo $output->heading(get_string('readingpagefor', 'mod_moodlereader', fullname($student)));
echo $output->notification(
    get_string('readingpagenote', 'mod_moodlereader', fullname($student)),
    \core\output\notification::NOTIFY_INFO,
    false
);
echo html_writer::div(html_writer::link(
    new moodle_url('/mod/moodlereader/report.php', ['id' => $id, 'mode' => 'userdetailed', 'userid' => $userid]),
    get_string('backtostudentdetails', 'mod_moodlereader'),
    ['class' => 'btn btn-secondary btn-sm']
), 'mb-3');

echo $output->reading_page($reader, $student, true);

echo $output->footer();
