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
 * Student landing page: reading progress and the books available to attempt.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_moodlereader\local\reader;

$id = required_param('id', PARAM_INT); // Course-module id.

$reader = reader::from_cmid($id);
require_login($reader->get_course(), true, $reader->get_cm());
$reader->require_cap('viewbooks');

$event = \mod_moodlereader\event\course_module_viewed::create([
    'objectid' => $reader->get_instance()->id,
    'context'  => $reader->get_context(),
]);
$event->add_record_snapshot('course_modules', $reader->get_cm());
$event->add_record_snapshot('moodlereader', $reader->get_instance());
$event->add_record_snapshot('course', $reader->get_course());
$event->trigger();

$PAGE->set_url('/mod/moodlereader/view.php', ['id' => $id]);
$PAGE->set_title(format_string($reader->get_instance()->name));
$PAGE->set_heading(format_string($reader->get_course()->fullname));
$PAGE->set_context($reader->get_context());
$PAGE->set_activity_record($reader->get_instance());

/** @var \mod_moodlereader\output\renderer $output */
$output = $PAGE->get_renderer('mod_moodlereader');

echo $output->header();
echo $output->heading(format_string($reader->get_instance()->name));

if (!empty($reader->get_instance()->intro)) {
    echo $output->box(format_module_intro('moodlereader', $reader->get_instance(), $reader->get_cm()->id), 'generalbox');
}

// Teacher tools.
$tools = [
    'viewreports' => ['report.php', 'report'],
    'manageusers' => ['messages.php', 'messages'],
];
$links = [];
foreach ($tools as $capability => [$script, $stringid]) {
    if ($reader->can($capability)) {
        $links[] = html_writer::link(
            new moodle_url("/mod/moodlereader/$script", ['id' => $id]),
            get_string($stringid, 'mod_moodlereader'),
            ['class' => 'btn btn-secondary']
        );
    }
}
if ($reader->can('manageusers')) {
    $links[] = html_writer::link(
        new moodle_url('/mod/moodlereader/cheating.php', ['id' => $id]),
        get_string('cheating', 'mod_moodlereader'),
        ['class' => 'btn btn-secondary']
    );
    $links[] = html_writer::link(
        new moodle_url('/mod/moodlereader/goals.php', ['id' => $id]),
        get_string('setgoals', 'mod_moodlereader'),
        ['class' => 'btn btn-secondary']
    );
}
if (has_capability('mod/moodlereader:managecatalogue', context_system::instance())) {
    $links[] = html_writer::link(
        new moodle_url('/mod/moodlereader/books.php', ['id' => $id]),
        get_string('managebooks', 'mod_moodlereader'),
        ['class' => 'btn btn-secondary']
    );
}
if ($links) {
    echo html_writer::div(implode(' ', $links), 'mod-moodlereader-teachertools mb-3');
}

// Quizzes need an approved mreader.org connection; say so instead of failing at launch.
if (!\mod_moodlereader\local\connection::allows_launch()) {
    if ($reader->can('viewreports')) {
        $message = get_string('notconnectedteacher', 'mod_moodlereader');
        if (has_capability('mod/moodlereader:manageremotesites', context_system::instance())) {
            $message .= ' ' . html_writer::link(
                new moodle_url('/mod/moodlereader/admin/connection.php'),
                get_string('connection', 'mod_moodlereader')
            );
        }
    } else {
        $message = get_string('notconnectedstudent', 'mod_moodlereader');
    }
    echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_WARNING, false);
}

// Quizzes that need Safe Exam Browser: how to open it, or, inside it, how to leave.
if ($sebrule = \mod_moodlereader\local\seb::rule($reader->get_instance())) {
    $seb = \mod_moodlereader\local\seb::export_required($reader, $sebrule);
    $seb['notice'] = true;
    $seb['allowed'] = \mod_moodlereader\local\seb::allows($reader);
    $seb['quiturl'] = $seb['allowed'] && $seb['isconfig'] && $seb['insideseb']
        ? \mod_moodlereader\local\seb::quit_url((int) $reader->get_cm()->id)->out(false) : '';
    echo $output->render_from_template('mod_moodlereader/seb_required', $seb);
}

// The student's reading page: messages, progress, level, books read, report, records and search.
echo $output->reading_page($reader, $USER, false);

echo $output->footer();
