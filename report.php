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
 * Teacher reports: student summary, student details (with result changes) and books.
 *
 * The two student reports and their actions are modelled on mod_reader's usersummary
 * and userdetailed reports.
 *
 * @package    mod_moodlereader
 * @copyright  2013 Gordon Bateson (gordon.bateson@gmail.com)
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');

use mod_moodlereader\local\attempt_admin;
use mod_moodlereader\local\reader;
use mod_moodlereader\local\report\report;
use mod_moodlereader\local\report\userdetailed_table;
use mod_moodlereader\local\report\usersummary_table;

$id = required_param('id', PARAM_INT);
$mode = optional_param('mode', 'usersummary', PARAM_ALPHA);
// The modes of 0.1.0 still open the matching report.
$mode = ['attempts' => 'userdetailed', 'users' => 'usersummary'][$mode] ?? $mode;
if (!in_array($mode, ['usersummary', 'userdetailed', 'books'], true)) {
    $mode = 'usersummary';
}
$term = optional_param('term', 'thisterm', PARAM_ALPHA) === 'allterms' ? 'allterms' : 'thisterm';
$userid = optional_param('userid', 0, PARAM_INT);
$showdeleted = optional_param('showdeleted', 0, PARAM_BOOL);
$download = optional_param('download', '', PARAM_ALPHA);

$reader = reader::from_cmid($id);
require_login($reader->get_course(), true, $reader->get_cm());
$reader->require_cap('viewreports');
$canmanage = $reader->can('manageusers');

$instance = $reader->get_instance();
$readerid = $instance->id;
$urlparams = ['id' => $id, 'mode' => $mode, 'term' => $term];
if ($mode === 'userdetailed') {
    $urlparams += ['userid' => $userid, 'showdeleted' => (int) $showdeleted];
}
$pageurl = new moodle_url('/mod/moodlereader/report.php', $urlparams);
$PAGE->set_url($pageurl);
$PAGE->set_context($reader->get_context());
$PAGE->set_title(get_string('report', 'mod_moodlereader'));
$PAGE->set_heading(format_string($reader->get_course()->fullname));
$PAGE->set_activity_record($instance);

// Actions on the selected rows.
$action = optional_param('reportaction', '', PARAM_ALPHA);
if ($action !== '' && $canmanage && $mode !== 'books') {
    require_sesskey();
    $done = 0;
    if ($mode === 'userdetailed') {
        $attemptids = optional_param_array('attemptids', [], PARAM_INT);
        if (in_array($action, attempt_admin::ATTEMPT_ACTIONS, true)) {
            $done = attempt_admin::update($reader, $attemptids, $action);
        } else if ($action === 'message') {
            [$insql, $inparams] = $DB->get_in_or_equal($attemptids ?: [0], SQL_PARAMS_NAMED);
            $users = $DB->get_fieldset_select(
                'moodlereader_attempts',
                'DISTINCT userid',
                "id $insql AND readerid = :readerid",
                $inparams + ['readerid' => $readerid]
            );
            $done = attempt_admin::message($reader, $users, optional_param('messagetext', '', PARAM_TEXT));
        }
    } else {
        $userids = optional_param_array('userids', [], PARAM_INT);
        if (in_array($action, attempt_admin::LEVEL_FIELDS, true)) {
            $value = optional_param($action === 'goal' ? 'goalvalue' : 'levelvalue', 0, PARAM_INT);
            $done = attempt_admin::set_level_field($reader, $userids, $action, $value);
        } else if ($action === 'awardbook') {
            $matches = attempt_admin::find_books(optional_param('awardbooktitle', '', PARAM_TEXT));
            if (count($matches) !== 1) {
                redirect($pageurl, get_string(
                    count($matches) ? 'awardbooktoomany' : 'awardbooknone',
                    'mod_moodlereader',
                    count($matches)
                ), null, \core\output\notification::NOTIFY_WARNING);
            }
            $done = attempt_admin::award_book($reader, $userids, (int) reset($matches)->id);
        } else if ($action === 'message') {
            $done = attempt_admin::message($reader, $userids, optional_param('messagetext', '', PARAM_TEXT));
        }
    }
    redirect(
        $pageurl,
        get_string('reportactiondone', 'mod_moodlereader', $done),
        null,
        $done ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_INFO
    );
}

