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

use stdClass;

/**
 * Remembers, for a day, the books whose cover mreader.org did not supply, so cover.php
 * does not ask mreader.org again on every view of such a book.
 *
 * A miss is tied to the book's cover ETag: when a book sync brings a new ETag, the
 * remembered miss no longer applies and the cover is fetched again.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class covermiss {
    /**
     * The cache.
     *
     * @return \cache
     */
    protected static function cache(): \cache {
        return \cache::make('mod_moodlereader', 'covermiss');
    }

    /**
     * Whether a recent fetch of this book's cover (with its current ETag) found nothing.
     *
     * @param stdClass $book A moodlereader_books row (id, coveretag).
     * @return bool
     */
    public static function is_known(stdClass $book): bool {
        $etag = self::cache()->get((int) $book->id);
        return $etag !== false && $etag === (string) ($book->coveretag ?? '');
    }

    /**
     * Remember that fetching this book's cover found nothing.
     *
     * @param stdClass $book A moodlereader_books row (id, coveretag).
     */
    public static function remember(stdClass $book): void {
        self::cache()->set((int) $book->id, (string) ($book->coveretag ?? ''));
    }

    /**
     * Forget a remembered miss (a cover has been stored for the book).
     *
     * @param int $bookid
     */
    public static function forget(int $bookid): void {
        self::cache()->delete($bookid);
    }
}
