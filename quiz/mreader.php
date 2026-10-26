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
 * Launch a book quiz for Moodle to draw (quiz/render.php), the only way this plugin shows quizzes.
 *
 * The launch is a reservation requested server-to-server for this attempt; nothing is shown here.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

use mod_moodlereader\local\api\api_exception;
use mod_moodlereader\local\connection;
use mod_moodlereader\local\quizsession;
use mod_moodlereader\local\reader;

$attemptid = required_param('attempt', PARAM_INT);

$attempt = $DB->get_record('moodlereader_attempts', ['id' => $attemptid], '*', MUST_EXIST);
$reader = reader::from_instanceid((int) $attempt->readerid);

require_login($reader->get_course(), false, $reader->get_cm());
$reader->require_quiz_taker();

if ((int) $attempt->userid !== (int) $USER->id) {
    throw new moodle_exception('notyourattempt', 'mod_moodlereader', $reader->view_url());
}
if ($attempt->state !== 'inprogress' || !empty($attempt->deleted)) {
    redirect($reader->view_url());
}
$reader->require_available();
\mod_moodlereader\local\seb::require_access($reader);
if (!connection::allows_launch()) {
    throw new moodle_exception('notconnected', 'mod_moodlereader', $reader->view_url());
}

$renderurl = new moodle_url('/mod/moodlereader/quiz/render.php', ['attempt' => $attempt->id]);
// An attempt that has started resumes where it is.
if ($attempt->delivery === quizsession::DELIVERY_MOODLE && !empty($attempt->attemptref) && (int) $attempt->timelimit > 0) {
    redirect($renderurl);
}
// Not launched yet: the book must still be one the student may take (it may have been finished
// meanwhile in another course, disabled, or be beyond the level quota now).
$reader->require_still_available($attempt);

$book = $DB->get_record('moodlereader_books', ['id' => $attempt->bookid], '*', MUST_EXIST);
try {
    quizsession::launch($reader, $attempt, $book);
} catch (api_exception $e) {
    if ($e->is_state_refusal()) {
        connection::refresh();
    }
    if ($e->apicode === 'nomoodledrawing') {
        throw new moodle_exception('quiznomoodledrawing', 'mod_moodlereader', $reader->view_url());
    }
    // One quiz at a time per student (mreader.org's rule): point to the open one.
    if ($e->apicode === 'attempt_open') {
        $ref = (string) ($e->response['attempt_ref'] ?? '');
        $open = $DB->get_record('moodlereader_attempts', [
            'attemptref' => $ref, 'userid' => $USER->id, 'state' => 'inprogress', 'deleted' => 0,
        ]);
        if ($open) {
            redirect(
                new moodle_url('/mod/moodlereader/quiz/render.php', ['attempt' => $open->id]),
                get_string('quizalreadyopen', 'mod_moodlereader'),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }
        throw new moodle_exception('quizopenelsewhere', 'mod_moodlereader', $reader->view_url());
    }
    throw new moodle_exception('launchfailed', 'mod_moodlereader', $reader->view_url(), s($e->apicode));
}
redirect($renderurl);
