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
 * A refused or failed mreader.org API v2 call.
 *
 * `errorcode` is the machine-readable code from mreader.org's error envelope
 * (site_pending, site_revoked, bad_signature, ...), or one of our own transport
 * codes: `network` (no HTTP response) and `badresponse` (not the expected JSON).
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_exception extends \moodle_exception {
    /** @var string Machine-readable error code. */
    public $apicode;

    /** @var int HTTP status, or 0 when there was no response. */
    public $httpstatus;

    /** @var array The decoded response body, when there was one. */
    public $response;

    /**
     * Constructor.
     *
     * @param string $apicode
     * @param string $message Message from mreader.org, or transport detail.
     * @param int $httpstatus
     * @param array $response
     */
    public function __construct(string $apicode, string $message, int $httpstatus = 0, array $response = []) {
        $this->apicode = $apicode;
        $this->httpstatus = $httpstatus;
        $this->response = $response;
        parent::__construct('apierror', 'mod_moodlereader', '', (object) [
            'code' => $apicode,
            'message' => $message,
        ], $message);
    }

    /**
     * Whether the refusal is about the site's authorization state.
     *
     * @return bool
     */
    public function is_state_refusal(): bool {
        return in_array($this->apicode, ['site_pending', 'site_rejected', 'site_revoked', 'unknown_site'], true);
    }
}
