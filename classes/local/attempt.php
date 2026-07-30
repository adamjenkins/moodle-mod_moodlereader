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

use stdClass;

/**
 * Creation and completion of book-quiz attempts.
 *
 * The quiz itself runs on mreader.org; an attempt records only that a Moodle user
 * started a book quiz and, on the signed callback, the grade they achieved.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt {
    /**
     * Start (or resume) an attempt for a user on a book.
     *
     * Returns any in-progress attempt for the same book; otherwise creates one.
     *
     * @param reader $reader
     * @param int    $userid
     * @param int    $bookid
     * @return stdClass The {moodlereader_attempts} record.
     */
    public static function start(reader $reader, int $userid, int $bookid): stdClass {
        global $DB;

        $readerid = $reader->get_instance()->id;
        $existing = $DB->get_record('moodlereader_attempts', [
            'readerid' => $readerid,
            'userid'   => $userid,
            'bookid'   => $bookid,
            'state'    => 'inprogress',
            'deleted'  => 0,
        ]);
        if ($existing) {
            return $existing;
        }

        $count = $DB->count_records('moodlereader_attempts', [
            'readerid' => $readerid, 'userid' => $userid, 'bookid' => $bookid,
        ]);

        $now = time();
        $attempt = (object) [
            'readerid'     => $readerid,
            'userid'       => $userid,
            'bookid'       => $bookid,
            'attempt'      => $count + 1,
            'state'        => 'inprogress',
            'percentgrade' => 0,
            'passed'       => 0,
            'credit'       => 0,
            'cheated'      => 0,
            'deleted'      => 0,
            'bookrating'   => 0,
            'timestart'    => $now,
            'timefinish'   => 0,
            'timemodified' => $now,
            'ip'           => getremoteaddr('', ''),
        ];
        $attempt->id = $DB->insert_record('moodlereader_attempts', $attempt);

        \mod_moodlereader\event\attempt_started::create_from_attempt($reader, $attempt)->trigger();
        return $attempt;
    }

    /**
     * Finish an in-progress attempt with the grade returned by mreader.org.
     *
     * Idempotent: a finished attempt is left unchanged.
     *
     * @param reader    $reader
     * @param stdClass  $attempt The {moodlereader_attempts} record.
     * @param float     $percentgrade The quiz percentage (0-100) from mreader.org.
     * @return void
     */
    public static function finish(reader $reader, stdClass $attempt, float $percentgrade): void {
        global $DB;

        // Re-read the authoritative state: a repeated callback must not re-finish.
        if ($DB->get_field('moodlereader_attempts', 'state', ['id' => $attempt->id]) === 'finished') {
            return;
        }

        $percentgrade = max(0.0, min(100.0, $percentgrade));
        $minpass = (int) $reader->get_instance()->minpassgrade;

        $now = time();
        $attempt->state = 'finished';
        $attempt->percentgrade = $percentgrade;
        $attempt->passed = $percentgrade >= $minpass ? 1 : 0;
        $attempt->timefinish = $now;
        $attempt->timemodified = $now;

        // Guarded update: once finished, a later/replayed callback cannot overwrite the grade.
        $DB->execute(
            "UPDATE {moodlereader_attempts}
                SET state = ?, percentgrade = ?, passed = ?, timefinish = ?, timemodified = ?
              WHERE id = ? AND state = 'inprogress'",
            ['finished', $attempt->percentgrade, $attempt->passed, $now, $now, $attempt->id]
        );

        \mod_moodlereader\event\attempt_submitted::create_from_attempt($reader, $attempt)->trigger();

        // Push the aggregate grade to the gradebook.
        moodlereader_update_grades($reader->get_instance(), $attempt->userid);

        // Update activity completion, if in use.
        $completion = new \completion_info($reader->get_course());
        if ($completion->is_enabled($reader->get_cm())) {
            $completion->update_state($reader->get_cm(), COMPLETION_UNKNOWN, $attempt->userid);
        }
    }
}
