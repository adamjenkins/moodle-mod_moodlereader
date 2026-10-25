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
 * A student's own reading record: books attempted, results and running total.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_moodlereader\local\reader;

$id = required_param('id', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);

$reader = reader::from_cmid($id);
require_login($reader->get_course(), true, $reader->get_cm());
$reader->require_cap('viewbooks');

// A teacher may look at a student's records, as on the student's reading page.
$targetid = (int) $USER->id;
$heading = get_string('myrecords', 'mod_moodlereader');
$backurl = $reader->view_url();
if ($userid && $userid !== (int) $USER->id) {
    $reader->require_cap('viewreports');
    if (!\mod_moodlereader\local\attempt_admin::visible_userids($reader, [$userid])) {
        throw new moodle_exception('notyourstudent', 'mod_moodlereader', $reader->view_url());
    }
    $targetid = $userid;
    $heading = get_string('recordsfor', 'mod_moodlereader', fullname(core_user::get_user($userid, '*', MUST_EXIST)));
    $backurl = new moodle_url('/mod/moodlereader/student.php', ['id' => $id, 'userid' => $userid]);
}

$PAGE->set_url('/mod/moodlereader/records.php', ['id' => $id] + ($targetid !== (int) $USER->id ? ['userid' => $targetid] : []));
$PAGE->set_title($heading);
$PAGE->set_heading(format_string($reader->get_course()->fullname));
$PAGE->set_context($reader->get_context());

/** @var \mod_moodlereader\output\renderer $output */
$output = $PAGE->get_renderer('mod_moodlereader');

echo $output->header();
echo $output->heading($heading);

// Reading-progress panel (same bar the student sees on the activity page).
echo $output->progress($reader, $targetid);

$readerid = $reader->get_instance()->id;
$attempts = $DB->get_records_sql(
    "SELECT a.id, a.percentgrade, a.passed, a.credit, a.cheated, a.deleted, a.timefinish, b.name AS bookname, b.publisher, b.words
       FROM {moodlereader_attempts} a
       JOIN {moodlereader_books} b ON b.id = a.bookid
      WHERE a.readerid = :readerid AND a.userid = :userid
            AND a.deleted = 0 AND a.state = 'finished'
   ORDER BY a.timefinish DESC",
    ['readerid' => $readerid, 'userid' => $targetid]
);

if ($attempts) {
    $table = new html_table();
    $table->head = [
        get_string('book', 'mod_moodlereader'),
        get_string('publisher', 'mod_moodlereader'),
        get_string('words', 'mod_moodlereader'),
        get_string('score', 'mod_moodlereader'),
        get_string('result', 'mod_moodlereader'),
        get_string('date'),
    ];
    foreach ($attempts as $a) {
        $result = \mod_moodlereader\local\report\report::result_label($a, false);
        $table->data[] = [
            s($a->bookname),
            s($a->publisher),
            s(number_format((int) $a->words)),
            $a->credit ? '-' : s(round((float) $a->percentgrade)) . '%',
            $result,
            $a->timefinish ? userdate($a->timefinish) : '-',
        ];
    }
    echo html_writer::table($table);
} else {
    echo $output->notification(get_string('norecordsyet', 'mod_moodlereader'), 'info');
}

echo html_writer::div(
    html_writer::link(
        $backurl,
        get_string('back'),
        ['class' => 'btn btn-secondary']
    ),
    'mt-3'
);

echo $output->footer();
