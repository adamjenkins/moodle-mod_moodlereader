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
 * A book's details changed on this site: title, publisher, series, reading level and words.
 *
 * The book's own columns hold what the site uses. {moodlereader_books}.localedits records, for each changed
 * field, the local value and mreader.org's value (JSON: field => {local, remote}). The sync keeps the local
 * value and updates the recorded mreader.org value, so a change survives every sync and can be undone.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookedits {
    /** @var string[] The fields that can be changed, with their label strings. */
    const FIELDS = [
        'name'       => 'booktitle',
        'publisher'  => 'publisher',
        'series'     => 'series',
        'difficulty' => 'readinglevel',
        'words'      => 'words',
    ];

    /**
     * The recorded changes of a book.
     *
     * @param \stdClass $book A {moodlereader_books} record.
     * @return array field => ['local' => value, 'remote' => value]
     */
    public static function decode(\stdClass $book): array {
        $edits = json_decode((string) ($book->localedits ?? ''), true);
        $out = [];
        foreach (is_array($edits) ? $edits : [] as $field => $edit) {
            if (
                isset(self::FIELDS[$field]) && is_array($edit) && array_key_exists('local', $edit)
                    && array_key_exists('remote', $edit)
            ) {
                $out[$field] = ['local' => self::clean($field, $edit['local']), 'remote' => self::clean($field, $edit['remote'])];
            }
        }
        return $out;
    }

    /**
     * The fields changed on this site.
     *
     * @param \stdClass $book
     * @return string[]
     */
    public static function fields(\stdClass $book): array {
        return array_keys(self::decode($book));
    }

    /**
     * A field's value as stored: text cleaned and cut to the column, numbers held to their range.
     *
     * @param string $field
     * @param mixed $value
     * @return string|int
     */
    public static function clean(string $field, $value) {
        $value = is_scalar($value) ? (string) $value : '';
        switch ($field) {
            case 'difficulty':
                return max(0, min(99, (int) $value));
            case 'words':
                return max(0, min(999999, (int) $value));
            case 'name':
                return \core_text::substr(trim(clean_param($value, PARAM_TEXT)), 0, 255);
            default:
                return \core_text::substr(trim(clean_param($value, PARAM_TEXT)), 0, 100);
        }
    }

    /**
     * Change a book's details on this site. A field set back to mreader.org's value is no longer a change.
     *
     * @param int $bookid
     * @param array $values field => new value (fields not given are left alone).
     * @return \stdClass The updated book.
     */
    public static function save(int $bookid, array $values): \stdClass {
        global $DB;
        $book = $DB->get_record('moodlereader_books', ['id' => $bookid], '*', MUST_EXIST);
        $edits = self::decode($book);
        $update = (object) ['id' => $book->id];
        foreach (self::FIELDS as $field => $unused) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            $new = self::clean($field, $values[$field]);
            if ($field === 'name' && $new === '') {
                continue;
            }
            $remote = array_key_exists($field, $edits) ? $edits[$field]['remote'] : self::clean($field, $book->$field);
            if ($new === $remote) {
                unset($edits[$field]);
            } else {
                $edits[$field] = ['local' => $new, 'remote' => $remote];
            }
            $update->$field = $new;
        }
        $update->localedits = $edits ? json_encode($edits) : null;
        $DB->update_record('moodlereader_books', $update);
        return $DB->get_record('moodlereader_books', ['id' => $bookid], '*', MUST_EXIST);
    }

    /**
     * Undo every change: the book takes mreader.org's details again.
     *
     * @param int $bookid
     * @return void
     */
    public static function revert(int $bookid): void {
        global $DB;
        $book = $DB->get_record('moodlereader_books', ['id' => $bookid], '*', MUST_EXIST);
        $update = (object) ['id' => $book->id, 'localedits' => null];
        foreach (self::decode($book) as $field => $edit) {
            $update->$field = $edit['remote'];
        }
        $DB->update_record('moodlereader_books', $update);
    }

    /**
     * Keep a book's local changes through a sync: the synced record takes the local values, and the changes
     * record mreader.org's new values.
     *
     * @param \stdClass $existing The stored book.
     * @param \stdClass $synced The cleaned record from mreader.org, changed in place.
     * @return void
     */
    public static function keep_through_sync(\stdClass $existing, \stdClass $synced): void {
        $edits = self::decode($existing);
        foreach ($edits as $field => $edit) {
            if (!property_exists($synced, $field)) {
                continue;
            }
            $remote = self::clean($field, $synced->$field);
            if ($remote === $edit['local']) {
                // The mreader.org value now agrees with the local change: it is no longer one.
                unset($edits[$field]);
                continue;
            }
            $edits[$field]['remote'] = $remote;
            $synced->$field = $edit['local'];
        }
        $synced->localedits = $edits ? json_encode($edits) : null;
    }
}
