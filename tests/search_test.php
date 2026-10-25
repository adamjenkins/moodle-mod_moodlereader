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

/**
 * Tests for the student book search.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\reader::class)]
final class search_test extends \advanced_testcase {
    /**
     * Publisher, level and language filters; unknown language always matches; disabled books never do.
     *
     * @return void
     */
    public function test_filters_and_disabled_books(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $instance = $gen->create_instance(['course' => $course->id, 'goal' => 5000, 'levelcheck' => 0]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $books = [
            'a1en' => $gen->create_book(['publisher' => 'A', 'difficulty' => 1, 'language' => 'en']),
            'b2en' => $gen->create_book(['publisher' => 'B', 'difficulty' => 2, 'language' => 'en']),
            'c3es' => $gen->create_book(['publisher' => 'C', 'difficulty' => 3, 'language' => 'es']),
            'a3xx' => $gen->create_book(['publisher' => 'A', 'difficulty' => 3, 'language' => '']),
            'b1hidden' => $gen->create_book(['publisher' => 'B', 'difficulty' => 1, 'language' => 'en', 'hidden' => 1]),
        ];
        $reader = reader::from_instanceid($instance->id);
        $find = function (array $filters) use ($reader, $student, $books): array {
            $found = $reader->search_books($student->id, $filters);
            $names = array_keys(array_filter($books, fn($b) => isset($found[$b->id])));
            sort($names);
            return $names;
        };

        // Disabled books never appear, whatever the filter.
        $this->assertSame(['a1en', 'a3xx', 'b2en', 'c3es'], $find([]));
        $this->assertSame(['a1en', 'a3xx', 'b2en'], $find(['language' => 'en']));
        $this->assertSame(['a1en', 'a3xx'], $find(['publisher' => 'A']));
        $this->assertSame(['a3xx', 'c3es'], $find(['difficulty' => 3]));
        $this->assertSame(['a3xx'], $find(['difficulty' => 3, 'language' => 'en']));
        $this->assertSame(['a1en'], $find(['publisher' => 'A', 'difficulty' => 1, 'language' => 'en']));
        $this->assertSame(['en', 'es'], $reader->available_languages($student->id));
    }
}
