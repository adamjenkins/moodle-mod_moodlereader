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

use mod_moodlereader\local\covermiss;
use mod_moodlereader\local\reader;

/**
 * Tests for which book covers an activity serves, and the remembered cover misses.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\reader::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\covermiss::class)]
final class cover_test extends \advanced_testcase {
    /**
     * Add a finished attempt on a book.
     *
     * @param int $readerid
     * @param int $userid
     * @param \stdClass $book
     * @return void
     */
    protected function attempt(int $readerid, int $userid, \stdClass $book): void {
        global $DB;
        $now = time();
        $DB->insert_record('moodlereader_attempts', (object) [
            'readerid' => $readerid, 'userid' => $userid, 'bookid' => $book->id, 'attempt' => 1,
            'state' => 'finished', 'percentgrade' => 90, 'passed' => 1, 'credit' => 0,
            'cheated' => 0, 'deleted' => 0, 'bookrating' => 0, 'timestart' => $now - 600, 'timefinish' => $now,
            'timemodified' => $now, 'ip' => '',
        ]);
    }

    /**
     * With no book list, every enabled book's cover is shown; a disabled one only when it has an attempt here.
     *
     * @return void
     */
    public function test_shows_cover_without_book_list(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $instance = $gen->create_instance(['course' => $course->id, 'bookinstances' => 0]);
        $other = $gen->create_instance(['course' => $course->id, 'bookinstances' => 0]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $enabled = $gen->create_book();
        $hidden = $gen->create_book(['hidden' => 1]);
        $hiddenread = $gen->create_book(['hidden' => 1]);
        $hiddenelsewhere = $gen->create_book(['hidden' => 1]);
        $this->attempt($instance->id, $student->id, $hiddenread);
        $this->attempt($other->id, $student->id, $hiddenelsewhere);

        $reader = reader::from_instanceid($instance->id);
        $this->assertTrue($reader->shows_cover($enabled));
        $this->assertFalse($reader->shows_cover($hidden));
        $this->assertTrue($reader->shows_cover($hiddenread));
        $this->assertFalse($reader->shows_cover($hiddenelsewhere));
    }

    /**
     * With a book list, only the listed books' covers are shown, plus books with an attempt here.
     *
     * @return void
     */
    public function test_shows_cover_with_book_list(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $instance = $gen->create_instance(['course' => $course->id, 'bookinstances' => 1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $listed = $gen->create_book();
        $listedhidden = $gen->create_book(['hidden' => 1]);
        $unlisted = $gen->create_book();
        $unlistedread = $gen->create_book();
        foreach ([$listed, $listedhidden] as $book) {
            $DB->insert_record('moodlereader_bookinstances', (object) ['readerid' => $instance->id,
                'bookid' => $book->id, 'difficulty' => 1, 'words' => 0, 'points' => 0]);
        }
        $this->attempt($instance->id, $student->id, $unlistedread);

        $reader = reader::from_instanceid($instance->id);
        $this->assertTrue($reader->shows_cover($listed));
        $this->assertFalse($reader->shows_cover($listedhidden));
        $this->assertFalse($reader->shows_cover($unlisted));
        $this->assertTrue($reader->shows_cover($unlistedread));
    }

    /**
     * A remembered miss applies to the book's current cover ETag only, and can be forgotten.
     *
     * @return void
     */
    public function test_cover_miss_is_remembered_per_etag(): void {
        $this->resetAfterTest();
        $book = (object) ['id' => 42, 'coveretag' => null];
        $this->assertFalse(covermiss::is_known($book));

        covermiss::remember($book);
        $this->assertTrue(covermiss::is_known($book));
        $this->assertFalse(covermiss::is_known((object) ['id' => 43, 'coveretag' => null]));

        // A sync that brings a new ETag means the cover is asked for again.
        $book->coveretag = '"v2"';
        $this->assertFalse(covermiss::is_known($book));
        covermiss::remember($book);
        $this->assertTrue(covermiss::is_known($book));

        covermiss::forget(42);
        $this->assertFalse(covermiss::is_known($book));
    }
}
