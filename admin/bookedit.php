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
 * Change one book's details for the whole site (title, publisher, series, reading level, words), or take
 * mreader.org's details again. Changes are kept through every sync.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use mod_moodlereader\form\bookedit_form;
use mod_moodlereader\local\bookedits;

$bookid = required_param('book', PARAM_INT);
$returnto = optional_param('returnto', '', PARAM_LOCALURL);

// The catalogue is the whole site's, managed in Site administration only.
admin_externalpage_setup('mod_moodlereader_books');
require_capability('mod/moodlereader:managecatalogue', context_system::instance());

$book = $DB->get_record('moodlereader_books', ['id' => $bookid], '*', MUST_EXIST);
$pageurl = new moodle_url('/mod/moodlereader/admin/bookedit.php', ['book' => $bookid]);
$backurl = $returnto !== '' ? new moodle_url($returnto) : new moodle_url('/mod/moodlereader/admin/books.php');
$PAGE->set_url($pageurl);
$PAGE->navbar->add(get_string('editbook', 'mod_moodlereader'));

$form = new bookedit_form($pageurl, ['book' => $book]);
$data = ['book' => $bookid, 'returnto' => $returnto];
foreach (bookedits::FIELDS as $field => $unused) {
    $data[$field] = $book->$field;
}
$form->set_data($data);

if ($form->is_cancelled()) {
    redirect($backurl);
}
if ($submitted = $form->get_data()) {
    if (!empty($submitted->revert)) {
        bookedits::revert($bookid);
        redirect(
            $backurl,
            get_string('bookedit_reverted', 'mod_moodlereader', s($book->name)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    $values = [];
    foreach (bookedits::FIELDS as $field => $unused) {
        $values[$field] = $submitted->$field ?? $book->$field;
    }
    $saved = bookedits::save($bookid, $values);
    redirect(
        $backurl,
        get_string('bookedit_saved', 'mod_moodlereader', s($saved->name)),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('editbookheading', 'mod_moodlereader', s($book->name)));
$form->display();
echo $OUTPUT->footer();
