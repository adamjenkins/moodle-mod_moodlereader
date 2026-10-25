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

use mod_moodlereader\local\attempt_admin;
use mod_moodlereader\local\reader;

/**
 * Tests for the teacher actions of the student reports.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\attempt_admin::class)]
final class attempt_admin_test extends \advanced_testcase {
    /**
     * Insert a finished attempt.
     *
     * @param int $readerid
     * @param int $userid
     * @param int $bookid
     * @param int $passed
     * @return int Attempt id.
     */
    protected function make_attempt(int $readerid, int $userid, int $bookid, int $passed): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record('moodlereader_attempts', (object) [
            'readerid' => $readerid, 'userid' => $userid, 'bookid' => $bookid, 'attempt' => 1,
            'state' => 'finished', 'percentgrade' => $passed ? 90 : 40, 'passed' => $passed, 'credit' => 0,
            'cheated' => 0, 'deleted' => 0, 'bookrating' => 0, 'timestart' => $now - 600, 'timefinish' => $now,
            'timemodified' => $now, 'ip' => '10.0.0.1',
        ]);
    }

    /**
     * The student's gradebook grade.
     *
     * @param \stdClass $course
     * @param \stdClass $instance
     * @param int $userid
     * @return float|null
     */
    protected function grade(\stdClass $course, \stdClass $instance, int $userid): ?float {
        $grades = grade_get_grades($course->id, 'mod', 'moodlereader', $instance->id, $userid);
        $grade = reset($grades->items)->grades[$userid]->grade;
        return $grade === null ? null : (float) $grade;
    }

    /**
     * Set up a course, an activity, a student, a teacher and a book worth the whole goal.
     *
     * @param array $instancerecord Extra activity settings.
     * @return array [$course, $instance, $student, $teacher, $book]
     */
    protected function setup_activity(array $instancerecord = []): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $instance = $gen->create_instance(['course' => $course->id, 'goal' => 5000, 'maxgrade' => 100] + $instancerecord);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $book = $gen->create_book(['words' => 5000]);
        return [$course, $instance, $student, $teacher, $book];
    }

    /**
     * Pass, fail, cheat and delete change the result and the grade; restore and uncheat undo them.
     *
     * @return void
     */
    public function test_result_changes_regrade(): void {
        global $DB;
        [$course, $instance, $student, $teacher, $book] = $this->setup_activity();
        $this->setUser($teacher);
        $reader = reader::from_instanceid($instance->id);
        $attemptid = $this->make_attempt($instance->id, $student->id, $book->id, 0);

        $sink = $this->redirectEvents();
        $this->assertSame(1, attempt_admin::update($reader, [$attemptid], attempt_admin::ACTION_PASS));
        $this->assertEquals(100, $this->grade($course, $instance, $student->id));
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof event\attempt_updated);
        $this->assertCount(1, $events);
        $this->assertSame('pass', reset($events)->other['action']);
        $sink->close();

        // Repeating a change changes nothing.
        $this->assertSame(0, attempt_admin::update($reader, [$attemptid], attempt_admin::ACTION_PASS));

        attempt_admin::update($reader, [$attemptid], attempt_admin::ACTION_CHEAT);
        $this->assertEquals(0, (float) $this->grade($course, $instance, $student->id));
        attempt_admin::update($reader, [$attemptid], attempt_admin::ACTION_UNCHEAT);
        $this->assertEquals(100, $this->grade($course, $instance, $student->id));

        attempt_admin::update($reader, [$attemptid], attempt_admin::ACTION_DELETE);
        $this->assertEquals(1, $DB->get_field('moodlereader_attempts', 'deleted', ['id' => $attemptid]));
        $this->assertEquals(0, (float) $this->grade($course, $instance, $student->id));
        attempt_admin::update($reader, [$attemptid], attempt_admin::ACTION_RESTORE);
        $this->assertEquals(100, $this->grade($course, $instance, $student->id));

        attempt_admin::update($reader, [$attemptid], attempt_admin::ACTION_FAIL);
        $this->assertEquals(0, (float) $this->grade($course, $instance, $student->id));
    }

    /**
     * An attempt of another activity, or one still in progress, is never changed.
     *
     * @return void
     */
    public function test_scoped_to_activity_and_finished_attempts(): void {
        global $DB;
        [$course, $instance, $student, $teacher, $book] = $this->setup_activity();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $other = $gen->create_instance(['course' => $course->id, 'goal' => 5000]);
        $this->setUser($teacher);
        $foreign = $this->make_attempt($other->id, $student->id, $book->id, 0);
        $inprogress = $this->make_attempt($instance->id, $student->id, $book->id, 0);
        $DB->set_field('moodlereader_attempts', 'state', 'inprogress', ['id' => $inprogress]);

        $reader = reader::from_instanceid($instance->id);
        $this->assertSame(0, attempt_admin::update($reader, [$foreign, $inprogress], attempt_admin::ACTION_PASS));
        $this->assertEquals(0, $DB->get_field('moodlereader_attempts', 'passed', ['id' => $foreign]));
        $this->assertEquals(0, $DB->get_field('moodlereader_attempts', 'passed', ['id' => $inprogress]));
    }

    /**
     * In separate groups, a teacher without access to all groups acts only on their own group.
     *
     * @return void
     */
    public function test_separate_groups(): void {
        [$course, $instance, $student, , $book] = $this->setup_activity(['groupmode' => SEPARATEGROUPS]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $mine = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $mine->id, 'userid' => $teacher->id]);
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $mine->id, 'userid' => $other->id]);
        $this->setUser($teacher);

        $reader = reader::from_instanceid($instance->id);
        $visible = attempt_admin::visible_userids($reader, [$student->id, $other->id]);
        $this->assertSame([$other->id => true], $visible);
        $outside = $this->make_attempt($instance->id, $student->id, $book->id, 0);
        $this->assertSame(0, attempt_admin::update($reader, [$outside], attempt_admin::ACTION_PASS));
    }

    /**
     * A teacher or administrator who tries the quizzes is in the reports and can change their own attempt.
     *
     * @return void
     */
    public function test_teacher_and_admin_attempts_are_reported_and_editable(): void {
        global $DB;
        [$course, $instance, , $teacher, $book] = $this->setup_activity();
        $reader = reader::from_instanceid($instance->id);
        $this->setUser($teacher);
        $this->assertSame([], attempt_admin::visible_userids($reader, [$teacher->id]));

        // The teacher takes a quiz and fails it, then sets it to passed to show the progress bar.
        $mine = $this->make_attempt($instance->id, $teacher->id, $book->id, 0);
        $this->assertSame([$teacher->id => true], attempt_admin::visible_userids($reader, [$teacher->id]));
        $this->assertSame(1, attempt_admin::update($reader, [$mine], attempt_admin::ACTION_PASS));
        $this->assertEquals(100, $this->grade($course, $instance, $teacher->id));

        // An administrator, not enrolled at all, likewise.
        $this->setAdminUser();
        $admin = get_admin();
        $adminattempt = $this->make_attempt($instance->id, $admin->id, $book->id, 0);
        $this->assertSame(1, attempt_admin::update($reader, [$adminattempt], attempt_admin::ACTION_PASS));
        $this->assertEquals(1, $DB->get_field('moodlereader_attempts', 'passed', ['id' => $adminattempt]));
    }

    /**
     * Awarding credit counts the book once; levels and goals are set for the students given.
     *
     * @return void
     */
    public function test_award_book_and_levels(): void {
        global $DB;
        [$course, $instance, $student, $teacher, $book] = $this->setup_activity();
        $this->setUser($teacher);
        $reader = reader::from_instanceid($instance->id);

        $this->assertSame(1, attempt_admin::award_book($reader, [$student->id], $book->id));
        $this->assertSame(0, attempt_admin::award_book($reader, [$student->id], $book->id));
        $this->assertEquals(100, $this->grade($course, $instance, $student->id));
        $credit = $DB->get_record('moodlereader_attempts', ['userid' => $student->id, 'credit' => 1], '*', MUST_EXIST);
        $this->assertSame('', $credit->ip);

        $this->assertSame(1, attempt_admin::set_level_field($reader, [$student->id], 'goal', 10000));
        $this->assertEquals(50, $this->grade($course, $instance, $student->id));
        $this->assertSame(1, attempt_admin::set_level_field($reader, [$student->id], 'currentlevel', 3));
        $this->assertEquals(3, $DB->get_field('moodlereader_levels', 'currentlevel', [
            'readerid' => $instance->id, 'userid' => $student->id,
        ]));
        $this->assertEquals(10000, $DB->get_field('moodlereader_levels', 'goal', [
            'readerid' => $instance->id, 'userid' => $student->id,
        ]));

        $this->assertSame(1, attempt_admin::message($reader, [$student->id, $teacher->id], 'Read more!'));
        $this->assertSame(0, attempt_admin::message($reader, [$student->id], '  '));
    }

    /**
     * The award search lists books without a quiz and withdrawn ones, never disabled books.
     *
     * @return void
     */
    public function test_search_award_books(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $kite = $gen->create_book(['name' => 'The Kite', 'publisher' => 'A', 'language' => 'en']);
        $gen->create_book(['name' => 'Kite Without Quiz', 'publisher' => 'A', 'hasquiz' => 0]);
        $gen->create_book(['name' => 'Kite Withdrawn', 'publisher' => 'B', 'withdrawn' => 1]);
        $gen->create_book(['name' => 'Kite Disabled', 'publisher' => 'A', 'hidden' => 1]);
        $gen->create_book(['name' => 'Kite en espanol', 'publisher' => 'A', 'language' => 'es']);

        $names = fn(array $books) => array_values(array_map(fn($b) => $b->name, $books));
        $total = 0;
        $found = attempt_admin::search_award_books(['name' => 'kite', 'language' => 'en'], 100, $total);
        $this->assertSame(['Kite Without Quiz', 'The Kite', 'Kite Withdrawn'], $names($found));
        $this->assertSame(3, $total);
        $this->assertSame(['Kite Withdrawn'], $names(attempt_admin::search_award_books(['publisher' => 'B'])));
        $this->assertCount(4, attempt_admin::search_award_books(['name' => 'kite']));

        // A disabled book cannot be awarded even by id.
        [$course, $instance, $student, $teacher] = $this->setup_activity();
        $this->setUser($teacher);
        $disabled = $gen->create_book(['name' => 'Off', 'hidden' => 1]);
        $this->expectException(\dml_missing_record_exception::class);
        attempt_admin::award_book(reader::from_instanceid($instance->id), [$student->id], $disabled->id);
    }
}
