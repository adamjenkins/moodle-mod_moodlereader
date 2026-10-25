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

use mod_moodlereader\local\api\signer;

/**
 * The API v2 signatures reproduce the shared wire-specification vectors byte for byte.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\api\signer::class)]
final class signer_test extends \basic_testcase {
    /**
     * The vectors agreed with mreader.org.
     *
     * @return array
     */
    protected function vectors(): array {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/v2_vectors.json'), true);
    }

    /**
     * Each request vector's canonical string and signature match.
     *
     * @return void
     */
    public function test_request_vectors(): void {
        $v = $this->vectors();
        $this->assertCount(4, $v['requests']);
        foreach ($v['requests'] as $r) {
            $query = is_array($r['query']) ? $r['query'] : [];
            $this->assertSame(
                $r['canonical'],
                signer::canonical($r['method'], $r['path'], $query, $r['body'], $r['ts'], $r['nonce'])
            );
            $this->assertSame(
                $r['sig'],
                signer::request($v['secret'], $r['method'], $r['path'], $query, $r['body'], $r['ts'], $r['nonce'])
            );
        }
    }

    /**
     * The return-link, site-proof and legacy-claim vectors match.
     *
     * @return void
     */
    public function test_other_vectors(): void {
        $v = $this->vectors();
        $this->assertSame($v['return']['sig'], signer::return_link($v['secret'], $v['return']['ref'], $v['return']['ts']));
        $this->assertSame($v['siteproof']['response'], signer::siteproof($v['secret'], $v['siteproof']['c']));
        $this->assertSame($v['legacy_claim']['proof'], signer::legacy_claim(
            $v['legacy_claim']['legacy_key'],
            $v['site_id'],
            $v['legacy_claim']['ts']
        ));
    }

    /**
     * Query order does not matter; encoding does.
     *
     * @return void
     */
    public function test_sorted_query(): void {
        $this->assertSame('a=1&b=2', signer::sorted_query(['b' => 2, 'a' => 1]));
        $this->assertSame('cursor=a%20b%2Fc', signer::sorted_query(['cursor' => 'a b/c']));
        $this->assertSame('', signer::sorted_query([]));
        $this->assertNotSame(
            signer::request('k', 'GET', '/api/v2/x', [], '', 1, 'n'),
            signer::request('k2', 'GET', '/api/v2/x', [], '', 1, 'n')
        );
    }
}
