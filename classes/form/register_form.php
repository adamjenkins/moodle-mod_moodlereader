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

/**
 * mreader.org account credentials form (used once, to register the site).
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class register_form extends \moodleform {
    /**
     * Define the form.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('text', 'username', get_string('mreaderusername', 'mod_moodlereader'));
        $mform->setType('username', PARAM_RAW_TRIMMED);
        $mform->addRule('username', null, 'required', null, 'client');

        $mform->addElement('passwordunmask', 'password', get_string('mreaderpassword', 'mod_moodlereader'));
        $mform->setType('password', PARAM_RAW);
        $mform->addRule('password', null, 'required', null, 'client');

        $this->add_action_buttons(true, get_string('register', 'mod_moodlereader'));
    }
}
