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

/**
 * Test data generator for mod_moodlereader.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Generator for moodlereader instances and books.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_moodlereader_generator extends testing_module_generator {
    /**
     * Create a moodlereader instance.
     *
     * @param array|stdClass|null $record
     * @param array|null $options
     * @return stdClass
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (array) $record + [
            'goal'          => 30000,
            'maxgrade'      => 100,
            'wordsorpoints' => 0,
            'minpassgrade'  => 60,
            'levelcheck'    => 0,
        ];
        return parent::create_instance($record, (array) $options);
    }

    /**
     * Create a book in the shared catalogue.
     *
     * @param array $record
     * @return stdClass
     */
    public function create_book(array $record = []): \stdClass {
        global $DB;
        static $n = 0;
        $n++;
        $book = (object) ($record + [
            'publisher'  => 'Test Publisher',
            'series'     => 'Level 1',
            'difficulty' => 1,
            'name'       => "Test Book $n",
            'words'      => 5000,
            'genre'      => 'fiction',
            'fiction'    => 'true',
            'points'     => 0,
            'image'      => "book$n.jpg",
            'sametitle'  => '',
            'hidden'     => 0,
            'language'   => '',
            'hasquiz'    => 1,
            'time'       => time(),
        ]);
        $book->id = $DB->insert_record('moodlereader_books', $book);
        return $book;
    }
}
