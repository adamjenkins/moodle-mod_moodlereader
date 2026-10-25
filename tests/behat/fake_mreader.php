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

/**
 * A fake mreader.org for Behat: answers API v2 31 §11 (Moodle-drawn quizzes) for one canned quiz.
 *
 * Used only on the Behat test site, and only once the step "mreader.org draws quizzes for Moodle"
 * has switched it on (client::transport()). Its state is kept in plugin config, because every
 * Behat step is a separate web request.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The fake server.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_fake_mreader {
    /**
     * The canned quiz: a multiple-choice, an ordering and a true/false question. The first option,
     * the given order and "true" are right.
     *
     * @return array[]
     */
    protected static function items(): array {
        return [
            1 => ['type' => 'mc', 'text' => 'Who found the red kite?', 'format' => 'html', 'options' => [
                ['id' => 'optAnna', 'text' => 'Anna'], ['id' => 'optTom', 'text' => 'Tom'],
                ['id' => 'optSam', 'text' => 'Sam'],
            ]],
            2 => ['type' => 'o', 'text' => 'What happened first?', 'format' => 'html',
                'instruction' => 'Put the events in order.', 'items' => [
                    ['id' => 'ordB', 'text' => 'The kite flew away.'], ['id' => 'ordA', 'text' => 'Anna found a kite.'],
                    ['id' => 'ordC', 'text' => 'Tom brought it back.'],
                ]],
            3 => ['type' => 'tf', 'text' => 'The kite was red.', 'format' => 'html', 'options' => [
                ['id' => 'tfT', 'value' => 'true'], ['id' => 'tfF', 'value' => 'false'],
            ]],
        ];
    }

    /**
     * Answer one request.
     *
     * @param string $method
     * @param string $url
     * @param string $body
     * @return array ['status', 'headers', 'body']
     */
    public static function handle(string $method, string $url, string $body): array {
        $path = preg_replace('#^.*/api/v2#', '', (string) parse_url($url, PHP_URL_PATH));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $in = json_decode($body, true) ?: [];
        $ref = (string) ($in['ref'] ?? $in['attempt_ref'] ?? $query['ref'] ?? '');
        $all = json_decode((string) get_config('mod_moodlereader', 'behatfakestate'), true) ?: [];
        $a = $all[$ref] ?? null;
        $out = null;
        switch ($method . ' ' . $path) {
            case 'POST /launches':
                $all[$ref] = ['uid' => $in['uid'], 'book_id' => $in['book_id'], 'slot' => 0, 'score' => 0,
                    'state' => 'in_progress', 'survey' => true];
                self::save($all);
                return self::reply(201, ['attempt_ref' => $ref, 'time_limit' => 900, 'slots' => 3]);
            case 'POST /attempt/start':
            case 'POST /attempt/current':
                if (!$a) {
                    return self::reply(404, ['error' => ['code' => 'not_found', 'message' => 'Unknown ref.']]);
                }
                if ($a['slot'] === 0) {
                    $a['slot'] = 1;
                }
                break;
            case 'POST /attempt/answer':
                $right = [1 => ['option' => 'optAnna'], 2 => ['order' => ['ordA', 'ordB', 'ordC']], 3 => ['option' => 'tfT']];
                if (($in['response'] ?? null) == $right[$a['slot']]) {
                    $a['score']++;
                }
                $a['slot']++;
                if ($a['slot'] > 3) {
                    $a['state'] = 'finished';
                }
                break;
            case 'POST /attempt/finish':
                $a['state'] = 'finished';
                break;
            case 'POST /attempt/survey':
                $a['survey'] = false;
                break;
            case 'GET /attempt':
                $percent = (int) round(100 * $a['score'] / 3);
                return self::reply(200, ['attempt_ref' => $ref, 'uid' => $a['uid'], 'book_id' => $a['book_id'],
                    'state' => $a['state'] === 'finished' ? 'finished' : 'in_progress',
                    'percent' => $a['state'] === 'finished' ? $percent : null, 'passed' => $percent >= 60]);
            default:
                return self::reply(404, ['error' => ['code' => 'not_found', 'message' => 'Not in the fake.']]);
        }
        $all[$ref] = $a;
        self::save($all);
        $finished = $a['state'] === 'finished';
        return self::reply(200, [
            'attempt_ref' => $ref, 'state' => $finished ? 'finished' : 'in_progress',
            'slot' => min($a['slot'], 3), 'slots' => 3, 'time_limit' => 900,
            'item' => $finished ? null : self::items()[$a['slot']],
            'result' => null,
            'survey' => $finished ? ($a['survey'] ? [['id' => 1, 'question' => 'How did you like this book?',
                'format' => 'html', 'answers' => [['id' => 1, 'text' => 'Very much'], ['id' => 2, 'text' => 'Not much']]]] : null)
                : null,
        ]);
    }

    /**
     * Keep the state.
     *
     * @param array $all
     * @return void
     */
    protected static function save(array $all): void {
        set_config('behatfakestate', json_encode($all), 'mod_moodlereader');
    }

    /**
     * A JSON reply.
     *
     * @param int $status
     * @param array $body
     * @return array
     */
    protected static function reply(int $status, array $body): array {
        return ['status' => $status, 'headers' => ['content-type' => 'application/json'],
            'body' => json_encode($body + ['server_time' => time()])];
    }
}
