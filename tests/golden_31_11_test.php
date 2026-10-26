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

use mod_moodlereader\local\api\api_exception;
use mod_moodlereader\local\api\fake_client;
use mod_moodlereader\local\attempt;
use mod_moodlereader\local\quizsession;
use mod_moodlereader\local\reader;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_client.php');

/**
 * Replays mreader.org's golden examples for 31 §11 (tests/fixtures/golden-31-11, recorded from the real
 * server by mreaderdev) through the plugin's code, and checks that the plugin's requests have the same shape.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\quizsession::class)]
final class golden_31_11_test extends \advanced_testcase {
    /**
     * One golden example.
     *
     * @param string $name File name without .json.
     * @return array request, response
     */
    protected function golden(string $name): array {
        return json_decode(file_get_contents(__DIR__ . "/fixtures/golden-31-11/$name.json"), true);
    }

    /**
     * Queue a golden response, with the recorded ref swapped for the plugin's own.
     *
     * @param fake_client $client
     * @param string $name
     * @param string $recordedref
     * @return void
     */
    protected function queue(fake_client $client, string $name, string $recordedref): void {
        $golden = $this->golden($name);
        $path = substr($golden['request']['path'], strlen('/api/v2'));
        $client->queue_callback(
            $golden['request']['method'],
            $path,
            $golden['response']['status'],
            function (array $in) use ($golden, $recordedref) {
                $ref = $in['ref'] ?? $in['attempt_ref'];
                $json = str_replace(json_encode($recordedref), json_encode($ref), json_encode($golden['response']['body']));
                return json_decode($json, true);
            }
        );
    }

    /**
     * The keys of the plugin's last request body must be the golden request's keys.
     *
     * @param fake_client $client
     * @param string $name
     * @return void
     */
    protected function assert_same_request_shape(fake_client $client, string $name): void {
        $sent = json_decode($client->requests[count($client->requests) - 1]['body'] ?: '{}', true);
        $recorded = $this->golden($name)['request']['body'];
        $this->assertSame(array_keys($recorded), array_keys($sent), "request shape of $name");
        if (isset($recorded['response'])) {
            $this->assertSame(array_keys($recorded['response']), array_keys($sent['response']), "response shape of $name");
        }
    }

    /**
     * A whole recorded attempt: every item type, the refusals, the result and the survey; then a second
     * attempt with nothing chosen and the time limit, with finish sent twice.
     *
     * @return void
     */
    public function test_replay(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('v2siteid', 'site-1', 'mod_moodlereader');
        set_config('v2secret', 'Zm9vYmFyLWV4YW1wbGUtc2VjcmV0LTMyLWJ5dGVzISE', 'mod_moodlereader');
        set_config('v2state', 'approved', 'mod_moodlereader');
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $instance = $gen->create_instance(['course' => $course->id, 'goal' => 5000]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $reader = reader::from_instanceid($instance->id);
        $book = $gen->create_book(['remoteid' => 99985572]);
        quizsession::$now = 2000000;
        $ref = 'golden-8b86c292';
        $client = new fake_client();

        // Launch: the plugin's request has the recorded shape, and the reply makes a Moodle-drawn attempt.
        $attempt = attempt::start($reader, (int) $student->id, (int) $book->id);
        $this->queue($client, '01-launch-moodle', $ref);
        quizsession::launch($reader, $attempt, $book, $client);
        $this->assertSame('moodle', $attempt->delivery);
        $this->assert_same_request_shape($client, '01-launch-moodle');
        $this->assertEquals(4, $attempt->slots);

        // A "not started" from current leads to start; a reload asks current and gets the same item.
        $attempt->currentslot = 1;
        $this->queue($client, '02-not-started', $ref);
        $this->queue($client, '03-start', $ref);
        $state = quizsession::show($attempt, $client);
        $this->assertSame(['mc', 4], [$state['item']['type'], count($state['item']['choices'])]);
        $this->assertStringContainsString('______', $state['item']['text']);
        $this->queue($client, '04-current-same-item', $ref);
        $this->assertSame($state['item'], quizsession::show($attempt, $client)['item']);

        // Refusals: an unknown option is an error; a wrong slot answers with the current state.
        $this->queue($client, '05-answer-unknown-option', $ref);
        try {
            quizsession::answer($attempt, 1, ['option' => 'no-such-id'], $client);
            $this->fail('An unknown option must be refused.');
        } catch (api_exception $e) {
            $this->assertSame([400, 'validation'], [$e->httpstatus, $e->apicode]);
        }
        $this->queue($client, '06-answer-wrong-slot', $ref);
        $this->assertSame(1, quizsession::answer($attempt, 3, ['option' => 'x'], $client)['slot']);

        // Each type answered as recorded, then the description finishes the attempt with the survey.
        $mc = $this->golden('08-answer-mc')['request']['body']['response'];
        $this->queue($client, '08-answer-mc', $ref);
        $this->assertSame('tf', quizsession::answer($attempt, 1, $mc, $client)['item']['type']);
        $this->assert_same_request_shape($client, '08-answer-mc');
        $this->queue($client, '10-answer-tf', $ref);
        $state = quizsession::answer($attempt, 2, $this->golden('10-answer-tf')['request']['body']['response'], $client);
        $this->assertSame('o', $state['item']['type']);
        $order = $this->golden('11-answer-ordering')['request']['body']['response']['order'];
        $this->assertSame(['order' => $order], quizsession::response('o', '', $order));
        $this->queue($client, '11-answer-ordering', $ref);
        $this->assertSame('d', quizsession::answer($attempt, 3, ['order' => $order], $client)['item']['type']);
        $this->queue($client, '12-answer-description-finishes', $ref);
        $state = quizsession::answer($attempt, 4, quizsession::response('d', '', []), $client);
        $this->assertSame('finished', $state['state']);
        $this->assertSame(816, $state['survey'][0]['id']);

        // The survey, then again: the second is refused as done and is not an error.
        $this->queue($client, '14-survey', $ref);
        quizsession::survey($attempt, [816 => 1], $client);
        $this->assert_same_request_shape($client, '14-survey');
        $this->assertEquals(1, $DB->get_field('moodlereader_attempts', 'bookrating', ['id' => $attempt->id]));
        $this->queue($client, '15-survey-again', $ref);
        quizsession::survey($attempt, [816 => 1], $client);

        // Second attempt: nothing chosen on an mc slot is {}; finish at the limit, twice, both finished.
        $ref2 = 'golden-00939a92';
        $DB->set_field('moodlereader_attempts', 'state', 'finished', ['id' => $attempt->id]);
        $attempt2 = attempt::start($reader, (int) $student->id, (int) $gen->create_book(['remoteid' => 99985573])->id);
        $this->queue($client, '16-launch-2', $ref2);
        quizsession::launch($reader, $attempt2, $DB->get_record('moodlereader_books', ['id' => $attempt2->bookid]), $client);
        $this->queue($client, '19-start-2', $ref2);
        quizsession::show($attempt2, $client);
        $this->assertSame([], quizsession::response('mc', '', []));
        $this->queue($client, '20-answer-mc-nothing-chosen', $ref2);
        quizsession::answer($attempt2, 1, [], $client);
        $this->assert_same_request_shape($client, '20-answer-mc-nothing-chosen');
        $this->queue($client, '21-finish-timeout', $ref2);
        $this->queue($client, '22-finish-again', $ref2);
        $this->assertSame('finished', quizsession::finish($attempt2, $client)['state']);
        $this->assert_same_request_shape($client, '21-finish-timeout');
        $this->assertSame('finished', quizsession::finish($attempt2, $client)['state']);
        quizsession::$now = null;
    }

    /**
     * Every state the server recorded, including those carried by refusals, passes the plugin's checks.
     *
     * @return void
     */
    public function test_every_recorded_state_is_accepted(): void {
        $checked = 0;
        foreach (glob(__DIR__ . '/fixtures/golden-31-11/*.json') as $file) {
            $body = json_decode(file_get_contents($file), true)['response']['body'];
            $state = isset($body['attempt_ref'], $body['state']) && is_string($body['state']) ? $body
                : (is_array($body['state'] ?? null) ? $body['state'] : null);
            if ($state) {
                quizsession::clean_state($state, $state['attempt_ref']);
                $checked++;
            }
        }
        $this->assertGreaterThanOrEqual(14, $checked);
    }
}
