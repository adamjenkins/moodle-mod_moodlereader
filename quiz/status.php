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
 * JSON status of the current user's quiz attempt, polled by the quiz page.
 *
 * Fetches the result from mreader.org server-to-server (rate limited per attempt)
 * and tells the page where to go once the attempt is finished.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../../config.php');

use mod_moodlereader\local\api\api_exception;
use mod_moodlereader\local\reader;
use mod_moodlereader\local\results;

$attemptid = required_param('attempt', PARAM_INT);
$force = optional_param('force', 0, PARAM_BOOL);

$attempt = $DB->get_record('moodlereader_attempts', ['id' => $attemptid], '*', MUST_EXIST);
$reader = reader::from_instanceid((int) $attempt->readerid);
require_login($reader->get_course(), false, $reader->get_cm(), false, true);
require_sesskey();

if ((int) $attempt->userid !== (int) $USER->id) {
    throw new moodle_exception('notyourattempt', 'mod_moodlereader');
}

try {
    $state = results::check($attempt, (bool) $force);
} catch (api_exception $e) {
    $state = $attempt->state;
}

$response = ['state' => $state];
if ($state === 'finished') {
    $response['redirect'] = (new moodle_url('/mod/moodlereader/quiz/return.php', ['attempt' => $attempt->id]))->out(false);
}
echo json_encode($response);
