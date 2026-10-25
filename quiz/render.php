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
 * A book quiz drawn by Moodle (API v2 31 §11): one question per page, the Moodle-run clock, the survey.
 *
 * mreader.org keeps the questions, the answer key and the marking. This page asks it for the question
 * on screen each time (nothing is stored), posts the student's answer back server to server, and then
 * redirects, so a reload never re-sends an answer.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

use mod_moodlereader\local\api\api_exception;
use mod_moodlereader\local\quizsession;
use mod_moodlereader\local\reader;
use mod_moodlereader\local\results;

$attemptid = required_param('attempt', PARAM_INT);

$attempt = $DB->get_record('moodlereader_attempts', ['id' => $attemptid], '*', MUST_EXIST);
$reader = reader::from_instanceid((int) $attempt->readerid);
require_login($reader->get_course(), false, $reader->get_cm());
$reader->require_cap('viewbooks');
if ((int) $attempt->userid !== (int) $USER->id) {
    throw new moodle_exception('notyourattempt', 'mod_moodlereader', $reader->view_url());
}
if ($attempt->delivery !== quizsession::DELIVERY_MOODLE || empty($attempt->attemptref)) {
    redirect(new moodle_url('/mod/moodlereader/quiz/mreader.php', ['attempt' => $attempt->id]));
}

$pageurl = new moodle_url('/mod/moodlereader/quiz/render.php', ['attempt' => $attempt->id]);
$returnurl = new moodle_url('/mod/moodlereader/quiz/return.php', ['attempt' => $attempt->id]);
$book = $DB->get_record('moodlereader_books', ['id' => $attempt->bookid], '*', MUST_EXIST);
$title = get_string('takequizfor', 'mod_moodlereader', format_string($book->name));

// The question text must not be kept anywhere, the browser's cache included.
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');

/**
 * Leave the page with a message when mreader.org cannot be reached or refuses.
 *
 * @param reader $reader
 * @param api_exception $e
 * @return never
 */
function moodlereader_quiz_failed(reader $reader, api_exception $e) {
    $key = $e->apicode === 'rate_limited' ? 'quizbusy' : 'quizunreachable';
    redirect(
        $reader->view_url(),
        get_string($key, 'mod_moodlereader', s($e->apicode)),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

try {
    // An answer, the time limit, or the survey.
    if (data_submitted() && confirm_sesskey()) {
        if (optional_param('survey', 0, PARAM_BOOL)) {
            $answers = optional_param('decline', 0, PARAM_BOOL) ? [] : optional_param_array('answers', [], PARAM_INT);
            quizsession::survey($attempt, array_filter($answers));
            redirect($returnurl);
        }
        if ($attempt->state === 'inprogress') {
            $response = quizsession::response(
                optional_param('type', '', PARAM_ALPHA),
                optional_param('option', '', PARAM_ALPHANUMEXT),
                optional_param_array('order', [], PARAM_ALPHANUMEXT)
            );
            $timeout = optional_param('timeout', 0, PARAM_BOOL);
            // Only a malformed form is refused; nothing chosen is a (wrong) answer.
            if ($response === null && !$timeout) {
                redirect(
                    $pageurl,
                    get_string('quizchooseanswer', 'mod_moodlereader'),
                    null,
                    \core\output\notification::NOTIFY_WARNING
                );
            }
            // At the time limit the answer chosen so far still counts; the rest of the quiz is then finished.
            $state = $response !== null
                ? quizsession::answer($attempt, required_param('slot', PARAM_INT), $response)
                : ['state' => 'in_progress'];
            if ($timeout && $state['state'] === 'in_progress') {
                quizsession::finish($attempt);
            }
        }
        redirect($pageurl);
    }

    $state = quizsession::show($attempt);
    if ($state['state'] === 'relaunch') {
        redirect(new moodle_url('/mod/moodlereader/quiz/mreader.php', ['attempt' => $attempt->id]));
    }
} catch (api_exception $e) {
    moodlereader_quiz_failed($reader, $e);
}

if ($state['state'] === 'finished') {
    // The grade comes from mreader.org's result, as for every attempt.
    try {
        results::check($attempt, true);
    } catch (api_exception $e) {
        debugging('mreader.org result not yet available for attempt ' . $attempt->id, DEBUG_DEVELOPER);
    }
    if (empty($state['survey'])) {
        redirect($returnurl);
    }
}

$PAGE->set_url($pageurl);
$PAGE->set_context($reader->get_context());
$PAGE->set_title($title);
$PAGE->set_heading(format_string($reader->get_course()->fullname));
$PAGE->set_activity_record($reader->get_instance());
$PAGE->set_cacheable(false);

$context = $reader->get_context();
$clean = fn(string $html) => format_text($html, FORMAT_HTML, ['context' => $context, 'para' => false, 'filter' => false]);

if ($state['state'] === 'finished') {
    $questions = [];
    foreach ($state['survey'] as $q) {
        $answers = [];
        foreach ($q['answers'] as $a) {
            $answers[] = ['id' => $a['id'], 'questionid' => $q['id'], 'texthtml' => $clean($a['text']),
                'inputid' => 'mr-s-' . $q['id'] . '-' . $a['id']];
        }
        $questions[] = ['id' => $q['id'], 'questionhtml' => $clean($q['question']), 'answers' => $answers];
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('surveytitle', 'mod_moodlereader', format_string($book->name)));
    echo $OUTPUT->render_from_template('mod_moodlereader/quiz_survey', [
        'actionurl' => $pageurl->out(false), 'attemptid' => $attempt->id, 'sesskey' => sesskey(),
        'questions' => $questions,
    ]);
    echo $OUTPUT->footer();
    exit;
}

$item = $state['item'];
$timeleft = max(0, quizsession::time_left($attempt));
$choices = [];
foreach ($item['choices'] as $i => $choice) {
    $choices[] = [
        'id' => $choice['id'],
        'texthtml' => $clean($choice['text']),
        'label' => $choice['value'] !== '' ? get_string('tf' . $choice['value'], 'mod_moodlereader') : '',
        'position' => $i + 1,
    ];
}
$PAGE->requires->js_call_amd('mod_moodlereader/quizrender', 'init', [[
    'timeleft' => $timeleft,
    'heartbeat' => quizsession::HEARTBEAT,
    'heartbeaturl' => (new moodle_url('/mod/moodlereader/quiz/heartbeat.php'))->out(false),
    'attemptid' => (int) $attempt->id,
    'sesskey' => sesskey(),
]]);

echo $OUTPUT->header();
echo $OUTPUT->heading($title, 3);
echo $OUTPUT->render_from_template('mod_moodlereader/quiz_page', [
    'bookname' => format_string($book->name),
    'slot' => $state['slot'],
    'slots' => $state['slots'] ?: (int) $attempt->slots,
    'timeleft' => sprintf('%d:%02d', intdiv($timeleft, 60), $timeleft % 60),
    'actionurl' => $pageurl->out(false),
    'attemptid' => $attempt->id,
    'sesskey' => sesskey(),
    'type' => $item['type'],
    'texthtml' => $clean($item['text']),
    'instructionhtml' => $clean($item['instruction']),
    'ismc' => $item['type'] === 'mc',
    'istf' => $item['type'] === 'tf',
    'isordering' => $item['type'] === 'o',
    'isdescription' => $item['type'] === 'd',
    'choices' => $choices,
    'islast' => $state['slots'] > 0 && $state['slot'] >= $state['slots'],
]);
echo $OUTPUT->footer();
