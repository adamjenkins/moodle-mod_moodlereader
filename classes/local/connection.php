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
use mod_moodlereader\local\api\signer;

/**
 * This site's authorization with mreader.org (API v2).
 *
 * A Moodle admin requests access; an mreader.org superadmin approves, rejects or
 * revokes it. The state is cached in the plugin config and refreshed from
 * mreader.org when the connection page is opened and by a daily task. mreader.org
 * checks the live state on every call, so the cache only decides what the UI shows.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connection {
    /** @var string No request has been made. */
    const STATE_NONE = '';
    /** @var string Requested, waiting for an mreader.org superadmin. */
    const STATE_PENDING = 'pending';
    /** @var string Students can take quizzes. */
    const STATE_APPROVED = 'approved';
    /** @var string The request was declined. */
    const STATE_REJECTED = 'rejected';
    /** @var string Access was withdrawn. */
    const STATE_REVOKED = 'revoked';
    /** @var string mreader.org no longer knows this site's credential. */
    const STATE_UNKNOWN = 'unknown';

    /** @var string[] Config keys holding the connection; cleared together by {@see self::forget()}. */
    const KEYS = ['v2siteid', 'v2secret', 'v2state', 'v2reason', 'v2requestref', 'v2requestedat',
        'v2approvedat', 'v2revokedat', 'v2lastchecked', 'v2clockskew', 'v2legacylinks', 'v2institution',
        'v2contactname', 'v2contactemail', 'v2reconcilesince', 'v2lastbooksync'];

    /**
     * The cached state.
     *
     * @return string One of the STATE_* constants.
     */
    public static function state(): string {
        return (string) get_config('mod_moodlereader', 'v2state');
    }

    /**
     * Whether students may start quizzes (mreader.org enforces this independently).
     *
     * @return bool
     */
    public static function allows_launch(): bool {
        return self::state() === self::STATE_APPROVED;
    }

    /**
     * The address mreader.org sends students back to after a quiz.
     *
     * @return string
     */
    public static function return_url(): string {
        return (new \moodle_url('/mod/moodlereader/quiz/return.php'))->out(false);
    }

    /**
     * The address of this site's ownership-proof responder.
     *
     * @return string
     */
    public static function siteproof_url(): string {
        return (new \moodle_url('/mod/moodlereader/siteproof.php'))->out(false);
    }

    /**
     * The mod_reader registration on this site, if mod_reader holds one.
     *
     * @return array|null ['sid' => int, 'key' => string]
     */
    public static function legacy_registration(): ?array {
        $sid = (int) get_config('mod_reader', 'mreadersiteid');
        $key = (string) get_config('mod_reader', 'mreadersitekey');
        if ($sid <= 0 || $key === '') {
            return null;
        }
        return ['sid' => $sid, 'key' => $key];
    }

    /**
     * What a request sends about this site (shown to the admin before sending).
     *
     * @return array
     */
    public static function site_details(): array {
        global $CFG, $SITE;
        return [
            'site_name'      => format_string($SITE->fullname, true, ['context' => \context_system::instance()]),
            'wwwroot'        => $CFG->wwwroot,
            'return_url'     => self::return_url(),
            'siteproof_url'  => self::siteproof_url(),
            'plugin_release' => (string) (\core_plugin_manager::instance()->get_plugin_info('mod_moodlereader')->release ?? ''),
            'moodle_release' => (string) $CFG->release,
        ];
    }

    /**
     * Send an authorization request to mreader.org and store the issued credential.
     *
     * @param string $institution
     * @param string $contactname
     * @param string $contactemail
     * @param string $message
     * @param bool $claimlegacy Ask mreader.org to link this site's mod_reader registration.
     * @param client|null $client For tests.
     * @return void
     */
    public static function request(
        string $institution,
        string $contactname,
        string $contactemail,
        string $message,
        bool $claimlegacy,
        ?client $client = null
    ): void {
        $client = $client ?? new client(null, '', '');
        $body = self::site_details() + [
            'institution'   => $institution,
            'contact_name'  => $contactname,
            'contact_email' => $contactemail,
        ];
        if (trim($message) !== '') {
            $body['message'] = $message;
        }
        // The legacy claim needs our new site id, which only exists after the request:
        // it is sent straight afterwards by claim_legacy().
        $data = $client->post('/site-requests', $body, false);

        $siteid = clean_param((string) ($data['site_id'] ?? ''), PARAM_ALPHANUMEXT);
        $secret = (string) ($data['secret'] ?? '');
        if ($siteid === '' || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $secret)) {
            throw new api_exception('badresponse', 'mreader.org did not issue a credential.');
        }
        set_config('v2siteid', $siteid, 'mod_moodlereader');
        set_config('v2secret', $secret, 'mod_moodlereader');
        set_config('v2state', self::clean_state($data['state'] ?? self::STATE_PENDING), 'mod_moodlereader');
        set_config(
            'v2requestref',
            clean_param((string) ($data['request_ref'] ?? ''), PARAM_ALPHANUMEXT),
            'mod_moodlereader'
        );
        set_config('v2requestedat', time(), 'mod_moodlereader');
        set_config('v2institution', $institution, 'mod_moodlereader');
        set_config('v2contactname', $contactname, 'mod_moodlereader');
        set_config('v2contactemail', $contactemail, 'mod_moodlereader');
        set_config('v2reason', '', 'mod_moodlereader');
        self::record_clock($client);

        if ($claimlegacy && self::legacy_registration()) {
            try {
                self::claim_legacy(new client(null, $siteid, $secret));
            } catch (api_exception $e) {
                // The request itself stands; the admin can retry the claim from the connection page.
                debugging('mreader.org legacy claim failed: ' . $e->apicode, DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Ask mreader.org to link this site to its mod_reader registration.
     *
     * Sends only an HMAC made with the legacy key, never the key.
     *
     * @param client|null $client
     * @return void
     */
    public static function claim_legacy(?client $client = null): void {
        $legacy = self::legacy_registration();
        if (!$legacy) {
            throw new \moodle_exception('nolegacyregistration', 'mod_moodlereader');
        }
        $client = $client ?? new client();
        $siteid = (string) get_config('mod_moodlereader', 'v2siteid');
        $ts = time();
        $client->post('/site/legacy-claim', [
            'legacy_sid' => $legacy['sid'],
            'ts'         => $ts,
            'proof'      => signer::legacy_claim($legacy['key'], $siteid, $ts),
        ]);
        self::record_clock($client);
    }

    /**
     * Fetch the current state from mreader.org, store it, and tell the admins if it changed.
     *
     * @param client|null $client
     * @return string The new state.
     */
    public static function refresh(?client $client = null): string {
        $client = $client ?? new client();
        if (!$client->has_credential()) {
            return self::state();
        }
        $before = self::state();
        try {
            $data = $client->get('/site');
        } catch (api_exception $e) {
            if ($e->apicode === 'unknown_site') {
                set_config('v2state', self::STATE_UNKNOWN, 'mod_moodlereader');
                set_config('v2lastchecked', time(), 'mod_moodlereader');
                self::notify_if_changed($before, self::STATE_UNKNOWN, '');
                return self::STATE_UNKNOWN;
            }
            self::record_clock($client);
            throw $e;
        }
        $state = self::clean_state($data['state'] ?? '');
        $reason = clean_param((string) ($data['reason'] ?? ''), PARAM_TEXT);
        set_config('v2state', $state, 'mod_moodlereader');
        set_config('v2reason', $reason, 'mod_moodlereader');
        set_config('v2approvedat', (int) ($data['approved_at'] ?? 0), 'mod_moodlereader');
        set_config('v2revokedat', (int) ($data['revoked_at'] ?? 0), 'mod_moodlereader');
        if (!empty($data['request_ref'])) {
            set_config('v2requestref', clean_param((string) $data['request_ref'], PARAM_ALPHANUMEXT), 'mod_moodlereader');
        }
        $links = [];
        foreach ((array) ($data['legacy_links'] ?? []) as $link) {
            if (is_array($link)) {
                $links[] = [
                    'legacy_sid' => (int) ($link['legacy_sid'] ?? 0),
                    'state' => clean_param((string) ($link['state'] ?? ''), PARAM_ALPHA),
                ];
            }
        }
        set_config('v2legacylinks', json_encode($links), 'mod_moodlereader');
        set_config('v2lastchecked', time(), 'mod_moodlereader');
        self::record_clock($client);
        self::notify_if_changed($before, $state, $reason);
        return $state;
    }

    /**
     * Replace the site secret with a new one issued by mreader.org.
     *
     * The new secret is stored before it is first used; mreader.org keeps the old
     * one valid until the new one has signed a request.
     *
     * @param client|null $client
     * @return void
     */
    public static function rotate(?client $client = null): void {
        $client = $client ?? new client();
        $data = $client->post('/site/credential', []);
        $secret = (string) ($data['secret'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $secret)) {
            throw new api_exception('badresponse', 'mreader.org did not issue a credential.');
        }
        set_config('v2secret', $secret, 'mod_moodlereader');
        // The first request signed with the new secret retires the old one on mreader.org.
        self::refresh($client->with_secret($secret));
    }

    /**
     * Drop the local connection so a new request can be sent (after a rejection).
     *
     * @return void
     */
    public static function forget(): void {
        foreach (self::KEYS as $key) {
            unset_config($key, 'mod_moodlereader');
        }
    }

    /**
     * The stored legacy-link entries.
     *
     * @return array
     */
    public static function legacy_links(): array {
        $links = json_decode((string) get_config('mod_moodlereader', 'v2legacylinks'), true);
        return is_array($links) ? $links : [];
    }

    /**
     * Normalise a state value from mreader.org.
     *
     * @param mixed $state
     * @return string
     */
    protected static function clean_state($state): string {
        $state = (string) $state;
        $known = [self::STATE_PENDING, self::STATE_APPROVED, self::STATE_REJECTED, self::STATE_REVOKED];
        return in_array($state, $known, true) ? $state : self::STATE_UNKNOWN;
    }

    /**
     * Keep the difference between our clock and mreader.org's (signatures need ±300 s).
     *
     * @param client $client
     * @return void
     */
    protected static function record_clock(client $client): void {
        if ($client->get_servertime() !== null) {
            set_config('v2clockskew', time() - $client->get_servertime(), 'mod_moodlereader');
        }
    }

    /**
     * Message the site admins when the authorization state changes.
     *
     * @param string $before
     * @param string $after
     * @param string $reason
     * @return void
     */
    protected static function notify_if_changed(string $before, string $after, string $reason): void {
        if ($before === $after || $before === self::STATE_NONE) {
            return;
        }
        $url = new \moodle_url('/mod/moodlereader/admin/connection.php');
        $a = (object) [
            'state' => get_string('connstate_' . $after, 'mod_moodlereader'),
            'reason' => $reason,
            'url' => $url->out(false),
        ];
        foreach (get_admins() as $admin) {
            $message = new \core\message\message();
            $message->component = 'mod_moodlereader';
            $message->name = 'siteconnection';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $admin;
            $message->subject = get_string('connmessage_subject', 'mod_moodlereader', $a);
            $message->fullmessage = get_string('connmessage_body', 'mod_moodlereader', $a);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = $message->subject;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = get_string('connection', 'mod_moodlereader');
            message_send($message);
        }
    }
}
