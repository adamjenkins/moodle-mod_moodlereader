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

use mod_moodlereader\local\levels;
use mod_moodlereader\local\progressbar;
use mod_moodlereader\local\reader;

/**
 * Tests for reading levels, promotion and the progress bar.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\levels::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\progressbar::class)]
final class levels_test extends \advanced_testcase {
    /**
     * An activity restricting books to the level: 3 to move up, 2 below, 1 above.
     *
     * @return array [reader, student, books by level]
     */
    protected function setup_levels(): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $instance = $gen->create_instance(['course' => $course->id, 'levelcheck' => 1, 'thislevel' => 3,
            'prevlevel' => 2, 'nextlevel' => 1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $books = [];
        foreach ([0, 1, 2, 3] as $level) {
            for ($i = 0; $i < 4; $i++) {
                $books[$level][] = $gen->create_book(['difficulty' => $level]);
            }
        }
        return [reader::from_instanceid($instance->id), $student, $books];
    }

    /**
     * Record a finished attempt.
     *
     * @param reader $reader
     * @param int $userid
     * @param \stdClass $book
     * @param int $passed
     * @return void
     */
    protected function attempt(reader $reader, int $userid, \stdClass $book, int $passed): void {
        global $DB;
        $now = time();
        $DB->insert_record('moodlereader_attempts', (object) [
            'readerid' => $reader->get_instance()->id, 'userid' => $userid, 'bookid' => $book->id, 'attempt' => 1,
            'state' => 'finished', 'percentgrade' => $passed ? 90 : 30, 'passed' => $passed, 'credit' => 0,
            'cheated' => 0, 'deleted' => 0, 'bookrating' => 0, 'timestart' => $now - 600, 'timefinish' => $now,
            'timemodified' => $now, 'ip' => '',
        ]);
    }

    /**
     * Allowances fill with passes only, and promotion comes at the threshold.
     *
     * @return void
     */
    public function test_allowances_and_promotion(): void {
        global $DB;
        [$reader, $student, $books] = $this->setup_levels();
        $DB->insert_record('moodlereader_levels', (object) ['readerid' => $reader->get_instance()->id,
            'userid' => $student->id, 'goal' => 0, 'startlevel' => 1, 'currentlevel' => 1, 'stoplevel' => 0,
            'allowpromotion' => 1, 'time' => time() - DAYSECS]);

        $status = levels::status($reader, $student->id);
        $this->assertSame([0, 1, 2], array_column($status->columns, 'level'));
        $this->assertSame([2, 3, 1], array_column($status->columns, 'quota'));
        $this->assertSame(3, $status->remainingtopromotion);
        $this->assertSame([0, 1, 2], levels::allowed($reader, $student->id));

        // A failed quiz uses nothing up; a pass one level up uses the only one allowed there.
        $this->attempt($reader, $student->id, $books[1][0], 0);
        $this->attempt($reader, $student->id, $books[2][0], 1);
        $this->assertSame([0, 1], levels::allowed($reader, $student->id));
        $this->assertSame(3, levels::status($reader, $student->id)->remainingtopromotion);

        // Three passes at the own level: promoted to level 2, and the counts start again.
        foreach ([1, 2] as $i) {
            $this->attempt($reader, $student->id, $books[1][$i], 1);
            $this->assertFalse(levels::maybe_promote($reader, $student->id));
        }
        $this->attempt($reader, $student->id, $books[1][3], 1);
        $this->assertTrue(levels::maybe_promote($reader, $student->id));
        $status = levels::status($reader, $student->id);
        $this->assertSame(2, $status->level);
        $this->assertSame(3, $status->remainingtopromotion);
        $this->assertSame([1, 2, 3], levels::allowed($reader, $student->id));
    }

    /**
     * No promotion at the stop level or when it is switched off; the own level then has no limit.
     *
     * @return void
     */
    public function test_stop_level_and_promotion_off(): void {
        global $DB;
        [$reader, $student, $books] = $this->setup_levels();
        $DB->insert_record('moodlereader_levels', (object) ['readerid' => $reader->get_instance()->id,
            'userid' => $student->id, 'goal' => 0, 'startlevel' => 0, 'currentlevel' => 2, 'stoplevel' => 2,
            'allowpromotion' => 1, 'time' => 0]);
        foreach ([0, 1, 2] as $i) {
            $this->attempt($reader, $student->id, $books[2][$i], 1);
        }
        $status = levels::status($reader, $student->id);
        $this->assertTrue($status->attop);
        $this->assertNull($status->remainingtopromotion);
        $this->assertSame(levels::UNLIMITED, $status->columns[1]->remaining);
        $this->assertFalse(levels::maybe_promote($reader, $student->id));
        // At the top there is no level above to read at.
        $this->assertSame([1, 2], levels::allowed($reader, $student->id));

        $DB->set_field('moodlereader_levels', 'stoplevel', 0, ['userid' => $student->id]);
        $DB->set_field('moodlereader_levels', 'allowpromotion', 0, ['userid' => $student->id]);
        $this->assertFalse(levels::maybe_promote($reader, $student->id));
        $this->assertSame([1, 2, 3], levels::allowed($reader, $student->id));
    }

    /**
     * Colour bands, and the second bar only past the goal and when the activity wants it.
     *
     * @return void
     */
    public function test_progress_bar(): void {
        $this->resetAfterTest();
        $this->assertSame('red', progressbar::band(25));
        $this->assertSame('orange', progressbar::band(26));
        $this->assertSame('yellow', progressbar::band(75));
        $this->assertSame('green', progressbar::band(99.9));
        $this->assertSame('blue', progressbar::band(100));

        $this->assertStringContainsString('mr-progress-yellow', progressbar::render(1600, 3000, false, true));
        $twobars = progressbar::render(4500, 3000, false, true);
        $this->assertSame(2, substr_count($twobars, 'role="progressbar"'));
        $this->assertStringContainsString('Extra reading: 1,500 more words', $twobars);
        // The extra-reading bar is blue even though it is only half full; the first is blue at the goal.
        $this->assertSame(2, substr_count($twobars, 'mr-progress-blue'));
        $this->assertStringNotContainsString('mr-progress-yellow', $twobars);
        $this->assertSame(1, substr_count(progressbar::render(4500, 3000, false, false), 'role="progressbar"'));
        $this->assertStringContainsString('51%', progressbar::mini(1530, 3000, false));
    }
}
