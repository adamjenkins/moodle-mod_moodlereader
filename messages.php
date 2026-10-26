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
 * Teacher messaging: compose and delete messages shown to students.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');

use mod_moodlereader\local\reader;
use mod_moodlereader\local\message;
use mod_moodlereader\form\message_form;

$id = required_param('id', PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);

$reader = reader::from_cmid($id);
require_login($reader->get_course(), true, $reader->get_cm());
$reader->require_cap('manageusers');

$readerid = $reader->get_instance()->id;
$pageurl = new moodle_url('/mod/moodlereader/messages.php', ['id' => $id]);
$PAGE->set_url($pageurl);
$PAGE->set_context($reader->get_context());
$PAGE->set_title(get_string('messages', 'mod_moodlereader'));
$PAGE->set_heading(format_string($reader->get_course()->fullname));

// Build the recipient menu: each student this teacher can see (their own groups in separate-groups
// mode), plus "everyone" for a teacher who can see every student.
$recipients = [];
$cm = $reader->get_cm();
if (groups_get_activity_groupmode($cm) != SEPARATEGROUPS || has_capability('moodle/site:accessallgroups', $reader->get_context())) {
    $recipients[0] = get_string('everyone', 'mod_moodlereader');
}
[$esql, $eparams] = \mod_moodlereader\local\attempt_admin::students_sql($reader, 0);
$names = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
$enrolled = $DB->get_records_sql(
    "SELECT u.id, $names FROM {user} u WHERE u.id IN ($esql) ORDER BY u.lastname, u.firstname",
    $eparams,
    0,
    250
);
foreach ($enrolled as $student) {
    $recipients[$student->id] = fullname($student);
}

// In separate groups a teacher sees, and deletes, only the messages to students they can see; a message to
// everyone is theirs to delete only when they can see every student.
$allgroups = isset($recipients[0]);
$mine = function (\stdClass $m) use ($reader, $allgroups): bool {
    return (int) $m->userid === 0 ? $allgroups
        : (bool) \mod_moodlereader\local\attempt_admin::visible_userids($reader, [(int) $m->userid]);
};

if ($delete) {
    require_sesskey();
    $target = $DB->get_record('moodlereader_messages', ['id' => $delete, 'readerid' => $readerid]);
    if ($target && $mine($target)) {
        message::delete($delete, $readerid);
    }
    redirect($pageurl, get_string('messagedeleted', 'mod_moodlereader'));
}

$mform = new message_form($pageurl->out(false), ['cmid' => $id, 'recipients' => $recipients]);
if ($data = $mform->get_data()) {
    if (!array_key_exists($data->target, $recipients)) {
        throw new \moodle_exception('invaliduser');
    }
    $target = (int) $data->target;
    message::create($readerid, $target, $data->messagetext['text'], (int) $data->messagetext['format']);
    redirect($pageurl, get_string('messagesent', 'mod_moodlereader'));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('messages', 'mod_moodlereader'));
$mform->display();

$messages = array_filter(message::all($readerid), fn($m) => (int) $m->userid === 0 || $mine($m));
if ($messages) {
    echo $OUTPUT->heading(get_string('existingmessages', 'mod_moodlereader'), 4);
    $table = new html_table();
    $table->head = [get_string('messagetarget', 'mod_moodlereader'), get_string('message', 'mod_moodlereader'),
        get_string('date'), ''];
    foreach ($messages as $m) {
        $to = $m->userid == 0 ? get_string('everyone', 'mod_moodlereader')
            : fullname($DB->get_record('user', ['id' => $m->userid]));
        $delurl = new moodle_url($pageurl, ['delete' => $m->id, 'sesskey' => sesskey()]);
        $table->data[] = [
            s($to),
            format_text($m->messagetext, $m->messageformat, ['context' => $reader->get_context()]),
            userdate($m->timecreated),
            $mine($m) ? $OUTPUT->single_button($delurl, get_string('delete'), 'post') : '',
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
