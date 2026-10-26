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
 * Check the keys from Safe Exam Browser's JS API, for SEB versions that send no headers.
 *
 * Called by mod_moodlereader/sebcheck with the page's URL and the two hashes SEB computed for it. When
 * they match, the activity's quizzes are open for this session, as core's quizaccess_seb
 * validate_quiz_keys does.
 *
 * @package    mod_moodlereader
 * @copyright  2021 Catalyst IT
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

use mod_moodlereader\local\reader;
use mod_moodlereader\local\seb;

$id = required_param('cmid', PARAM_INT);
$url = required_param('url', PARAM_URL);
$confighash = optional_param('configkey', '', PARAM_ALPHANUM);
$bekhash = optional_param('browserexamkey', '', PARAM_ALPHANUM);

$reader = reader::from_cmid($id);
require_login($reader->get_course(), false, $reader->get_cm(), false, true);
require_sesskey();
$reader->require_cap('viewbooks');

$rule = seb::rule($reader->get_instance());
// Only this plugin's own pages are hashed; SEB hashes the URL without its fragment.
$url = preg_replace('/#.*$/', '', $url);
$ownpages = (new moodle_url('/mod/moodlereader/'))->out(false);
$hash = fn(string $value) => preg_match('/^[a-fA-F0-9]{64}$/', $value) ? strtolower($value) : null;
$allowed = $rule === null || (
    strpos($url, $ownpages) === 0
        && seb::check_keys($id, $rule, $url, $hash($confighash), $hash($bekhash), seb::is_seb())
);
if ($rule !== null) {
    seb::set_session_access($id, $allowed);
}
echo json_encode(['allowed' => $allowed]);
