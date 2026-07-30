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

namespace mod_moodlereader\event;

use mod_moodlereader\local\reader;

/**
 * The attempt_started event: a student launched a book quiz.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_started extends \core\event\base {
    /**
     * Build the event from an attempt record.
     *
     * @param reader $reader
     * @param \stdClass $attempt The {moodlereader_attempts} record.
     * @return self
     */
    public static function create_from_attempt(reader $reader, \stdClass $attempt): self {
        $event = self::create([
            'objectid'      => $attempt->id,
            'context'       => $reader->get_context(),
            'relateduserid' => $attempt->userid,
            'other'         => ['bookid' => $attempt->bookid],
        ]);
        $event->add_record_snapshot('moodlereader_attempts', $attempt);
        return $event;
    }

    /**
     * Initialise the event data.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'moodlereader_attempts';
    }

    /**
     * Human-readable event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventattemptstarted', 'mod_moodlereader');
    }

    /**
     * Description for logs.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->relateduserid}' started attempt '{$this->objectid}' " .
            "in the moodlereader activity with course module id '{$this->contextinstanceid}'.";
    }
}
