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

/**
 * Tests for the reasons a student cannot take a quiz (teacher's view of a reading page).
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\eligibility::class)]
final class eligibility_test extends \advanced_testcase {
    /**
     * Each reason, and agreement with the student's own search.
     *
     * @return void
     */
    public function test_reasons_match_the_student_search(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('v2siteid', 'site-1', 'mod_moodlereader');
        set_config('v2secret', 'Zm9vYmFyLWV4YW1wbGUtc2VjcmV0LTMyLWJ5dGVzISE', 'mod_moodlereader');
        set_config('v2state', 'approved', 'mod_moodlereader');
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $instance = $gen->create_instance(['course' => $course->id, 'levelcheck' => 1, 'thislevel' => 3,
            'prevlevel' => 0, 'nextlevel' => 1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $reader = reader::from_instanceid($instance->id);
        $book = fn(array $r) => $gen->create_book($r + ['difficulty' => 0]);
        $books = [
            'free'      => $book([]),
            'read'      => $book(['sametitle' => 'T1']),
            'edition'   => $book(['sametitle' => 'T1']),
            'noquiz'    => $book(['hasquiz' => 0]),
            'withdrawn' => $book(['withdrawn' => 1]),
            'up1'       => $book(['difficulty' => 1]),
            'up1b'      => $book(['difficulty' => 1]),
            'up2'       => $book(['difficulty' => 2]),
        ];
        $now = time();
        foreach (['read' => 1, 'up1' => 1] as $key => $passed) {
            $DB->insert_record('moodlereader_attempts', (object) [
                'readerid' => $instance->id, 'userid' => $student->id, 'bookid' => $books[$key]->id, 'attempt' => 1,
                'state' => 'finished', 'percentgrade' => 90, 'passed' => $passed, 'credit' => 0, 'cheated' => 0,
                'deleted' => 0, 'bookrating' => 0, 'timestart' => $now - 600, 'timefinish' => $now,
                'timemodified' => $now, 'ip' => '',
            ]);
        }

        $eligibility = new eligibility($reader, (int) $student->id);
        $reasons = array_map(fn($b) => $eligibility->reason($DB->get_record('moodlereader_books', ['id' => $b->id])), $books);
        $this->assertNull($reasons['free']);
        $this->assertStringStartsWith('Already taken: Passed', $reasons['read']);
        $this->assertSame('They have already read another edition of this title.', $reasons['edition']);
        $this->assertSame('This book has no quiz.', $reasons['noquiz']);
        $this->assertSame('mreader.org has withdrawn this quiz.', $reasons['withdrawn']);
        $this->assertStringStartsWith('Already taken', $reasons['up1']);
        $this->assertSame('They have passed all 1 book(s) allowed at level 1 for now.', $reasons['up1b']);
        $this->assertSame('Level 2 is not one they may read at (their level is 0).', $reasons['up2']);

        // Exactly the books without a reason are the ones the student's own search offers, plus a book without a
        // quiz, which is listed so the student can ask the teacher for credit.
        $offered = array_keys($reader->search_books((int) $student->id, []));
        $free = array_map(fn($k) => (int) $books[$k]->id, array_keys(array_filter($reasons, fn($r) => $r === null)));
        $free[] = (int) $books['noquiz']->id;
        sort($offered);
        sort($free);
        $this->assertEquals($free, $offered);

        // Not connected, or closed: every book says so.
        set_config('v2state', 'revoked', 'mod_moodlereader');
        $this->assertSame(
            'Quizzes are unavailable: this site is not connected to mreader.org.',
            (new eligibility($reader, (int) $student->id))->reason($books['free'])
        );
    }

    /**
     * A quiz taken in one course cannot be taken again in another course's reading activity.
     *
     * @return void
     */
    public function test_a_book_taken_in_one_course_is_used_up_in_every_course(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('v2siteid', 'site-1', 'mod_moodlereader');
        set_config('v2secret', 'Zm9vYmFyLWV4YW1wbGUtc2VjcmV0LTMyLWJ5dGVzISE', 'mod_moodlereader');
        set_config('v2state', 'approved', 'mod_moodlereader');
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $coursea = $this->getDataGenerator()->create_course(['summary' => '']);
        $courseb = $this->getDataGenerator()->create_course(['summary' => '']);
        $readera = reader::from_instanceid($gen->create_instance(['course' => $coursea->id])->id);
        $readerb = reader::from_instanceid($gen->create_instance(['course' => $courseb->id])->id);
        $student = $this->getDataGenerator()->create_and_enrol($coursea, 'student');
        $this->getDataGenerator()->enrol_user($student->id, $courseb->id, 'student');
        $taken = $gen->create_book(['difficulty' => 0, 'sametitle' => 'T1']);
        $edition = $gen->create_book(['difficulty' => 0, 'sametitle' => 'T1']);
        $other = $gen->create_book(['difficulty' => 0]);

        // A failed attempt uses the book up too, as in the same activity.
        $now = time();
        $attemptid = $DB->insert_record('moodlereader_attempts', (object) [
            'readerid' => $readera->get_instance()->id, 'userid' => $student->id, 'bookid' => $taken->id, 'attempt' => 1,
            'state' => 'finished', 'percentgrade' => 20, 'passed' => 0, 'credit' => 0, 'cheated' => 0,
            'deleted' => 0, 'bookrating' => 0, 'timestart' => $now - 600, 'timefinish' => $now,
            'timemodified' => $now, 'ip' => '',
        ]);

        $uid = (int) $student->id;
        foreach ([$readera, $readerb] as $reader) {
            $this->assertFalse($reader->book_is_available((int) $taken->id, $uid));
            $this->assertFalse($reader->book_is_available((int) $edition->id, $uid));
            $this->assertTrue($reader->book_is_available((int) $other->id, $uid));
            $this->assertSame([(int) $other->id], array_keys($reader->search_books($uid, [])));
        }
        $eligibility = new eligibility($readerb, $uid);
        $this->assertStringStartsWith('Already taken: Failed', $eligibility->reason($taken));
        $this->assertSame('They have already read another edition of this title.', $eligibility->reason($edition));
        $this->assertFalse($eligibility->in_progress((int) $taken->id));

        // A teacher who deletes the attempt in course A frees the book everywhere.
        $DB->set_field('moodlereader_attempts', 'deleted', 1, ['id' => $attemptid]);
        $this->assertTrue($readerb->book_is_available((int) $taken->id, $uid));
        $this->assertNull((new eligibility($readerb, $uid))->reason($taken));
    }

    /**
     * Two attempts opened in two courses before either is finished: the second result is recorded but deleted,
     * so the book counts once; and an attempt not launched yet is refused once the book is used up.
     *
     * @return void
     */
    public function test_a_second_result_for_a_used_book_does_not_count(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('v2siteid', 'site-1', 'mod_moodlereader');
        set_config('v2secret', 'Zm9vYmFyLWV4YW1wbGUtc2VjcmV0LTMyLWJ5dGVzISE', 'mod_moodlereader');
        set_config('v2state', 'approved', 'mod_moodlereader');
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $coursea = $this->getDataGenerator()->create_course(['summary' => '']);
        $courseb = $this->getDataGenerator()->create_course(['summary' => '']);
        $readera = reader::from_instanceid($gen->create_instance(['course' => $coursea->id, 'minpassgrade' => 60])->id);
        $readerb = reader::from_instanceid($gen->create_instance(['course' => $courseb->id, 'minpassgrade' => 60])->id);
        $student = $this->getDataGenerator()->create_and_enrol($coursea, 'student');
        $this->getDataGenerator()->enrol_user($student->id, $courseb->id, 'student');
        $book = $gen->create_book(['difficulty' => 0, 'words' => 1000]);
        $uid = (int) $student->id;

        $first = \mod_moodlereader\local\attempt::start($readera, $uid, (int) $book->id);
        $second = \mod_moodlereader\local\attempt::start($readerb, $uid, (int) $book->id);
        \mod_moodlereader\local\attempt::finish($readera, $first, 90.0);
        $this->assertFalse($readerb->book_is_available((int) $book->id, $uid));
        \mod_moodlereader\local\attempt::finish($readerb, $second, 95.0);

        $this->assertEquals(0, $DB->get_field('moodlereader_attempts', 'deleted', ['id' => $first->id]));
        $this->assertEquals(1, $DB->get_field('moodlereader_attempts', 'deleted', ['id' => $second->id]));
        $this->assertEquals('finished', $DB->get_field('moodlereader_attempts', 'state', ['id' => $second->id]));

        // A teacher's Restore does not bring it back while the first one counts.
        $this->setAdminUser();
        $this->assertSame(0, \mod_moodlereader\local\attempt_admin::update($readerb, [(int) $second->id], 'restore'));
        $this->assertEquals(1, $DB->get_field('moodlereader_attempts', 'deleted', ['id' => $second->id]));
    }

    /**
     * A book used up stays used up for life: a course reset, and deleting the activity, free nothing.
     *
     * @return void
     */
    public function test_a_used_book_stays_used_after_reset_and_deletion(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/moodlereader/lib.php');
        $this->resetAfterTest();
        set_config('v2siteid', 'site-1', 'mod_moodlereader');
        set_config('v2secret', 'Zm9vYmFyLWV4YW1wbGUtc2VjcmV0LTMyLWJ5dGVzISE', 'mod_moodlereader');
        set_config('v2state', 'approved', 'mod_moodlereader');
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $coursea = $this->getDataGenerator()->create_course(['summary' => '']);
        $courseb = $this->getDataGenerator()->create_course(['summary' => '']);
        $instancea = $gen->create_instance(['course' => $coursea->id, 'minpassgrade' => 60]);
        $readera = reader::from_instanceid($instancea->id);
        $readerb = reader::from_instanceid($gen->create_instance(['course' => $courseb->id])->id);
        $student = $this->getDataGenerator()->create_and_enrol($coursea, 'student');
        $this->getDataGenerator()->enrol_user($student->id, $courseb->id, 'student');
        $uid = (int) $student->id;
        $book = $gen->create_book(['difficulty' => 0, 'sametitle' => 'T9']);
        $edition = $gen->create_book(['difficulty' => 0, 'sametitle' => 'T9']);
        $other = $gen->create_book(['difficulty' => 0]);
        $first = \mod_moodlereader\local\attempt::start($readera, $uid, (int) $book->id);
        \mod_moodlereader\local\attempt::finish($readera, $first, 40.0);

        // The course is reset: its attempts go, the book stays used up (failed, but used up), in both courses.
        moodlereader_reset_userdata((object) ['courseid' => $coursea->id, 'reset_moodlereader_attempts' => 1]);
        $this->assertSame(0, $DB->count_records('moodlereader_attempts', ['readerid' => $instancea->id]));
        foreach ([$readera, $readerb] as $reader) {
            $this->assertFalse($reader->book_is_available((int) $book->id, $uid));
            $this->assertFalse($reader->book_is_available((int) $edition->id, $uid));
            $this->assertTrue($reader->book_is_available((int) $other->id, $uid));
        }
        $this->assertStringStartsWith('Already taken: Failed', (new eligibility($readerb, $uid))->reason($book));

        // A result that arrives afterwards for the same title does not count; nor does award credit.
        $late = \mod_moodlereader\local\attempt::start($readerb, $uid, (int) $edition->id);
        \mod_moodlereader\local\attempt::finish($readerb, $late, 100.0);
        $this->assertEquals(1, $DB->get_field('moodlereader_attempts', 'deleted', ['id' => $late->id]));

        // Deleting the activity frees nothing either.
        $second = \mod_moodlereader\local\attempt::start($readera, $uid, (int) $other->id);
        \mod_moodlereader\local\attempt::finish($readera, $second, 90.0);
        moodlereader_delete_instance($instancea->id);
        $this->assertFalse($readerb->book_is_available((int) $other->id, $uid));
        $this->assertSame(2, $DB->count_records('moodlereader_booksused', ['userid' => $uid]));
    }
}
