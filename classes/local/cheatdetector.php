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

/**
 * Detects and records suspected cheating.
 *
 * Each marker can be switched off and tuned per activity (see {@see self::settings()}):
 *
 * - sameip: two different students pass the same book from the same address (or the
 *   same network) within a time window. Addresses on the school's allow-list are skipped,
 *   because a whole school can share one public address.
 * - quickfinish: a book quiz passed faster than a minimum time.
 * - burst: one student passes many books within a short time.
 *
 * Flags are advisory: a teacher decides whether to mark an attempt as cheated, which
 * then removes its words from the grade. Each scan replaces the flags nobody has acted
 * on yet, so a changed setting or allow-list takes effect on the next scan.
 *
 * @package    mod_moodlereader
 * @copyright  2013 Gordon Bateson (gordon.bateson@gmail.com)
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cheatdetector {
    /** @var string[] The markers, in display order. */
    const MARKERS = ['sameip', 'quickfinish', 'burst'];

    /** @var int Compare whole addresses. */
    const MATCH_EXACT = 4;

    /** @var int Compare networks: IPv4 /24 (first three numbers), IPv6 /64. */
    const MATCH_NETWORK = 3;

    /** @var int Compare wide networks: IPv4 /16 (first two numbers), IPv6 /48. */
    const MATCH_WIDE = 2;

    /** @var string[] Addresses never compared (this server itself). */
    const LOOPBACK = ['127.0.0.1', '::1'];

    /**
     * The settings used when an activity has not changed them.
     *
     * @return array
     */
    public static function defaults(): array {
        return [
            'sameip' => ['enabled' => 1, 'window' => 30 * MINSECS, 'match' => self::MATCH_EXACT],
            'quickfinish' => ['enabled' => 1, 'minduration' => 2 * MINSECS],
            'burst' => ['enabled' => 0, 'count' => 4, 'window' => HOURSECS],
            'allowlist' => '',
        ];
    }

    /**
     * An activity's detection settings, completed with the defaults.
     *
     * @param \stdClass $instance The {moodlereader} record.
     * @return array
     */
    public static function settings(\stdClass $instance): array {
        $stored = json_decode((string) ($instance->cheatsettings ?? ''), true);
        return self::clean_settings(is_array($stored) ? $stored : []);
    }

    /**
     * Validate settings from any source (form, database, backup) into the stored shape.
     *
     * Unknown keys are dropped and every number is kept within its range.
     *
     * @param array $raw
     * @return array
     */
    public static function clean_settings(array $raw): array {
        $defaults = self::defaults();
        $int = function (string $marker, string $key, int $min, int $max) use ($raw, $defaults): int {
            $value = $raw[$marker][$key] ?? $defaults[$marker][$key];
            return max($min, min($max, is_numeric($value) ? (int) $value : (int) $defaults[$marker][$key]));
        };
        // A marker whose switch is not given keeps its default (on or off).
        $enabled = fn(string $marker): int => isset($raw[$marker]['enabled'])
            ? (empty($raw[$marker]['enabled']) ? 0 : 1) : $defaults[$marker]['enabled'];
        $match = (int) ($raw['sameip']['match'] ?? self::MATCH_EXACT);
        if (!in_array($match, [self::MATCH_EXACT, self::MATCH_NETWORK, self::MATCH_WIDE], true)) {
            $match = self::MATCH_EXACT;
        }
        [$allowlist] = self::clean_allowlist((string) ($raw['allowlist'] ?? ''));
        return [
            'sameip' => [
                'enabled' => $enabled('sameip'),
                'window' => $int('sameip', 'window', MINSECS, WEEKSECS),
                'match' => $match,
            ],
            'quickfinish' => [
                'enabled' => $enabled('quickfinish'),
                'minduration' => $int('quickfinish', 'minduration', 10, HOURSECS),
            ],
            'burst' => [
                'enabled' => $enabled('burst'),
                'count' => $int('burst', 'count', 2, 100),
                'window' => $int('burst', 'window', MINSECS, WEEKSECS),
            ],
            'allowlist' => $allowlist,
        ];
    }

    /**
     * Split an allow-list into valid entries and rejected ones.
     *
     * Entries are one per line (commas also separate; # starts a comment) in the forms Moodle's
     * address_in_subnet() accepts: 192.168.1.10, 192.168.1.0/24, 192.168.1.10-20,
     * 192.168. (a prefix), and the IPv6 equivalents.
     *
     * @param string $text
     * @return array [string $clean, string[] $invalid] The clean list holds one entry per line.
     */
    public static function clean_allowlist(string $text): array {
        $valid = [];
        $invalid = [];
        // Text after a # on a line is a comment.
        $text = preg_replace('/#[^\n]*/', '', $text);
        foreach (preg_split('/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            if (self::valid_allowlist_entry($entry)) {
                $valid[$entry] = $entry;
            } else {
                $invalid[] = $entry;
            }
        }
        return [implode("\n", $valid), $invalid];
    }

    /**
     * Whether one allow-list entry is an address, a range, a CIDR block or a prefix.
     *
     * @param string $entry
     * @return bool
     */
    protected static function valid_allowlist_entry(string $entry): bool {
        if (filter_var($entry, FILTER_VALIDATE_IP)) {
            return true;
        }
        if (preg_match('#^([0-9a-fA-F:.]+)/(\d{1,3})$#', $entry, $m)) {
            $max = str_contains($m[1], ':') ? 128 : 32;
            return filter_var($m[1], FILTER_VALIDATE_IP) && (int) $m[2] <= $max;
        }
        if (preg_match('#^([0-9a-fA-F:.]+)-([0-9a-fA-F]{1,4})$#', $entry, $m)) {
            return (bool) filter_var($m[1], FILTER_VALIDATE_IP);
        }
        // A prefix such as "192.168." or "2001:db8:".
        return (bool) preg_match('#^(\d{1,3}\.){1,3}$#', $entry) || (bool) preg_match('#^([0-9a-fA-F]{1,4}:){1,7}$#', $entry);
    }

    /**
     * The allow-list in force for an activity: the site's list plus the activity's own.
     *
     * @param array $settings From {@see self::settings()}.
     * @return string Comma-separated, for address_in_subnet().
     */
    public static function effective_allowlist(array $settings): string {
        [$site] = self::clean_allowlist((string) get_config('mod_moodlereader', 'cheatallowlist'));
        $entries = array_filter(array_merge(explode("\n", $site), explode("\n", $settings['allowlist'])));
        return implode(',', array_unique($entries));
    }

    /**
     * Whether an address is on an allow-list.
     *
     * @param string $ip
     * @param string $allowlist Comma-separated entries.
     * @return bool
     */
    public static function is_allowed(string $ip, string $allowlist): bool {
        global $CFG;
        require_once($CFG->libdir . '/moodlelib.php');
        return $allowlist !== '' && address_in_subnet($ip, $allowlist);
    }

    /**
     * The part of an address compared by the same-address marker.
     *
     * @param string $ip
     * @param int $match One of the MATCH_ constants.
     * @return string Empty when the address cannot be compared.
     */
    public static function network_key(string $ip, int $match): string {
        if ($ip === '' || in_array($ip, self::LOOPBACK, true) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return '';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_slice(explode('.', $ip), 0, $match));
        }
        // IPv6: expand to eight groups, then keep 8 (exact), 4 (/64) or 3 (/48) of them.
        $groups = str_split(bin2hex(inet_pton($ip)), 4);
        $keep = [self::MATCH_EXACT => 8, self::MATCH_NETWORK => 4, self::MATCH_WIDE => 3][$match];
        return implode(':', array_slice($groups, 0, $keep));
    }

    /**
     * Scan an activity and replace the flags nobody has acted on with the current result.
     *
     * Only this term's attempts are scanned (those finished since the activity's
     * "ignore before" date). Flags on attempts already marked as cheated, and flags
     * imported from mod_reader, are kept.
     *
     * @param int $readerid
     * @return int Number of attempts flagged by this scan.
     */
    public static function detect(int $readerid): int {
        global $DB;
        $instance = $DB->get_record('moodlereader', ['id' => $readerid], '*', MUST_EXIST);
        $settings = self::settings($instance);

        [$insql, $inparams] = $DB->get_in_or_equal(self::MARKERS, SQL_PARAMS_NAMED, 'reason');
        $DB->delete_records_select(
            'moodlereader_cheatlog',
            "readerid = :readerid AND reason $insql AND attemptid IN (
                SELECT id FROM {moodlereader_attempts} WHERE readerid = :readerid2 AND cheated = 0)",
            ['readerid' => $readerid, 'readerid2' => $readerid] + $inparams
        );

        // Passed quizzes only: credit awarded by a teacher is not a quiz.
        $attempts = $DB->get_records_select(
            'moodlereader_attempts',
            "readerid = :readerid AND state = 'finished' AND passed = 1 AND credit = 0 AND deleted = 0
                 AND timefinish > 0 AND timefinish >= :since",
            ['readerid' => $readerid, 'since' => (int) $instance->ignoredate],
            'timefinish, id',
            'id, userid, bookid, ip, timestart, timefinish'
        );

        $flags = [];
        if ($settings['sameip']['enabled']) {
            $flags = array_merge($flags, self::find_same_ip($attempts, $settings));
        }
        if ($settings['quickfinish']['enabled']) {
            $flags = array_merge($flags, self::find_quick_finish($attempts, $settings['quickfinish']['minduration']));
        }
        if ($settings['burst']['enabled']) {
            $flags = array_merge($flags, self::find_bursts($attempts, $settings['burst']['count'], $settings['burst']['window']));
        }

        $now = time();
        $flagged = [];
        foreach ($flags as $flag) {
            $key = ['readerid' => $readerid, 'attemptid' => $flag['attemptid'],
                'otherattemptid' => $flag['otherattemptid'], 'reason' => $flag['reason']];
            if (!$DB->record_exists('moodlereader_cheatlog', $key)) {
                $DB->insert_record('moodlereader_cheatlog', (object) ($key + [
                    'userid' => $attempts[$flag['attemptid']]->userid,
                    'otheruserid' => $flag['otherattemptid'] ? $attempts[$flag['otherattemptid']]->userid : 0,
                    'detail' => $flag['detail'],
                    'timecreated' => $now,
                ]));
            }
            $flagged[$flag['attemptid']] = true;
        }
        return count($flagged);
    }

    /**
     * Same-book passes by different students from one address or network, close in time.
     *
     * @param \stdClass[] $attempts Sorted by finish time.
     * @param array $settings
     * @return array[] Flags.
     */
    protected static function find_same_ip(array $attempts, array $settings): array {
        $allowlist = self::effective_allowlist($settings);
        $window = $settings['sameip']['window'];
        $bybook = [];
        foreach ($attempts as $a) {
            $key = self::network_key((string) $a->ip, $settings['sameip']['match']);
            if ($key !== '' && !self::is_allowed((string) $a->ip, $allowlist)) {
                $bybook[$a->bookid][] = [$a, $key];
            }
        }
        $flags = [];
        foreach ($bybook as $list) {
            $n = count($list);
            for ($i = 0; $i < $n; $i++) {
                [$a, $akey] = $list[$i];
                for ($j = $i + 1; $j < $n && $list[$j][0]->timefinish - $a->timefinish <= $window; $j++) {
                    [$b, $bkey] = $list[$j];
                    if ($akey === $bkey && $a->userid != $b->userid) {
                        $gap = (int) ($b->timefinish - $a->timefinish);
                        $flags[] = ['attemptid' => $a->id, 'otherattemptid' => $b->id, 'reason' => 'sameip', 'detail' => $gap];
                        $flags[] = ['attemptid' => $b->id, 'otherattemptid' => $a->id, 'reason' => 'sameip', 'detail' => $gap];
                    }
                }
            }
        }
        return $flags;
    }

    /**
     * Quizzes passed faster than the minimum time.
     *
     * @param \stdClass[] $attempts
     * @param int $minduration Seconds.
     * @return array[] Flags.
     */
    protected static function find_quick_finish(array $attempts, int $minduration): array {
        $flags = [];
        foreach ($attempts as $a) {
            $duration = (int) ($a->timefinish - $a->timestart);
            if ($a->timestart > 0 && $duration >= 0 && $duration < $minduration) {
                $flags[] = ['attemptid' => $a->id, 'otherattemptid' => 0, 'reason' => 'quickfinish', 'detail' => $duration];
            }
        }
        return $flags;
    }

    /**
     * Students who pass at least $count quizzes within $window seconds.
     *
     * @param \stdClass[] $attempts Sorted by finish time.
     * @param int $count
     * @param int $window Seconds.
     * @return array[] Flags.
     */
    protected static function find_bursts(array $attempts, int $count, int $window): array {
        $byuser = [];
        foreach ($attempts as $a) {
            $byuser[$a->userid][] = $a;
        }
        $flags = [];
        foreach ($byuser as $list) {
            $n = count($list);
            $inburst = [];
            for ($start = 0, $end = 0; $end < $n; $end++) {
                while ($list[$end]->timefinish - $list[$start]->timefinish > $window) {
                    $start++;
                }
                if ($end - $start + 1 >= $count) {
                    for ($k = $start; $k <= $end; $k++) {
                        $inburst[$list[$k]->id] = max($inburst[$list[$k]->id] ?? 0, $end - $start + 1);
                    }
                }
            }
            foreach ($inburst as $attemptid => $size) {
                $flags[] = ['attemptid' => $attemptid, 'otherattemptid' => 0, 'reason' => 'burst', 'detail' => $size];
            }
        }
        return $flags;
    }

    /**
     * One line describing a switched-on marker's limits.
     *
     * @param string $marker
     * @param array $settings
     * @return string
     */
    public static function summary(string $marker, array $settings): string {
        $s = $settings[$marker];
        switch ($marker) {
            case 'sameip':
                $match = [
                    self::MATCH_EXACT => 'sameip_match_exact',
                    self::MATCH_NETWORK => 'sameip_match_network',
                    self::MATCH_WIDE => 'sameip_match_wide',
                ][$s['match']];
                $allowed = self::effective_allowlist($settings);
                return get_string('summary_sameip', 'mod_moodlereader', [
                    'window' => format_time($s['window']),
                    'match' => get_string($match, 'mod_moodlereader'),
                    'allowed' => $allowed === '' ? 0 : count(explode(',', $allowed)),
                ]);
            case 'quickfinish':
                return get_string('summary_quickfinish', 'mod_moodlereader', format_time($s['minduration']));
            default:
                return get_string('summary_burst', 'mod_moodlereader', [
                    'count' => $s['count'],
                    'window' => format_time($s['window']),
                ]);
        }
    }

    /**
     * Mark or unmark an attempt as cheated, and refresh the user's grade.
     *
     * @param reader $reader
     * @param int $attemptid
     * @param bool $cheated
     * @return void
     */
    public static function set_cheated(reader $reader, int $attemptid, bool $cheated): void {
        attempt_admin::update($reader, [$attemptid], $cheated ? attempt_admin::ACTION_CHEAT : attempt_admin::ACTION_UNCHEAT);
    }
}
