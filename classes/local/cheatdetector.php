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
 * Detects and records suspected cheating.
 *
 * The heuristic flags pairs of passed attempts on the same book, by different
 * users, from the same IP address, finishing within a short time window — the
 * classic "two students sat together and shared answers" signal. Flagging is
 * advisory; a teacher decides whether to mark an attempt as cheated (which then
 * excludes its words from the grade).
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cheatdetector {
    /** @var int Default window (seconds) within which two same-IP passes are suspicious. */
    const DEFAULT_WINDOW = 1800;

    /**
     * Scan an instance for suspicious attempt pairs and record any new ones.
     *
     * @param int $readerid
     * @param int $window Seconds; two same-book, same-IP passes closer than this are flagged.
     * @return int Number of newly logged suspicious attempts.
     */
    public static function detect(int $readerid, int $window = self::DEFAULT_WINDOW): int {
        global $DB;

        $sql = "SELECT a.id AS attemptid, a.userid, b.id AS otherattemptid, b.userid AS otheruserid
                  FROM {moodlereader_attempts} a
                  JOIN {moodlereader_attempts} b
                    ON b.readerid = a.readerid AND b.bookid = a.bookid
                   AND b.ip = a.ip AND b.userid <> a.userid
                   AND b.passed = 1 AND b.deleted = 0 AND b.cheated = 0
                   AND b.timefinish > 0
                   AND b.timefinish >= a.timefinish - :window
                   AND b.timefinish <= a.timefinish + :window2
                 WHERE a.readerid = :readerid
                       AND a.passed = 1 AND a.deleted = 0 AND a.cheated = 0
                       AND a.timefinish > 0 AND a.ip <> ''";
        $params = ['readerid' => $readerid, 'window' => $window, 'window2' => $window];

        $now = time();
        $new = 0;
        foreach ($DB->get_recordset_sql($sql, $params) as $row) {
            $exists = $DB->record_exists('moodlereader_cheatlog', [
                'readerid' => $readerid, 'attemptid' => $row->attemptid, 'otherattemptid' => $row->otherattemptid,
            ]);
            if ($exists) {
                continue;
            }
            $DB->insert_record('moodlereader_cheatlog', (object) [
                'readerid'       => $readerid,
                'attemptid'      => $row->attemptid,
                'userid'         => $row->userid,
                'otherattemptid' => $row->otherattemptid,
                'otheruserid'    => $row->otheruserid,
                'reason'         => 'sameip',
                'timecreated'    => $now,
            ]);
            $new++;
        }
        return $new;
    }

    /**
     * Mark or unmark an attempt as cheated, and refresh the user's grade.
     *
     * @param reader $reader
     * @param int $attemptid
     * @param bool $cheated
     * @return void
     */
    public static function set_cheated(reader $reader, int $attemptid, bool $cheated): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/moodlereader/lib.php');

        // Scope the attempt to this instance — never trust the submitted id.
        $attempt = $DB->get_record(
            'moodlereader_attempts',
            ['id' => $attemptid, 'readerid' => $reader->get_instance()->id],
            '*',
            MUST_EXIST
        );

        $DB->set_field('moodlereader_attempts', 'cheated', $cheated ? 1 : 0, ['id' => $attempt->id]);
        moodlereader_update_grades($reader->get_instance(), $attempt->userid);
    }
}
