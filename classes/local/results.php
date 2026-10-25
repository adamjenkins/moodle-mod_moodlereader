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
 * Quiz launches and results through the mreader.org API v2.
 *
 * The grade is never taken from the student's browser: this site asks mreader.org
 * for the attempt's result with its own credential, so it cannot be forged and a
 * closed tab cannot lose it ({@see self::reconcile()} collects anything missed).
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class results {
    /** @var int Minimum seconds between two result checks for one attempt. */
    const CHECK_INTERVAL = 8;

    /**
     * Get a single-use quiz URL for an attempt.
     *
     * @param \stdClass $attempt {moodlereader_attempts} record (updated with its reference).
     * @param \stdClass $book {moodlereader_books} record.
     * @param client|null $client
     * @return string The launch URL to put in the iframe.
     */
    public static function launch(\stdClass $attempt, \stdClass $book, ?client $client = null): string {
        global $DB;
        if ((int) $book->remoteid <= 0) {
            throw new \moodle_exception('booknotlinked', 'mod_moodlereader');
        }
        // A fresh reference for a new attempt, or when mreader.org gave up on the old one.
        if (empty($attempt->attemptref) || $attempt->remotestate === 'abandoned') {
            $attempt->attemptref = $attempt->id . '-' . random_string(16);
            $attempt->remotestate = null;
            $DB->update_record('moodlereader_attempts', (object) [
                'id' => $attempt->id,
                'attemptref' => $attempt->attemptref,
                'remotestate' => null,
            ]);
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
        ]);
        $url = clean_param((string) ($data['launch_url'] ?? ''), PARAM_URL);
        if ($url === '' || !preg_match('#^https?://#', $url)) {
            throw new api_exception('badresponse', 'mreader.org did not return a launch URL.');
        }
        return $url;
    }

    /**
     * Ask mreader.org for an attempt's result, and finish the attempt if it is ready.
     *
     * @param \stdClass $attempt {moodlereader_attempts} record.
     * @param bool $force Skip the rate limit.
     * @param client|null $client
     * @return string The attempt's local state after the check.
     */
    public static function check(\stdClass $attempt, bool $force = false, ?client $client = null): string {
        global $DB;
        if ($attempt->state !== 'inprogress' || empty($attempt->attemptref)) {
            return $attempt->state;
        }
        if (!$force && time() - (int) $attempt->timeresultchecked < self::CHECK_INTERVAL) {
            return $attempt->state;
        }
        $DB->set_field('moodlereader_attempts', 'timeresultchecked', time(), ['id' => $attempt->id]);
        $client = $client ?? new client();
        try {
            $data = $client->get('/attempt', ['ref' => $attempt->attemptref]);
        } catch (api_exception $e) {
            if ($e->apicode === 'not_found') {
                return $attempt->state;
            }
            throw $e;
        }
        return self::apply($attempt, $data);
    }

    /**
     * Record one result object from mreader.org against its attempt.
     *
     * @param \stdClass $attempt
     * @param array $data An attempt object from /attempt or /attempts.
     * @return string The attempt's local state.
     */
    public static function apply(\stdClass $attempt, array $data): string {
        global $DB;
        if (($data['attempt_ref'] ?? null) !== $attempt->attemptref) {
            return $attempt->state;
        }
        // The result must be for this attempt's student and book.
        $identity = $DB->get_record('moodlereader_users', ['userid' => $attempt->userid]);
        $remotebook = (int) $DB->get_field('moodlereader_books', 'remoteid', ['id' => $attempt->bookid]);
        if (!$identity || ($data['uid'] ?? null) !== $identity->uniqueid || (int) ($data['book_id'] ?? 0) !== $remotebook) {
            debugging('mreader.org result does not match attempt ' . $attempt->id, DEBUG_DEVELOPER);
            return $attempt->state;
        }
        $remotestate = clean_param((string) ($data['state'] ?? ''), PARAM_ALPHAEXT);
        $DB->set_field('moodlereader_attempts', 'remotestate', $remotestate, ['id' => $attempt->id]);
        $attempt->remotestate = $remotestate;
        if ($remotestate !== 'finished' || !isset($data['percent']) || !is_numeric($data['percent'])) {
            return $attempt->state;
        }
        $reader = reader::from_instanceid((int) $attempt->readerid);
        attempt::finish($reader, $attempt, (float) $data['percent']);
        return 'finished';
    }

    /**
     * Collect every result mreader.org has finished since the last run.
     *
     * @param client|null $client
     * @return int Attempts finished by this run.
     */
    public static function reconcile(?client $client = null): int {
        global $DB;
        if (!connection::allows_launch() && connection::state() !== connection::STATE_REVOKED) {
            return 0;
        }
        $client = $client ?? new client();
        $since = (int) get_config('mod_moodlereader', 'v2reconcilesince');
        $cursor = null;
        $finished = 0;
        $newest = $since;
        $pages = 0;
        do {
            $query = ['finished_since' => $since, 'limit' => 500];
            if ($cursor !== null) {
                $query['cursor'] = $cursor;
            }
            $data = $client->get('/attempts', $query);
            foreach ((array) ($data['attempts'] ?? []) as $row) {
                if (!is_array($row) || empty($row['attempt_ref'])) {
                    continue;
                }
                $newest = max($newest, (int) ($row['finished_at'] ?? 0));
                $attempt = $DB->get_record('moodlereader_attempts', [
                    'attemptref' => (string) $row['attempt_ref'],
                    'state' => 'inprogress',
                ]);
                if ($attempt && self::apply($attempt, $row) === 'finished') {
                    $finished++;
                }
            }
            $cursor = isset($data['next_cursor']) && $data['next_cursor'] !== '' ? (string) $data['next_cursor'] : null;
            // A bounded run: anything left over is collected next time.
        } while ($cursor !== null && ++$pages < 50);
        // Overlap by five minutes so a result finished during this run is not skipped.
        set_config('v2reconcilesince', max(0, $newest - 300), 'mod_moodlereader');
        return $finished;
    }
}
