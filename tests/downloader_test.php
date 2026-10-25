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

use mod_moodlereader\local\api\fake_client;
use mod_moodlereader\local\downloader;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/fake_client.php');

/**
 * Tests for the API v2 book catalogue sync.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\downloader::class)]
final class downloader_test extends \advanced_testcase {
    /**
     * A v2 book object.
     *
     * @param int $id
     * @param array $overrides
     * @return array
     */
    protected function book(int $id, array $overrides = []): array {
        return $overrides + [
            'id' => $id, 'legacy_image' => "DFM-Book_$id.jpg", 'title' => "Book $id", 'publisher' => 'Fastback Series',
            'series' => 'Fastback', 'level' => 'YL-5', 'difficulty' => 5, 'words' => 7500, 'genre' => 'th',
            'fiction' => 'f', 'points' => 1.5, 'has_quiz' => true, 'cover_etag' => 'e1', 'updated_at' => 1,
            'withdrawn' => false,
        ];
    }

    /**
     * Mark the site approved.
     *
     * @return void
     */
    protected function approve(): void {
        set_config('v2siteid', 'site-1', 'mod_moodlereader');
        set_config('v2secret', 'Zm9vYmFyLWV4YW1wbGUtc2VjcmV0LTMyLWJ5dGVzISE', 'mod_moodlereader');
        set_config('v2state', 'approved', 'mod_moodlereader');
    }

    /**
     * Field mapping: level label to series, legacy image kept as text (no PARAM_FILE mangling), language.
     *
     * @return void
     */
    public function test_clean_book(): void {
        $book = downloader::clean_book($this->book(7, ['title' => "Brannigan's Folly", 'legacy_image' => 'A & B (1).jpg']));
        $this->assertSame(7, $book->remoteid);
        $this->assertSame("Brannigan's Folly", $book->name);
        $this->assertSame('YL-5', $book->series);
        $this->assertSame('A & B (1).jpg', $book->image);
        $this->assertSame(0, $book->withdrawn);
        $this->assertNull(downloader::clean_book(['title' => 'No id']));
        $this->assertNull(downloader::clean_book(['id' => 3]));
        $this->assertSame(1, downloader::clean_book($this->book(8, ['has_quiz' => false]))->withdrawn);
        // Language: sent by newer servers, unknown ('') when missing or malformed.
        $this->assertSame('', $book->language);
        $this->assertSame('es', downloader::clean_book($this->book(9, ['language' => 'ES']))->language);
        $this->assertSame('', downloader::clean_book($this->book(9, ['language' => '../x']))->language);
    }

    /**
     * Pages are followed, books matched by id, imported books linked by legacy image, teacher curation kept.
     *
     * @return void
     */
    public function test_sync_pages_and_matches(): void {
        global $DB;
        $this->resetAfterTest();
        $this->approve();
        /** @var \mod_moodlereader_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        // A book imported from mod_reader: no remote id yet, hidden by a teacher.
        $imported = $gen->create_book(['remoteid' => 0, 'image' => 'DFM-Book_2.jpg', 'hidden' => 1]);

        $client = (new fake_client())
            ->queue('GET', '/books', 200, ['books' => [$this->book(1)], 'next_cursor' => 'c2'])
            ->queue('GET', '/books', 200, ['books' => [$this->book(2), ['id' => 0, 'title' => 'bad']],
                'next_cursor' => null]);
        $this->assertSame(2, downloader::sync_books(false, $client));
        $this->assertSame(['changed_since' => '0', 'cursor' => 'c2', 'limit' => '500'], $client->requests[1]['query']);

        $this->assertSame(2, $DB->count_records('moodlereader_books'));
        $linked = $DB->get_record('moodlereader_books', ['id' => $imported->id]);
        $this->assertEquals(2, $linked->remoteid);
        $this->assertEquals(1, $linked->hidden);

        // A later sync asks for changes only, and a withdrawn book is flagged, not deleted.
        $client = (new fake_client())->queue('GET', '/books', 200, ['books' => [$this->book(1, ['withdrawn' => true])],
            'next_cursor' => null]);
        downloader::sync_books(false, $client);
        $this->assertNotSame('0', $client->requests[0]['query']['changed_since']);
        $this->assertEquals(1, $DB->get_field('moodlereader_books', 'withdrawn', ['remoteid' => 1]));
        $this->assertSame(2, $DB->count_records('moodlereader_books'));
    }

    /**
     * No sync without an approved connection.
     *
     * @return void
     */
    public function test_requires_connection(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        downloader::sync_books(false, new fake_client());
    }
}
