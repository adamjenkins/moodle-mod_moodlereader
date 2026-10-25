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
 * The languages a book can be written in, as mreader.org stores them.
 *
 * A book whose language mreader.org has not sent (an empty value) is "unknown"
 * and is never hidden by a language filter.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booklanguage {
    /** @var string[] The language codes mreader.org uses for books. */
    const CODES = ['en', 'es', 'ja', 'ar'];

    /** @var string The language selected in the book search until a student changes it. */
    const DEFAULT = 'en';

    /**
     * Clean a language code received from mreader.org or a form.
     *
     * @param string $code
     * @return string A lower-case code of 2-10 letters, or '' when it is not one.
     */
    public static function clean(string $code): string {
        $code = strtolower(trim($code));
        return preg_match('/^[a-z]{2,10}$/', $code) ? $code : '';
    }

    /**
     * The readable name of a language code.
     *
     * @param string $code
     * @return string
     */
    public static function label(string $code): string {
        if (in_array($code, self::CODES, true)) {
            return get_string('booklanguage_' . $code, 'mod_moodlereader');
        }
        return $code === '' ? get_string('booklanguage_unknown', 'mod_moodlereader') : $code;
    }

    /**
     * Language menu options: the known languages plus any other code present in the given list.
     *
     * @param string[] $present Codes found in the catalogue.
     * @return array code => label
     */
    public static function options(array $present = []): array {
        $options = [];
        foreach (array_unique(array_merge(self::CODES, array_filter($present))) as $code) {
            $options[$code] = self::label($code);
        }
        return $options;
    }
}
