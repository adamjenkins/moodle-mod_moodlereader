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
 * Safe Exam Browser for every Moodle Reader quiz in a course (reached from the course's More menu).
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_moodlereader\form\sebcourse_form;
use mod_moodlereader\local\seb;

$id = required_param('id', PARAM_INT); // Course id.

$course = get_course($id);
require_login($course);
$context = context_course::instance($course->id);
require_capability('mod/moodlereader:manageseb', $context);

$pageurl = new moodle_url('/mod/moodlereader/sebcourse.php', ['id' => $course->id]);
$courseurl = course_get_url($course);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('sebcourse', 'mod_moodlereader'));
$PAGE->set_heading(format_string($course->fullname));

if (!seb::teachers_decide()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('sebcourse', 'mod_moodlereader'));
    $key = !seb::available() ? 'sebunavailable' : (seb::site_mode() === seb::SITE_REQUIRED ? 'sebbysite' : 'sebnever');
    echo $OUTPUT->notification(get_string($key, 'mod_moodlereader'), \core\output\notification::NOTIFY_INFO, false);
    echo $OUTPUT->continue_button($courseurl);
    echo $OUTPUT->footer();
    exit;
}

$current = seb::course_settings((int) $course->id);
$form = new sebcourse_form($pageurl);
$form->set_data([
    'id' => $course->id,
    'required' => (int) ($current->required ?? 0),
    'sebmethod' => (int) ($current->method ?? seb::METHOD_CONFIG),
    'sebkeys' => (string) ($current->sebkeys ?? ''),
    'sebquitpassword' => (string) ($current->quitpassword ?? ''),
]);

if ($form->is_cancelled()) {
    redirect($courseurl);
}
if ($data = $form->get_data()) {
    $record = (object) [
        'courseid' => $course->id,
        'required' => empty($data->required) ? 0 : 1,
        'method' => (int) $data->sebmethod === seb::METHOD_CLIENT ? seb::METHOD_CLIENT : seb::METHOD_CONFIG,
        'sebkeys' => implode("\n", seb::split_keys((string) $data->sebkeys)),
        'quitpassword' => (string) $data->sebquitpassword,
        'timemodified' => time(),
    ];
    if ($current) {
        $record->id = $current->id;
        $DB->update_record('moodlereader_sebcourse', $record);
    } else {
        $DB->insert_record('moodlereader_sebcourse', $record);
    }
    redirect($pageurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('sebcourse', 'mod_moodlereader'));
$form->display();
echo $OUTPUT->footer();
