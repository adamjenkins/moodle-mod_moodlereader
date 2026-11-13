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
use mod_moodlereader\local\bookedits;
use mod_moodlereader\local\booklanguage;
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
        // A book without a quiz is not withdrawn: credit can still be awarded for it.
        $noquiz = downloader::clean_book($this->book(8, ['has_quiz' => false]));
        $this->assertSame(0, $noquiz->withdrawn);
        $this->assertSame(0, $noquiz->hasquiz);
        $this->assertSame(1, $book->hasquiz);
        // Language: sent by newer servers, unknown ('') when missing or malformed.
        $this->assertSame('', $book->language);
        $this->assertSame('es', downloader::clean_book($this->book(9, ['language' => 'ES']))->language);
        $this->assertSame('', downloader::clean_book($this->book(9, ['language' => '../x']))->language);
        // Level "99" ("Extra points") is not a book: not synced.
        $this->assertNull(downloader::clean_book($this->book(10, ['level' => '99'])));
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
                'next_cursor' => null])
            // A book without a quiz, numbered 1 in mreader.org's separate no-quiz list.
            ->queue('GET', '/books-noquiz', 200, ['books' => [$this->book(1, ['title' => 'No Quiz Book'])],
                'next_cursor' => null]);
        $this->assertSame(3, downloader::sync_books(false, $client));
        $this->assertSame(['changed_since' => '0', 'cursor' => 'c2', 'limit' => '500'], $client->requests[1]['query']);

        $this->assertSame(3, $DB->count_records('moodlereader_books'));
        $this->assertSame('No Quiz Book', $DB->get_field('moodlereader_books', 'name', ['remoteid' => 1, 'hasquiz' => 0]));
        $this->assertSame('Book 1', $DB->get_field('moodlereader_books', 'name', ['remoteid' => 1, 'hasquiz' => 1]));
        $linked = $DB->get_record('moodlereader_books', ['id' => $imported->id]);
        $this->assertEquals(2, $linked->remoteid);
        $this->assertEquals(1, $linked->hidden);

        // A later sync asks for changes only, and a withdrawn book is flagged, not deleted.
        // Each list sends its own date, the server_time of the first page of its last complete walk.
        $booksince = get_config('mod_moodlereader', 'v2lastbooksync');
        $noquizsince = get_config('mod_moodlereader', 'v2lastnoquizsync');
        $this->assertNotEmpty($booksince);
        $this->assertNotEmpty($noquizsince);
        // A server without the no-quiz list (404) is not an error, and keeps that list's date.
        $client = (new fake_client())->queue('GET', '/books', 200, ['books' => [$this->book(1, ['withdrawn' => true])],
            'next_cursor' => null])
            ->queue('GET', '/books-noquiz', 404, ['error' => ['code' => 'not_found', 'message' => 'Not found.']]);
        downloader::sync_books(false, $client);
        $this->assertSame((string) $booksince, $client->requests[0]['query']['changed_since']);
        $this->assertSame((string) $noquizsince, $client->requests[1]['query']['changed_since']);
        $this->assertSame($noquizsince, get_config('mod_moodlereader', 'v2lastnoquizsync'));
        $this->assertEquals(1, $DB->get_field('moodlereader_books', 'withdrawn', ['remoteid' => 1, 'hasquiz' => 1]));
        $this->assertSame(3, $DB->count_records('moodlereader_books'));
    }

    /**
     * A walk that fails part-way keeps its old date, so the next sync asks again from there.
     *
     * @return void
     */
    public function test_failed_walk_keeps_its_date(): void {
        $this->resetAfterTest();
        $this->approve();
        set_config('v2lastbooksync', 1000, 'mod_moodlereader');
        $client = (new fake_client())
            ->queue('GET', '/books', 200, ['books' => [$this->book(1)], 'next_cursor' => 'c2'])
            ->queue('GET', '/books', 500, ['error' => ['code' => 'internal', 'message' => 'Oops.']]);
        try {
            downloader::sync_books(false, $client);
            $this->fail('A failed page must not pass silently.');
        } catch (\mod_moodlereader\local\api\api_exception $e) {
            $this->assertSame(500, $e->httpstatus);
        }
        $this->assertSame('1000', $client->requests[1]['query']['changed_since']);
        $this->assertSame('1000', get_config('mod_moodlereader', 'v2lastbooksync'));
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

    /**
     * The "same book" key: normalised as mreader.org compares it, set from the item when it carries one,
     * and kept when an update does not (a key from mod_reader is not wiped by a server that sends none).
     *
     * @return void
     */
    public function test_same_book_key(): void {
        global $DB;
        $this->resetAfterTest();
        $this->approve();
        $this->assertSame('the red kite', \mod_moodlereader\local\sametitle::normalise("  The\u{00A0}RED\u{3000}\t kite "));
        $this->assertSame('', \mod_moodlereader\local\sametitle::normalise("\u{3000} "));
        $this->assertArrayNotHasKey('sametitle', (array) downloader::clean_book($this->book(1)));
        $this->assertSame('red kite', downloader::clean_book($this->book(1, ['same_title' => 'Red  Kite']))->sametitle);

        $client = (new fake_client())
            ->queue('GET', '/books', 200, ['books' => [$this->book(1, ['same_title' => 'The Red Kite']),
                $this->book(2, ['publisher' => 'Other Press', 'same_title' => 'the red kite']), $this->book(3)],
                'next_cursor' => null])
            ->queue('GET', '/books-noquiz', 200, ['books' => [], 'next_cursor' => null]);
        downloader::sync_books(false, $client);
        $key = fn(int $id) => $DB->get_field('moodlereader_books', 'sametitle', ['remoteid' => $id, 'hasquiz' => 1]);
        $this->assertSame('the red kite', $key(1));
        $this->assertSame('the red kite', $key(2));
        $this->assertSame('', $key(3));

        // An update without the field keeps the key; one with an empty key clears it.
        $client = (new fake_client())
            ->queue('GET', '/books', 200, ['books' => [$this->book(1, ['title' => 'Renamed']),
                $this->book(2, ['same_title' => ''])], 'next_cursor' => null])
            ->queue('GET', '/books-noquiz', 200, ['books' => [], 'next_cursor' => null]);
        downloader::sync_books(true, $client);
        $this->assertSame('the red kite', $key(1));
        $this->assertSame('', $key(2));
    }

    /**
     * Only the books in the chosen languages are synced (and those with none); books already here in another
     * language are removed, or hidden when a student or an activity uses them; a new choice forces a full sync.
     *
     * @return void
     */
    public function test_sync_chosen_languages(): void {
        global $DB;
        $this->resetAfterTest();
        $this->approve();
        /** @var \mod_moodlereader_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        // Synced earlier, before the choice: a Spanish book nobody used and one a student finished.
        $unused = $gen->create_book(['remoteid' => 90, 'language' => 'es']);
        $used = $gen->create_book(['remoteid' => 91, 'language' => 'es']);
        $DB->insert_record('moodlereader_booksused', (object) ['userid' => 2, 'bookid' => $used->id, 'passed' => 1,
            'credit' => 0, 'cheated' => 0, 'timefinish' => time(), 'timecreated' => time()]);

        // Every language ticked, or none: the whole catalogue.
        set_config('synclanguages', implode(',', booklanguage::CODES), 'mod_moodlereader');
        $this->assertNull(downloader::sync_languages());
        set_config('synclanguages', '', 'mod_moodlereader');
        $this->assertNull(downloader::sync_languages());

        set_config('synclanguages', 'en,ja', 'mod_moodlereader');
        set_config('v2lastbooksync', 123, 'mod_moodlereader');
        downloader::languages_changed();
        $this->assertFalse(get_config('mod_moodlereader', 'v2lastbooksync'));
        $this->assertSame(['en', 'ja'], downloader::sync_languages());

        $client = (new fake_client())
            ->queue('GET', '/books', 200, ['books' => [
                $this->book(1, ['language' => 'en']),
                $this->book(2, ['language' => 'es']),
                $this->book(3, ['language' => 'ja']),
                $this->book(4, ['language' => '']),
            ], 'next_cursor' => null])
            ->queue('GET', '/books-noquiz', 200, ['books' => [$this->book(5, ['language' => ''])], 'next_cursor' => null]);
        $this->assertSame(4, downloader::sync_books(false, $client));
        $this->assertSame('0', $client->requests[0]['query']['changed_since']);
        $this->assertFalse($DB->record_exists('moodlereader_books', ['remoteid' => 2]));
        foreach ([1, 3, 4, 5] as $remoteid) {
            $this->assertTrue($DB->record_exists('moodlereader_books', ['remoteid' => $remoteid]));
        }
        $this->assertFalse($DB->record_exists('moodlereader_books', ['id' => $unused->id]));
        $this->assertEquals(1, $DB->get_field('moodlereader_books', 'hidden', ['id' => $used->id]));
    }

    /**
     * A book's details changed on this site survive a sync, record mreader.org's new values, and can be
     * undone (GitHub issue #3).
     *
     * @return void
     */
    public function test_local_edits_kept_through_sync(): void {
        global $DB;
        $this->resetAfterTest();
        $this->approve();
        $client = (new fake_client())
            ->queue('GET', '/books', 200, ['books' => [$this->book(1, ['title' => 'The Red Kyte'])], 'next_cursor' => null])
            ->queue('GET', '/books-noquiz', 200, ['books' => [], 'next_cursor' => null]);
        downloader::sync_books(true, $client);
        $book = $DB->get_record('moodlereader_books', ['remoteid' => 1, 'hasquiz' => 1]);

        // Lower the level and correct the title; a field set to its own value is not a change.
        $book = bookedits::save($book->id, ['difficulty' => 4, 'name' => 'The Red Kite', 'words' => 7500]);
        $this->assertSame(['name', 'difficulty'], bookedits::fields($book));
        $this->assertEquals(4, $book->difficulty);

        // The word count and the level change on mreader.org: the local level stays, its new value is recorded.
        $client = (new fake_client())
            ->queue('GET', '/books', 200, ['books' => [$this->book(1, ['title' => 'The Red Kyte', 'difficulty' => 6,
                'words' => 8000])], 'next_cursor' => null])
            ->queue('GET', '/books-noquiz', 200, ['books' => [], 'next_cursor' => null]);
        downloader::sync_books(true, $client);
        $book = $DB->get_record('moodlereader_books', ['id' => $book->id]);
        $this->assertEquals(4, $book->difficulty);
        $this->assertSame('The Red Kite', $book->name);
        $this->assertEquals(8000, $book->words);
        $this->assertSame(6, bookedits::decode($book)['difficulty']['remote']);

        // Undo: mreader.org's details again.
        bookedits::revert($book->id);
        $book = $DB->get_record('moodlereader_books', ['id' => $book->id]);
        $this->assertEquals(6, $book->difficulty);
        $this->assertSame('The Red Kyte', $book->name);
        $this->assertNull($book->localedits);
    }
}
