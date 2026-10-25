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
 * Answer mreader.org's site-ownership challenge ("Verify site" in its console).
 *
 * Public by design: it proves that the site at this address holds the credential
 * of the request. It reveals only an HMAC of the caller's challenge, domain-separated
 * from every other signature, and nothing else.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- a public, machine-to-machine endpoint.
define('NO_MOODLE_COOKIES', true);

require(__DIR__ . '/../../config.php');

$challenge = optional_param('c', '', PARAM_RAW);
$secret = (string) get_config('mod_moodlereader', 'v2secret');

if ($secret === '' || !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $challenge)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    die;
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo \mod_moodlereader\local\api\signer::siteproof($secret, $challenge);
