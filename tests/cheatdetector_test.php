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
use mod_moodlereader\local\cheatdetector;

/**
 * Tests for cheating detection.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\cheatdetector::class)]
final class cheatdetector_test extends \advanced_testcase {
    /**
     * Insert a finished, passing attempt directly.
     *
     * @param int $readerid
     * @param int $userid
     * @param int $bookid
     * @param string $ip
     * @param int $timefinish
     * @return int Attempt id.
     */
    protected function make_attempt(int $readerid, int $userid, int $bookid, string $ip, int $timefinish): int {
        global $DB;
        return (int) $DB->insert_record('moodlereader_attempts', (object) [
            'readerid' => $readerid, 'userid' => $userid, 'bookid' => $bookid, 'attempt' => 1,
            'state' => 'finished', 'percentgrade' => 90, 'passed' => 1, 'credit' => 0, 'cheated' => 0,
            'deleted' => 0, 'bookrating' => 0, 'timestart' => $timefinish - 60, 'timefinish' => $timefinish,
            'timemodified' => $timefinish, 'ip' => $ip,
        ]);
    }

    /**
     * Two same-book passes from the same IP, close in time, are flagged.
     *
     * @return void
     */
    public function test_same_ip_pair_is_flagged(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $cm = $gen->create_instance(['course' => $course->id, 'goal' => 5000]);
        $book = $gen->create_book();
        $u1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $u2 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $now = time();

        $this->make_attempt($cm->id, $u1->id, $book->id, '10.0.0.5', $now);
        $this->make_attempt($cm->id, $u2->id, $book->id, '10.0.0.5', $now + 60);

        $flagged = cheatdetector::detect($cm->id);
        $this->assertGreaterThanOrEqual(2, $flagged);
        $this->assertTrue($DB->record_exists('moodlereader_cheatlog', ['readerid' => $cm->id]));
    }

    /**
     * Different IPs are not flagged, and a second scan does not duplicate.
     *
     * @return void
     */
    public function test_different_ip_not_flagged_and_no_duplicates(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $cm = $gen->create_instance(['course' => $course->id, 'goal' => 5000]);
        $book = $gen->create_book();
        $u1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $u2 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $now = time();

        $this->make_attempt($cm->id, $u1->id, $book->id, '10.0.0.5', $now);
        $this->make_attempt($cm->id, $u2->id, $book->id, '10.0.0.9', $now + 60);
        $this->assertEquals(0, cheatdetector::detect($cm->id));

        // Now add a same-IP pair, scan twice — second scan adds nothing new.
        $this->make_attempt($cm->id, $u1->id, $book->id, '10.0.0.7', $now);
        $this->make_attempt($cm->id, $u2->id, $book->id, '10.0.0.7', $now + 30);
        $first = cheatdetector::detect($cm->id);
        $this->assertGreaterThanOrEqual(2, $first);
        $this->assertEquals(0, cheatdetector::detect($cm->id));
    }

    /**
     * Marking an attempt cheated removes its words from the grade.
     *
     * @return void
     */
    public function test_marking_cheated_updates_grade(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $cm = $gen->create_instance(['course' => $course->id, 'goal' => 5000, 'maxgrade' => 100]);
        $book = $gen->create_book(['words' => 5000]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $reader = reader::from_instanceid($cm->id);

        $attemptid = $this->make_attempt($cm->id, $user->id, $book->id, '10.0.0.5', time());
        \moodlereader_update_grades($reader->get_instance(), $user->id);

        $before = grade_get_grades($course->id, 'mod', 'moodlereader', $cm->id, $user->id);
        $this->assertEquals(100, (int) reset($before->items)->grades[$user->id]->grade);

        cheatdetector::set_cheated($reader, $attemptid, true);
        $after = grade_get_grades($course->id, 'mod', 'moodlereader', $cm->id, $user->id);
        $this->assertEquals(0, (int) reset($after->items)->grades[$user->id]->grade);
    }
}
