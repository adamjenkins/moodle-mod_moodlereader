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

        // Exactly the books without a reason are the ones the student's own search offers.
        $offered = array_keys($reader->search_books((int) $student->id, []));
        $free = array_map(fn($k) => (int) $books[$k]->id, array_keys(array_filter($reasons, fn($r) => $r === null)));
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
}
