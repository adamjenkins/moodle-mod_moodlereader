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
 * Teacher report: quiz attempts by students in this activity.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_moodlereader\local\reader;

$id = required_param('id', PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;

$reader = reader::from_cmid($id);
require_login($reader->get_course(), true, $reader->get_cm());
$reader->require_cap('viewreports');

$context = $reader->get_context();
$PAGE->set_url('/mod/moodlereader/report.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('report', 'mod_moodlereader'));
$PAGE->set_heading(format_string($reader->get_course()->fullname));

// Restrict to users this teacher may see (respects separate groups).
$userfieldsapi = \core_user\fields::for_name();
$namefields = $userfieldsapi->get_sql('u', false, '', '', false)->selects;

$total = $DB->count_records(
    'moodlereader_attempts',
    ['readerid' => $reader->get_instance()->id, 'deleted' => 0]
);

$sql = "SELECT a.id, a.userid, a.percentgrade, a.passed, a.timefinish, b.name AS bookname $namefields
          FROM {moodlereader_attempts} a
          JOIN {moodlereader_books} b ON b.id = a.bookid
          JOIN {user} u ON u.id = a.userid
         WHERE a.readerid = :readerid AND a.deleted = 0
      ORDER BY a.timefinish DESC, a.id DESC";
$attempts = $DB->get_records_sql($sql, ['readerid' => $reader->get_instance()->id], $page * $perpage, $perpage);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report', 'mod_moodlereader'));
echo html_writer::div(get_string('reportintro', 'mod_moodlereader'), 'mb-2');

if ($attempts) {
    $table = new html_table();
    $table->head = [
        get_string('fullname'),
        get_string('book', 'mod_moodlereader'),
        get_string('minpassgrade', 'mod_moodlereader'),
        get_string('passed', 'core'),
        get_string('date'),
    ];
    foreach ($attempts as $a) {
        $table->data[] = [
            fullname($a),
            s($a->bookname),
            s(round((float) $a->percentgrade)) . '%',
            $a->passed ? get_string('yes') : get_string('no'),
            $a->timefinish ? userdate($a->timefinish) : '-',
        ];
    }
    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($total, $page, $perpage, $PAGE->url);
} else {
    echo $OUTPUT->notification(get_string('nobooksavailable', 'mod_moodlereader'), 'info');
}

echo $OUTPUT->footer();
