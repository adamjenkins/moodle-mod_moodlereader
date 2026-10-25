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
 * Import from mod_reader: preview, sync, re-sync and cut over, course by course.
 *
 * mod_reader's activities and data are only read, never changed.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core\output\notification;
use mod_moodlereader\local\admin_nav;
use mod_moodlereader\local\import\importer;

admin_externalpage_setup('mod_moodlereader_import');
require_capability('moodle/site:config', context_system::instance());

$action = optional_param('action', '', PARAM_ALPHA);
$courseid = optional_param('course', 0, PARAM_INT);
$pageurl = new moodle_url('/mod/moodlereader/admin/import.php');

if (!importer::source_available()) {
    echo $OUTPUT->header();
    echo $OUTPUT->render(admin_nav::tabs('import'));
    echo $OUTPUT->notification(get_string('importnosource', 'mod_moodlereader'), notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    die;
}

$courses = importer::courses();
if ($courseid && !isset($courses[$courseid])) {
    throw new moodle_exception('invalidcourseid');
}

if ($action === 'options') {
    require_sesskey();
    set_config('importcheatlog', optional_param('importcheatlog', 0, PARAM_BOOL), 'mod_moodlereader');
    set_config('importmessages', optional_param('importmessages', 0, PARAM_BOOL), 'mod_moodlereader');
    redirect($pageurl, get_string('changessaved'), null, notification::NOTIFY_SUCCESS);
}

if ($action === 'sync' && $courseid) {
    require_sesskey();
    $report = (new importer())->sync($courseid);
    redirect(
        new moodle_url($pageurl, ['action' => 'preview', 'course' => $courseid]),
        get_string('importsynced', 'mod_moodlereader', format_string($courses[$courseid]['fullname'])),
        null,
        notification::NOTIFY_SUCCESS
    );
}

if ($action === 'syncall') {
    require_sesskey();
    $queued = 0;
    foreach ($courses as $course) {
        if ($course['cutover'] < $course['activities']) {
            \mod_moodlereader\task\import_course::queue($course['courseid'], 'sync');
            $queued++;
        }
    }
    redirect($pageurl, get_string('importqueued', 'mod_moodlereader', $queued), null, notification::NOTIFY_SUCCESS);
}

if ($action === 'cutover' && $courseid) {
    require_sesskey();
    $name = format_string($courses[$courseid]['fullname']);
    if (!optional_param('confirm', 0, PARAM_BOOL)) {
        echo $OUTPUT->header();
        echo $OUTPUT->render(admin_nav::tabs('import'));
        echo $OUTPUT->confirm(
            get_string('importcutoverconfirm', 'mod_moodlereader', $name),
            new moodle_url($pageurl, ['action' => 'cutover', 'course' => $courseid, 'confirm' => 1]),
            new moodle_url($pageurl, ['action' => 'preview', 'course' => $courseid])
        );
        echo $OUTPUT->footer();
        die;
    }
    $report = (new importer())->cutover($courseid);
    redirect(
        new moodle_url($pageurl, ['action' => 'preview', 'course' => $courseid]),
        get_string('importcutoverdone', 'mod_moodlereader', (object) ['course' => $name, 'count' => $report['cutover']]),
        null,
        notification::NOTIFY_SUCCESS
    );
}

$queued = [];
foreach (\core\task\manager::get_adhoc_tasks(\mod_moodlereader\task\import_course::class) as $task) {
    $data = $task->get_custom_data();
    if (!empty($data->courseid)) {
        $queued[(int) $data->courseid] = true;
    }
}

echo $OUTPUT->header();
echo $OUTPUT->render(admin_nav::tabs('import'));

$button = function (moodle_url $url, string $labelkey, string $type = \core\output\single_button::BUTTON_SECONDARY): string {
    global $OUTPUT;
    return $OUTPUT->render(new \core\output\single_button($url, get_string($labelkey, 'mod_moodlereader'), 'post', $type));
};
$fmt = fn(?float $value): string => $value === null ? '-' : format_float($value, 2);

if ($action === 'preview' && $courseid) {
    // One course in detail: what a sync would change (nothing is written by this view).
    $course = $courses[$courseid];
    $report = (new importer())->preview($courseid);
    echo $OUTPUT->heading(format_string($course['fullname']), 3);
    echo html_writer::link($pageurl, get_string('importbacktolist', 'mod_moodlereader'));

    $counts = fn(array $c): string => get_string('importcounts', 'mod_moodlereader', (object) $c);
    echo html_writer::tag('p', get_string('importbooks', 'mod_moodlereader') . ': ' . $counts($report['books']) . '<br>' .
        get_string('importusers', 'mod_moodlereader') . ': ' . $counts($report['users']) .
        ($report['users']['conflicts'] ? ' ' . html_writer::span(get_string(
            'importconflicts',
            'mod_moodlereader',
            $report['users']['conflicts']
        ), 'text-danger') : ''), ['class' => 'mt-3']);

    $insync = true;
    foreach ($report['activities'] as $activity) {
        $table = new html_table();
        $table->head = [get_string('importitem', 'mod_moodlereader'), get_string('importnew', 'mod_moodlereader'),
            get_string('importchanged', 'mod_moodlereader'), get_string('importunchanged', 'mod_moodlereader'),
            get_string('importremoved', 'mod_moodlereader')];
        foreach ($activity['items'] as $type => $c) {
            $table->data[] = [get_string('importitem_' . $type, 'mod_moodlereader'), $c['new'], $c['changed'],
                $c['unchanged'], $c['removed']];
            $insync = $insync && !$c['new'] && !$c['changed'] && !$c['removed'];
        }
        $insync = $insync && in_array($activity['action'], ['unchanged', 'locked'], true);
        $links = [html_writer::link(
            new moodle_url('/mod/reader/view.php', ['id' => $activity['legacycmid']]),
            format_string($activity['name'])
        )];
        if ($activity['newcmid']) {
            $links[] = html_writer::link(
                new moodle_url('/mod/moodlereader/view.php', ['id' => $activity['newcmid']]),
                get_string('importcopy', 'mod_moodlereader')
            );
        }
        echo $OUTPUT->heading(implode(' → ', $links), 4, 'mt-4');
        echo html_writer::tag('p', get_string('importaction_' . $activity['action'], 'mod_moodlereader') . ' · ' .
            get_string('importgrades', 'mod_moodlereader', (object) [
                'old' => $fmt($activity['gradesold']),
                'new' => $fmt($activity['gradesnew']),
            ]));
        if ($activity['action'] !== 'locked' && $activity['action'] !== 'skipped') {
            echo html_writer::table($table);
        }
    }
    foreach ($report['warnings'] as $warning) {
        echo $OUTPUT->notification(s($warning), notification::NOTIFY_WARNING);
    }

    $allcutover = $course['cutover'] >= $course['activities'];
    if (!$allcutover) {
        echo $OUTPUT->notification(
            get_string($insync ? 'importinsync' : 'importnotinsync', 'mod_moodlereader'),
            $insync ? notification::NOTIFY_SUCCESS : notification::NOTIFY_INFO,
            false
        );
        $buttons = $button(
            new moodle_url($pageurl, ['action' => 'sync', 'course' => $courseid]),
            $course['synced'] ? 'importresync' : 'importsync'
        );
        if ($course['synced']) {
            $buttons .= $button(
                new moodle_url($pageurl, ['action' => 'cutover', 'course' => $courseid]),
                'importcutover',
                \core\output\single_button::BUTTON_PRIMARY
            );
        }
        echo html_writer::div($buttons, 'd-flex flex-wrap gap-2 mt-3');
    }
    echo $OUTPUT->footer();
    die;
}

// The course list.
echo $OUTPUT->box(get_string('importintro', 'mod_moodlereader'), 'generalbox');

$table = new html_table();
$table->head = [get_string('course'), get_string('importactivities', 'mod_moodlereader'),
    get_string('importstudents', 'mod_moodlereader'), get_string('importattempts', 'mod_moodlereader'),
    get_string('status'), ''];
foreach ($courses as $course) {
    if (!empty($queued[$course['courseid']])) {
        $status = get_string('importstatus_queued', 'mod_moodlereader');
    } else if ($course['cutover'] >= $course['activities']) {
        $status = get_string('importstatus_cutover', 'mod_moodlereader', userdate($course['timecutover']));
    } else if ($course['synced'] || $course['cutover']) {
        $status = get_string('importstatus_synced', 'mod_moodlereader', userdate($course['timesynced']));
    } else {
        $status = get_string('importstatus_none', 'mod_moodlereader');
    }
    $table->data[] = [
        html_writer::link(new moodle_url('/course/view.php', ['id' => $course['courseid']]), format_string($course['fullname'])),
        $course['activities'],
        $course['students'],
        $course['attempts'],
        $status,
        html_writer::link(
            new moodle_url($pageurl, ['action' => 'preview', 'course' => $course['courseid']]),
            get_string('importpreview', 'mod_moodlereader')
        ),
    ];
}
echo html_writer::table($table);
if ($courses) {
    echo $button(new moodle_url($pageurl, ['action' => 'syncall']), 'importsyncall');
}

// Options that apply to every sync.
$config = get_config('mod_moodlereader');
$form = html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false), 'class' => 'mt-4']);
$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'options']);
$form .= $OUTPUT->heading(get_string('importoptions', 'mod_moodlereader'), 4);
foreach (['importcheatlog' => 1, 'importmessages' => 0] as $name => $default) {
    $form .= html_writer::div(html_writer::checkbox(
        $name,
        1,
        (bool) ($config->$name ?? $default),
        ' ' . get_string($name, 'mod_moodlereader'),
        ['id' => 'id_' . $name]
    ), 'form-check');
}
$form .= html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-secondary mt-2']);
$form .= html_writer::end_tag('form');
echo $form;

echo $OUTPUT->footer();
