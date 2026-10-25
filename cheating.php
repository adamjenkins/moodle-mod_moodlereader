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
 * Cheating-detection review: what is looked for, a scan, and the flagged attempts to mark or unmark.
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

// What a scan looks for, as configured for this activity.
$settings = cheatdetector::settings($reader->get_instance());
$items = [];
foreach (cheatdetector::MARKERS as $marker) {
    $state = $settings[$marker]['enabled'] ? 'markeron' : 'markeroff';
    $items[] = html_writer::tag('li', html_writer::tag('strong', get_string('marker_' . $marker, 'mod_moodlereader')) .
        ': ' . get_string($state, 'mod_moodlereader') .
        ($settings[$marker]['enabled'] ? ' - ' . cheatdetector::summary($marker, $settings) : ''));
}
echo $OUTPUT->box(
    html_writer::tag('p', get_string('cheatingintro', 'mod_moodlereader')) . html_writer::tag('ul', implode('', $items)),
    'generalbox'
);
echo html_writer::div(
    $OUTPUT->single_button(
        new moodle_url($pageurl, ['scan' => 1, 'sesskey' => sesskey()]),
        get_string('cheatingscan', 'mod_moodlereader'),
        'post',
        ['primary' => true]
    ) . ' ' .
    $OUTPUT->single_button(
        new moodle_url('/mod/moodlereader/cheatsettings.php', ['id' => $id]),
        get_string('cheatsettings', 'mod_moodlereader'),
        'get'
    ),
    'd-flex mb-3'
);

// Only students this teacher can see (their own groups in separate-groups mode).
[$esql, $eparams] = \mod_moodlereader\local\attempt_admin::students_sql($reader, 0);
$ubfields = \core_user\fields::for_name()->get_sql('ub', false, '', 'ubid')->selects;
$obfields = \core_user\fields::for_name()->get_sql('ob', false, 'other', 'obid')->selects;
$sql = "SELECT c.id, c.attemptid, c.reason, c.detail, a.cheated, a.userid, c.otheruserid,
               b.name AS bookname, a.ip, a.timefinish $ubfields $obfields
          FROM {moodlereader_cheatlog} c
          JOIN {moodlereader_attempts} a ON a.id = c.attemptid
          JOIN {moodlereader_books} b ON b.id = a.bookid
          JOIN {user} ub ON ub.id = c.userid
     LEFT JOIN {user} ob ON ob.id = c.otheruserid
         WHERE c.readerid = :readerid AND a.userid IN ($esql)
      ORDER BY a.timefinish DESC, c.id";
$rows = $DB->get_records_sql($sql, ['readerid' => $reader->get_instance()->id] + $eparams);

if ($rows) {
    $table = new html_table();
    $table->head = [
        get_string('fullname'), get_string('book', 'mod_moodlereader'),
        get_string('cheatreason', 'mod_moodlereader'), get_string('ipaddress', 'mod_moodlereader'),
        get_string('date'), get_string('cheated', 'mod_moodlereader'), '',
    ];
    foreach ($rows as $r) {
        $user = (object) ['id' => $r->userid];
        username_load_fields_from_object($user, $r, null, \core_user\fields::get_name_fields());
        $reason = get_string('marker_' . $r->reason, 'mod_moodlereader');
        if ($r->reason === 'sameip' && $r->otheruserid) {
            $other = (object) ['id' => $r->otheruserid];
            username_load_fields_from_object($other, $r, 'other', \core_user\fields::get_name_fields());
            $reason = get_string('flag_sameip', 'mod_moodlereader', [
                'other' => fullname($other), 'gap' => format_time(max(1, (int) $r->detail)),
            ]);
        } else if ($r->reason === 'quickfinish') {
            $reason = get_string('flag_quickfinish', 'mod_moodlereader', format_time(max(1, (int) $r->detail)));
        } else if ($r->reason === 'burst') {
            $reason = get_string('flag_burst', 'mod_moodlereader', (int) $r->detail);
        }
        $toggle = $r->cheated ? ['unmarkattempt', 'unmarkcheated'] : ['markattempt', 'markcheated'];
        $action = $OUTPUT->single_button(
            new moodle_url($pageurl, [$toggle[0] => $r->attemptid, 'sesskey' => sesskey()]),
            get_string($toggle[1], 'mod_moodlereader'),
            'post'
        );
        $table->data[] = [
            fullname($user), format_string($r->bookname), s($reason), s($r->ip),
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
