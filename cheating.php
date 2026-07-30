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
 * Cheating-detection review: scan for suspicious attempts and mark/unmark them.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_moodlereader\local\reader;
use mod_moodlereader\local\cheatdetector;

$id = required_param('id', PARAM_INT);
$scan = optional_param('scan', 0, PARAM_BOOL);
$markattempt = optional_param('markattempt', 0, PARAM_INT);
$unmarkattempt = optional_param('unmarkattempt', 0, PARAM_INT);

$reader = reader::from_cmid($id);
require_login($reader->get_course(), true, $reader->get_cm());
$reader->require_cap('manageusers');

$pageurl = new moodle_url('/mod/moodlereader/cheating.php', ['id' => $id]);
$PAGE->set_url($pageurl);
$PAGE->set_context($reader->get_context());
$PAGE->set_title(get_string('cheating', 'mod_moodlereader'));
$PAGE->set_heading(format_string($reader->get_course()->fullname));

if ($scan) {
    require_sesskey();
    $count = cheatdetector::detect($reader->get_instance()->id);
    redirect(
        $pageurl,
        get_string('cheatingscanned', 'mod_moodlereader', $count),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}
if ($markattempt || $unmarkattempt) {
    require_sesskey();
    cheatdetector::set_cheated($reader, $markattempt ?: $unmarkattempt, (bool) $markattempt);
    redirect($pageurl);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('cheating', 'mod_moodlereader'));
echo $OUTPUT->single_button(
    new moodle_url($pageurl, ['scan' => 1, 'sesskey' => sesskey()]),
    get_string('cheatingscan', 'mod_moodlereader'),
    'post'
);

$sql = "SELECT c.id, c.attemptid, a.cheated, a.userid, ub.id AS ubid, ob.id AS obid,
               b.name AS bookname, a.ip, a.timefinish, c.otheruserid
          FROM {moodlereader_cheatlog} c
          JOIN {moodlereader_attempts} a ON a.id = c.attemptid
          JOIN {moodlereader_books} b ON b.id = a.bookid
          JOIN {user} ub ON ub.id = c.userid
          JOIN {user} ob ON ob.id = c.otheruserid
         WHERE c.readerid = :readerid
      ORDER BY a.timefinish DESC";
$rows = $DB->get_records_sql($sql, ['readerid' => $reader->get_instance()->id]);

if ($rows) {
    $table = new html_table();
    $table->head = [
        get_string('fullname'), get_string('book', 'mod_moodlereader'),
        get_string('sharedwith', 'mod_moodlereader'), 'IP',
        get_string('date'), get_string('cheated', 'mod_moodlereader'), '',
    ];
    foreach ($rows as $r) {
        $user = $DB->get_record('user', ['id' => $r->userid]);
        $other = $DB->get_record('user', ['id' => $r->otheruserid]);
        if ($r->cheated) {
            $action = $OUTPUT->single_button(
                new moodle_url($pageurl, ['unmarkattempt' => $r->attemptid, 'sesskey' => sesskey()]),
                get_string('unmarkcheated', 'mod_moodlereader'),
                'post'
            );
        } else {
            $action = $OUTPUT->single_button(
                new moodle_url($pageurl, ['markattempt' => $r->attemptid, 'sesskey' => sesskey()]),
                get_string('markcheated', 'mod_moodlereader'),
                'post'
            );
        }
        $table->data[] = [
            fullname($user), s($r->bookname), fullname($other), s($r->ip),
            userdate($r->timefinish),
            $r->cheated ? get_string('yes') : get_string('no'),
            $action,
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nocheatingfound', 'mod_moodlereader'), 'info');
}

echo $OUTPUT->footer();
