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
 * Wait times between quizzes in an activity: a default, and rules for single reading levels.
 *
 * Modelled on mod_reader's "Set reading rates" page.
 *
 * @package    mod_moodlereader
 * @copyright  2013 Gordon Bateson (gordon.bateson@gmail.com)
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_moodlereader\form\waits_form;
use mod_moodlereader\local\reader;
use mod_moodlereader\local\waits;

$id = required_param('id', PARAM_INT);

$reader = reader::from_cmid($id);
require_login($reader->get_course(), true, $reader->get_cm());
$reader->require_cap('manageusers');

$instance = $reader->get_instance();
$pageurl = new moodle_url('/mod/moodlereader/waits.php', ['id' => $id]);
$PAGE->set_url($pageurl);
$PAGE->set_context($reader->get_context());
$PAGE->set_activity_record($instance);
$PAGE->set_title(get_string('waits', 'mod_moodlereader'));
$PAGE->set_heading(format_string($reader->get_course()->fullname));

$rules = waits::rules((int) $instance->id);
$levelrules = count(array_filter(array_keys($rules), fn($level) => $level !== waits::DEFAULT_LEVEL));
$form = new waits_form($pageurl, ['levelrules' => $levelrules]);
$form->set_data(['id' => $id] + waits_form::to_form($instance, $rules));

if ($form->is_cancelled()) {
    redirect($reader->view_url());
}
if ($data = $form->get_data()) {
    $DB->set_field(
        'moodlereader',
        'waitlevel',
        (int) $data->waitlevel === waits::BY_BOOK ? waits::BY_BOOK : waits::BY_STUDENT,
        ['id' => $instance->id]
    );
    waits::save((int) $instance->id, waits_form::from_form($data));
    redirect($pageurl, get_string('waitssaved', 'mod_moodlereader'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('waits', 'mod_moodlereader'));
$form->display();
echo $OUTPUT->footer();
