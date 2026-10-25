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
 * Settings form for a moodlereader activity instance.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * The moodlereader instance settings form.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_moodlereader_mod_form extends moodleform_mod {
    /**
     * Define the form.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        // Reading target.
        $mform->addElement('header', 'gradingheader', get_string('gradingheader', 'mod_moodlereader'));

        $mform->addElement('text', 'goal', get_string('goal', 'mod_moodlereader'), ['size' => 8]);
        $mform->setType('goal', PARAM_INT);
        $mform->setDefault('goal', 30000);
        $mform->addHelpButton('goal', 'goal', 'mod_moodlereader');

        $mform->addElement('select', 'wordsorpoints', get_string('wordsorpoints', 'mod_moodlereader'), [
            0 => get_string('words', 'mod_moodlereader'),
            1 => get_string('points', 'mod_moodlereader'),
        ]);

        $mform->addElement('text', 'minpassgrade', get_string('minpassgrade', 'mod_moodlereader'), ['size' => 4]);
        $mform->setType('minpassgrade', PARAM_INT);
        $mform->setDefault('minpassgrade', 60);
        $mform->addHelpButton('minpassgrade', 'minpassgrade', 'mod_moodlereader');

        // Appearance: which sections of the activity page students see.
        $mform->addElement('header', 'appearanceheader', get_string('appearanceheader', 'mod_moodlereader'));
        $mform->addElement('static', 'appearancedesc', '', get_string('appearancedesc', 'mod_moodlereader'));
        foreach (['showprogressbar', 'showlevel', 'showbooksread', 'showreport', 'showsearch'] as $toggle) {
            $mform->addElement('advcheckbox', $toggle, get_string($toggle, 'mod_moodlereader'));
            $mform->setDefault($toggle, 1);
            if ($toggle === 'showprogressbar') {
                $mform->addElement('advcheckbox', 'extraprogressbar', get_string('extraprogressbar', 'mod_moodlereader'));
                $mform->setDefault('extraprogressbar', 1);
                $mform->addHelpButton('extraprogressbar', 'extraprogressbar', 'mod_moodlereader');
                $mform->disabledIf('extraprogressbar', 'showprogressbar');
            }
        }

        // Reading-level control.
        $mform->addElement('header', 'levelheader', get_string('levelheader', 'mod_moodlereader'));

        $mform->addElement('advcheckbox', 'levelcheck', get_string('levelcheck', 'mod_moodlereader'));
        $mform->addHelpButton('levelcheck', 'levelcheck', 'mod_moodlereader');

        // How many books a student reads at, below and above their level (mod_reader's defaults: 6, 3, 1).
        foreach (['thislevel' => 6, 'prevlevel' => 3, 'nextlevel' => 1] as $field => $default) {
            $mform->addElement('text', $field, get_string($field, 'mod_moodlereader'), ['size' => 3]);
            $mform->setType($field, PARAM_INT);
            $mform->setDefault($field, $default);
            $mform->addHelpButton($field, $field, 'mod_moodlereader');
            $mform->disabledIf($field, 'levelcheck');
        }

        // Availability window.
        $mform->addElement('header', 'availabilityheader', get_string('availability'));
        $mform->addElement(
            'date_time_selector',
            'availablefrom',
            get_string('availablefrom', 'mod_moodlereader'),
            ['optional' => true]
        );
        $mform->addElement(
            'date_time_selector',
            'availableuntil',
            get_string('availableuntil', 'mod_moodlereader'),
            ['optional' => true]
        );

        // Standard grade element (drives $data->maxgrade via 'grade').
        $this->standard_grading_coursemodule_elements();

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Validate the settings.
     *
     * @param array $data
     * @param array $files
     * @return array Errors by element name.
     */
    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        foreach (['thislevel', 'prevlevel', 'nextlevel'] as $field) {
            if (isset($data[$field]) && ((int) $data[$field] < 0 || (int) $data[$field] > 99)) {
                $errors[$field] = get_string('levelcountrange', 'mod_moodlereader');
            }
        }
        // The mreader.org catalogue has no points, so grading by points needs books that have them.
        if (!empty($data['wordsorpoints']) && !$DB->record_exists_select('moodlereader_books', 'points > 0 AND hidden = 0')) {
            $errors['wordsorpoints'] = get_string('nopointsbooks', 'mod_moodlereader');
        }
        return $errors;
    }

    /**
     * Add custom completion rules.
     *
     * @return array Element names.
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $mform->addElement(
            'advcheckbox',
            'completionpass',
            '',
            get_string('completionpass', 'mod_moodlereader')
        );
        $group = [
            $mform->createElement(
                'advcheckbox',
                'completiontotalwordsenabled',
                '',
                get_string('completiontotalwords', 'mod_moodlereader')
            ),
            $mform->createElement('text', 'completiontotalwords', '', ['size' => 8]),
        ];
        $mform->addGroup(
            $group,
            'completiontotalwordsgroup',
            get_string('completiontotalwords', 'mod_moodlereader'),
            [' '],
            false
        );
        $mform->setType('completiontotalwords', PARAM_INT);
        $mform->disabledIf('completiontotalwords', 'completiontotalwordsenabled', 'notchecked');
        return ['completionpass', 'completiontotalwordsgroup'];
    }

    /**
     * Whether any custom completion rule is enabled.
     *
     * @param array $data Form data.
     * @return bool
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionpass']) ||
            (!empty($data['completiontotalwordsenabled']) && $data['completiontotalwords'] > 0);
    }

    /**
     * Preload derived fields when editing an existing instance.
     *
     * @param array $defaultvalues
     * @return void
     */
    public function data_preprocessing(&$defaultvalues) {
        // The gradebook element is 'grade'; the DB column is 'maxgrade'.
        if (isset($defaultvalues['maxgrade'])) {
            $defaultvalues['grade'] = $defaultvalues['maxgrade'];
        }
        // Reflect the stored word threshold in its enable checkbox.
        if (!empty($defaultvalues['completiontotalwords'])) {
            $defaultvalues['completiontotalwordsenabled'] = 1;
        }
    }

    /**
     * Normalise submitted completion data.
     *
     * @return stdClass|null
     */
    public function get_data() {
        $data = parent::get_data();
        if ($data && !empty($data->completionunlocked)) {
            $autocompletion = !empty($data->completion) && $data->completion == COMPLETION_TRACKING_AUTOMATIC;
            if (empty($data->completiontotalwordsenabled) || !$autocompletion) {
                $data->completiontotalwords = 0;
            }
            if (!$autocompletion) {
                $data->completionpass = 0;
            }
        }
        return $data;
    }
}
