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
 * HMAC-SHA256 signatures for the mreader.org API v2.
 *
 * Every value here is fixed by the shared wire specification agreed with mreader.org
 * (canonical string, key = the secret string exactly as issued, base64url without
 * padding). The test vectors in tests/fixtures/v2_vectors.json come from that
 * specification; both sides must reproduce them byte for byte.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signer {
    /**
     * Base64url encoding without padding.
     *
     * @param string $raw
     * @return string
     */
    public static function b64url(string $raw): string {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * The query string in canonical form: rawurlencoded pairs sorted by key, then value.
     *
     * @param array $query Parameter name => value.
     * @return string
     */
    public static function sorted_query(array $query): string {
        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[] = [rawurlencode((string) $key), rawurlencode((string) $value)];
        }
        usort($pairs, fn($a, $b) => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));
        return implode('&', array_map(fn($p) => $p[0] . '=' . $p[1], $pairs));
    }

    /**
     * The canonical string a request signature covers.
     *
     * @param string $method HTTP method.
     * @param string $path Request path starting with /api/v2/.
     * @param array $query Query parameters.
     * @param string $body Exact request body bytes ('' for GET).
     * @param int $timestamp Unix seconds.
     * @param string $nonce
     * @return string
     */
    public static function canonical(
        string $method,
        string $path,
        array $query,
        string $body,
        int $timestamp,
        string $nonce
    ): string {
        return strtoupper($method) . "\n" . $path . "\n" . self::sorted_query($query) . "\n" .
            $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
    }

    /**
     * Sign a request.
     *
     * @param string $secret The site secret as issued.
     * @param string $method
     * @param string $path
     * @param array $query
     * @param string $body
     * @param int $timestamp
     * @param string $nonce
     * @return string
     */
    public static function request(
        string $secret,
        string $method,
        string $path,
        array $query,
        string $body,
        int $timestamp,
        string $nonce
    ): string {
        return self::mac($secret, self::canonical($method, $path, $query, $body, $timestamp, $nonce));
    }

    /**
     * Signature on the browser hand-back link (return.php?ref&ts&sig).
     *
     * @param string $secret
     * @param string $ref Attempt reference.
     * @param int $timestamp
     * @return string
     */
    public static function return_link(string $secret, string $ref, int $timestamp): string {
        return self::mac($secret, "return\n" . $ref . "\n" . $timestamp);
    }

    /**
     * Answer to mreader.org's site-ownership challenge.
     *
     * @param string $secret
     * @param string $challenge
     * @return string
     */
    public static function siteproof(string $secret, string $challenge): string {
        return self::mac($secret, "siteproof\n" . $challenge);
    }

    /**
     * Proof that this site holds the key of a legacy (mod_reader) registration.
     *
     * The legacy key itself is never sent; only this HMAC is.
     *
     * @param string $legacykey The key mod_reader stored for its registration.
     * @param string $siteid This site's v2 site id.
     * @param int $timestamp
     * @return string
     */
    public static function legacy_claim(string $legacykey, string $siteid, int $timestamp): string {
        return self::mac($legacykey, "legacy-claim\n" . $siteid . "\n" . $timestamp);
    }

    /**
     * A random nonce for one request.
     *
     * @return string 32 characters of base64url.
     */
    public static function nonce(): string {
        return self::b64url(random_bytes(24));
    }

    /**
     * HMAC-SHA256, base64url without padding.
     *
     * @param string $key
     * @param string $message
     * @return string
     */
    protected static function mac(string $key, string $message): string {
        return self::b64url(hash_hmac('sha256', $message, $key, true));
    }
}
