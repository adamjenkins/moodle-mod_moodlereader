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
 * Teacher reports: attempts, per-user summary and per-book summary.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_moodlereader\local\reader;

$id = required_param('id', PARAM_INT);
$mode = optional_param('mode', 'attempts', PARAM_ALPHA);
if (!in_array($mode, ['attempts', 'users', 'books'], true)) {
    $mode = 'attempts';
}

$reader = reader::from_cmid($id);
require_login($reader->get_course(), true, $reader->get_cm());
$reader->require_cap('viewreports');

$readerid = $reader->get_instance()->id;
$countfield = empty($reader->get_instance()->wordsorpoints) ? 'b.words' : 'b.points';

$PAGE->set_url('/mod/moodlereader/report.php', ['id' => $id, 'mode' => $mode]);
$PAGE->set_context($reader->get_context());
$PAGE->set_title(get_string('report', 'mod_moodlereader'));
$PAGE->set_heading(format_string($reader->get_course()->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report', 'mod_moodlereader'));

// Tabs.
$tabs = [];
foreach (['attempts', 'users', 'books'] as $t) {
    $tabs[] = new tabobject(
        $t,
        new moodle_url($PAGE->url, ['mode' => $t]),
        get_string("report_$t", 'mod_moodlereader')
    );
}
echo $OUTPUT->tabtree($tabs, $mode);

$namefieldsql = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
$namegroupby = 'u.' . implode(', u.', \core_user\fields::get_name_fields());

$table = new html_table();

if ($mode === 'attempts') {
    $table->head = [
        get_string('fullname'), get_string('book', 'mod_moodlereader'),
        get_string('score', 'mod_moodlereader'), get_string('passed', 'mod_moodlereader'), get_string('date'),
    ];
    $sql = "SELECT a.id, a.percentgrade, a.passed, a.timefinish, b.name AS bookname, u.id AS uid $namefieldsql
              FROM {moodlereader_attempts} a
              JOIN {moodlereader_books} b ON b.id = a.bookid
              JOIN {user} u ON u.id = a.userid
             WHERE a.readerid = :readerid AND a.deleted = 0
          ORDER BY a.timefinish DESC, a.id DESC";
    foreach ($DB->get_records_sql($sql, ['readerid' => $readerid], 0, 200) as $r) {
        $table->data[] = [
            fullname($r), s($r->bookname), s(round((float) $r->percentgrade)) . '%',
            $r->passed ? get_string('yes') : get_string('no'),
            $r->timefinish ? userdate($r->timefinish) : '-',
        ];
    }
} else if ($mode === 'users') {
    $table->head = [
        get_string('fullname'), get_string('bookspassed', 'mod_moodlereader'),
        get_string('wordsread', 'mod_moodlereader'), get_string('currentlevel', 'mod_moodlereader'),
    ];
    $ok = 'a.passed = 1 AND a.deleted = 0 AND a.cheated = 0';
    $sql = "SELECT u.id $namefieldsql,
                   COUNT(DISTINCT CASE WHEN $ok THEN a.bookid END) AS passes,
                   COALESCE(SUM(CASE WHEN $ok THEN $countfield ELSE 0 END), 0) AS total,
                   MAX(l.currentlevel) AS currentlevel
              FROM {moodlereader_attempts} a
              JOIN {user} u ON u.id = a.userid
              JOIN {moodlereader_books} b ON b.id = a.bookid
         LEFT JOIN {moodlereader_levels} l ON l.readerid = a.readerid AND l.userid = a.userid
             WHERE a.readerid = :readerid
          GROUP BY u.id, $namegroupby
          ORDER BY total DESC";
    foreach ($DB->get_records_sql($sql, ['readerid' => $readerid]) as $r) {
        $table->data[] = [
            fullname($r), s((int) $r->passes), s(number_format((int) $r->total)), s((int) $r->currentlevel),
        ];
    }
} else {
    $table->head = [
        get_string('book', 'mod_moodlereader'), get_string('publisher', 'mod_moodlereader'),
        get_string('attempts', 'mod_moodlereader'), get_string('passrate', 'mod_moodlereader'),
    ];
    $sql = "SELECT b.id, b.name, b.publisher,
                   COUNT(a.id) AS attempts,
                   SUM(CASE WHEN a.passed = 1 THEN 1 ELSE 0 END) AS passes
              FROM {moodlereader_attempts} a
              JOIN {moodlereader_books} b ON b.id = a.bookid
             WHERE a.readerid = :readerid AND a.deleted = 0
          GROUP BY b.id, b.name, b.publisher
          ORDER BY attempts DESC";
    foreach ($DB->get_records_sql($sql, ['readerid' => $readerid]) as $r) {
        $rate = $r->attempts > 0 ? round(100 * $r->passes / $r->attempts) : 0;
        $table->data[] = [s($r->name), s($r->publisher), s((int) $r->attempts), s($rate) . '%'];
    }
}

if ($table->data) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('noreportdata', 'mod_moodlereader'), 'info');
}

echo $OUTPUT->footer();
