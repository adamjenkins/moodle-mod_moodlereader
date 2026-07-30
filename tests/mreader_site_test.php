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

use mod_moodlereader\local\mreader_site;
use moodle_url;

/**
 * Tests for the mreader.org client, especially token verification.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\mreader_site::class)]
final class mreader_site_test extends \advanced_testcase {
    /**
     * A token generated for a URL verifies against that same URL.
     *
     * @return void
     */
    public function test_token_roundtrip(): void {
        $this->resetAfterTest();
        set_config('mreadersitekey', 'secret-key-123', 'mod_moodlereader');
        set_config('mreadersiteid', '42', 'mod_moodlereader');

        $site = new mreader_site();
        $url = new moodle_url('https://mreader.org/api_takequiz.php', ['sid' => '42', 'custom_id' => 7]);
        $token = $site->generate_token($url);

        $this->assertNotEmpty($token);
        $this->assertTrue($site->verify_token($url, $token));
    }

    /**
     * A wrong or empty token is rejected (the legacy always-passes bug must not recur).
     *
     * @return void
     */
    public function test_token_rejects_forgery(): void {
        $this->resetAfterTest();
        set_config('mreadersitekey', 'secret-key-123', 'mod_moodlereader');

        $site = new mreader_site();
        $url = new moodle_url('https://mreader.org/api_takequiz.php', ['custom_id' => 7, 'grade' => 100]);

        $this->assertFalse($site->verify_token($url, ''));
        $this->assertFalse($site->verify_token($url, 'not-the-real-token'));
        $this->assertFalse($site->verify_token($url, md5('wrong')));
    }

    /**
     * Tampering with a signed parameter invalidates the token.
     *
     * @return void
     */
    public function test_token_detects_tampering(): void {
        $this->resetAfterTest();
        set_config('mreadersitekey', 'secret-key-123', 'mod_moodlereader');

        $site = new mreader_site();
        $honest = new moodle_url('https://mreader.org/x.php', ['custom_id' => 7, 'grade' => 50]);
        $token = $site->generate_token($honest);

        $tampered = new moodle_url('https://mreader.org/x.php', ['custom_id' => 7, 'grade' => 100]);
        $this->assertFalse($site->verify_token($tampered, $token));
    }

    /**
     * The opaque identity is created once and reused, and never exposes the username.
     *
     * @return void
     */
    public function test_identity_is_stable_and_opaque(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['username' => 'realstudent']);

        $site = new mreader_site();
        $first = $site->get_identity($user->id);
        $second = $site->get_identity($user->id);

        $this->assertSame($first->uniqueid, $second->uniqueid);
        $this->assertStringNotContainsString('realstudent', $first->uniqueid);
        $this->assertEquals(1, $DB->count_records('moodlereader_users', ['userid' => $user->id]));
    }
}
