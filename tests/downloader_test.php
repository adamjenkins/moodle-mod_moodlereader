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

use mod_moodlereader\local\downloader;

/**
 * Tests for the mreader.org book-list parser.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\downloader::class)]
final class downloader_test extends \advanced_testcase {
    /**
     * Invoke the protected extract_books() on a raw response string.
     *
     * @param string $response
     * @return \stdClass[]
     */
    protected function extract(string $response): array {
        $method = new \ReflectionMethod(downloader::class, 'extract_books');
        $method->setAccessible(true);
        return $method->invoke(null, $response);
    }

    /**
     * The real mreader.org JSON shape maps title->name and level->series.
     *
     * @return void
     */
    public function test_parses_mreader_json(): void {
        $json = json_encode([
            '7' => [
                'id' => '7', 'publisher' => 'Fastback Series', 'level' => 'YL-5',
                'title' => "Brannigan's Folly", 'sametitle' => "Brannigan's Folly",
                'difficulty' => '5', 'words' => '7500', 'genre' => 'th', 'fiction' => 'f',
                'image' => 'DFM-Brannigans_Folly.jpg',
            ],
            '8' => [
                'id' => '8', 'publisher' => 'Fastback Series', 'level' => 'YL-5',
                'title' => 'Death at the Border', 'difficulty' => '6', 'words' => '7200',
                'image' => 'DFM-Death_at_the_Border.jpg',
            ],
        ]);
        $books = $this->extract($json);

        $this->assertCount(2, $books);
        $this->assertEquals("Brannigan's Folly", $books[0]->name);
        $this->assertEquals('YL-5', $books[0]->series);
        $this->assertEquals('Fastback Series', $books[0]->publisher);
        $this->assertEquals(7500, $books[0]->words);
        $this->assertEquals(5, $books[0]->difficulty);
        $this->assertEquals('DFM-Brannigans_Folly.jpg', $books[0]->image);
    }

    /**
     * A non-JSON or empty body yields no books rather than an error.
     *
     * @return void
     */
    public function test_rejects_non_json(): void {
        $this->assertSame([], $this->extract('<xml>legacy</xml>'));
        $this->assertSame([], $this->extract(''));
        $this->assertSame([], $this->extract('null'));
    }

    /**
     * Entries without a title are skipped.
     *
     * @return void
     */
    public function test_skips_entries_without_title(): void {
        $json = json_encode(['1' => ['publisher' => 'X', 'words' => '100']]);
        $this->assertSame([], $this->extract($json));
    }
}
