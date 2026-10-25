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

use mod_moodlereader\local\api\fake_client;
use mod_moodlereader\local\attempt;
use mod_moodlereader\local\mreader_site;
use mod_moodlereader\local\reader;
use mod_moodlereader\local\results;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/fake_client.php');

/**
 * Tests for quiz launches and results over API v2.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\results::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\mreader_site::class)]
final class results_test extends \advanced_testcase {
    /**
     * An approved site, an activity, a student, a linked book and a started attempt.
     *
     * @return array [reader, user, book, attempt]
     */
    protected function setup_attempt(): array {
        $this->resetAfterTest();
        set_config('v2siteid', 'site-1', 'mod_moodlereader');
        set_config('v2secret', 'Zm9vYmFyLWV4YW1wbGUtc2VjcmV0LTMyLWJ5dGVzISE', 'mod_moodlereader');
        set_config('v2state', 'approved', 'mod_moodlereader');
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        /** @var \mod_moodlereader_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $instance = $gen->create_instance(['course' => $course->id, 'goal' => 5000, 'minpassgrade' => 60]);
        $book = $gen->create_book(['words' => 5000, 'difficulty' => 0, 'remoteid' => 1234]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student', ['firstname' => 'Hanako',
            'lastname' => 'Yamada', 'email' => 'hanako@example.com', 'username' => 'hyamada']);
        $reader = reader::from_instanceid($instance->id);
        $attempt = attempt::start($reader, $user->id, $book->id);
        return [$reader, $user, $book, $attempt];
    }

    /**
     * A launch sends only the opaque identity, and gives the attempt a reference.
     *
     * @return void
     */
    public function test_launch_sends_only_pseudonyms(): void {
        global $DB;
        [, $user, $book, $attempt] = $this->setup_attempt();
        $client = (new fake_client())->queue('POST', '/launches', 201, ['launch_url' => 'https://mreader.org/q/abc',
            'expires_at' => time() + 120]);

        $this->assertSame('https://mreader.org/q/abc', results::launch($attempt, $book, $client));

        $ref = $DB->get_field('moodlereader_attempts', 'attemptref', ['id' => $attempt->id]);
        $this->assertMatchesRegularExpression('/^' . $attempt->id . '-[A-Za-z0-9]{16}$/', $ref);
        $identity = $DB->get_record('moodlereader_users', ['userid' => $user->id]);
        $body = json_decode($client->requests[0]['body'], true);
        $this->assertSame(['uid' => $identity->uniqueid, 'display_name' => $identity->uniquename, 'book_id' => 1234,
            'attempt_ref' => $ref, 'locale' => 'en'], $body);
        // Nothing that identifies the student is in the request.
        foreach (['Hanako', 'Yamada', 'hanako@example.com', 'hyamada', '"' . $user->id . '"'] as $secret) {
            $this->assertStringNotContainsString($secret, $client->requests[0]['body']);
        }
        $this->assertNotSame((string) $user->id, $identity->uniqueid);
    }

    /**
     * The grade comes only from the signed server response, and only if it matches the attempt.
     *
     * @return void
     */
    public function test_check_finishes_from_server_result(): void {
        global $DB;
        [, $user, $book, $attempt] = $this->setup_attempt();
        $launch = (new fake_client())->queue('POST', '/launches', 201, ['launch_url' => 'https://mreader.org/q/abc']);
        results::launch($attempt, $book, $launch);
        $attempt = $DB->get_record('moodlereader_attempts', ['id' => $attempt->id]);
        $identity = (new mreader_site())->get_identity($user->id);

        // A result for another student is ignored.
        $client = (new fake_client())->queue('GET', '/attempt', 200, ['attempt_ref' => $attempt->attemptref,
            'uid' => 'someoneelse', 'book_id' => 1234, 'state' => 'finished', 'percent' => 100]);
        $this->assertSame('inprogress', results::check($attempt, true, $client));
        $this->assertDebuggingCalled('mreader.org result does not match attempt ' . $attempt->id);
        $this->assertSame(['ref' => $attempt->attemptref], $client->requests[0]['query']);

        // Still in progress.
        $client = (new fake_client())->queue('GET', '/attempt', 200, ['attempt_ref' => $attempt->attemptref,
            'uid' => $identity->uniqueid, 'book_id' => 1234, 'state' => 'in_progress', 'percent' => null]);
        $this->assertSame('inprogress', results::check($attempt, true, $client));

        // Finished: the attempt takes the server's score.
        $client = (new fake_client())->queue('GET', '/attempt', 200, ['attempt_ref' => $attempt->attemptref,
            'uid' => $identity->uniqueid, 'book_id' => 1234, 'state' => 'finished', 'percent' => 80]);
        $this->assertSame('finished', results::check($attempt, true, $client));
        $row = $DB->get_record('moodlereader_attempts', ['id' => $attempt->id]);
        $this->assertSame('finished', $row->state);
        $this->assertEquals(80, $row->percentgrade);
        $this->assertEquals(1, $row->passed);
    }

    /**
     * Checks are rate limited unless forced.
     *
     * @return void
     */
    public function test_check_is_rate_limited(): void {
        global $DB;
        [, , $book, $attempt] = $this->setup_attempt();
        results::launch($attempt, $book, (new fake_client())->queue(
            'POST',
            '/launches',
            201,
            ['launch_url' => 'https://mreader.org/q/abc']
        ));
        $attempt = $DB->get_record('moodlereader_attempts', ['id' => $attempt->id]);
        $attempt->timeresultchecked = time();
        $client = new fake_client();
        $this->assertSame('inprogress', results::check($attempt, false, $client));
        $this->assertCount(0, $client->requests);
    }

    /**
     * Reconcile collects results whose browser hand-back never arrived.
     *
     * @return void
     */
    public function test_reconcile(): void {
        global $DB;
        [, $user, $book, $attempt] = $this->setup_attempt();
        results::launch($attempt, $book, (new fake_client())->queue(
            'POST',
            '/launches',
            201,
            ['launch_url' => 'https://mreader.org/q/abc']
        ));
        $attempt = $DB->get_record('moodlereader_attempts', ['id' => $attempt->id]);
        $identity = (new mreader_site())->get_identity($user->id);
        $finished = time() - 60;
        $client = (new fake_client())
            ->queue('GET', '/attempts', 200, ['attempts' => [['attempt_ref' => $attempt->attemptref,
                'uid' => $identity->uniqueid, 'book_id' => 1234, 'state' => 'finished', 'percent' => 40,
                'finished_at' => $finished]], 'next_cursor' => 'p2'])
            ->queue('GET', '/attempts', 200, ['attempts' => [], 'next_cursor' => null]);

        $this->assertSame(1, results::reconcile($client));
        $this->assertSame('finished', $DB->get_field('moodlereader_attempts', 'state', ['id' => $attempt->id]));
        $this->assertSame('p2', $client->requests[1]['query']['cursor']);
        $this->assertEquals($finished - 300, get_config('mod_moodlereader', 'v2reconcilesince'));
    }

    /**
     * A user's opaque identity is created once and reused.
     *
     * @return void
     */
    public function test_identity_is_stable_and_opaque(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $site = new mreader_site();
        $first = $site->get_identity($user->id);
        $this->assertSame($first->uniqueid, $site->get_identity($user->id)->uniqueid);
        $this->assertSame(8, strlen($first->uniqueid));
        $this->assertStringNotContainsString($user->username, $first->uniquename);
    }
}
