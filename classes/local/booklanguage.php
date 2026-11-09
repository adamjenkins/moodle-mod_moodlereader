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
     * The language filter's options: only the languages the books on offer are in, known ones first.
     * A filter with fewer than two of them is not shown (see {@see self::filter_needed()}).
     *
     * @param string[] $present Codes found among the books on offer.
     * @return array code => label
     */
    public static function present_options(array $present): array {
        $present = array_values(array_unique(array_filter(array_map('strval', $present))));
        $known = array_values(array_intersect(self::CODES, $present));
        $options = [];
        foreach (array_merge($known, array_diff($present, $known)) as $code) {
            $options[$code] = self::label($code);
        }
        return $options;
    }

    /**
     * Whether a language filter means anything: only when the books on offer are in more than one language.
     *
     * @param string[] $present Codes found among the books on offer ('' left out).
     * @return bool
     */
    public static function filter_needed(array $present): bool {
        return count(array_unique(array_filter(array_map('strval', $present)))) > 1;
    }

    /**
     * The language a search filters by.
     *
     * @param string $chosen The submitted value ('' = any language).
     * @param bool $searched Whether the form was submitted (before that, English is chosen, when there is any).
     * @param string[] $present Codes found among the books on offer.
     * @return string A code, or '' for every language (always, when the filter is not shown).
     */
    public static function filter_value(string $chosen, bool $searched, array $present): string {
        if (!self::filter_needed($present)) {
            return '';
        }
        $code = $searched ? self::clean($chosen) : self::DEFAULT;
        return in_array($code, $present, true) ? $code : '';
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
