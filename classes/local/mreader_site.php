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
 * The opaque identities under which Moodle users are known to mreader.org.
 *
 * mreader.org never sees a real name, username, email address or Moodle user id:
 * each user is known only by a random 8-character id and a random display name,
 * kept in {moodlereader_users}. Identities imported from mod_reader keep their
 * original values, so a student's mreader.org history carries over.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mreader_site {
    /** @var int Length of a generated opaque user id. */
    const LENGTH_UNIQUEID = 8;

    /**
     * The opaque mreader.org identity for a user, creating it on first use.
     *
     * @param int $userid
     * @return \stdClass Record from {moodlereader_users}.
     */
    public function get_identity(int $userid): \stdClass {
        global $DB;
        if ($record = $DB->get_record('moodlereader_users', ['userid' => $userid])) {
            return $record;
        }
        $record = (object) [
            'userid'     => $userid,
            'uniqueid'   => $this->generate_uniqueid(),
            'uniquename' => random_string(2) . ' ' . random_string(4),
        ];
        try {
            $record->id = $DB->insert_record('moodlereader_users', $record);
            return $record;
        } catch (\dml_exception $e) {
            // A concurrent first launch won the unique index; re-read the winner.
            if ($existing = $DB->get_record('moodlereader_users', ['userid' => $userid])) {
                return $existing;
            }
            throw $e;
        }
    }

    /**
     * Generate a database-unique opaque user id.
     *
     * @return string
     */
    protected function generate_uniqueid(): string {
        global $DB;
        do {
            $id = random_string(self::LENGTH_UNIQUEID);
        } while ($DB->record_exists('moodlereader_users', ['uniqueid' => $id]));
        return $id;
    }
}
