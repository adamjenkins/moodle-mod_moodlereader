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
 * The Safe Exam Browser configuration Moodle makes for an activity, as a .seb file.
 *
 * Opened by SEB through a seb:// or sebs:// link, or downloaded. As core's quizaccess_seb config.php,
 * it needs a login with access to the activity.
 *
 * @package    mod_moodlereader
 * @copyright  2019 Catalyst IT
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_moodlereader\local\reader;
use mod_moodlereader\local\seb;

$id = required_param('id', PARAM_INT); // Course-module id.

$reader = reader::from_cmid($id);
require_login($reader->get_course(), false, $reader->get_cm());
$reader->require_cap('viewbooks');

$rule = seb::rule($reader->get_instance());
if ($rule === null || $rule->method !== seb::METHOD_CONFIG) {
    throw new moodle_exception('sebnoconfig', 'mod_moodlereader', $reader->view_url());
}

$xml = seb::config_xml($id, $rule);
foreach (\quizaccess_seb\helper::get_seb_file_headers() as $header) {
    header($header);
}
echo $xml;
