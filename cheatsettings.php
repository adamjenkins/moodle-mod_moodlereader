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
 * Cheating-detection settings of an activity: markers, limits and the school's allow-list.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_moodlereader\form\cheatsettings_form;
use mod_moodlereader\local\cheatdetector;
use mod_moodlereader\local\reader;

$id = required_param('id', PARAM_INT);

$reader = reader::from_cmid($id);
require_login($reader->get_course(), true, $reader->get_cm());
$reader->require_cap('manageusers');

$instance = $reader->get_instance();
$pageurl = new moodle_url('/mod/moodlereader/cheatsettings.php', ['id' => $id]);
$cheatingurl = new moodle_url('/mod/moodlereader/cheating.php', ['id' => $id]);
$PAGE->set_url($pageurl);
$PAGE->set_context($reader->get_context());
$PAGE->set_activity_record($instance);
$PAGE->set_title(get_string('cheatsettings', 'mod_moodlereader'));
$PAGE->set_heading(format_string($reader->get_course()->fullname));

$current = cheatdetector::settings($instance);
[$siteallowlist] = cheatdetector::clean_allowlist((string) get_config('mod_moodlereader', 'cheatallowlist'));
$form = new cheatsettings_form($pageurl, ['siteallowlist' => $siteallowlist]);
$form->set_data(['id' => $id] + cheatsettings_form::to_form($current));

if ($form->is_cancelled()) {
    redirect($cheatingurl);
}
if ($data = $form->get_data()) {
    $settings = cheatsettings_form::from_form($data, $current);
    $DB->set_field('moodlereader', 'cheatsettings', json_encode($settings), ['id' => $instance->id]);
    redirect(
        $cheatingurl,
        get_string('cheatsettingssaved', 'mod_moodlereader'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('cheatsettings', 'mod_moodlereader'));
$form->display();
echo $OUTPUT->footer();
