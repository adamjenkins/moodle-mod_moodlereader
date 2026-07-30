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

namespace mod_moodlereader\local;

/**
 * Teacher-to-student messages shown on the activity page.
 *
 * A message targets a single user or everyone in the instance (userid = 0).
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message {
    /**
     * Messages visible to a given user (their own plus instance-wide).
     *
     * @param int $readerid
     * @param int $userid
     * @return \stdClass[]
     */
    public static function for_user(int $readerid, int $userid): array {
        global $DB;
        return $DB->get_records_select(
            'moodlereader_messages',
            'readerid = :readerid AND (userid = 0 OR userid = :userid)',
            ['readerid' => $readerid, 'userid' => $userid],
            'timecreated DESC'
        );
    }

    /**
     * All messages in an instance (teacher view).
     *
     * @param int $readerid
     * @return \stdClass[]
     */
    public static function all(int $readerid): array {
        global $DB;
        return $DB->get_records('moodlereader_messages', ['readerid' => $readerid], 'timecreated DESC');
    }

    /**
     * Create a message.
     *
     * @param int $readerid
     * @param int $userid Target user, or 0 for everyone.
     * @param string $text
     * @param int $format
     * @return int New message id.
     */
    public static function create(int $readerid, int $userid, string $text, int $format): int {
        global $DB;
        $now = time();
        return $DB->insert_record('moodlereader_messages', (object) [
            'readerid'      => $readerid,
            'userid'        => $userid,
            'messagetext'   => $text,
            'messageformat' => $format,
            'timecreated'   => $now,
            'timemodified'  => $now,
        ]);
    }

    /**
     * Delete a message, scoped to its instance.
     *
     * @param int $id
     * @param int $readerid
     * @return void
     */
    public static function delete(int $id, int $readerid): void {
        global $DB;
        $DB->delete_records('moodlereader_messages', ['id' => $id, 'readerid' => $readerid]);
    }
}
