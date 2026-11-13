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

namespace mod_moodlereader;

use mod_moodlereader\local\eligibility;
use mod_moodlereader\local\reader;
use mod_moodlereader\local\waits;

/**
 * Wait times between quizzes (GitHub issue #4).
 *
 * @package    mod_moodlereader
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\waits::class)]
final class waits_test extends \advanced_testcase {
    /**
     * An activity, a student at level 4 and books at levels 2 and 4.
     *
     * @return array [reader, student, books by level]
     */
    protected function setup_activity(): array {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $instance = $gen->create_instance(['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $books = [];
        foreach ([2, 4] as $level) {
            for ($i = 0; $i < 3; $i++) {
                $books[$level][] = $gen->create_book(['difficulty' => $level]);
            }
        }
        $DB->insert_record('moodlereader_levels', (object) ['readerid' => $instance->id, 'userid' => $student->id,
            'goal' => 0, 'startlevel' => 4, 'currentlevel' => 4, 'stoplevel' => 0, 'allowpromotion' => 1, 'time' => 0]);
        return [reader::from_instanceid($instance->id), $student, $books];
    }

    /**
     * Record an attempt started at a time.
     *
     * @param reader $reader
     * @param int $userid
     * @param \stdClass $book
     * @param int $timestart
     * @param int $deleted
     * @return void
     */
    protected function attempt(reader $reader, int $userid, \stdClass $book, int $timestart, int $deleted = 0): void {
        global $DB;
        $DB->insert_record('moodlereader_attempts', (object) [
            'readerid' => $reader->get_instance()->id, 'userid' => $userid, 'bookid' => $book->id, 'attempt' => 1,
            'state' => 'finished', 'percentgrade' => 80, 'passed' => 1, 'credit' => 0, 'cheated' => 0,
            'deleted' => $deleted, 'bookrating' => 0, 'timestart' => $timestart, 'timefinish' => $timestart + 300,
            'timemodified' => $timestart + 300, 'ip' => '',
        ]);
    }

    /**
     * By the student's level: the level's own rule wins over the default; deleted attempts do not count.
     *
     * @return void
     */
    public function test_by_student_level(): void {
        [$reader, $student, $books] = $this->setup_activity();
        $now = time();
        $this->assertSame(0, waits::until($reader, $student->id, null, $now));

        waits::save($reader->get_instance()->id, [
            waits::DEFAULT_LEVEL => [1, HOURSECS],
            4 => [1, 3 * HOURSECS],
        ]);
        $this->assertSame([-1, 4], array_keys(waits::rules($reader->get_instance()->id)));

        $this->attempt($reader, $student->id, $books[2][0], $now - 2 * HOURSECS, 1);
        $this->assertSame(0, waits::until($reader, $student->id, null, $now));

        // Two hours after a quiz, a level 4 student waits one more hour (3 hours at level 4).
        $this->attempt($reader, $student->id, $books[2][1], $now - 2 * HOURSECS);
        $this->assertSame($now + HOURSECS, waits::until($reader, $student->id, null, $now));

        // Without the level 4 rule, the default of one hour has passed.
        waits::save($reader->get_instance()->id, [waits::DEFAULT_LEVEL => [1, HOURSECS]]);
        $this->assertSame(0, waits::until($reader, $student->id, null, $now));
    }

    /**
     * Several quizzes within a time: the wait ends when the oldest of them leaves the window.
     *
     * @return void
     */
    public function test_several_quizzes_within(): void {
        [$reader, $student, $books] = $this->setup_activity();
        $now = time();
        waits::save($reader->get_instance()->id, [waits::DEFAULT_LEVEL => [2, 4 * HOURSECS]]);
        $this->attempt($reader, $student->id, $books[4][0], $now - 3 * HOURSECS);
        $this->assertSame(0, waits::until($reader, $student->id, null, $now));
        $this->attempt($reader, $student->id, $books[4][1], $now - HOURSECS);
        $this->assertSame($now + HOURSECS, waits::until($reader, $student->id, null, $now));
        $this->attempt($reader, $student->id, $books[4][2], $now - 600);
        // The two newest keep the window full until the older of them is 4 hours old.
        $this->assertSame($now + 3 * HOURSECS, waits::until($reader, $student->id, null, $now));
    }

    /**
     * By the book's level: only quizzes at that level count, and that level's rule applies.
     *
     * @return void
     */
    public function test_by_book_level(): void {
        global $DB;
        [$reader, $student, $books] = $this->setup_activity();
        $DB->set_field('moodlereader', 'waitlevel', waits::BY_BOOK, ['id' => $reader->get_instance()->id]);
        $reader = reader::from_instanceid($reader->get_instance()->id);
        $now = time();
        waits::save($reader->get_instance()->id, [2 => [1, HOURSECS], 4 => [1, 4 * HOURSECS]]);
        $this->attempt($reader, $student->id, $books[4][0], $now - 2 * HOURSECS);

        $this->assertSame($now + 2 * HOURSECS, waits::until($reader, $student->id, 4, $now));
        // The level 4 quiz does not hold back a level 2 book.
        $this->assertSame(0, waits::until($reader, $student->id, 2, $now));
        $this->assertSame(4, waits::book_level($reader, $books[4][1]->id));

        // The teacher's view of the student says why (on a connected site).
        set_config('v2siteid', 'site-1', 'mod_moodlereader');
        set_config('v2secret', 'Zm9vYmFyLWV4YW1wbGUtc2VjcmV0LTMyLWJ5dGVzISE', 'mod_moodlereader');
        set_config('v2state', 'approved', 'mod_moodlereader');
        $this->setAdminUser();
        $eligibility = new eligibility($reader, $student->id);
        $book = $DB->get_record('moodlereader_books', ['id' => $books[4][1]->id]);
        $this->assertStringContainsString('You can take your next quiz at', (string) $eligibility->reason($book));
    }

    /**
     * Saved rules are held to their ranges, and rules without a time are dropped.
     *
     * @return void
     */
    public function test_save_clamps(): void {
        [$reader] = $this->setup_activity();
        waits::save($reader->get_instance()->id, [
            waits::DEFAULT_LEVEL => [500, 365 * DAYSECS],
            3 => [1, 0],
            150 => [1, HOURSECS],
        ]);
        $rules = waits::rules($reader->get_instance()->id);
        $this->assertSame([-1], array_keys($rules));
        $this->assertEquals(waits::MAX_ATTEMPTS, $rules[-1]->attempts);
        $this->assertEquals(waits::MAX_DURATION, $rules[-1]->duration);
    }
}
