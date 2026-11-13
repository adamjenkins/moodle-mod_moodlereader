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
 * Whether a student may take a book's quiz now, and if not, why.
 *
 * The rules are the ones the student's own book search applies
 * ({@see reader::available_where()}); this class states the reason for a book the
 * search would leave out, for the teacher's view of a student's reading page.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class eligibility {
    /** @var reader */
    protected $reader;

    /** @var \stdClass[] Finished, not deleted attempts in any activity by book id (the latest). */
    protected $finished = [];

    /** @var array Same-title keys of finished books => true. */
    protected $titles = [];

    /** @var int[] Books with an unfinished attempt. */
    protected $inprogress = [];

    /** @var \stdClass|null Level status, when the activity restricts books to the level. */
    protected $levels = null;

    /** @var int The student. */
    protected $userid;

    /** @var int[] Wait times between quizzes: level => when the next quiz may start (0 = now). */
    protected $waits = [];

    /** @var bool */
    protected $connected;

    /** @var bool */
    protected $open;

    /**
     * Load what the rules need for one student.
     *
     * @param reader $reader
     * @param int $userid
     */
    public function __construct(reader $reader, int $userid) {
        global $DB;
        $this->reader = $reader;
        $this->userid = $userid;
        $attempts = $DB->get_records_sql(
            "SELECT a.id, a.bookid, a.state, a.passed, a.credit, a.cheated, a.timefinish, b.sametitle
               FROM {moodlereader_attempts} a
               JOIN {moodlereader_books} b ON b.id = a.bookid
              WHERE a.userid = :userid AND a.deleted = 0
                    AND (a.readerid = :readerid OR a.state = 'finished')
           ORDER BY a.timefinish, a.id",
            ['readerid' => $reader->get_instance()->id, 'userid' => $userid]
        );
        // Books used up for life (their attempts went with a course reset or a deleted activity) count as finished.
        $used = $DB->get_records_sql(
            "SELECT u.id, u.bookid, 'finished' AS state, u.passed, u.credit, u.cheated, u.timefinish, b.sametitle
               FROM {moodlereader_booksused} u
               JOIN {moodlereader_books} b ON b.id = u.bookid
              WHERE u.userid = :userid
           ORDER BY u.timefinish, u.id",
            ['userid' => $userid]
        );
        $attempts = array_merge(array_values($used), array_values($attempts));
        // A finished attempt in any reading activity uses the book up; an unfinished one only here (to resume it).
        foreach ($attempts as $a) {
            if ($a->state === 'finished') {
                $this->finished[$a->bookid] = $a;
                if ($a->sametitle !== '') {
                    $this->titles[$a->sametitle] = true;
                }
            } else {
                $this->inprogress[$a->bookid] = true;
            }
        }
        if (!empty($reader->get_instance()->levelcheck)) {
            $this->levels = levels::status($reader, $userid);
        }
        $this->connected = connection::allows_launch();
        $this->open = $reader->within_availability_window();
    }

    /**
     * The reason the student cannot take this book's quiz now.
     *
     * @param \stdClass $book A {moodlereader_books} record (with a per-activity difficulty, if any, in difficulty).
     * @return string|null Null when the student can take it.
     */
    public function reason(\stdClass $book): ?string {
        $str = fn(string $key, $a = null) => get_string('cannot_' . $key, 'mod_moodlereader', $a);
        if (!$this->connected) {
            return $str('notconnected');
        }
        if (!$this->open) {
            return $str('closed');
        }
        if (empty($book->hasquiz)) {
            return $str('noquiz');
        }
        if (!empty($book->withdrawn)) {
            return $str('withdrawn');
        }
        if (isset($this->finished[$book->id])) {
            $a = $this->finished[$book->id];
            $result = $a->cheated ? 'cheated' : ($a->credit ? 'credited' : ($a->passed ? 'passed' : 'failed'));
            return $str('alreadytaken', [
                'result' => get_string($result, 'mod_moodlereader'),
                'date' => userdate($a->timefinish, get_string('strftimedate', 'langconfig')),
            ]);
        }
        if ((string) $book->sametitle !== '' && isset($this->titles[$book->sametitle])) {
            return $str('sametitle');
        }
        if ($this->levels !== null) {
            $level = (int) $book->difficulty;
            $column = null;
            foreach ($this->levels->columns as $each) {
                if ($each->level === $level) {
                    $column = $each;
                }
            }
            if ($column === null) {
                return $str('level', ['level' => $level, 'own' => $this->levels->level]);
            }
            if ($column->remaining === 0) {
                return $str('levelused', ['level' => $level, 'quota' => $column->quota]);
            }
        }
        // Wait times between quizzes (computed once per level); a quiz already started can be resumed.
        $booklevel = (int) $book->difficulty;
        $this->waits[$booklevel] ??= waits::until($this->reader, $this->userid, $booklevel);
        if ($this->waits[$booklevel] > 0 && !$this->in_progress((int) $book->id)) {
            return waits::message($this->waits[$booklevel]);
        }
        return null;
    }

    /**
     * Whether the student has started this book's quiz without finishing it.
     *
     * @param int $bookid
     * @return bool
     */
    public function in_progress(int $bookid): bool {
        return isset($this->inprogress[$bookid]);
    }
}
