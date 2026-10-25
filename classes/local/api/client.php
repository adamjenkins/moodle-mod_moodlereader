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

namespace mod_moodlereader\local\api;

/**
 * HTTP client for the mreader.org API v2.
 *
 * Every call except the authorization request is signed with the site secret
 * ({@see signer}). Only opaque pseudonyms ever leave this site: no name, username,
 * email address or Moodle user id is part of any request this class sends.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client {
    /** @var string Path prefix of every v2 endpoint. */
    const PREFIX = '/api/v2';

    /** @var string Default base URL of the mreader.org service. */
    const DEFAULT_BASEURL = 'https://mreader.org';

    /** @var string Base URL, no trailing slash. */
    protected $baseurl;

    /** @var string Site id issued by mreader.org ('' before a request). */
    protected $siteid;

    /** @var string Site secret ('' before a request). */
    protected $secret;

    /** @var int|null mreader.org's clock, from the last response. */
    protected $servertime = null;

    /**
     * Constructor.
     *
     * @param string|null $baseurl Null to read the plugin setting.
     * @param string|null $siteid Null to read the stored connection.
     * @param string|null $secret Null to read the stored connection.
     */
    public function __construct(?string $baseurl = null, ?string $siteid = null, ?string $secret = null) {
        $config = get_config('mod_moodlereader');
        $baseurl = $baseurl ?? ($config->mreaderurl ?? '');
        $this->baseurl = rtrim($baseurl !== '' ? $baseurl : self::DEFAULT_BASEURL, '/');
        $this->siteid = $siteid ?? (string) ($config->v2siteid ?? '');
        $this->secret = $secret ?? (string) ($config->v2secret ?? '');
    }

    /**
     * Whether a credential is held.
     *
     * @return bool
     */
    public function has_credential(): bool {
        return $this->siteid !== '' && $this->secret !== '';
    }

    /**
     * The same client, signing with another secret (after a rotation).
     *
     * @param string $secret
     * @return static
     */
    public function with_secret(string $secret): self {
        $copy = clone $this;
        $copy->secret = $secret;
        return $copy;
    }

    /**
     * The configured base URL.
     *
     * @return string
     */
    public function get_baseurl(): string {
        return $this->baseurl;
    }

    /**
     * mreader.org's clock as reported in the last response, if any.
     *
     * @return int|null
     */
    public function get_servertime(): ?int {
        return $this->servertime;
    }

    /**
     * A signed GET returning decoded JSON.
     *
     * @param string $path Endpoint path below /api/v2, e.g. '/site'.
     * @param array $query
     * @return array
     */
    public function get(string $path, array $query = []): array {
        return $this->json($this->send('GET', $path, $query, '', true));
    }

    /**
     * A POST returning decoded JSON.
     *
     * @param string $path Endpoint path below /api/v2.
     * @param array $data Body, sent as a JSON object.
     * @param bool $signed False only for the unsigned authorization request.
     * @return array
     */
    public function post(string $path, array $data, bool $signed = true): array {
        $body = json_encode((object) $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $this->json($this->send('POST', $path, [], $body, $signed));
    }

    /**
     * A signed GET returning the raw response (used for cover images).
     *
     * @param string $path
     * @param array $query
     * @param array $headers Extra request headers, e.g. ['If-None-Match: "x"'].
     * @return array ['status' => int, 'headers' => array (lower-case names), 'body' => string]
     */
    public function get_raw(string $path, array $query = [], array $headers = []): array {
        $response = $this->send('GET', $path, $query, '', true, $headers);
        if ($response['status'] >= 400) {
            $this->json($response);
        }
        return $response;
    }

    /**
     * Build, sign and send one request.
     *
     * @param string $method
     * @param string $path
     * @param array $query
     * @param string $body
     * @param bool $signed
     * @param array $extraheaders
     * @return array ['status' => int, 'headers' => array, 'body' => string]
     */
    protected function send(
        string $method,
        string $path,
        array $query,
        string $body,
        bool $signed,
        array $extraheaders = []
    ): array {
        $fullpath = self::PREFIX . $path;
        $querystring = signer::sorted_query($query);
        $url = $this->baseurl . $fullpath . ($querystring !== '' ? '?' . $querystring : '');

        $headers = array_merge(['Accept: application/json'], $extraheaders);
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }
        if ($signed) {
            if (!$this->has_credential()) {
                throw new api_exception('nocredential', 'This site holds no mreader.org credential.');
            }
            $timestamp = time();
            $nonce = signer::nonce();
            $headers[] = 'X-MReader-Site: ' . $this->siteid;
            $headers[] = 'X-MReader-Timestamp: ' . $timestamp;
            $headers[] = 'X-MReader-Nonce: ' . $nonce;
            $headers[] = 'X-MReader-Signature: ' .
                signer::request($this->secret, $method, $fullpath, $query, $body, $timestamp, $nonce);
        }
        return $this->transport($method, $url, $headers, $body);
    }

    /**
     * Perform the HTTP exchange. Overridden in unit tests.
     *
     * @param string $method
     * @param string $url
     * @param string[] $headers
     * @param string $body
     * @return array ['status' => int, 'headers' => array (lower-case names), 'body' => string]
     */
    protected function transport(string $method, string $url, array $headers, string $body): array {
        global $CFG;
        // On the Behat test site only, a fake mreader.org can answer instead (tests/behat/fake_mreader.php).
        if (defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING && get_config('mod_moodlereader', 'behatfakemreader')) {
            require_once($CFG->dirroot . '/mod/moodlereader/tests/behat/fake_mreader.php');
            return \behat_fake_mreader::handle($method, $url, $body);
        }
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $curl->setHeader($headers);
        $options = [
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_RETURNTRANSFER' => 1,
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_TIMEOUT'        => 30,
        ];
        $result = $method === 'POST' ? $curl->post($url, $body, $options) : $curl->get($url, [], $options);
        if ($curl->get_errno() || $result === false) {
            throw new api_exception('network', (string) ($curl->error ?: 'No response from ' . $this->baseurl));
        }
        $info = $curl->get_info();
        $responseheaders = [];
        foreach ((array) $curl->getResponse() as $name => $value) {
            $responseheaders[\core_text::strtolower((string) $name)] = is_array($value) ? end($value) : $value;
        }
        return ['status' => (int) ($info['http_code'] ?? 0), 'headers' => $responseheaders, 'body' => (string) $result];
    }

    /**
     * Decode a JSON response, raising the error envelope as an exception.
     *
     * @param array $response
     * @return array
     */
    protected function json(array $response): array {
        $data = json_decode($response['body'], true);
        if (is_array($data) && isset($data['server_time']) && is_numeric($data['server_time'])) {
            $this->servertime = (int) $data['server_time'];
        }
        if ($response['status'] >= 400 || $response['status'] < 200) {
            $code = is_array($data) ? (string) ($data['error']['code'] ?? '') : '';
            $message = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
            throw new api_exception(
                $code !== '' ? clean_param($code, PARAM_ALPHANUMEXT) : 'http' . $response['status'],
                $message !== '' ? clean_param($message, PARAM_TEXT) : 'HTTP status ' . $response['status'],
                $response['status'],
                is_array($data) ? $data : []
            );
        }
        if (!is_array($data)) {
            throw new api_exception('badresponse', 'mreader.org did not answer with JSON.', $response['status']);
        }
        return $data;
    }
}
