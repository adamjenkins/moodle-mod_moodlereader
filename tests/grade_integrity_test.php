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
 * Regression tests for the grade-integrity and completion fixes.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\reader::class)]
final class grade_integrity_test extends \advanced_testcase {
    /**
     * Set up a course, instance, student and book.
     *
     * @param array $override
     * @return array [reader, user, book]
     */
    protected function make(array $override = []): array {
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $cm = $gen->create_instance(['course' => $course->id, 'goal' => 5000, 'maxgrade' => 100] + $override);
        $book = $gen->create_book(['words' => 5000, 'difficulty' => 0]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        return [reader::from_instanceid($cm->id), $user, $book];
    }

    /**
     * A book with only an in-progress attempt stays available (resume), then is gone once finished.
     *
     * @return void
     */
    public function test_availability_across_attempt_lifecycle(): void {
        [$reader, $user, $book] = $this->make();

        $this->assertTrue($reader->book_is_available($book->id, $user->id));

        $attempt = attempt::start($reader, $user->id, $book->id);
        // In progress: still available so the student can resume (H4).
        $this->assertTrue($reader->book_is_available($book->id, $user->id));

        attempt::finish($reader, $attempt, 90.0);
        // Finished: no longer available — cannot be re-taken (C1).
        $this->assertFalse($reader->book_is_available($book->id, $user->id));
    }

    /**
     * Duplicate passed attempts on one book count its words only once (C1).
     *
     * @return void
     */
    public function test_words_counted_once_per_book(): void {
        global $DB;
        [$reader, $user, $book] = $this->make();
        $instance = $reader->get_instance();

        // Two passed attempts on the same 5000-word book (as legacy migration might carry).
        foreach ([1, 2] as $n) {
            $DB->insert_record('moodlereader_attempts', (object) [
                'readerid' => $instance->id, 'userid' => $user->id, 'bookid' => $book->id,
                'attempt' => $n, 'state' => 'finished', 'percentgrade' => 90, 'passed' => 1,
                'credit' => 0, 'cheated' => 0, 'deleted' => 0, 'bookrating' => 0,
                'timestart' => time(), 'timefinish' => time(), 'timemodified' => time(), 'ip' => '',
            ]);
        }
        $grades = moodlereader_get_user_grades($instance, $user->id);
        // 5000 words counted once against a 5000 goal => 100, not 200-clamped.
        $this->assertEquals(100, (int) $grades[$user->id]->rawgrade);
    }

    /**
     * The gradebook max is taken from the form's grade element, not left at the default (H3).
     *
     * @return void
     */
    public function test_grade_form_maps_to_maxgrade(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        // The activity generator passes 'grade' through moodleform handling.
        $cm = $gen->create_instance(['course' => $course->id, 'goal' => 5000, 'grade' => 50]);
        $instance = $DB->get_record('moodlereader', ['id' => $cm->id]);
        $this->assertEquals(50, (int) $instance->maxgrade);
    }

    /**
     * The completionpass rule is incomplete until the student passes a book (H2).
     *
     * @return void
     */
    public function test_completion_pass_rule(): void {
        [$reader, $user, $book] = $this->make(['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionpass' => 1]);

        // A real cm_info exposes the custom completion rules via get_coursemodule_info().
        $getcm = fn() => get_fast_modinfo($reader->get_course())->get_cm($reader->get_cm()->id);

        $completion = new \mod_moodlereader\completion\custom_completion($getcm(), (int) $user->id);
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_state('completionpass'));

        $attempt = attempt::start($reader, $user->id, $book->id);
        attempt::finish($reader, $attempt, 90.0);

        $completion = new \mod_moodlereader\completion\custom_completion($getcm(), (int) $user->id);
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_state('completionpass'));
    }

    /**
     * The words rule counts this term's books once each, as the grade does, credit included.
     *
     * @return void
     */
    public function test_completion_words_rule_matches_grade(): void {
        global $DB;
        [$reader, $user, $book] = $this->make([
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completiontotalwords' => 6000, 'ignoredate' => time() - DAYSECS,
        ]);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $old = $gen->create_book(['words' => 3000]);
        $base = ['readerid' => $reader->get_instance()->id, 'userid' => $user->id, 'attempt' => 1, 'state' => 'finished',
            'percentgrade' => 90, 'passed' => 1, 'credit' => 0, 'cheated' => 0, 'deleted' => 0, 'bookrating' => 0,
            'ip' => '', 'timemodified' => time()];
        // This term: the same 5,000-word book passed twice. Last term: a 3,000-word book.
        foreach ([time() - 600, time() - 300] as $t) {
            $row = $base + ['bookid' => $book->id, 'timestart' => $t, 'timefinish' => $t];
            $DB->insert_record('moodlereader_attempts', (object) $row);
        }
        $t = time() - 10 * DAYSECS;
        $row = $base + ['bookid' => $old->id, 'timestart' => $t, 'timefinish' => $t];
        $DB->insert_record('moodlereader_attempts', (object) $row);

        $cm = get_fast_modinfo($reader->get_course())->get_cm($reader->get_cm()->id);
        $completion = new \mod_moodlereader\completion\custom_completion($cm, (int) $user->id);
        $this->assertSame(COMPLETION_INCOMPLETE, $completion->get_state('completiontotalwords'));

        // Credit for another book this term reaches the threshold.
        $this->setAdminUser();
        \mod_moodlereader\local\attempt_admin::award_book($reader, [$user->id], $gen->create_book(['words' => 1000])->id);
        $this->assertSame(COMPLETION_COMPLETE, $completion->get_state('completiontotalwords'));
    }
}
