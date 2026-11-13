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
 * Wait times between quizzes: a student may start no more than a number of quizzes within a time.
 *
 * An activity has a default rule (level -1) and may have a rule for any reading level; the rule for the
 * level wins over the default. The level is the student's reading level (as mod_reader's "quiz attempt
 * rate"), or, when the activity says so, the level of the book whose quiz is next, and then only quizzes
 * at that level count. Quizzes started in this activity count, whatever their result; deleted ones do not.
 * Teachers trying a quiz never wait.
 *
 * @package    mod_moodlereader
 * @copyright  2013 Gordon Bateson (gordon.bateson@gmail.com)
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class waits {
    /** @var int The level of the default rule. */
    const DEFAULT_LEVEL = -1;

    /** @var int Rules are picked by the student's reading level. */
    const BY_STUDENT = 0;

    /** @var int Rules are picked by the level of the book whose quiz is next. */
    const BY_BOOK = 1;

    /** @var int Most quizzes a rule can allow within its time. */
    const MAX_ATTEMPTS = 20;

    /** @var int Longest time a rule can set: 30 days. */
    const MAX_DURATION = 30 * DAYSECS;

    /**
     * An activity's rules.
     *
     * @param int $readerid
     * @return \stdClass[] level => rule (level, attempts, duration), the default under DEFAULT_LEVEL.
     */
    public static function rules(int $readerid): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records('moodlereader_waits', ['readerid' => $readerid], 'level') as $rule) {
            if ((int) $rule->duration > 0 && (int) $rule->attempts > 0) {
                $out[(int) $rule->level] = $rule;
            }
        }
        return $out;
    }

    /**
     * Replace an activity's rules.
     *
     * @param int $readerid
     * @param array $rules level => [attempts, duration]; a rule without a time or a quiz count is dropped.
     * @return void
     */
    public static function save(int $readerid, array $rules): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('moodlereader_waits', ['readerid' => $readerid]);
        foreach ($rules as $level => [$attempts, $duration]) {
            $level = (int) $level;
            $attempts = (int) $attempts;
            $duration = (int) $duration;
            if ($level < self::DEFAULT_LEVEL || $level > 99 || $attempts < 1 || $duration < 1) {
                continue;
            }
            $DB->insert_record('moodlereader_waits', (object) [
                'readerid' => $readerid,
                'level' => $level,
                'attempts' => min(self::MAX_ATTEMPTS, $attempts),
                'duration' => min(self::MAX_DURATION, $duration),
                'timemodified' => time(),
            ]);
        }
        $transaction->allow_commit();
    }

    /**
     * When a student may start their next quiz.
     *
     * @param reader $reader
     * @param int $userid
     * @param int|null $booklevel The level of the book whose quiz is next (its level in this activity); used
     *     when the activity picks rules by the book's level.
     * @param int|null $now
     * @return int A time in the future, or 0 when the student need not wait.
     */
    public static function until(reader $reader, int $userid, ?int $booklevel = null, ?int $now = null): int {
        global $DB;
        $now = $now ?? time();
        $instance = $reader->get_instance();
        $rules = self::rules((int) $instance->id);
        if (!$rules) {
            return 0;
        }
        $bybook = (int) ($instance->waitlevel ?? 0) === self::BY_BOOK;
        if ($bybook) {
            if ($booklevel === null) {
                return 0;
            }
            $level = $booklevel;
        } else {
            $level = max(0, (int) $reader->get_level($userid)->currentlevel);
        }
        $rule = $rules[$level] ?? $rules[self::DEFAULT_LEVEL] ?? null;
        if (!$rule) {
            return 0;
        }

        $params = ['readerid' => $instance->id, 'userid' => $userid, 'since' => $now - (int) $rule->duration];
        $join = '';
        $levelsql = '';
        if ($bybook) {
            $difficulty = empty($instance->bookinstances) ? 'b.difficulty' : 'COALESCE(bi.difficulty, b.difficulty)';
            $join = 'JOIN {moodlereader_books} b ON b.id = a.bookid' . (empty($instance->bookinstances) ? ''
                : ' LEFT JOIN {moodlereader_bookinstances} bi ON bi.bookid = b.id AND bi.readerid = a.readerid');
            $levelsql = " AND $difficulty = :level";
            $params['level'] = $level;
        }
        $starts = $DB->get_fieldset_sql(
            "SELECT a.timestart
               FROM {moodlereader_attempts} a
               $join
              WHERE a.readerid = :readerid AND a.userid = :userid AND a.deleted = 0
                    AND a.timestart > :since$levelsql
           ORDER BY a.timestart",
            $params
        );
        $count = count($starts);
        if ($count < (int) $rule->attempts) {
            return 0;
        }
        // The window frees a place when the oldest quiz that keeps it full leaves it.
        $until = (int) $starts[$count - (int) $rule->attempts] + (int) $rule->duration;
        return $until > $now ? $until : 0;
    }

    /**
     * A book's level in an activity (its own level there, when the activity's book list gives it one).
     *
     * @param reader $reader
     * @param int $bookid
     * @return int
     */
    public static function book_level(reader $reader, int $bookid): int {
        global $DB;
        $instance = $reader->get_instance();
        if (!empty($instance->bookinstances)) {
            $own = $DB->get_field('moodlereader_bookinstances', 'difficulty', ['readerid' => $instance->id, 'bookid' => $bookid]);
            if ($own !== false && $own !== null) {
                return (int) $own;
            }
        }
        return (int) $DB->get_field('moodlereader_books', 'difficulty', ['id' => $bookid]);
    }

    /**
     * Whether a user never waits here: a teacher trying a quiz.
     *
     * @param reader $reader
     * @return bool
     */
    public static function exempt(reader $reader): bool {
        return $reader->can('viewreports');
    }

    /**
     * The message for a student who must wait.
     *
     * @param int $until
     * @param int|null $now
     * @return string
     */
    public static function message(int $until, ?int $now = null): string {
        $now = $now ?? time();
        return get_string('waituntil', 'mod_moodlereader', (object) [
            'time' => userdate($until, get_string('strftimedatetimeshort', 'langconfig')),
            'left' => format_time(max(60, $until - $now)),
        ]);
    }
}
