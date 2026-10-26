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
 * Start a book quiz: drawn by Moodle (quiz/render.php) when the activity and mreader.org allow it,
 * otherwise mreader.org's pages in a frame, watched for the result.
 *
 * The launch is requested server-to-server for this attempt; a frame's URL is single-use.
 * The page listens for mreader.org's "finished" message and also polls
 * quiz/status.php, which fetches the result with this site's own credential.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/lib/resourcelib.php');

use mod_moodlereader\local\api\api_exception;
use mod_moodlereader\local\api\client;
use mod_moodlereader\local\connection;
use mod_moodlereader\local\quizsession;
use mod_moodlereader\local\reader;
use mod_moodlereader\local\results;

$attemptid = required_param('attempt', PARAM_INT);

$attempt = $DB->get_record('moodlereader_attempts', ['id' => $attemptid], '*', MUST_EXIST);
$reader = reader::from_instanceid((int) $attempt->readerid);

require_login($reader->get_course(), false, $reader->get_cm());
// Everyone who sees the books may take a quiz, teachers included; reports list students only.
$reader->require_cap('viewbooks');

if ((int) $attempt->userid !== (int) $USER->id) {
    throw new moodle_exception('notyourattempt', 'mod_moodlereader', $reader->view_url());
}
if ($attempt->state !== 'inprogress') {
    redirect($reader->view_url());
}
$reader->require_available();
\mod_moodlereader\local\seb::require_access($reader);
if (!connection::allows_launch()) {
    throw new moodle_exception('notconnected', 'mod_moodlereader', $reader->view_url());
}

$renderurl = new moodle_url('/mod/moodlereader/quiz/render.php', ['attempt' => $attempt->id]);
// A Moodle-drawn attempt that has started resumes where it is.
if ($attempt->delivery === quizsession::DELIVERY_MOODLE && !empty($attempt->attemptref) && (int) $attempt->timelimit > 0) {
    redirect($renderurl);
}

$book = $DB->get_record('moodlereader_books', ['id' => $attempt->bookid], '*', MUST_EXIST);
try {
    $launch = quizsession::launch($reader, $attempt, $book);
} catch (api_exception $e) {
    if ($e->is_state_refusal()) {
        connection::refresh();
    }
    // One Moodle-drawn quiz at a time per student (mreader.org's rule): point to the open one.
    if ($e->apicode === 'attempt_open') {
        $open = $DB->get_record('moodlereader_attempts', [
            'attemptref' => (string) ($e->response['attempt_ref'] ?? ''), 'userid' => $USER->id, 'state' => 'inprogress',
        ]);
        if ($open) {
            redirect(
                new moodle_url('/mod/moodlereader/quiz/render.php', ['attempt' => $open->id]),
                get_string('quizalreadyopen', 'mod_moodlereader'),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }
    }
    throw new moodle_exception('launchfailed', 'mod_moodlereader', $reader->view_url(), s($e->apicode));
}
if ($launch[0] === quizsession::DELIVERY_MOODLE) {
    redirect($renderurl);
}
$quizurl = $launch[1];

$title = get_string('takequizfor', 'mod_moodlereader', format_string($book->name));
$PAGE->set_url('/mod/moodlereader/quiz/mreader.php', ['attempt' => $attempt->id]);
$PAGE->set_title($title);
$PAGE->set_heading($reader->get_course()->fullname);
$PAGE->set_pagelayout('embedded');

// Messages from the quiz frame are accepted only from the mreader.org origin.
$base = new moodle_url((new client())->get_baseurl());
$mreaderorigin = $base->get_scheme() . '://' . $base->get_host() . ($base->get_port() ? ':' . $base->get_port() : '');
$PAGE->requires->js_call_amd('mod_moodlereader/quizframe', 'init', [[
    'attemptid' => (int) $attempt->id,
    'attemptref' => $attempt->attemptref,
    'origin' => $mreaderorigin,
    'statusurl' => (new moodle_url('/mod/moodlereader/quiz/status.php'))->out(false),
    'sesskey' => sesskey(),
]]);

$mimetype = resourcelib_guess_url_mimetype($quizurl);
$iframe = resourcelib_embed_general($quizurl, $title, html_writer::link($quizurl, $title), $mimetype);

echo $OUTPUT->header();
echo $OUTPUT->heading($title, 3);
echo $OUTPUT->box($iframe);
echo html_writer::div(get_string('quizwatching', 'mod_moodlereader'), 'small text-muted mt-2', [
    'data-region' => 'mod_moodlereader-quizstatus',
    'aria-live' => 'polite',
]);
echo $OUTPUT->footer();
