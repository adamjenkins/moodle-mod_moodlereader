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
 * The question page is still open: keep its share of the Moodle-run clock counting.
 *
 * Called by mod_moodlereader/quizrender every few seconds. A page that stops calling (closed, lost
 * connection) stops counting shortly after its last call.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../../config.php');

use mod_moodlereader\local\quizsession;
use mod_moodlereader\local\reader;

$attemptid = required_param('attempt', PARAM_INT);

$attempt = $DB->get_record('moodlereader_attempts', ['id' => $attemptid], '*', MUST_EXIST);
$reader = reader::from_instanceid((int) $attempt->readerid);
require_login($reader->get_course(), false, $reader->get_cm(), false, true);
require_sesskey();
$reader->require_quiz_taker();
if ((int) $attempt->userid !== (int) $USER->id || $attempt->delivery !== quizsession::DELIVERY_MOODLE) {
    throw new moodle_exception('notyourattempt', 'mod_moodlereader');
}

$timeleft = $attempt->state === 'inprogress' ? quizsession::heartbeat($attempt) : 0;
echo json_encode(['timeleft' => max(0, $timeleft)]);