$groupid = (int) groups_get_activity_group($reader->get_cm(), true);
// A download has no select column: it is a form control, not data.
$selectable = $canmanage && $download === '';

if ($mode === 'usersummary') {
    $table = new usersummary_table($reader, $pageurl, $term === 'thisterm', $groupid, $selectable);
} else if ($mode === 'userdetailed') {
    $table = new userdetailed_table($reader, $pageurl, $term === 'thisterm', $groupid, $userid, $showdeleted, $selectable);
}
if (isset($table)) {
    $table->is_downloadable(true);
    $table->show_download_buttons_at([TABLE_P_BOTTOM]);
    $filename = clean_filename(format_string($instance->name) . '-' . get_string('report_' . $mode, 'mod_moodlereader'));
    $table->is_downloading($download, $filename, get_string('report_' . $mode, 'mod_moodlereader'));
    if ($table->is_downloading()) {
        $table->out(0, false);
        exit;
    }
}

$PAGE->requires->js_call_amd('core/checkbox-toggleall', 'init');
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report', 'mod_moodlereader'));

$tabs = [];
foreach (['usersummary', 'userdetailed', 'books'] as $t) {
    $tabs[] = new tabobject(
        $t,
        new moodle_url('/mod/moodlereader/report.php', ['id' => $id, 'mode' => $t, 'term' => $term]),
        get_string("report_$t", 'mod_moodlereader')
    );
}
echo $OUTPUT->tabtree($tabs, $mode);

if ($mode === 'books') {
    $table = new html_table();
    $table->head = [
        get_string('book', 'mod_moodlereader'), get_string('publisher', 'mod_moodlereader'),
        get_string('attempts', 'mod_moodlereader'), get_string('passrate', 'mod_moodlereader'),
    ];
    $since = $term === 'thisterm' ? (int) $instance->ignoredate : 0;
    $sql = "SELECT b.id, b.name, b.publisher,
                   COUNT(a.id) AS attempts,
                   SUM(CASE WHEN a.passed = 1 AND a.cheated = 0 THEN 1 ELSE 0 END) AS passes
              FROM {moodlereader_attempts} a
              JOIN {moodlereader_books} b ON b.id = a.bookid
             WHERE a.readerid = :readerid AND a.deleted = 0 AND a.credit = 0 AND a.state = 'finished'
                   AND a.timefinish >= :since
          GROUP BY b.id, b.name, b.publisher
          ORDER BY attempts DESC";
    foreach ($DB->get_records_sql($sql, ['readerid' => $readerid, 'since' => $since]) as $r) {
        $rate = $r->attempts > 0 ? round(100 * $r->passes / $r->attempts) : 0;
        $table->data[] = [format_string($r->name), s($r->publisher), s((int) $r->attempts), s($rate) . '%'];
    }
    echo html_writer::div(report::term_select($pageurl, $term), 'mb-3');
    echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('noreportdata', 'mod_moodlereader'), 'info');
    echo $OUTPUT->footer();
    exit;
}

// Filters: group, term, and (details) one student and deleted attempts.
groups_print_activity_menu($reader->get_cm(), $pageurl);
$filters = report::term_select($pageurl, $term);
if ($mode === 'userdetailed') {
    $filters .= ' ' . html_writer::link(
        new moodle_url($pageurl, ['showdeleted' => $showdeleted ? 0 : 1]),
        get_string($showdeleted ? 'hidedeleted' : 'showdeleted', 'mod_moodlereader'),
        ['class' => 'btn btn-link']
    );
    if ($userid && attempt_admin::visible_userids($reader, [$userid]) && ($student = core_user::get_user($userid))) {
        $filters .= ' ' . html_writer::span(get_string('reportforstudent', 'mod_moodlereader', fullname($student))) . ' ' .
            html_writer::link(new moodle_url($pageurl, ['userid' => 0]), get_string('showallstudents', 'mod_moodlereader'));
    }
}
echo html_writer::div($filters, 'mb-3 d-flex flex-wrap align-items-center');

// Wide reports scroll sideways rather than overflow the page.
echo html_writer::start_div('table-responsive');
$table->out(50, true);
echo html_writer::end_div();

if ($canmanage) {
    echo report::action_form($mode, $pageurl, $instance);
}

echo $OUTPUT->footer();
