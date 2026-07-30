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

/**
 * Synchronises the local book catalogue from mreader.org.
 *
 * mreader.org's api.php returns an XML list of books. The field mapping below
 * follows the legacy plugin's contract; if mreader.org changes its response
 * schema this is the single place to adjust.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class downloader {
    /**
     * Fetch the book list from mreader.org and upsert it into {moodlereader_books}.
     *
     * @return int Number of books inserted or updated.
     */
    public static function sync_books(): int {
        global $CFG, $DB;
        require_once($CFG->libdir . '/filelib.php');

        $site = new mreader_site();
        if (!$site->is_configured()) {
            throw new \moodle_exception('notconfigured', 'mod_moodlereader');
        }

        $url = $site->books_url(mreader_site::BOOKS_WITH_QUIZZES);
        $curl = new \curl();
        $response = $curl->get($url->out(false), [], [
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_RETURNTRANSFER' => 1,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_TIMEOUT'        => 120,
        ]);
        if ($curl->get_errno() || $response === false) {
            throw new \moodle_exception('registrationfailed', 'mod_moodlereader', '', $curl->error);
        }

        $books = self::extract_books($response);
        if (!$books) {
            throw new \moodle_exception('syncnodata', 'mod_moodlereader');
        }

        // Preload existing books once (natural key => id) to avoid a query per book.
        $existing = [];
        $rs = $DB->get_recordset('moodlereader_books', null, '', 'id, publisher, series, name');
        foreach ($rs as $b) {
            $existing[self::key($b->publisher, $b->series, $b->name)] = $b->id;
        }
        $rs->close();

        $count = 0;
        foreach ($books as $book) {
            $key = self::key($book->publisher, $book->series, $book->name);
            $book->time = time();
            if (isset($existing[$key])) {
                $book->id = $existing[$key];
                // Never override a teacher's curation of the 'hidden' flag on re-sync.
                unset($book->hidden);
                $DB->update_record('moodlereader_books', $book);
            } else {
                $existing[$key] = $DB->insert_record('moodlereader_books', $book);
            }
            $count++;
        }
        return $count;
    }

    /**
     * The natural key used to match a book across syncs.
     *
     * @param string $publisher
     * @param string $series
     * @param string $name
     * @return string
     */
    protected static function key(string $publisher, string $series, string $name): string {
        return \core_text::strtolower(trim("$publisher|$series|$name"));
    }

    /**
     * Map the mreader.org JSON response into clean book records.
     *
     * mreader.org's api.php returns a JSON object keyed by book id; each entry
     * uses "title" for the name and "level" for the series. Every stored value is
     * cleaned before it reaches the database.
     *
     * @param string $response Raw HTTP body.
     * @return \stdClass[]
     */
    protected static function extract_books(string $response): array {
        $data = json_decode($response, true);
        if (!is_array($data)) {
            return [];
        }
        $books = [];
        foreach ($data as $row) {
            if (!is_array($row) || empty($row['title'])) {
                continue;
            }
            $get = fn($k) => isset($row[$k]) ? trim((string) $row[$k]) : '';
            $books[] = (object) [
                'publisher'  => clean_param($get('publisher'), PARAM_TEXT),
                'series'     => clean_param($get('level'), PARAM_TEXT),
                'difficulty' => (int) clean_param($get('difficulty'), PARAM_INT),
                'name'       => clean_param($get('title'), PARAM_TEXT),
                'words'      => (int) clean_param($get('words'), PARAM_INT),
                'genre'      => clean_param($get('genre'), PARAM_TEXT),
                'fiction'    => clean_param($get('fiction'), PARAM_TEXT),
                'points'     => (float) clean_param($get('points'), PARAM_FLOAT),
                'image'      => clean_param($get('image'), PARAM_FILE),
                'sametitle'  => clean_param($get('sametitle'), PARAM_TEXT),
                'hidden'     => 0,
            ];
        }
        return $books;
    }
}
