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
use mod_moodlereader\local\api\signer;
use mod_moodlereader\local\connection;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/fake_client.php');

/**
 * Tests for the site's mreader.org authorization (request, state, rotation, legacy claim).
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\connection::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\api\client::class)]
final class connection_test extends \advanced_testcase {
    /** @var string A secret as mreader.org would issue it. */
    const SECRET = 'Zm9vYmFyLWV4YW1wbGUtc2VjcmV0LTMyLWJ5dGVzISE';

    /**
     * A request is unsigned, carries no student data, and stores an inert credential.
     *
     * @return void
     */
    public function test_request_stores_pending_credential(): void {
        $this->resetAfterTest();
        $client = (new fake_client(null, '', ''))->queue('POST', '/site-requests', 201, [
            'site_id' => '6f1c2a9e-3b7d-4c11-9a0e-5d2b8f4e7a10', 'secret' => self::SECRET,
            'state' => 'pending', 'request_ref' => 'MR-7K3Q',
        ]);
        connection::request('Example School', 'A. Admin', 'admin@example.com', 'Hello', false, $client);

        $this->assertSame('pending', connection::state());
        $this->assertFalse(connection::allows_launch());
        $this->assertSame(self::SECRET, get_config('mod_moodlereader', 'v2secret'));
        $this->assertSame('MR-7K3Q', get_config('mod_moodlereader', 'v2requestref'));

        $request = $client->requests[0];
        $this->assertArrayNotHasKey('X-MReader-Signature', $request['headers']);
        $body = json_decode($request['body'], true);
        $this->assertSame((new \moodle_url('/mod/moodlereader/quiz/return.php'))->out(false), $body['return_url']);
        $keys = array_keys($body);
        sort($keys);
        $this->assertSame(['contact_email', 'contact_name', 'institution', 'message', 'moodle_release', 'plugin_release',
            'return_url', 'site_name', 'siteproof_url', 'wwwroot'], $keys);
    }

    /**
     * Refreshing stores the state, and a change is messaged to the admins.
     *
     * @return void
     */
    public function test_refresh_approves_and_notifies(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->set_pending();
        $client = (new fake_client(null, 'site-1', self::SECRET))->queue('GET', '/site', 200, [
            'site_id' => 'site-1', 'state' => 'approved', 'reason' => null, 'request_ref' => 'MR-7K3Q',
            'approved_at' => 1792900000, 'legacy_links' => [['legacy_sid' => 42, 'state' => 'linked']],
        ]);
        $sink = $this->redirectMessages();
        $this->assertSame('approved', connection::refresh($client));
        $this->assertTrue(connection::allows_launch());
        $this->assertSame([['legacy_sid' => 42, 'state' => 'linked']], connection::legacy_links());
        $messages = $sink->get_messages();
        $this->assertCount(count(get_admins()), $messages);
        $this->assertStringContainsString('Approved', $messages[0]->subject);

        // The status call is signed, and the signature verifies.
        $request = $client->requests[0];
        $headers = $request['headers'];
        $this->assertSame('site-1', $headers['X-MReader-Site']);
        $this->assertSame(signer::request(
            self::SECRET,
            'GET',
            '/api/v2/site',
            [],
            '',
            (int) $headers['X-MReader-Timestamp'],
            $headers['X-MReader-Nonce']
        ), $headers['X-MReader-Signature']);
    }

    /**
     * A revoked site's error envelope becomes an api_exception with its code.
     *
     * @return void
     */
    public function test_error_envelope(): void {
        $this->resetAfterTest();
        $client = (new fake_client(null, 'site-1', self::SECRET))->queue(
            'POST',
            '/launches',
            403,
            ['error' => ['code' => 'site_revoked', 'message' => 'Licence ended']]
        );
        try {
            $client->post('/launches', []);
            $this->fail('Expected an api_exception');
        } catch (api_exception $e) {
            $this->assertSame('site_revoked', $e->apicode);
            $this->assertSame(403, $e->httpstatus);
            $this->assertTrue($e->is_state_refusal());
        }
    }

    /**
     * An unknown credential marks the connection unknown, so a new request can be made.
     *
     * @return void
     */
    public function test_unknown_site_and_forget(): void {
        $this->resetAfterTest();
        $this->set_pending();
        $client = (new fake_client(null, 'site-1', self::SECRET))->queue(
            'GET',
            '/site',
            401,
            ['error' => ['code' => 'unknown_site', 'message' => '']]
        );
        $this->assertSame('unknown', connection::refresh($client));
        connection::forget();
        $this->assertSame('', connection::state());
        $this->assertFalse(get_config('mod_moodlereader', 'v2secret'));
    }

    /**
     * Rotation stores the new secret before using it, then confirms with it.
     *
     * @return void
     */
    public function test_rotate(): void {
        $this->resetAfterTest();
        $this->set_pending();
        set_config('v2state', 'approved', 'mod_moodlereader');
        $new = 'bmV3LXNlY3JldC0wMTIzNDU2Nzg5YWJjZGVmZ2hpams';
        $client = (new fake_client(null, 'site-1', self::SECRET))
            ->queue('POST', '/site/credential', 200, ['secret' => $new])
            ->queue('GET', '/site', 200, ['state' => 'approved']);
        connection::rotate($client);
        $this->assertSame($new, get_config('mod_moodlereader', 'v2secret'));
        $this->assertSame('approved', connection::state());
        // The rotation is signed with the old secret, the confirmation with the new one.
        foreach ([[0, self::SECRET, 'POST', '/api/v2/site/credential'], [1, $new, 'GET', '/api/v2/site']] as [$i, $key, $m, $p]) {
            $h = $client->requests[$i]['headers'];
            $this->assertSame(signer::request(
                $key,
                $m,
                $p,
                [],
                $client->requests[$i]['body'],
                (int) $h['X-MReader-Timestamp'],
                $h['X-MReader-Nonce']
            ), $h['X-MReader-Signature']);
        }
    }

    /**
     * The legacy claim proves the mod_reader key without sending it.
     *
     * @return void
     */
    public function test_claim_legacy(): void {
        $this->resetAfterTest();
        $this->set_pending();
        set_config('mreadersiteid', '42', 'mod_reader');
        set_config('mreadersitekey', 'legacykey123', 'mod_reader');
        $client = (new fake_client(null, 'site-1', self::SECRET))->queue(
            'POST',
            '/site/legacy-claim',
            202,
            ['state' => 'claimed']
        );
        connection::claim_legacy($client);
        $body = json_decode($client->requests[0]['body'], true);
        $this->assertSame(42, $body['legacy_sid']);
        $this->assertSame(signer::legacy_claim('legacykey123', 'site-1', $body['ts']), $body['proof']);
        $this->assertStringNotContainsString('legacykey123', $client->requests[0]['body']);
    }

    /**
     * Store a pending connection.
     *
     * @return void
     */
    protected function set_pending(): void {
        set_config('v2siteid', 'site-1', 'mod_moodlereader');
        set_config('v2secret', self::SECRET, 'mod_moodlereader');
        set_config('v2state', 'pending', 'mod_moodlereader');
    }
}
