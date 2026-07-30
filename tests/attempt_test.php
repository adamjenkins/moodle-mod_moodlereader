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

use mod_moodlereader\local\reader;
use mod_moodlereader\local\attempt;

/**
 * Tests for the attempt lifecycle and grading.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\attempt::class)]
final class attempt_test extends \advanced_testcase {
    /**
     * Build a course, instance, enrolled student and a book.
     *
     * @param array $readeroverrides
     * @return array [reader, user, book]
     */
    protected function setup_activity(array $readeroverrides = []): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_moodlereader_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $cm = $gen->create_instance(['course' => $course->id] + $readeroverrides);
        $book = $gen->create_book(['words' => 5000, 'difficulty' => 0]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        return [reader::from_instanceid($cm->id), $user, $book];
    }

    /**
     * A passing grade finishes the attempt and pushes a gradebook grade.
     *
     * @return void
     */
    public function test_finish_passing_updates_gradebook(): void {
        [$reader, $user, $book] = $this->setup_activity(['goal' => 5000, 'maxgrade' => 100, 'minpassgrade' => 60]);

        $attempt = attempt::start($reader, $user->id, $book->id);
        $this->assertEquals('inprogress', $attempt->state);

        attempt::finish($reader, $attempt, 80.0);

        $stored = $this->reload($attempt->id);
        $this->assertEquals('finished', $stored->state);
        $this->assertEquals(1, $stored->passed);

        $grades = grade_get_grades(
            $reader->get_course()->id,
            'mod',
            'moodlereader',
            $reader->get_instance()->id,
            $user->id
        );
        $item = reset($grades->items);
        // 5000 words read of a 5000 goal => full marks.
        $this->assertEquals(100, (int) $item->grades[$user->id]->grade);
    }

    /**
     * A failing grade finishes the attempt but contributes no words to the grade.
     *
     * @return void
     */
    public function test_finish_failing_scores_zero(): void {
        [$reader, $user, $book] = $this->setup_activity(['goal' => 5000, 'maxgrade' => 100, 'minpassgrade' => 60]);

        $attempt = attempt::start($reader, $user->id, $book->id);
        attempt::finish($reader, $attempt, 40.0);

        $stored = $this->reload($attempt->id);
        $this->assertEquals(0, $stored->passed);

        $grades = grade_get_grades(
            $reader->get_course()->id,
            'mod',
            'moodlereader',
            $reader->get_instance()->id,
            $user->id
        );
        $item = reset($grades->items);
        $this->assertEquals(0, (int) $item->grades[$user->id]->grade);
    }

    /**
     * Finishing an already-finished attempt is a no-op (idempotent callback).
     *
     * @return void
     */
    public function test_finish_is_idempotent(): void {
        [$reader, $user, $book] = $this->setup_activity(['goal' => 5000]);
        $attempt = attempt::start($reader, $user->id, $book->id);
        attempt::finish($reader, $attempt, 90.0);

        $first = $this->reload($attempt->id);
        attempt::finish($reader, $first, 10.0);
        $second = $this->reload($attempt->id);

        $this->assertEquals($first->percentgrade, $second->percentgrade);
    }

    /**
     * Reload an attempt record.
     *
     * @param int $id
     * @return \stdClass
     */
    protected function reload(int $id): \stdClass {
        global $DB;
        return $DB->get_record('moodlereader_attempts', ['id' => $id], '*', MUST_EXIST);
    }
}
