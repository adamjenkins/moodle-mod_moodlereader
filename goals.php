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
 * Teacher tool: set per-student reading-goal overrides.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_moodlereader\local\reader;

$id = required_param('id', PARAM_INT);

$reader = reader::from_cmid($id);
require_login($reader->get_course(), true, $reader->get_cm());
$reader->require_cap('manageusers');

$context = $reader->get_context();
$instance = $reader->get_instance();
$defaultgoal = (int) $instance->goal;
$pageurl = new moodle_url('/mod/moodlereader/goals.php', ['id' => $id]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_title(get_string('setgoals', 'mod_moodlereader'));
$PAGE->set_heading(format_string($reader->get_course()->fullname));

// Only students this teacher can see (their own groups in separate-groups mode).
[$esql, $eparams] = \mod_moodlereader\local\attempt_admin::students_sql($reader, 0);
$names = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
$students = $DB->get_records_sql(
    "SELECT u.id, $names FROM {user} u WHERE u.id IN ($esql) ORDER BY u.lastname, u.firstname",
    $eparams
);

if (($data = data_submitted()) && confirm_sesskey()) {
    $goals = optional_param_array('goal', [], PARAM_INT);
    foreach ($students as $student) {
        if (!array_key_exists($student->id, $goals)) {
            continue;
        }
        $goal = max(0, (int) $goals[$student->id]);
        $level = $DB->get_record('moodlereader_levels', ['readerid' => $instance->id, 'userid' => $student->id]);
        if ($level) {
            if ((int) $level->goal !== $goal) {
                $level->goal = $goal;
                $DB->update_record('moodlereader_levels', $level);
            }
        } else if ($goal > 0) {
            // Only create a level row when there is an override to store.
            $DB->insert_record('moodlereader_levels', (object) [
                'readerid'       => $instance->id,
                'userid'         => $student->id,
                'goal'           => $goal,
                'startlevel'     => 0,
                'currentlevel'   => 0,
                'stoplevel'      => 0,
                'allowpromotion' => 1,
                'time'           => time(),
            ]);
        }
    }
    // Regrade so the gradebook reflects the new goals.
    moodlereader_update_grades($instance);
    redirect($pageurl, get_string('goalssaved', 'mod_moodlereader'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('setgoals', 'mod_moodlereader'));
echo $OUTPUT->box(get_string('setgoals_help', 'mod_moodlereader', number_format($defaultgoal)), 'generalbox');

if (!$students) {
    echo $OUTPUT->notification(get_string('nostudents', 'mod_moodlereader'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$overrides = $DB->get_records_menu('moodlereader_levels', ['readerid' => $instance->id], '', 'userid, goal');

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

$table = new html_table();
$table->head = [get_string('fullname'), get_string('goaloverride', 'mod_moodlereader')];
$table->align = ['left', 'left'];
foreach ($students as $student) {
    $value = !empty($overrides[$student->id]) ? (int) $overrides[$student->id] : '';
    $input = html_writer::empty_tag('input', [
        'type' => 'number', 'min' => 0, 'name' => "goal[{$student->id}]", 'value' => $value,
        'class' => 'form-control d-inline-block', 'style' => 'width: 10rem;',
        'placeholder' => $defaultgoal,
    ]);
    $table->data[] = [fullname($student), $input];
}
echo html_writer::table($table);

echo html_writer::div(
    html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary']) . ' ' .
    html_writer::link($reader->view_url(), get_string('backtoactivity', 'mod_moodlereader'), ['class' => 'btn btn-secondary']),
    'mt-2'
);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
