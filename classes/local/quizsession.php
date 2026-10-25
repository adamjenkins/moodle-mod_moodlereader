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

use mod_moodlereader\local\api\api_exception;
use mod_moodlereader\local\api\client;

/**
 * A quiz drawn by Moodle (API v2 31 §11): mreader.org keeps the questions, the answer key and the
 * marking; Moodle shows one question at a time and runs the clock.
 *
 * The clock counts only time a question is on the student's screen. A question's time runs from when
 * Moodle sent its page (which it can only do once mreader.org has answered) to when the answer arrives,
 * or to the last heartbeat from the page if it went away. Time spent waiting for mreader.org is never
 * counted, so the countdown pauses while the next question loads.
 *
 * Question text is never stored: not in the database, the session or a cache. Each page asks
 * mreader.org for the pinned item again.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quizsession {
    /** @var string The attempt is drawn by Moodle. */
    const DELIVERY_MOODLE = 'moodle';

    /** @var string The attempt runs in mreader.org's pages in a frame. */
    const DELIVERY_IFRAME = 'iframe';

    /** @var int Seconds between the question page's heartbeats. */
    const HEARTBEAT = 10;

    /** @var int How long after the last heartbeat a page still counts as on screen. */
    const HEARTBEAT_GRACE = 15;

    /** @var int Seconds allowed past the limit for an answer already on its way. */
    const ANSWER_GRACE = 5;

    /** @var string[] Item types (31 §11.3). */
    const TYPES = ['mc', 'tf', 'o', 'd'];

    /** @var int|null The time, for tests. */
    public static $now = null;

    /**
     * The current time.
     *
     * @return int
     */
    protected static function now(): int {
        return self::$now ?? time();
    }

    /**
     * Launch an attempt, asking mreader.org to let Moodle draw it.
     *
     * A server without Moodle drawing ignores the request and answers with a frame URL (31 §11.10);
     * the attempt then runs in the frame.
     *
     * @param reader $reader
     * @param \stdClass $attempt
     * @param \stdClass $book
     * @param client|null $client
     * @return array ['moodle'] or ['iframe', string $url]
     */
    public static function launch(reader $reader, \stdClass $attempt, \stdClass $book, ?client $client = null): array {
        global $DB;
        if (
            empty($reader->get_instance()->quizdelivery) || $attempt->delivery === self::DELIVERY_IFRAME
                && !empty($attempt->attemptref) && $attempt->remotestate !== 'abandoned'
        ) {
            return [self::DELIVERY_IFRAME, results::launch($attempt, $book, $client)];
        }
        if ((int) $book->remoteid <= 0) {
            throw new \moodle_exception('booknotlinked', 'mod_moodlereader');
        }
        // A fresh reference, so a ref never changes mode (31 §11.10).
        if (empty($attempt->attemptref) || $attempt->remotestate === 'abandoned' || $attempt->delivery !== self::DELIVERY_MOODLE) {
            $attempt->attemptref = $attempt->id . '-' . random_string(16);
        }
        $identity = (new mreader_site())->get_identity((int) $attempt->userid);
        $client = $client ?? new client();
        // Only the opaque id and the random display name identify the student.
        $data = $client->post('/launches', [
            'uid'          => $identity->uniqueid,
            'display_name' => $identity->uniquename,
            'book_id'      => (int) $book->remoteid,
            'attempt_ref'  => $attempt->attemptref,
            'locale'       => current_language(),
            'delivery'     => self::DELIVERY_MOODLE,
        ]);
        $url = clean_param((string) ($data['launch_url'] ?? ''), PARAM_URL);
        if ($url !== '') {
            // The server does not draw for Moodle yet: this ref is a frame attempt from now on.
            if (!preg_match('#^https?://#', $url)) {
                throw new api_exception('badresponse', 'mreader.org did not return a launch URL.');
            }
            $DB->update_record('moodlereader_attempts', (object) [
                'id' => $attempt->id, 'attemptref' => $attempt->attemptref, 'remotestate' => null,
                'delivery' => self::DELIVERY_IFRAME,
            ]);
            $attempt->delivery = self::DELIVERY_IFRAME;
            return [self::DELIVERY_IFRAME, $url];
        }
        $limit = (int) ($data['time_limit'] ?? 0);
        $slots = (int) ($data['slots'] ?? 0);
        if (($data['attempt_ref'] ?? '') !== $attempt->attemptref || $limit <= 0 || $slots <= 0) {
            throw new api_exception('badresponse', 'mreader.org did not accept the attempt for Moodle drawing.');
        }
        $update = [
            'id' => $attempt->id, 'attemptref' => $attempt->attemptref, 'remotestate' => null,
            'delivery' => self::DELIVERY_MOODLE, 'timelimit' => min($limit, DAYSECS), 'slots' => min($slots, 100),
            'currentslot' => 0, 'secondsused' => 0, 'timeserved' => 0, 'timeheartbeat' => 0,
        ];
        $DB->update_record('moodlereader_attempts', (object) $update);
        foreach ($update as $field => $value) {
            $attempt->$field = $value;
        }
        return [self::DELIVERY_MOODLE];
    }

    /**
     * The attempt's state from mreader.org, with the item to show (started if need be).
     *
     * Records that a question is now on screen, which starts its share of the clock.
     *
     * @param \stdClass $attempt A Moodle-drawn attempt.
     * @param client|null $client
     * @return array The cleaned state (31 §11.2).
     */
    public static function show(\stdClass $attempt, ?client $client = null): array {
        $client = $client ?? new client();
        // A page left open earlier stops counting at its last heartbeat.
        self::close_segment($attempt);
        if (self::time_left($attempt) <= 0) {
            return self::finish($attempt, $client);
        }
        $path = (int) $attempt->currentslot === 0 ? '/attempt/start' : '/attempt/current';
        $state = self::call($client, $path, ['ref' => $attempt->attemptref]);
        if ($state['state'] === 'in_progress') {
            self::open_segment($attempt, (int) $state['slot']);
        }
        return $state;
    }

    /**
     * Send the student's answer for the slot on screen.
     *
     * @param \stdClass $attempt
     * @param int $slot
     * @param array $response {"option": id}, {"order": [ids]} or {} (31 §11.3).
     * @param client|null $client
     * @return array The state for the next slot, or finished.
     */
    public static function answer(\stdClass $attempt, int $slot, array $response, ?client $client = null): array {
        $client = $client ?? new client();
        // The answer has arrived: the question is off screen, and the wait for mreader.org is not counted.
        self::close_segment($attempt, true);
        if (self::time_left($attempt) < -self::ANSWER_GRACE) {
            return self::finish($attempt, $client);
        }
        return self::call($client, '/attempt/answer', [
            'ref' => $attempt->attemptref, 'slot' => $slot, 'seconds_used' => min(
                (int) $attempt->secondsused,
                (int) $attempt->timelimit
            ), 'response' => (object) $response,
        ]);
    }

    /**
     * Finish the attempt at the time limit.
     *
     * @param \stdClass $attempt
     * @param client|null $client
     * @return array The finished state.
     */
    public static function finish(\stdClass $attempt, ?client $client = null): array {
        $client = $client ?? new client();
        self::close_segment($attempt, true);
        return self::call($client, '/attempt/finish', [
            'ref' => $attempt->attemptref, 'seconds_used' => min((int) $attempt->secondsused, (int) $attempt->timelimit),
            'reason' => 'timeout',
        ]);
    }

    /**
     * Send the post-quiz survey (31 §11.11); an empty list means the student declined.
     *
     * @param \stdClass $attempt
     * @param array $responses question id => answer id
     * @param client|null $client
     * @return void
     */
    public static function survey(\stdClass $attempt, array $responses, ?client $client = null): void {
        global $DB;
        $client = $client ?? new client();
        $list = [];
        foreach ($responses as $question => $answer) {
            $list[] = ['question' => (int) $question, 'answer' => (int) $answer];
        }
        try {
            $client->post('/attempt/survey', ['ref' => $attempt->attemptref, 'responses' => $list]);
        } catch (api_exception $e) {
            if ($e->apicode !== 'survey_done') {
                throw $e;
            }
        }
        // The answer to the first question ("How did you like this book?") is kept as the book rating.
        if ($list) {
            $DB->set_field('moodlereader_attempts', 'bookrating', max(0, min(99, $list[0]['answer'])), ['id' => $attempt->id]);
        }
    }

    /**
     * Note that the question page is still open.
     *
     * @param \stdClass $attempt
     * @return int Seconds left on the clock.
     */
    public static function heartbeat(\stdClass $attempt): int {
        global $DB;
        if ((int) $attempt->timeserved > 0) {
            $attempt->timeheartbeat = self::now();
            $DB->set_field('moodlereader_attempts', 'timeheartbeat', $attempt->timeheartbeat, ['id' => $attempt->id]);
        }
        return self::time_left($attempt);
    }

    /**
     * Seconds left: the limit minus the time used, including the question on screen now.
     *
     * @param \stdClass $attempt
     * @return int May be negative.
     */
    public static function time_left(\stdClass $attempt): int {
        $used = (int) $attempt->secondsused;
        if ((int) $attempt->timeserved > 0) {
            $used += self::segment($attempt, false);
        }
        return (int) $attempt->timelimit - $used;
    }

    /**
     * The seconds of the question on screen so far.
     *
     * @param \stdClass $attempt
     * @param bool $answered The answer has just arrived (the same rule applies; kept for the callers' clarity).
     * @return int
     */
    protected static function segment(\stdClass $attempt, bool $answered): int {
        $served = (int) $attempt->timeserved;
        // To now, but never past the page's last sign of life (plus grace): a page that went away,
        // or an answer from a page that had gone silent, stops counting there.
        $end = min(self::now(), max($served, (int) $attempt->timeheartbeat) + self::HEARTBEAT_GRACE);
        return max(0, $end - $served);
    }

    /**
     * A question is on screen from now.
     *
     * @param \stdClass $attempt
     * @param int $slot
     * @return void
     */
    protected static function open_segment(\stdClass $attempt, int $slot): void {
        global $DB;
        $attempt->timeserved = self::now();
        $attempt->timeheartbeat = $attempt->timeserved;
        $attempt->currentslot = $slot;
        $DB->update_record('moodlereader_attempts', (object) [
            'id' => $attempt->id, 'timeserved' => $attempt->timeserved, 'timeheartbeat' => $attempt->timeheartbeat,
            'currentslot' => $slot,
        ]);
    }

    /**
     * Add the question on screen to the time used, and stop the clock.
     *
     * @param \stdClass $attempt
     * @param bool $answered The answer has just arrived.
     * @return void
     */
    protected static function close_segment(\stdClass $attempt, bool $answered = false): void {
        global $DB;
        if ((int) $attempt->timeserved <= 0) {
            return;
        }
        $attempt->secondsused = (int) $attempt->secondsused + self::segment($attempt, $answered);
        $attempt->timeserved = 0;
        $DB->update_record('moodlereader_attempts', (object) [
            'id' => $attempt->id, 'secondsused' => $attempt->secondsused, 'timeserved' => 0,
        ]);
    }

    /**
     * One signed call; a refusal that carries the current state (409, 410) answers with that state.
     *
     * @param client $client
     * @param string $path
     * @param array $body
     * @return array The cleaned state.
     */
    protected static function call(client $client, string $path, array $body): array {
        try {
            $data = $client->post($path, $body);
        } catch (api_exception $e) {
            $carried = $e->response['state'] ?? null;
            if (in_array($e->httpstatus, [409, 410], true) && is_array($carried)) {
                $data = $carried;
            } else {
                throw $e;
            }
        }
        return self::clean_state($data, (string) $body['ref']);
    }

    /**
     * Check a state object from mreader.org and keep only what Moodle uses.
     *
     * @param array $data
     * @param string $ref The attempt asked about.
     * @return array state, slot, slots, item (cleaned, or null), survey (list, or null)
     */
    public static function clean_state(array $data, string $ref): array {
        if (($data['attempt_ref'] ?? null) !== $ref || !in_array($data['state'] ?? '', ['in_progress', 'finished'], true)) {
            throw new api_exception('badresponse', 'mreader.org sent a state for another attempt.');
        }
        $state = [
            'state' => $data['state'],
            'slot' => max(0, (int) ($data['slot'] ?? 0)),
            'slots' => max(0, (int) ($data['slots'] ?? 0)),
            'item' => null,
            'survey' => null,
        ];
        if ($state['state'] === 'in_progress') {
            $state['item'] = self::clean_item(is_array($data['item'] ?? null) ? $data['item'] : []);
        } else if (is_array($data['survey'] ?? null)) {
            $state['survey'] = self::clean_survey($data['survey']);
        }
        return $state;
    }

    /**
     * Check one item (31 §11.3): its type, and ids that are safe to put in a form.
     *
     * @param array $item
     * @return array type, text, instruction, choices (each: id, text, value)
     */
    protected static function clean_item(array $item): array {
        $type = (string) ($item['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw new api_exception('badresponse', 'mreader.org sent an unknown question type.');
        }
        $choices = [];
        foreach ((array) ($item[$type === 'o' ? 'items' : 'options'] ?? []) as $choice) {
            $id = (string) ($choice['id'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id)) {
                throw new api_exception('badresponse', 'mreader.org sent an option with a bad id.');
            }
            $choices[] = [
                'id' => $id,
                'text' => (string) ($choice['text'] ?? ''),
                'value' => in_array($choice['value'] ?? '', ['true', 'false'], true) ? $choice['value'] : '',
            ];
        }
        if ($type === 'd' ? $choices : (count($choices) < 2 || count($choices) > 20)) {
            throw new api_exception('badresponse', 'mreader.org sent a question with the wrong number of options.');
        }
        return [
            'type' => $type,
            'text' => (string) ($item['text'] ?? ''),
            'instruction' => (string) ($item['instruction'] ?? ''),
            'choices' => $choices,
        ];
    }

    /**
     * Check the post-quiz survey (31 §11.11).
     *
     * @param array $survey
     * @return array[] Each: id (int), question, answers (each: id int, text).
     */
    protected static function clean_survey(array $survey): array {
        $out = [];
        foreach ($survey as $q) {
            if (!is_array($q) || (int) ($q['id'] ?? 0) <= 0) {
                continue;
            }
            $answers = [];
            foreach ((array) ($q['answers'] ?? []) as $a) {
                if ((int) ($a['id'] ?? 0) > 0) {
                    $answers[] = ['id' => (int) $a['id'], 'text' => (string) ($a['text'] ?? '')];
                }
            }
            if ($answers) {
                $out[] = ['id' => (int) $q['id'], 'question' => (string) ($q['question'] ?? ''), 'answers' => $answers];
            }
        }
        return $out;
    }

    /**
     * Build the response to send from the submitted form (31 §11.3).
     *
     * Moodle does not keep the item, so only the shape is checked here: ids that look like ids, and an
     * order without repeats. mreader.org refuses an id that is not on the question (400 validation).
     *
     * @param string $type The item type the page was drawn for.
     * @param string $option The chosen option id (mc, tf).
     * @param string[] $order The ids in the student's order (o).
     * @return array|null The response, or null when the form holds no valid answer.
     */
    public static function response(string $type, string $option, array $order): ?array {
        $isid = fn($id) => is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id);
        switch ($type) {
            case 'd':
                return [];
            case 'o':
                $order = array_values($order);
                $wellformed = count($order) >= 2 && count($order) === count(array_unique($order))
                    && count(array_filter($order, $isid)) === count($order);
                if (!$wellformed) {
                    return null;
                }
                return ['order' => $order];
            case 'mc':
            case 'tf':
                return $isid($option) ? ['option' => $option] : null;
            default:
                return null;
        }
    }
}
