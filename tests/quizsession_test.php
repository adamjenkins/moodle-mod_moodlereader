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
use mod_moodlereader\local\quizsession;
use mod_moodlereader\local\reader;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_client.php');

/**
 * Tests for Moodle-drawn quizzes (API v2 31 §11): the launch, the Moodle-run clock, answers and the survey.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\quizsession::class)]
final class quizsession_test extends \advanced_testcase {
    /** @var reader */
    protected $reader;

    /** @var \stdClass */
    protected $attempt;

    /** @var \stdClass */
    protected $book;

    /**
     * An approved site, an activity, a student and an attempt about to launch.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('v2siteid', 'site-1', 'mod_moodlereader');
        set_config('v2secret', 'Zm9vYmFyLWV4YW1wbGUtc2VjcmV0LTMyLWJ5dGVzISE', 'mod_moodlereader');
        set_config('v2state', 'approved', 'mod_moodlereader');
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $instance = $gen->create_instance(['course' => $course->id, 'goal' => 5000]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $this->reader = reader::from_instanceid($instance->id);
        $this->book = $gen->create_book(['remoteid' => 77]);
        $this->attempt = attempt::start($this->reader, (int) $student->id, (int) $this->book->id);
        quizsession::$now = 1000000;
    }

    /**
     * Reset the test clock.
     *
     * @return void
     */
    protected function tearDown(): void {
        quizsession::$now = null;
        parent::tearDown();
    }

    /**
     * A state object as mreader.org sends it.
     *
     * @param string $ref
     * @param int $slot
     * @param string $state
     * @param array $extra
     * @return array
     */
    protected function state(string $ref, int $slot, string $state = 'in_progress', array $extra = []): array {
        return $extra + [
            'attempt_ref' => $ref, 'state' => $state, 'slot' => $slot, 'slots' => 3, 'time_limit' => 600,
            'item' => $state === 'finished' ? null : ['type' => 'mc', 'text' => 'Q<br>?', 'format' => 'html',
                'options' => [['id' => 'opt1', 'text' => 'A'], ['id' => 'opt2', 'text' => 'B']]],
            'result' => null,
        ];
    }

    /**
     * Launch for Moodle drawing and return the attempt's ref.
     *
     * @param fake_client $client
     * @return string
     */
    protected function launch(fake_client $client): string {
        // The ref is made by the plugin; the fake server echoes it.
        $client->queue_callback('POST', '/launches', 201, fn($in) => [
            'attempt_ref' => $in['attempt_ref'], 'time_limit' => 600, 'slots' => 3,
        ]);
        quizsession::launch($this->reader, $this->attempt, $this->book, $client);
        $this->assertSame(quizsession::DELIVERY_MOODLE, $this->attempt->delivery);
        return $this->attempt->attemptref;
    }

    /**
     * An old server answers with a frame URL: the quiz is refused, as quizzes are shown in Moodle only.
     * Only the pseudonym, the random display name, the book, the ref, the locale and the delivery are sent.
     *
     * @return void
     */
    public function test_old_server_is_refused(): void {
        $client = (new fake_client())->queue('POST', '/launches', 201, ['launch_url' => 'https://mreader.example/q']);
        try {
            quizsession::launch($this->reader, $this->attempt, $this->book, $client);
            $this->fail('A frame launch was accepted.');
        } catch (\mod_moodlereader\local\api\api_exception $e) {
            $this->assertSame('nomoodledrawing', $e->apicode);
        }
        $sent = json_decode($client->requests[0]['body'], true);
        $this->assertSame('moodle', $sent['delivery']);
        $this->assertSame(['uid', 'display_name', 'book_id', 'attempt_ref', 'locale', 'delivery'], array_keys($sent));
    }

    /**
     * A launch whose answer was lost is retried with the same ref; mreader.org names it as the open attempt,
     * and it is taken up from its state instead of blocking the student.
     *
     * @return void
     */
    public function test_lost_launch_answer_is_taken_up(): void {
        global $DB;
        $client = (new fake_client())->queue('POST', '/launches', 502, ['error' => ['code' => 'bad_gateway', 'message' => 'x']]);
        try {
            quizsession::launch($this->reader, $this->attempt, $this->book, $client);
            $this->fail('A failed launch was accepted.');
        } catch (\mod_moodlereader\local\api\api_exception $e) {
            $this->assertNotSame('', (string) $e->apicode);
        }
        $ref = $DB->get_field('moodlereader_attempts', 'attemptref', ['id' => $this->attempt->id]);
        $this->assertNotEmpty($ref);

        $attempt = $DB->get_record('moodlereader_attempts', ['id' => $this->attempt->id]);
        $client = (new fake_client())
            ->queue('POST', '/launches', 409, ['error' => ['code' => 'attempt_open', 'message' => 'x'], 'attempt_ref' => $ref])
            ->queue('POST', '/attempt/current', 200, $this->state($ref, 2));
        quizsession::launch($this->reader, $attempt, $this->book, $client);
        $this->assertSame($ref, json_decode($client->requests[0]['body'], true)['attempt_ref']);
        $this->assertEquals(600, $attempt->timelimit);
        $this->assertEquals(2, $attempt->currentslot);
    }

    /**
     * The clock counts the time each question is on screen, by the wall clock, but not the wait for mreader.org.
     *
     * @return void
     */
    public function test_clock_pauses_only_while_waiting(): void {
        global $DB;
        $client = new fake_client();
        $ref = $this->launch($client);

        // Slot 1 is served at t=0, the page beats every 10 seconds, and the answer arrives at t=40.
        $client->queue('POST', '/attempt/start', 200, $this->state($ref, 1));
        $state = quizsession::show($this->attempt, $client);
        $this->assertSame('mc', $state['item']['type']);
        foreach ([10, 10, 10] as $step) {
            quizsession::$now += $step;
            quizsession::heartbeat($this->attempt);
        }
        quizsession::$now += 10;
        $client->queue('POST', '/attempt/answer', 200, $this->state($ref, 2));
        quizsession::answer($this->attempt, 1, ['option' => 'opt1'], $client);
        $this->assertEquals(40, $this->attempt->secondsused);
        $answer = json_decode($client->requests[count($client->requests) - 1]['body'], true);
        $this->assertSame(['ref' => $ref, 'slot' => 1, 'seconds_used' => 40, 'response' => ['option' => 'opt1']], $answer);

        // The server takes 30 seconds to answer: the next page is served only then, so nothing is counted.
        quizsession::$now += 30;
        $client->queue('POST', '/attempt/current', 200, $this->state($ref, 2));
        quizsession::show($this->attempt, $client);
        $this->assertSame(600 - 40, quizsession::time_left($this->attempt));

        // The page beats for 20 seconds, then goes silent (closed, or heartbeats blocked) for 100 seconds:
        // the silence is counted, so an answer sent afterwards is charged all 120 seconds.
        quizsession::$now += 20;
        $this->assertSame(600 - 60, quizsession::heartbeat($this->attempt));
        quizsession::$now += 100;
        $this->assertSame(600 - 160, quizsession::time_left($this->attempt));
        $client->queue('POST', '/attempt/answer', 200, $this->state($ref, 3));
        quizsession::answer($this->attempt, 2, ['option' => 'opt1'], $client);
        $this->assertEquals(160, $DB->get_field('moodlereader_attempts', 'secondsused', ['id' => $this->attempt->id]));

        // Coming back an hour after closing the tab finds the time used up: the quiz is finished.
        $client->queue('POST', '/attempt/current', 200, $this->state($ref, 3));
        quizsession::show($this->attempt, $client);
        quizsession::$now += HOURSECS;
        $client->queue('POST', '/attempt/finish', 200, $this->state($ref, 3, 'finished', ['survey' => []]));
        $this->assertSame('finished', quizsession::show($this->attempt, $client)['state']);
    }

    /**
     * Past the limit the attempt is finished, not answered; a retry is answered by the state in a 409.
     *
     * @return void
     */
    public function test_time_limit_and_retries(): void {
        $client = new fake_client();
        $ref = $this->launch($client);
        $client->queue('POST', '/attempt/start', 200, $this->state($ref, 1));
        quizsession::show($this->attempt, $client);

        // A retry of an answer already marked: mreader.org refuses with the current state, which is used.
        quizsession::$now += 5;
        $client->queue('POST', '/attempt/answer', 409, ['error' => ['code' => 'already_answered', 'message' => 'x'],
            'state' => $this->state($ref, 2)]);
        $this->assertSame(2, quizsession::answer($this->attempt, 1, ['option' => 'opt1'], $client)['slot']);

        // Kept open, with heartbeats, until past the limit: the answer becomes a finish.
        $client->queue('POST', '/attempt/current', 200, $this->state($ref, 2));
        quizsession::show($this->attempt, $client);
        for ($i = 0; $i < 70; $i++) {
            quizsession::$now += 10;
            quizsession::heartbeat($this->attempt);
        }
        $client->queue('POST', '/attempt/finish', 200, $this->state($ref, 2, 'finished', ['survey' => []]));
        $state = quizsession::answer($this->attempt, 2, ['option' => 'opt2'], $client);
        $this->assertSame('finished', $state['state']);
        $finish = json_decode($client->requests[count($client->requests) - 1]['body'], true);
        $this->assertSame('timeout', $finish['reason']);
        $this->assertSame(600, $finish['seconds_used']);
    }

    /**
     * Bad states and items from the server are refused; answers are checked for shape.
     *
     * @return void
     */
    public function test_cleaning(): void {
        $this->expectException(\mod_moodlereader\local\api\api_exception::class);
        try {
            quizsession::clean_state(['attempt_ref' => 'other', 'state' => 'in_progress'], 'mine');
        } finally {
            $this->assertSame(['option' => 'opt1'], quizsession::response('mc', 'opt1', []));
            $this->assertNull(quizsession::response('mc', '<script>', []));
            $this->assertSame(['order' => ['b', 'a']], quizsession::response('o', '', ['b', 'a']));
            $this->assertNull(quizsession::response('o', '', ['a', 'a']));
            $this->assertSame([], quizsession::response('d', '', []));
            $this->assertNull(quizsession::response('zz', 'opt1', []));
            $item = quizsession::clean_state(
                $this->state('r', 1, 'in_progress', ['item' => ['type' => 'o',
                'text' => 'T', 'instruction' => 'I', 'items' => [['id' => 'x1', 'text' => 'a'], ['id' => 'x2', 'text' => 'b']]]]),
                'r'
            )['item'];
            $this->assertSame(['x1', 'x2'], array_column($item['choices'], 'id'));
        }
    }

    /**
     * The survey is sent once; the first answer becomes the book rating; "declined" sends an empty list.
     *
     * @return void
     */
    public function test_survey(): void {
        global $DB;
        $client = new fake_client();
        $ref = $this->launch($client);
        $client->queue('POST', '/attempt/survey', 200, $this->state($ref, 3, 'finished', ['survey' => null]));
        quizsession::survey($this->attempt, [1 => 2, 4 => 1], $client);
        $sent = json_decode($client->requests[count($client->requests) - 1]['body'], true);
        $this->assertSame(
            ['ref' => $ref, 'responses' => [['question' => 1, 'answer' => 2], ['question' => 4, 'answer' => 1]]],
            $sent
        );
        $this->assertEquals(2, $DB->get_field('moodlereader_attempts', 'bookrating', ['id' => $this->attempt->id]));

        // A second send is refused as done: not an error for the student, and the rating stays as it was.
        $client->queue('POST', '/attempt/survey', 409, ['error' => ['code' => 'survey_done', 'message' => 'x']]);
        quizsession::survey($this->attempt, [1 => 5], $client);
        $this->assertEquals(2, $DB->get_field('moodlereader_attempts', 'bookrating', ['id' => $this->attempt->id]));
        $this->assertSame(
            ['ref' => $ref, 'responses' => [['question' => 1, 'answer' => 5]]],
            json_decode($client->requests[count($client->requests) - 1]['body'], true)
        );
    }
}
