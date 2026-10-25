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
 * The attempt_updated event: a teacher changed an attempt's result in a report.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_updated extends \core\event\base {
    /**
     * Build the event from an attempt record.
     *
     * @param reader $reader
     * @param \stdClass $attempt The {moodlereader_attempts} record, after the change.
     * @param string $action What was done, e.g. 'pass' or 'cheat'.
     * @return self
     */
    public static function create_from_attempt(reader $reader, \stdClass $attempt, string $action): self {
        $event = self::create([
            'objectid'      => $attempt->id,
            'context'       => $reader->get_context(),
            'relateduserid' => $attempt->userid,
            'other'         => ['bookid' => (int) $attempt->bookid, 'action' => $action],
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
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'moodlereader_attempts';
    }

    /**
     * Human-readable event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventattemptupdated', 'mod_moodlereader');
    }

    /**
     * Description for logs.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' applied '{$this->other['action']}' to attempt '{$this->objectid}' " .
            "of the user with id '{$this->relateduserid}' in the moodlereader activity with course module id " .
            "'{$this->contextinstanceid}'.";
    }

    /**
     * Validate the custom data.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['action'])) {
            throw new \coding_exception('The \'action\' value must be set in other.');
        }
    }

    /**
     * Restore mapping for the 'other' values.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return ['bookid' => \core\event\base::NOT_MAPPED];
    }
}
