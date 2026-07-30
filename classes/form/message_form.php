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
 * Compose a teacher-to-student message.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_form extends \moodleform {
    /**
     * Define the form.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;
        $recipients = $this->_customdata['recipients'];

        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('select', 'target', get_string('messagetarget', 'mod_moodlereader'), $recipients);

        $mform->addElement(
            'editor',
            'messagetext',
            get_string('message', 'mod_moodlereader'),
            null,
            ['maxfiles' => 0, 'noclean' => false]
        );
        $mform->setType('messagetext', PARAM_RAW);
        $mform->addRule('messagetext', null, 'required', null, 'client');

        $this->add_action_buttons(false, get_string('sendmessage', 'mod_moodlereader'));
    }
}
