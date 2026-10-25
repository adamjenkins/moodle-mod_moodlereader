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

/**
 * The Moodle admin's request for mreader.org access.
 *
 * Asks only for the institution and a contact for the authorization conversation;
 * nothing about students is part of a request.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connection_request_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $details = $this->_customdata['details'];

        $mform->addElement('header', 'requesthdr', get_string('connrequest', 'mod_moodlereader'));

        $mform->addElement('text', 'institution', get_string('conninstitution', 'mod_moodlereader'), ['size' => 50]);
        $mform->setType('institution', PARAM_TEXT);
        $mform->addRule('institution', null, 'required', null, 'client');
        $mform->addRule('institution', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('text', 'contactname', get_string('conncontactname', 'mod_moodlereader'), ['size' => 50]);
        $mform->setType('contactname', PARAM_TEXT);
        $mform->addRule('contactname', null, 'required', null, 'client');
        $mform->addRule('contactname', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('text', 'contactemail', get_string('conncontactemail', 'mod_moodlereader'), ['size' => 50]);
        $mform->setType('contactemail', PARAM_EMAIL);
        $mform->addRule('contactemail', null, 'required', null, 'client');
        $mform->addRule('contactemail', null, 'email', null, 'client');
        $mform->addHelpButton('contactemail', 'conncontactemail', 'mod_moodlereader');

        $mform->addElement('textarea', 'message', get_string('connmessage', 'mod_moodlereader'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('message', PARAM_TEXT);

        if (!empty($this->_customdata['haslegacy'])) {
            $mform->addElement('advcheckbox', 'claimlegacy', '', get_string('connclaimlegacy', 'mod_moodlereader'));
            $mform->setDefault('claimlegacy', 1);
            $mform->addHelpButton('claimlegacy', 'connclaimlegacy', 'mod_moodlereader');
        }

        $rows = '';
        foreach (['site_name', 'wwwroot', 'return_url', 'plugin_release', 'moodle_release'] as $key) {
            $rows .= \html_writer::tag('dt', get_string('conndetail_' . $key, 'mod_moodlereader'), ['class' => 'col-sm-3']) .
                \html_writer::tag('dd', s($details[$key]), ['class' => 'col-sm-9']);
        }
        $mform->addElement(
            'static',
            'sent',
            get_string('connsent', 'mod_moodlereader'),
            \html_writer::tag('dl', $rows, ['class' => 'row mb-0'])
        );

        $this->add_action_buttons(false, get_string('connsend', 'mod_moodlereader'));
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        foreach (['institution', 'contactname'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $errors[$field] = get_string('required');
            }
        }
        if (!validate_email((string) ($data['contactemail'] ?? ''))) {
            $errors['contactemail'] = get_string('invalidemail');
        }
        return $errors;
    }
}
