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
 * Start (or resume) a book quiz, then hand off to the mreader.org iframe.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_moodlereader\local\reader;
use mod_moodlereader\local\attempt;

$id = required_param('id', PARAM_INT);       // Course-module id.
$bookid = required_param('book', PARAM_INT);  // Book id.

$reader = reader::from_cmid($id);
require_login($reader->get_course(), false, $reader->get_cm());
require_sesskey();
$reader->require_cap('attempt');

// Server-side availability gate: never trust the submitted book id.
if (!$reader->book_is_available($bookid, $USER->id)) {
    throw new moodle_exception('booknotavailable', 'mod_moodlereader', $reader->view_url());
}

$attempt = attempt::start($reader, $USER->id, $bookid);

redirect(new moodle_url('/mod/moodlereader/quiz/mreader.php', [
    'id'      => $reader->get_cm()->id,
    'attempt' => $attempt->id,
]));
