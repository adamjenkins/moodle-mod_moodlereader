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
 * The "same book" key: books whose keys match are one book for a student (the same title from another
 * publisher, another edition), so finishing one uses up all of them.
 *
 * Keys are stored normalised as mreader.org compares them (its SameTitleKey::normalise()): lower-cased,
 * trimmed, and every run of white space, no-break and full-width spaces included, made one space. A leading
 * "The" is not ignored. Stored this way, keys from mreader.org, from mod_reader and from backups compare with
 * plain string equality. An empty key groups nothing.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sametitle {
    /**
     * Normalise a key.
     *
     * @param string $key As typed or sent.
     * @return string At most 255 characters; '' for a book that stands alone.
     */
    public static function normalise(string $key): string {
        $key = clean_param($key, PARAM_TEXT);
        $key = preg_replace('/[\s\p{Zs}\x{FEFF}]+/u', ' ', $key) ?? '';
        return \core_text::substr(\core_text::strtolower(trim($key)), 0, 255);
    }

    /**
     * The key in a book item from mreader.org, or null when the item does not carry one (then the stored key
     * is kept). `same_title` is the field agreed for API v2; `sametitle` is accepted too.
     *
     * @param array $row A GET /books or /books-noquiz item.
     * @return string|null
     */
    public static function from_item(array $row): ?string {
        foreach (['same_title', 'sametitle'] as $field) {
            if (array_key_exists($field, $row) && (is_scalar($row[$field]) || $row[$field] === null)) {
                return self::normalise((string) $row[$field]);
            }
        }
        return null;
    }
}
