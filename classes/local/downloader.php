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

namespace mod_moodlereader\local;

use mod_moodlereader\local\api\client;

/**
 * Synchronises the local book catalogue from mreader.org (API v2).
 *
 * Books are matched on mreader.org's stable book id (`remoteid`, within the quiz or
 * no-quiz id space). A book that has
 * no id yet (one imported from mod_reader) is matched once on its legacy image
 * name, then on publisher/series/title, and takes the id from then on.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class downloader {
    /** @var int Books per page requested from mreader.org. */
    const PAGE_SIZE = 500;

    /**
     * Fetch the catalogue from mreader.org and upsert it into {moodlereader_books}.
     *
     * Two walks: the books with quizzes (/books), then the books without one
     * (/books-noquiz). The second is skipped while mreader.org does not offer it (404).
     *
     * @param bool $full Fetch the whole catalogue rather than the changes.
     * @param client|null $client
     * @return int Number of books inserted or updated.
     */
    public static function sync_books(bool $full = false, ?client $client = null): int {
        if (!connection::allows_launch()) {
            throw new \moodle_exception('notconnected', 'mod_moodlereader');
        }
        $client = $client ?? new client();
        $since = $full ? 0 : (int) get_config('mod_moodlereader', 'v2lastbooksync');
        $servertime = 0;
        $count = self::walk($client, '/books', $since, true, $servertime);
        try {
            $count += self::walk($client, '/books-noquiz', $since, false, $servertime);
        } catch (api\api_exception $e) {
            if ((int) $e->httpstatus !== 404) {
                throw $e;
            }
        }

        // The next sync asks for changes since mreader.org's clock at the start of this one.
        if ($servertime > 0) {
            set_config('v2lastbooksync', $servertime, 'mod_moodlereader');
        }
        return $count;
    }

    /**
     * Follow one paged book list to its end.
     *
     * @param client $client
     * @param string $path '/books' or '/books-noquiz'.
     * @param int $since
     * @param bool $hasquiz Whether the list's books have quizzes.
     * @param int $servertime Set from the first page when still 0.
     * @return int Books inserted or updated.
     */
    protected static function walk(client $client, string $path, int $since, bool $hasquiz, int &$servertime): int {
        $cursor = null;
        $count = 0;
        $pages = 0;
        do {
            $query = ['changed_since' => $since, 'limit' => self::PAGE_SIZE];
            if ($cursor !== null) {
                $query['cursor'] = $cursor;
            }
            $data = $client->get($path, $query);
            $servertime = $servertime ?: (int) ($data['server_time'] ?? 0);
            foreach ((array) ($data['books'] ?? []) as $row) {
                // The list decides whether a book has a quiz, whatever the row says.
                if (is_array($row) && ($book = self::clean_book(['has_quiz' => $hasquiz] + $row))) {
                    $book->hasquiz = $hasquiz ? 1 : 0;
                    self::upsert($book);
                    $count++;
                }
            }
            $cursor = isset($data['next_cursor']) && $data['next_cursor'] !== '' ? (string) $data['next_cursor'] : null;
        } while ($cursor !== null && ++$pages < 1000);
        return $count;
    }

    /**
     * Insert or update one catalogue book.
     *
     * @param \stdClass $book Cleaned book (see {@see self::clean_book()}), plus `coveretag`.
     * @return int Local book id.
     */
    protected static function upsert(\stdClass $book): int {
        global $DB;
        // Quiz and no-quiz books are numbered separately on mreader.org.
        $existing = $DB->get_record(
            'moodlereader_books',
            ['remoteid' => $book->remoteid, 'hasquiz' => $book->hasquiz],
            '*',
            IGNORE_MULTIPLE
        );
        if (!$existing && $book->image !== '') {
            $existing = $DB->get_record(
                'moodlereader_books',
                ['remoteid' => 0, 'hasquiz' => $book->hasquiz, 'image' => $book->image],
                '*',
                IGNORE_MULTIPLE
            );
        }
        if (!$existing) {
            $existing = $DB->get_record('moodlereader_books', [
                'remoteid' => 0, 'hasquiz' => $book->hasquiz, 'publisher' => $book->publisher, 'series' => $book->series,
                'name' => $book->name,
            ], '*', IGNORE_MULTIPLE);
        }
        $book->time = time();
        if (!$existing) {
            $book->hidden = 0;
            return $DB->insert_record('moodlereader_books', $book);
        }
        $book->id = $existing->id;
        // A changed cover is fetched again on its next view.
        if ((string) $existing->coveretag !== (string) $book->coveretag) {
            self::delete_cached_covers((int) $book->id);
        }
        // Never override a teacher's curation of the 'hidden' flag on re-sync.
        $DB->update_record('moodlereader_books', $book);
        return (int) $book->id;
    }

    /**
     * Delete every cached copy of a book's cover (they are stored per activity context).
     *
     * @param int $bookid
     * @return void
     */
    protected static function delete_cached_covers(int $bookid): void {
        global $DB;
        $fs = get_file_storage();
        $contextids = $DB->get_fieldset_select(
            'files',
            'DISTINCT contextid',
            "component = 'mod_moodlereader' AND filearea = 'bookcover' AND itemid = ?",
            [$bookid]
        );
        foreach ($contextids as $contextid) {
            $fs->delete_area_files((int) $contextid, 'mod_moodlereader', 'bookcover', $bookid);
        }
    }

    /**
     * Map one book object from mreader.org into a clean local record.
     *
     * Every stored value is cleaned before it reaches the database.
     *
     * @param array $row
     * @return \stdClass|null Null if the row has no id or title.
     */
    public static function clean_book(array $row): ?\stdClass {
        $get = fn($k) => isset($row[$k]) && is_scalar($row[$k]) ? trim((string) $row[$k]) : '';
        $remoteid = (int) clean_param($get('id'), PARAM_INT);
        if ($remoteid <= 0 || $get('title') === '') {
            return null;
        }
        // The local 'series' column holds what mreader.org calls the level label.
        $series = $get('level') !== '' ? $get('level') : $get('series');
        return (object) [
            'remoteid'   => $remoteid,
            'publisher'  => \core_text::substr(clean_param($get('publisher'), PARAM_TEXT), 0, 100),
            'series'     => \core_text::substr(clean_param($series, PARAM_TEXT), 0, 100),
            'difficulty' => (int) clean_param($get('difficulty'), PARAM_INT),
            'name'       => \core_text::substr(clean_param($get('title'), PARAM_TEXT), 0, 255),
            'words'      => max(0, min(999999, (int) clean_param($get('words'), PARAM_INT))),
            'genre'      => \core_text::substr(clean_param($get('genre'), PARAM_TEXT), 0, 255),
            'fiction'    => \core_text::substr(clean_param($get('fiction'), PARAM_TEXT), 0, 255),
            'points'     => (float) clean_param($get('points'), PARAM_FLOAT),
            // The legacy image name is kept as text, never run through PARAM_FILE (contract defect B1).
            'image'      => \core_text::substr(clean_param($get('legacy_image'), PARAM_TEXT), 0, 255),
            'sametitle'  => \core_text::substr(clean_param($get('sametitle'), PARAM_TEXT), 0, 255),
            'language'   => booklanguage::clean($get('language')),
            'withdrawn'  => !empty($row['withdrawn']) ? 1 : 0,
            'hasquiz'    => isset($row['has_quiz']) && !$row['has_quiz'] ? 0 : 1,
            'coveretag'  => $get('cover_etag') !== '' ? \core_text::substr($get('cover_etag'), 0, 255) : null,
        ];
    }
}
