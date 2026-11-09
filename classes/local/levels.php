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
 * Reading levels: how many books a student may read at each level, and promotion.
 *
 * When an activity restricts books to the reading level, three numbers set the rules:
 *
 * - thislevel: books to pass at the student's own level to move up a level (0 = no
 *   limit at the own level, and no promotion);
 * - prevlevel: books the student may read one level below (0 = none), or, when lowerlevels is set, at
 *   each of the levels below theirs, down to level 0 (mreader.org's rule);
 * - nextlevel: books the student may read one level above (0 = none).
 *
 * Only passed quizzes use up a level's allowance (a failed one does not), counted from
 * the student's last promotion (their level record's time) and within this term. When
 * the passes at the student's level reach thislevel, the student moves up a level,
 * unless promotion is switched off for them or they are at their stop level, and the
 * counts start again. This is mod_reader's scheme as mreader.org now applies it.
 *
 * @package    mod_moodlereader
 * @copyright  2013 Gordon Bateson (gordon.bateson@gmail.com)
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class levels {
    /** @var int A level's allowance with no limit. */
    const UNLIMITED = -1;

    /**
     * Passed quizzes per book level since the student's last promotion.
     *
     * @param reader $reader
     * @param int $userid
     * @param int $since The student's last promotion.
     * @return int[] level => passes
     */
    public static function passes(reader $reader, int $userid, int $since): array {
        global $DB;
        $instance = $reader->get_instance();
        // A per-activity book list can give a book its own level here.
        $difficulty = empty($instance->bookinstances) ? 'b.difficulty' : 'COALESCE(bi.difficulty, b.difficulty)';
        $bijoin = empty($instance->bookinstances) ? ''
            : 'LEFT JOIN {moodlereader_bookinstances} bi ON bi.bookid = b.id AND bi.readerid = a.readerid';
        $rows = $DB->get_records_sql(
            "SELECT $difficulty AS level, COUNT(1) AS passes
               FROM {moodlereader_attempts} a
               JOIN {moodlereader_books} b ON b.id = a.bookid
               $bijoin
              WHERE a.readerid = :readerid AND a.userid = :userid AND a.state = 'finished'
                    AND a.passed = 1 AND a.credit = 0 AND a.cheated = 0 AND a.deleted = 0
                    AND a.timefinish > :since AND a.timefinish >= :ignoredate
           GROUP BY $difficulty",
            ['readerid' => $instance->id, 'userid' => $userid, 'since' => $since, 'ignoredate' => (int) $instance->ignoredate]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->level] = (int) $row->passes;
        }
        return $out;
    }

    /**
     * A student's level and what they may still read at each level.
     *
     * @param reader $reader
     * @param int $userid
     * @return \stdClass level, stoplevel, attop, promotion (bool), allowpromotion (the teacher's switch),
     *     columns (each: level, quota,
     *     used, remaining, relation -1/0/1), remainingtopromotion (null when there is no
     *     promotion to work towards).
     */
    public static function status(reader $reader, int $userid): \stdClass {
        $instance = $reader->get_instance();
        $record = $reader->get_level($userid);
        $level = max(0, (int) $record->currentlevel);
        $stoplevel = (int) $record->stoplevel;
        $passes = self::passes($reader, $userid, (int) $record->time);
        $thislevel = max(0, (int) $instance->thislevel);
        $prevlevel = max(0, (int) $instance->prevlevel);
        $nextlevel = max(0, (int) $instance->nextlevel);
        $attop = $stoplevel > 0 && $level >= $stoplevel;
        $promotion = $thislevel > 0 && !empty($record->allowpromotion) && !$attop;

        $column = function (int $at, int $quota, int $relation) use ($passes): \stdClass {
            $used = $passes[$at] ?? 0;
            return (object) [
                'level' => $at, 'quota' => $quota, 'used' => $used, 'relation' => $relation,
                'remaining' => $quota === self::UNLIMITED ? self::UNLIMITED : max(0, $quota - $used),
            ];
        };
        $columns = [];
        if ($prevlevel > 0 && $level > 0) {
            // Every level below, lowest first, or only the one just below.
            $lowest = empty($instance->lowerlevels) ? $level - 1 : 0;
            for ($at = $lowest; $at < $level; $at++) {
                $columns[] = $column($at, $prevlevel, -1);
            }
        }
        // Without promotion (none set, switched off, or the stop level reached) the own level has no limit.
        $columns[] = $column($level, $promotion ? $thislevel : self::UNLIMITED, 0);
        if ($nextlevel > 0 && !$attop) {
            $columns[] = $column($level + 1, $nextlevel, 1);
        }
        return (object) [
            'level' => $level,
            'stoplevel' => $stoplevel,
            'attop' => $attop,
            'promotion' => $promotion,
            'allowpromotion' => !empty($record->allowpromotion),
            'columns' => $columns,
            'remainingtopromotion' => $promotion ? max(0, $thislevel - ($passes[$level] ?? 0)) : null,
        ];
    }

    /**
     * The book levels a student may attempt now.
     *
     * @param reader $reader
     * @param int $userid
     * @return int[] Empty when the activity does not restrict books to the reading level.
     */
    public static function allowed(reader $reader, int $userid): array {
        if (empty($reader->get_instance()->levelcheck)) {
            return [];
        }
        $allowed = [];
        foreach (self::status($reader, $userid)->columns as $column) {
            if ($column->remaining !== 0) {
                $allowed[] = $column->level;
            }
        }
        return $allowed;
    }

    /**
     * Move a student up a level when they have passed enough books at their level.
     *
     * Called when a quiz result arrives. Promotes at most one level per call, so a
     * teacher who lowers a level is not undone by old passes (the count starts again
     * from the promotion).
     *
     * @param reader $reader
     * @param int $userid
     * @return bool Whether the student was promoted.
     */
    public static function maybe_promote(reader $reader, int $userid): bool {
        global $DB;
        if (empty($reader->get_instance()->levelcheck)) {
            return false;
        }
        $status = self::status($reader, $userid);
        if (!$status->promotion || $status->remainingtopromotion > 0) {
            return false;
        }
        $record = $reader->get_level($userid);
        $record->currentlevel = $status->level + 1;
        $record->time = time();
        // Told to the student once, on their reading page (stored plus one, so a move from level 0 is 1).
        $record->promoted = $status->level + 1;
        if (empty($record->id)) {
            $record->goal = 0;
            $DB->insert_record('moodlereader_levels', $record);
        } else {
            $DB->update_record('moodlereader_levels', $record);
        }
        return true;
    }

    /**
     * The move up a student has not been told about yet, cleared as it is read.
     *
     * @param reader $reader
     * @param int $userid
     * @return \stdClass|null from and to levels, or null when there is nothing to tell.
     */
    public static function take_promotion_notice(reader $reader, int $userid): ?\stdClass {
        global $DB;
        $record = $DB->get_record(
            'moodlereader_levels',
            ['readerid' => $reader->get_instance()->id, 'userid' => $userid],
            'id, currentlevel, promoted',
            IGNORE_MULTIPLE
        );
        if (!$record || (int) $record->promoted <= 0) {
            return null;
        }
        $DB->set_field('moodlereader_levels', 'promoted', 0, ['id' => $record->id]);
        $from = (int) $record->promoted - 1;
        // A teacher may have changed the level since: tell only a move that still stands.
        if ((int) $record->currentlevel <= $from) {
            return null;
        }
        return (object) ['from' => $from, 'to' => (int) $record->currentlevel];
    }
}
