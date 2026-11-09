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

use mod_moodlereader\local\booklanguage;

/**
 * The language filter is offered only when the books on offer are in more than one language.
 *
 * @package    mod_moodlereader
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\booklanguage::class)]
final class booklanguage_test extends \advanced_testcase {
    /**
     * One language (or none): no filter, and every book is searched whatever is submitted.
     *
     * @return void
     */
    public function test_one_language_has_no_filter(): void {
        $this->assertFalse(booklanguage::filter_needed([]));
        $this->assertFalse(booklanguage::filter_needed(['en']));
        $this->assertFalse(booklanguage::filter_needed(['ja', '']));
        $this->assertSame('', booklanguage::filter_value('', false, ['en']));
        $this->assertSame('', booklanguage::filter_value('ar', true, ['en']));
        $this->assertSame('', booklanguage::filter_value('', false, ['ja']));
    }

    /**
     * Several languages: only those are offered; English first when present, else any language.
     *
     * @return void
     */
    public function test_several_languages(): void {
        $this->assertTrue(booklanguage::filter_needed(['en', 'es']));
        $this->assertSame(['en', 'es'], array_keys(booklanguage::present_options(['es', 'en'])));
        $this->assertSame('en', booklanguage::filter_value('', false, ['en', 'es']));
        $this->assertSame('es', booklanguage::filter_value('es', true, ['en', 'es']));
        $this->assertSame('', booklanguage::filter_value('', true, ['en', 'es']));
        // A language not on offer is never filtered by, and without English the search starts on any language.
        $this->assertSame('', booklanguage::filter_value('ar', true, ['en', 'es']));
        $this->assertSame('', booklanguage::filter_value('', false, ['ja', 'es']));
    }
}
