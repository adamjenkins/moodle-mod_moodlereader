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
 * mreader.org quiz endpoint: render the quiz iframe, and receive the grade callback.
 *
 * IMPORTANT — the path of this script is an external contract. mreader.org appends
 * "quiz/mreader.php" to the site URL registered during setup, so the grade callback
 * always arrives here. Do not move or rename this file without confirming the
 * callback path with the mreader.org service.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/lib/resourcelib.php');

use mod_moodlereader\local\reader;
use mod_moodlereader\local\attempt;
use mod_moodlereader\local\mreader_site;

// The custom_id parameter signals a grade callback from mreader.org; attempt launches the iframe.
$customid = optional_param('custom_id', 0, PARAM_INT);
$attemptid = $customid ?: required_param('attempt', PARAM_INT);

$attempt = $DB->get_record('moodlereader_attempts', ['id' => $attemptid], '*', MUST_EXIST);
$reader = reader::from_instanceid((int) $attempt->readerid);

require_login($reader->get_course(), false, $reader->get_cm());
$reader->require_cap('viewbooks');

// Only the owner of the attempt may launch or complete it.
if ((int) $attempt->userid !== (int) $USER->id) {
    throw new moodle_exception('notyourattempt', 'mod_moodlereader', $reader->view_url());
}

$site = new mreader_site();
$book = $DB->get_record('moodlereader_books', ['id' => $attempt->bookid], '*', MUST_EXIST);

if ($customid) {
    // Grade callback from mreader.org.
    $url = new moodle_url($FULLME);
    $received = (string) $url->get_param('token');
    $url->remove_params('token');

    // 1. Constant-time signature check against the recomputed token.
    if (!$site->verify_token($url, $received)) {
        throw new moodle_exception('invalidtoken', 'mod_moodlereader', $reader->view_url());
    }
    // 2. The callback must name this attempt's book and this user's identity.
    if ($book->image !== $url->get_param('book')) {
        throw new moodle_exception('invalidbook', 'mod_moodlereader', $reader->view_url());
    }
    $identity = $site->get_identity($USER->id);
    if ($identity->uniqueid !== $url->get_param('uname')) {
        throw new moodle_exception('invaliduser', 'mod_moodlereader', $reader->view_url());
    }
    // 3. Record the grade and finish the attempt.
    $grade = clean_param($url->get_param('grade'), PARAM_FLOAT);
    attempt::finish($reader, $attempt, (float) $grade);

    redirect(
        $reader->view_url(),
        get_string('quizfinished', 'mod_moodlereader'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Launch the quiz iframe.
if (!$site->is_configured()) {
    throw new moodle_exception('notconfigured', 'mod_moodlereader', $reader->view_url());
}

$identity = $site->get_identity($USER->id);
$quizurl = $site->takequiz_url($attempt, $book->image, $identity);
$title = get_string('takequizfor', 'mod_moodlereader', format_string($book->name));

$PAGE->set_url('/mod/moodlereader/quiz/mreader.php', ['attempt' => $attempt->id]);
$PAGE->set_title($title);
$PAGE->set_heading($reader->get_course()->fullname);
$PAGE->set_pagelayout('embedded');

$mimetype = resourcelib_guess_url_mimetype($quizurl);
$iframe = resourcelib_embed_general($quizurl, $title, html_writer::link($quizurl, $title), $mimetype);

echo $OUTPUT->header();
echo $OUTPUT->heading($title, 3);
echo $OUTPUT->box($iframe);
echo $OUTPUT->footer();
