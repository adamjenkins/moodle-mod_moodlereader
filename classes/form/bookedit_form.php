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

namespace mod_moodlereader\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use mod_moodlereader\local\bookedits;

/**
 * Change a book's details on this site, or take mreader.org's details again.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookedit_form extends \moodleform {
    /**
     * The form: one field per editable detail, each showing mreader.org's value when it is changed here.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $book = $this->_customdata['book'];
        $edits = bookedits::decode($book);

        $mform->addElement('hidden', 'book');
        $mform->setType('book', PARAM_INT);
        $mform->addElement('hidden', 'returnto');
        $mform->setType('returnto', PARAM_LOCALURL);

        $mform->addElement('static', 'bookeditintro', '', get_string('bookedit_intro', 'mod_moodlereader'));

        foreach (bookedits::FIELDS as $field => $label) {
            $attributes = in_array($field, ['difficulty', 'words'], true) ? ['size' => 8] : ['size' => 50];
            $mform->addElement('text', $field, get_string($label, 'mod_moodlereader'), $attributes);
            $mform->setType($field, in_array($field, ['difficulty', 'words'], true) ? PARAM_INT : PARAM_TEXT);
            if ($field === 'name') {
                $mform->addRule($field, null, 'required', null, 'client');
            }
            if (isset($edits[$field])) {
                $mform->addElement('static', $field . 'remote', '', get_string(
                    'bookedit_remote',
                    'mod_moodlereader',
                    s((string) $edits[$field]['remote'])
                ));
            }
        }
        $mform->addHelpButton('difficulty', 'bookedit_level', 'mod_moodlereader');

        $buttons = [
            $mform->createElement('submit', 'submitbutton', get_string('savechanges')),
        ];
        if ($edits) {
            $buttons[] = $mform->createElement('submit', 'revert', get_string('bookedit_revert', 'mod_moodlereader'));
        }
        $buttons[] = $mform->createElement('cancel');
        $mform->addGroup($buttons, 'buttonar', '', ' ', false);
        $mform->closeHeaderBefore('buttonar');
    }

    /**
     * Levels and words in range; a title is required.
     *
     * @param array $data
     * @param array $files
     * @return array Errors by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!empty($data['revert'])) {
            return $errors;
        }
        if (trim((string) ($data['name'] ?? '')) === '') {
            $errors['name'] = get_string('required');
        }
        if ((int) ($data['difficulty'] ?? 0) < 0 || (int) ($data['difficulty'] ?? 0) > 99) {
            $errors['difficulty'] = get_string('levelcountrange', 'mod_moodlereader');
        }
        if ((int) ($data['words'] ?? 0) < 0 || (int) ($data['words'] ?? 0) > 999999) {
            $errors['words'] = get_string('bookedit_wordsrange', 'mod_moodlereader');
        }
        return $errors;
    }
}
