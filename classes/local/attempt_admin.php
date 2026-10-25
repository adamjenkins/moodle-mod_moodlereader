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
 * Teacher changes to students' attempts, levels and goals, made from the reports.
 *
 * Every id passed in is re-checked against the activity: an attempt must belong to
 * it, and a user must be one of its students that the teacher can see. Each change
 * regrades the students it touches.
 *
 * @package    mod_moodlereader
 * @copyright  2013 Gordon Bateson (gordon.bateson@gmail.com)
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_admin {
    /** @var string Set the result to passed. */
    const ACTION_PASS = 'pass';

    /** @var string Set the result to failed (this also removes awarded credit). */
    const ACTION_FAIL = 'fail';

    /** @var string Mark as cheated: the attempt no longer counts. */
    const ACTION_CHEAT = 'cheat';

    /** @var string Remove the cheated mark. */
    const ACTION_UNCHEAT = 'uncheat';

    /** @var string Delete (hide) the attempt: it no longer counts, and the book can be read again. */
    const ACTION_DELETE = 'delete';

    /** @var string Restore a deleted attempt. */
    const ACTION_RESTORE = 'restore';

    /** @var string[] Attempt actions, in menu order. */
    const ATTEMPT_ACTIONS = [
        self::ACTION_PASS, self::ACTION_FAIL, self::ACTION_CHEAT, self::ACTION_UNCHEAT,
        self::ACTION_DELETE, self::ACTION_RESTORE,
    ];

    /** @var string[] The level fields a teacher can set from the student summary. */
    const LEVEL_FIELDS = ['currentlevel', 'stoplevel', 'allowpromotion', 'goal'];

    /**
     * Apply one action to several attempts of an activity.
     *
     * @param reader $reader
     * @param int[] $attemptids Ids from the form; ids of other activities are ignored.
     * @param string $action One of the ACTION_ constants.
     * @return int Number of attempts changed.
     */
    public static function update(reader $reader, array $attemptids, string $action): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/moodlereader/lib.php');

        $changes = [
            self::ACTION_PASS    => ['passed' => 1],
            self::ACTION_FAIL    => ['passed' => 0, 'credit' => 0],
            self::ACTION_CHEAT   => ['cheated' => 1],
            self::ACTION_UNCHEAT => ['cheated' => 0],
            self::ACTION_DELETE  => ['deleted' => 1],
            self::ACTION_RESTORE => ['deleted' => 0],
        ];
        if (!isset($changes[$action])) {
            throw new \coding_exception("Unknown attempt action '$action'");
        }
        $attemptids = array_values(array_unique(array_filter(array_map('intval', $attemptids))));
        if (!$attemptids) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED, 'att');
        $params['readerid'] = $reader->get_instance()->id;
        $attempts = $DB->get_records_select('moodlereader_attempts', "id $insql AND readerid = :readerid", $params);
        $visible = self::visible_userids($reader, array_unique(array_column($attempts, 'userid')));

        $now = time();
        $users = [];
        $changedcount = 0;
        foreach ($attempts as $attempt) {
            if (!isset($visible[$attempt->userid])) {
                continue;
            }
            // Pass/fail applies to finished attempts only: an in-progress one still waits for mreader.org.
            if (in_array($action, [self::ACTION_PASS, self::ACTION_FAIL], true) && $attempt->state !== 'finished') {
                continue;
            }
            $changed = false;
            foreach ($changes[$action] as $field => $value) {
                if ((int) $attempt->$field !== $value) {
                    $attempt->$field = $value;
                    $changed = true;
                }
            }
            if (!$changed) {
                continue;
            }
            $attempt->timemodified = $now;
            $DB->update_record('moodlereader_attempts', $attempt);
            \mod_moodlereader\event\attempt_updated::create_from_attempt($reader, $attempt, $action)->trigger();
            $users[$attempt->userid] = true;
            $changedcount++;
        }
        self::regrade($reader, array_keys($users));
        return $changedcount;
    }

    /**
     * Set a reading-level field or the reading goal for several students.
     *
     * @param reader $reader
     * @param int[] $userids
     * @param string $field One of LEVEL_FIELDS.
     * @param int $value A level 0-99 (a stop level of 0 means none), 0/1 for allowpromotion,
     *     or a goal (0 = the activity's default).
     * @return int Number of students changed.
     */
    public static function set_level_field(reader $reader, array $userids, string $field, int $value): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/moodlereader/lib.php');
        if (!in_array($field, self::LEVEL_FIELDS, true)) {
            throw new \coding_exception("Unknown level field '$field'");
        }
        if ($field === 'goal') {
            $value = max(0, min(9999999, $value));
        } else if ($field === 'allowpromotion') {
            $value = $value ? 1 : 0;
        } else {
            $value = max(0, min(99, $value));
        }
        // A level set by hand is a fresh start: books are counted towards promotion from now.
        $restart = $field === 'currentlevel' ? ['time' => time()] : [];
        $readerid = $reader->get_instance()->id;
        $count = 0;
        foreach (array_keys(self::visible_userids($reader, $userids)) as $userid) {
            $level = $DB->get_record('moodlereader_levels', ['readerid' => $readerid, 'userid' => $userid]);
            if (!$level) {
                $level = $reader->get_level($userid);
                unset($level->id);
                $level->goal = 0;
                $level->$field = $value;
                $level->time = time();
                $DB->insert_record('moodlereader_levels', $level);
                $count++;
            } else if ((int) $level->$field !== $value) {
                $DB->update_record('moodlereader_levels', (object) (['id' => $level->id, $field => $value] + $restart));
                $count++;
            }
        }
        if ($field === 'goal' && $count) {
            self::regrade($reader, array_keys(self::visible_userids($reader, $userids)));
        }
        return $count;
    }

    /**
     * Give several students credit for a book, as if they had passed its quiz.
     *
     * The book may have no quiz, or a withdrawn one, but must not be disabled. A student
     * who already has a counted attempt at the book is skipped.
     *
     * @param reader $reader
     * @param int[] $userids
     * @param int $bookid A catalogue book.
     * @return int Number of students credited.
     */
    public static function award_book(reader $reader, array $userids, int $bookid): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/moodlereader/lib.php');
        // A book disabled in Manage books is offered to no one, not even as credit.
        $book = $DB->get_record('moodlereader_books', ['id' => $bookid, 'hidden' => 0], '*', MUST_EXIST);
        $readerid = $reader->get_instance()->id;
        $now = time();
        $credited = [];
        foreach (array_keys(self::visible_userids($reader, $userids)) as $userid) {
            $counted = $DB->record_exists_select(
                'moodlereader_attempts',
                'readerid = ? AND userid = ? AND bookid = ? AND deleted = 0 AND cheated = 0 AND (passed = 1 OR credit = 1)',
                [$readerid, $userid, $book->id]
            );
            if ($counted) {
                continue;
            }
            $attempt = (object) [
                'readerid' => $readerid, 'userid' => $userid, 'bookid' => $book->id,
                'attempt' => $DB->count_records('moodlereader_attempts', [
                    'readerid' => $readerid, 'userid' => $userid, 'bookid' => $book->id,
                ]) + 1,
                'state' => 'finished', 'percentgrade' => 100, 'passed' => 1, 'credit' => 1,
                'cheated' => 0, 'deleted' => 0, 'bookrating' => 0,
                'timestart' => $now, 'timefinish' => $now, 'timemodified' => $now,
                // Not a quiz: no address, so cheating detection never compares it.
                'ip' => '', 'attemptref' => null, 'remotestate' => null, 'timeresultchecked' => 0,
                'delivery' => 'iframe', 'timelimit' => 0, 'slots' => 0, 'currentslot' => 0, 'secondsused' => 0,
                'timeserved' => 0, 'timeheartbeat' => 0,
            ];
            $attempt->id = $DB->insert_record('moodlereader_attempts', $attempt);
            \mod_moodlereader\event\attempt_updated::create_from_attempt($reader, $attempt, 'award')->trigger();
            $credited[] = $userid;
        }
        self::regrade($reader, $credited);
        return count($credited);
    }

    /**
     * Search the books a teacher may award credit for: every book not disabled in Manage books,
     * including books without a quiz and books whose quiz mreader.org withdrew.
     *
     * Filters (all optional): 'name' and 'series' (partial, case-insensitive), 'publisher'
     * (exact), 'difficulty' (a value below 0 means any) and 'language' (a book whose language
     * is unknown always matches).
     *
     * @param array $filters
     * @param int $limit Maximum rows to return.
     * @param int|null $total Set to the full match count.
     * @return \stdClass[] Keyed by book id.
     */
    public static function search_award_books(array $filters, int $limit = 100, ?int &$total = null): array {
        global $DB;
        $where = ['hidden = 0'];
        $params = [];
        foreach (['name', 'series'] as $field) {
            $value = trim((string) ($filters[$field] ?? ''));
            if ($value !== '') {
                $where[] = $DB->sql_like($field, ":$field", false, false);
                $params[$field] = '%' . $DB->sql_like_escape($value) . '%';
            }
        }
        if ((string) ($filters['publisher'] ?? '') !== '') {
            $where[] = 'publisher = :publisher';
            $params['publisher'] = (string) $filters['publisher'];
        }
        if (is_numeric($filters['difficulty'] ?? null) && (int) $filters['difficulty'] >= 0) {
            $where[] = 'difficulty = :difficulty';
            $params['difficulty'] = (int) $filters['difficulty'];
        }
        $language = booklanguage::clean((string) ($filters['language'] ?? ''));
        if ($language !== '') {
            $where[] = "(language = :language OR language = '')";
            $params['language'] = $language;
        }
        $wheresql = implode(' AND ', $where);
        $total = $DB->count_records_select('moodlereader_books', $wheresql, $params);
        return $DB->get_records_select('moodlereader_books', $wheresql, $params, 'publisher, series, name', '*', 0, $limit);
    }

    /**
     * Post a message to several students on the activity page.
     *
     * @param reader $reader
     * @param int[] $userids
     * @param string $text Plain text.
     * @return int Number of students messaged.
     */
    public static function message(reader $reader, array $userids, string $text): int {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        $count = 0;
        foreach (array_keys(self::visible_userids($reader, $userids)) as $userid) {
            message::create($reader->get_instance()->id, $userid, $text, FORMAT_PLAIN);
            $count++;
        }
        return $count;
    }

    /**
     * The people of the activity the current user may act on, among the given ids.
     *
     * See {@see self::students_sql()}: the activity's students, and everyone with attempts here
     * (teachers and administrators included), limited to the teacher's groups in separate-groups mode.
     *
     * @param reader $reader
     * @param int[] $userids
     * @return array user id => true
     */
    public static function visible_userids(reader $reader, array $userids): array {
        global $DB;
        $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
        if (!$userids) {
            return [];
        }
        [$esql, $eparams] = self::students_sql($reader);
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'vu');
        $ids = $DB->get_fieldset_sql("SELECT u.id FROM {user} u WHERE u.id $insql AND u.id IN ($esql)", $inparams + $eparams);
        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * SQL selecting the ids of the people the current user may see in this activity's reports.
     *
     * That is the activity's students, plus everyone who has taken a quiz here: teachers and
     * administrators try the quizzes too (for example to show students how the progress bar
     * fills). In separate-groups mode a teacher without access to all groups sees their own
     * groups' students and their own record.
     *
     * @param reader $reader
     * @param int|null $groupid A group chosen in the report, or null for the activity's current group.
     * @return array [string $sql, array $params]
     */
    public static function students_sql(reader $reader, ?int $groupid = null): array {
        global $USER;
        static $n = 0;
        $n++;
        $cm = $reader->get_cm();
        $context = $reader->get_context();
        if ($groupid === null) {
            $groupid = (int) groups_get_activity_group($cm, false);
        }
        $groups = $groupid;
        $mode = groups_get_activity_groupmode($cm);
        if ($mode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context) && !$groupid) {
            // Without access to all groups, "all participants" means the teacher's own groups.
            $groups = array_keys(groups_get_all_groups($cm->course, $USER->id, $cm->groupingid)) ?: [-1];
        }
        [$esql, $eparams] = get_enrolled_sql($context, 'mod/moodlereader:attempt', $groups, true);

        // Everyone with attempts here when no group narrows the list; otherwise only oneself.
        $params = $eparams + ["sxreader$n" => $reader->get_instance()->id];
        $others = "SELECT sxa$n.userid FROM {moodlereader_attempts} sxa$n WHERE sxa$n.readerid = :sxreader$n";
        if ($groups !== 0) {
            $others .= " AND sxa$n.userid = :sxself$n";
            $params["sxself$n"] = (int) $USER->id;
        }
        return ["$esql UNION $others", $params];
    }

    /**
     * Regrade students and refresh their activity completion.
     *
     * @param reader $reader
     * @param int[] $userids
     * @return void
     */
    protected static function regrade(reader $reader, array $userids): void {
        $completion = new \completion_info($reader->get_course());
        $tracked = $completion->is_enabled($reader->get_cm());
        foreach ($userids as $userid) {
            moodlereader_update_grades($reader->get_instance(), (int) $userid);
            if ($tracked) {
                $completion->update_state($reader->get_cm(), COMPLETION_UNKNOWN, (int) $userid);
            }
        }
    }
}
