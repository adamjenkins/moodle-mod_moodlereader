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

use moodle_url;

/**
 * Client for the mreader.org quiz service.
 *
 * mreader.org hosts the book quizzes; this class builds the signed URLs the plugin
 * uses to register the site, list downloadable books, launch a quiz (in an iframe)
 * and verify the grade callback. The request signature is
 * md5(query-string . site-key); that algorithm is fixed by the remote service and
 * must not change. Only the *verification* of an incoming token is our concern, and
 * it uses a constant-time compare (see {@see self::verify_token()}).
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mreader_site {
    /** @var string Default base URL of the mreader.org service. */
    const DEFAULT_BASEURL = 'https://mreader.org';

    /** @var string Endpoint that registers this Moodle site and returns its id/key. */
    const SCRIPT_SITEID = '/api_get_siteid.php';

    /** @var string Endpoint that lists downloadable books. */
    const SCRIPT_API = '/api.php';

    /** @var string Endpoint that renders a quiz (loaded in an iframe). */
    const SCRIPT_TAKEQUIZ = '/api_takequiz.php';

    /** @var int Length of a generated opaque user id. */
    const LENGTH_UNIQUEID = 8;

    /** @var string Book-list selector: books that have a quiz. */
    const BOOKS_WITH_QUIZZES = 'bookswithquizzes';

    /** @var string Book-list selector: books that have no quiz. */
    const BOOKS_WITHOUT_QUIZZES = 'bookswithoutquizzes';

    /** @var string api.php action that returns a book cover image (books with quizzes). */
    const IMAGE_WITH_QUIZ = 'imagewithquiz';

    /** @var string Base URL of the remote service. */
    protected $baseurl;

    /** @var string Numeric id under which this site is registered on mreader.org. */
    protected $siteid;

    /** @var string Secret key that signs requests to mreader.org. */
    protected $sitekey;

    /**
     * Constructor.
     *
     * @param string $baseurl Base URL, or '' to read the configured value.
     * @param string $siteid  Site id, or '' to read the configured value.
     * @param string $sitekey Site key, or '' to read the configured value.
     */
    public function __construct(string $baseurl = '', string $siteid = '', string $sitekey = '') {
        $config = get_config('mod_moodlereader');
        $this->baseurl = $baseurl !== '' ? $baseurl : ($config->mreaderurl ?? self::DEFAULT_BASEURL);
        $this->siteid  = $siteid !== '' ? $siteid : ($config->mreadersiteid ?? '');
        $this->sitekey = $sitekey !== '' ? $sitekey : ($config->mreadersitekey ?? '');
    }

    /**
     * Whether this site is registered with mreader.org.
     *
     * @return bool
     */
    public function is_configured(): bool {
        return $this->baseurl !== '' && $this->siteid !== '' && $this->sitekey !== '';
    }

    /**
     * The opaque mreader.org identity for a user, creating it on first use.
     *
     * mreader.org never sees the real Moodle username; it sees this random id.
     *
     * @param int $userid
     * @return \stdClass Record from {moodlereader_users}.
     */
    public function get_identity(int $userid): \stdClass {
        global $DB;
        if ($record = $DB->get_record('moodlereader_users', ['userid' => $userid])) {
            return $record;
        }
        $record = (object) [
            'userid'     => $userid,
            'uniqueid'   => $this->generate_uniqueid(),
            'uniquename' => random_string(2) . ' ' . random_string(4),
        ];
        try {
            $record->id = $DB->insert_record('moodlereader_users', $record);
            return $record;
        } catch (\dml_exception $e) {
            // A concurrent first launch won the unique index; re-read the winner.
            if ($existing = $DB->get_record('moodlereader_users', ['userid' => $userid])) {
                return $existing;
            }
            throw $e;
        }
    }

    /**
     * Generate a database-unique opaque user id.
     *
     * @return string
     */
    protected function generate_uniqueid(): string {
        global $DB;
        do {
            $id = random_string(self::LENGTH_UNIQUEID);
        } while ($DB->record_exists('moodlereader_users', ['uniqueid' => $id]));
        return $id;
    }

    /**
     * Compute the request signature for a URL.
     *
     * Fixed by the remote service: md5(query-string . site-key). Do not change.
     *
     * @param moodle_url $url
     * @return string
     */
    public function generate_token(moodle_url $url): string {
        return md5($url->get_query_string(false) . $this->sitekey);
    }

    /**
     * Verify a token received from mreader.org against the recomputed one.
     *
     * @param moodle_url $url      The callback URL with its token parameter removed.
     * @param string     $received The token supplied in the request.
     * @return bool
     */
    public function verify_token(moodle_url $url, string $received): bool {
        return hash_equals($this->generate_token($url), $received);
    }

    /**
     * Append a valid token to a URL.
     *
     * @param string $script Endpoint path.
     * @param array  $params Query parameters.
     * @return moodle_url
     */
    protected function signed_url(string $script, array $params): moodle_url {
        $url = new moodle_url($this->baseurl . $script, $params);
        $url->param('token', $this->generate_token($url));
        return $url;
    }

    /**
     * The iframe URL that launches a book quiz on mreader.org.
     *
     * @param \stdClass $attempt  Record from {moodlereader_attempts}.
     * @param string    $bookimage The book's mreader.org image identifier.
     * @param \stdClass $identity  The user's mreader.org identity.
     * @return moodle_url
     */
    public function takequiz_url(\stdClass $attempt, string $bookimage, \stdClass $identity): moodle_url {
        return $this->signed_url(self::SCRIPT_TAKEQUIZ, [
            't'         => time(),
            'sid'       => $this->siteid,
            'book'      => $bookimage,
            'uname'     => $identity->uniqueid,
            'sname'     => $identity->uniquename,
            'custom_id' => $attempt->id,
        ]);
    }

    /**
     * The URL that lists downloadable books.
     *
     * @param string $selector One of the BOOKS_* constants.
     * @return moodle_url
     */
    public function books_url(string $selector): moodle_url {
        return $this->signed_url(self::SCRIPT_API, [
            'a'   => $selector,
            't'   => time(),
            'sid' => $this->siteid,
        ]);
    }

    /**
     * Fetch a book's cover image bytes from mreader.org.
     *
     * The cover is requested with a signed api.php call (token in the query string)
     * and the remote book id posted as `imageid`. Returns null if the site is not
     * registered, the id is unknown, the request fails, or the response is not an image.
     *
     * @param int $remoteid The book's mreader.org id (moodlereader_books.remoteid).
     * @return string|null Raw image bytes, or null on any failure.
     */
    public function fetch_cover(int $remoteid): ?string {
        global $CFG;
        if (!$this->is_configured() || $remoteid <= 0) {
            return null;
        }
        require_once($CFG->libdir . '/filelib.php');

        $url = $this->signed_url(self::SCRIPT_API, [
            'a'   => self::IMAGE_WITH_QUIZ,
            't'   => time(),
            'sid' => $this->siteid,
        ]);
        $curl = new \curl();
        $body = $curl->post($url->out(false), ['imageid' => $remoteid], [
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_RETURNTRANSFER' => 1,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_TIMEOUT'        => 30,
        ]);
        if ($curl->get_errno() || $body === false || $body === '') {
            return null;
        }
        $info = $curl->get_info();
        if (strpos($info['content_type'] ?? '', 'image/') !== 0) {
            return null;
        }
        return $body;
    }

    /**
     * The registration URL that fetches (or creates) this site's id/key.
     *
     * @param string $username mreader.org account username.
     * @param string $password mreader.org account password.
     * @return moodle_url
     */
    public function siteid_url(string $username, string $password): moodle_url {
        global $CFG;
        return new moodle_url($this->baseurl . self::SCRIPT_SITEID, [
            'url' => $CFG->wwwroot . '/mod/moodlereader/',
            'u'   => $username,
            'p'   => $password,
        ]);
    }

    /**
     * Register this site with mreader.org and store the returned id/key.
     *
     * @param string $username mreader.org account username.
     * @param string $password mreader.org account password.
     * @return string '' on success, or an error message.
     */
    public static function register(string $username, string $password): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $site = new self(self::DEFAULT_BASEURL);
        $url = $site->siteid_url($username, $password);

        $curl = new \curl();
        $response = $curl->post($url->out_omit_querystring(), $url->params(), [
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_RETURNTRANSFER' => 1,
            'CURLOPT_FRESH_CONNECT'  => 1,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_TIMEOUT'        => 30,
        ]);
        if ($curl->get_errno()) {
            return get_string('registrationfailed', 'mod_moodlereader', $curl->error);
        }

        $data = json_decode($response);
        if (!is_object($data) || empty($data->siteid) || empty($data->sitekey)) {
            return get_string('registrationinvalid', 'mod_moodlereader');
        }

        set_config('mreaderurl', self::DEFAULT_BASEURL, 'mod_moodlereader');
        set_config('mreadersiteid', clean_param($data->siteid, PARAM_ALPHANUM), 'mod_moodlereader');
        set_config('mreadersitekey', clean_param($data->sitekey, PARAM_ALPHANUM), 'mod_moodlereader');
        return '';
    }
}
