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
    'managebooks' => ['books.php', 'managebooks'],
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
if ($links) {
    echo html_writer::div(implode(' ', $links), 'mod-moodlereader-teachertools mb-3');
}

// Quizzes need an approved mreader.org connection; say so instead of failing at launch.
if (!\mod_moodlereader\local\connection::allows_launch()) {
    if ($reader->can('managebooks')) {
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

// The rest of the page is the student's own reading: progress, level, records and the book search.
// Only someone who takes quizzes here has any (an administrator's or teacher's would always be empty).
if (!has_capability('mod/moodlereader:attempt', $reader->get_context(), null, false)) {
    echo $OUTPUT->notification(get_string('teacherviewnote', 'mod_moodlereader'), \core\output\notification::NOTIFY_INFO);
    echo $output->footer();
    exit;
}

// Teacher messages.
echo $output->messages($reader, $USER->id);

// Reading progress, current level, book covers and this-term reading report —
// each section is shown only when the teacher has enabled it (Appearance settings).
$instance = $reader->get_instance();
if (!empty($instance->showprogressbar)) {
    echo $output->progress($reader, $USER->id);
}
if (!empty($instance->showlevel)) {
    echo $output->level_panel($reader, $USER->id);
}
if (!empty($instance->showbooksread)) {
    echo $output->books_read($reader, $USER->id);
}
if (!empty($instance->showreport)) {
    echo $output->reading_report($reader, $USER->id, fullname($USER));
}

echo html_writer::div(
    html_writer::link(
        new moodle_url('/mod/moodlereader/records.php', ['id' => $id]),
        get_string('myrecords', 'mod_moodlereader'),
        ['class' => 'btn btn-outline-secondary btn-sm']
    ),
    'mod-moodlereader-recordslink mb-3'
);

// Search for the next book to read. Filters are layered on top of the availability
// gate, so students only ever see books they may actually attempt. Results are shown
// only once the student runs a search (the form does not list books by default).
if (!empty($instance->showsearch)) {
    $searched = optional_param('searchsubmit', 0, PARAM_INT);
    $difficulty = optional_param('searchdifficulty', -1, PARAM_INT);
    $filters = [
        'name'       => optional_param('searchname', '', PARAM_TEXT),
        'publisher'  => optional_param('searchpublisher', '', PARAM_TEXT),
        'series'     => optional_param('searchseries', '', PARAM_TEXT),
        'genre'      => optional_param('searchgenre', '', PARAM_ALPHA),
        'difficulty' => $difficulty,
        // English is selected until the student chooses another language (or any).
        'language'   => $searched
            ? \mod_moodlereader\local\booklanguage::clean(optional_param('searchlanguage', '', PARAM_ALPHA))
            : \mod_moodlereader\local\booklanguage::DEFAULT,
    ];
    $hasfilter = trim($filters['name']) !== '' || $filters['publisher'] !== '' || trim($filters['series']) !== ''
        || $filters['genre'] !== '' || $difficulty >= 0;

    echo $output->search_form($reader, $USER->id, $filters);

    if ($searched) {
        $limit = $hasfilter ? 200 : 50;
        $total = 0;
        $books = $reader->search_books($USER->id, $filters, $limit, $total);
        echo $output->search_results($reader, $books, $total, $limit);
    }
}

echo $output->footer();
