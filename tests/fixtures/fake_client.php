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
 * A client whose HTTP exchanges are scripted, for unit tests.
 *
 * Responses are queued per "METHOD /path" (the path below /api/v2, without the
 * query string); every request is recorded with its headers and body.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_client extends client {
    /** @var \ArrayObject Queued responses, keyed "METHOD /path" (shared with clones). */
    public $responses;

    /** @var \ArrayObject Requests sent: method, url, path, query, headers (name => value), body (shared with clones). */
    public $requests;

    /**
     * Constructor.
     *
     * @param string|null $baseurl
     * @param string|null $siteid
     * @param string|null $secret
     */
    public function __construct(?string $baseurl = null, ?string $siteid = null, ?string $secret = null) {
        parent::__construct($baseurl, $siteid, $secret);
        $this->responses = new \ArrayObject();
        $this->requests = new \ArrayObject();
    }

    /**
     * Queue a JSON response.
     *
     * @param string $method
     * @param string $path Below /api/v2, e.g. '/site'.
     * @param int $status
     * @param array $body
     * @param array $headers
     * @return self
     */
    public function queue(string $method, string $path, int $status, array $body, array $headers = []): self {
        $key = $method . ' ' . $path;
        $queue = $this->responses[$key] ?? [];
        $queue[] = [
            'status' => $status,
            'headers' => $headers,
            'body' => json_encode($body + ['server_time' => time()]),
        ];
        $this->responses[$key] = $queue;
        return $this;
    }

    /**
     * Queue a JSON response built from the request's decoded body (e.g. to echo a ref the plugin made).
     *
     * @param string $method
     * @param string $path Below /api/v2.
     * @param int $status
     * @param callable $build function(array $requestbody): array
     * @return self
     */
    public function queue_callback(string $method, string $path, int $status, callable $build): self {
        $key = $method . ' ' . $path;
        $queue = $this->responses[$key] ?? [];
        $queue[] = ['status' => $status, 'headers' => [], 'build' => $build];
        $this->responses[$key] = $queue;
        return $this;
    }

    /**
     * Answer from the queue instead of the network.
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param string $body
     * @return array
     */
    protected function transport(string $method, string $url, array $headers, string $body): array {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $path = substr($parts['path'], strlen(self::PREFIX));
        $named = [];
        foreach ($headers as $header) {
            [$name, $value] = array_map('trim', explode(':', $header, 2));
            $named[$name] = $value;
        }
        $this->requests[] = ['method' => $method, 'url' => $url, 'path' => $parts['path'], 'query' => $query,
            'headers' => $named, 'body' => $body];
        $key = $method . ' ' . $path;
        $queue = $this->responses[$key] ?? [];
        if (!$queue) {
            throw new \coding_exception('No scripted response for ' . $key);
        }
        $response = array_shift($queue);
        $this->responses[$key] = $queue;
        if (isset($response['build'])) {
            $built = ($response['build'])(json_decode($body, true) ?: []);
            $response = ['status' => $response['status'], 'headers' => [],
                'body' => json_encode($built + ['server_time' => time()])];
        }
        return $response;
    }
}
