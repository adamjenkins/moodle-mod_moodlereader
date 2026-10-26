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

use mod_moodlereader\local\seb;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Safe Exam Browser for every Moodle Reader quiz in a course.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sebcourse_form extends \moodleform {
    /**
     * Define the form.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('static', 'sebcourseintro', '', get_string('sebcourse_intro', 'mod_moodlereader'));
        $mform->addElement('advcheckbox', 'required', get_string('sebcourserequired', 'mod_moodlereader'));
        self::add_rule_elements($mform);
        foreach (['sebmethod', 'sebkeys', 'sebquitpassword'] as $name) {
            $mform->hideIf($name, 'required', 'notchecked');
        }

        $this->add_action_buttons();
    }

    /**
     * How SEB is checked: the elements shared by the activity and course settings.
     *
     * @param \MoodleQuickForm $mform
     * @return void
     */
    public static function add_rule_elements(\MoodleQuickForm $mform): void {
        $mform->addElement('select', 'sebmethod', get_string('sebmethod', 'mod_moodlereader'), [
            seb::METHOD_CONFIG => get_string('sebmethod_config', 'mod_moodlereader'),
            seb::METHOD_CLIENT => get_string('sebmethod_client', 'mod_moodlereader'),
        ]);
        $mform->addHelpButton('sebmethod', 'sebmethod', 'mod_moodlereader');
        $mform->addElement('textarea', 'sebkeys', get_string('sebkeys', 'mod_moodlereader'), ['rows' => 3, 'cols' => 70]);
        $mform->setType('sebkeys', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('sebkeys', 'sebkeys', 'mod_moodlereader');
        $mform->addElement('passwordunmask', 'sebquitpassword', get_string('sebquitpassword', 'mod_moodlereader'));
        $mform->setType('sebquitpassword', PARAM_RAW);
        $mform->addHelpButton('sebquitpassword', 'sebquitpassword', 'mod_moodlereader');
        $mform->hideIf('sebquitpassword', 'sebmethod', 'eq', seb::METHOD_CLIENT);
    }

    /**
     * Validate the keys.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!seb::valid_keys((string) ($data['sebkeys'] ?? ''))) {
            $errors['sebkeys'] = get_string('sebkeysinvalid', 'mod_moodlereader');
        }
        if (!empty($data['required']) && (int) $data['sebmethod'] === seb::METHOD_CLIENT && trim($data['sebkeys']) === '') {
            $errors['sebkeys'] = get_string('sebkeysneeded', 'mod_moodlereader');
        }
        return $errors;
    }
}
