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
 * Where a student lands after a quiz drawn by Moodle (?attempt=), once its result is in.
 *
 * Nothing in the URL is trusted for the grade: the result is fetched from mreader.org
 * with this site's own credential.
 *
 * The path of this script is registered with mreader.org as the site's return
 * address: do not move it.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

use mod_moodlereader\local\api\api_exception;
use mod_moodlereader\local\reader;
use mod_moodlereader\local\results;

$attemptid = required_param('attempt', PARAM_INT);
$attempt = $DB->get_record('moodlereader_attempts', ['id' => $attemptid], '*', MUST_EXIST);

$reader = reader::from_instanceid((int) $attempt->readerid);
require_login($reader->get_course(), false, $reader->get_cm());
if ((int) $attempt->userid !== (int) $USER->id) {
    throw new moodle_exception('notyourattempt', 'mod_moodlereader', $reader->view_url());
}

try {
    $state = results::check($attempt, true);
} catch (api_exception $e) {
    $state = $attempt->state;
}

if ($state === 'finished') {
    $attempt = $DB->get_record('moodlereader_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
    $book = $DB->get_record('moodlereader_books', ['id' => $attempt->bookid]);
    $a = (object) [
        'book' => $book ? format_string($book->name) : '',
        'percent' => format_float($attempt->percentgrade, 0),
    ];
    $key = $attempt->passed ? 'quizfinishedpassed' : 'quizfinishedfailed';
    redirect(
        $reader->view_url(),
        get_string($key, 'mod_moodlereader', $a),
        null,
        $attempt->passed ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_INFO
    );
}
redirect(
    $reader->view_url(),
    get_string('quizresultpending', 'mod_moodlereader'),
    null,
    \core\output\notification::NOTIFY_INFO
);
