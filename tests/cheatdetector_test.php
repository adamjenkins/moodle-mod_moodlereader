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

use mod_moodlereader\local\reader;
use mod_moodlereader\local\cheatdetector;

/**
 * Tests for cheating detection.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\cheatdetector::class)]
final class cheatdetector_test extends \advanced_testcase {
    /**
     * Insert a finished, passing attempt directly.
     *
     * @param int $readerid
     * @param int $userid
     * @param int $bookid
     * @param string $ip
     * @param int $timefinish
     * @param int $duration Seconds from start to finish.
     * @return int Attempt id.
     */
    protected function make_attempt(
        int $readerid,
        int $userid,
        int $bookid,
        string $ip,
        int $timefinish,
        int $duration = 600
    ): int {
        global $DB;
        return (int) $DB->insert_record('moodlereader_attempts', (object) [
            'readerid' => $readerid, 'userid' => $userid, 'bookid' => $bookid, 'attempt' => 1,
            'state' => 'finished', 'percentgrade' => 90, 'passed' => 1, 'credit' => 0, 'cheated' => 0,
            'deleted' => 0, 'bookrating' => 0, 'timestart' => $timefinish - $duration, 'timefinish' => $timefinish,
            'timemodified' => $timefinish, 'ip' => $ip,
        ]);
    }

    /**
     * Two same-book passes from the same IP, close in time, are flagged.
     *
     * @return void
     */
    public function test_same_ip_pair_is_flagged(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $cm = $gen->create_instance(['course' => $course->id, 'goal' => 5000]);
        $book = $gen->create_book();
        $u1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $u2 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $now = time();

        $this->make_attempt($cm->id, $u1->id, $book->id, '10.0.0.5', $now);
        $this->make_attempt($cm->id, $u2->id, $book->id, '10.0.0.5', $now + 60);

        $flagged = cheatdetector::detect($cm->id);
        $this->assertSame(2, $flagged);
        $this->assertTrue($DB->record_exists('moodlereader_cheatlog', ['readerid' => $cm->id]));
    }

    /**
     * Different IPs are not flagged, and a second scan does not duplicate flags.
     *
     * @return void
     */
    public function test_different_ip_not_flagged_and_no_duplicates(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $cm = $gen->create_instance(['course' => $course->id, 'goal' => 5000]);
        $book = $gen->create_book();
        $u1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $u2 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $now = time();

        $this->make_attempt($cm->id, $u1->id, $book->id, '10.0.0.5', $now);
        $this->make_attempt($cm->id, $u2->id, $book->id, '10.0.0.9', $now + 60);
        $this->assertEquals(0, cheatdetector::detect($cm->id));

        // Now add a same-IP pair, scan twice: the second scan finds the same pair and adds no rows.
        $this->make_attempt($cm->id, $u1->id, $book->id, '10.0.0.7', $now);
        $this->make_attempt($cm->id, $u2->id, $book->id, '10.0.0.7', $now + 30);
        $this->assertSame(2, cheatdetector::detect($cm->id));
        $rows = $DB->count_records('moodlereader_cheatlog', ['readerid' => $cm->id]);
        $this->assertSame(2, cheatdetector::detect($cm->id));
        $this->assertSame($rows, $DB->count_records('moodlereader_cheatlog', ['readerid' => $cm->id]));
    }

    /**
     * Marking an attempt cheated removes its words from the grade.
     *
     * @return void
     */
    public function test_marking_cheated_updates_grade(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $cm = $gen->create_instance(['course' => $course->id, 'goal' => 5000, 'maxgrade' => 100]);
        $book = $gen->create_book(['words' => 5000]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $reader = reader::from_instanceid($cm->id);

        $attemptid = $this->make_attempt($cm->id, $user->id, $book->id, '10.0.0.5', time());
        \moodlereader_update_grades($reader->get_instance(), $user->id);

        $before = grade_get_grades($course->id, 'mod', 'moodlereader', $cm->id, $user->id);
        $this->assertEquals(100, (int) reset($before->items)->grades[$user->id]->grade);

        cheatdetector::set_cheated($reader, $attemptid, true);
        $after = grade_get_grades($course->id, 'mod', 'moodlereader', $cm->id, $user->id);
        $this->assertEquals(0, (int) reset($after->items)->grades[$user->id]->grade);
    }

    /**
     * Create an activity with two students and a book.
     *
     * @param array $settings Detection settings to store, or none for the defaults.
     * @return array [\stdClass $instance, \stdClass $u1, \stdClass $u2, \stdClass $book]
     */
    protected function setup_activity(array $settings = []): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $instance = $gen->create_instance(['course' => $course->id, 'goal' => 5000]);
        if ($settings) {
            $DB->set_field(
                'moodlereader',
                'cheatsettings',
                json_encode(cheatdetector::clean_settings($settings)),
                ['id' => $instance->id]
            );
        }
        $u1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $u2 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        return [$instance, $u1, $u2, $gen->create_book()];
    }

    /**
     * Allow-listed addresses are never compared, from the site list or the activity's own.
     *
     * @return void
     */
    public function test_allowlisted_addresses_are_not_compared(): void {
        global $DB;
        $this->resetAfterTest();
        [$instance, $u1, $u2, $book] = $this->setup_activity();
        $now = time();
        $this->make_attempt($instance->id, $u1->id, $book->id, '203.0.113.7', $now);
        $this->make_attempt($instance->id, $u2->id, $book->id, '203.0.113.7', $now + 60);

        set_config('cheatallowlist', "203.0.113.0/24 # school", 'mod_moodlereader');
        $this->assertSame(0, cheatdetector::detect($instance->id));

        set_config('cheatallowlist', '', 'mod_moodlereader');
        $DB->set_field('moodlereader', 'cheatsettings', json_encode(cheatdetector::clean_settings(
            ['sameip' => ['enabled' => 1], 'allowlist' => '203.0.113.']
        )), ['id' => $instance->id]);
        $this->assertSame(0, cheatdetector::detect($instance->id));

        $DB->set_field('moodlereader', 'cheatsettings', null, ['id' => $instance->id]);
        $this->assertSame(2, cheatdetector::detect($instance->id));
    }

    /**
     * Network matching flags nearby addresses that whole-address matching does not, IPv6 included.
     *
     * @return void
     */
    public function test_network_matching(): void {
        $this->resetAfterTest();
        $this->assertSame('10.1.2', cheatdetector::network_key('10.1.2.3', cheatdetector::MATCH_NETWORK));
        $this->assertSame('10.1', cheatdetector::network_key('10.1.2.3', cheatdetector::MATCH_WIDE));
        $this->assertSame(
            '2001:0db8:0000:0001',
            cheatdetector::network_key('2001:db8:0:1::5', cheatdetector::MATCH_NETWORK)
        );
        $this->assertSame('', cheatdetector::network_key('127.0.0.1', cheatdetector::MATCH_EXACT));
        $this->assertSame('', cheatdetector::network_key('not-an-ip', cheatdetector::MATCH_EXACT));

        [$instance, $u1, $u2, $book] = $this->setup_activity(['sameip' => ['enabled' => 1, 'match' => 3]]);
        $now = time();
        $this->make_attempt($instance->id, $u1->id, $book->id, '10.1.2.3', $now);
        $this->make_attempt($instance->id, $u2->id, $book->id, '10.1.2.99', $now + 60);
        $this->assertSame(2, cheatdetector::detect($instance->id));
    }

    /**
     * A quick finish is flagged; switching the marker off removes the unconfirmed flag on rescan.
     *
     * @return void
     */
    public function test_quick_finish_and_rescan_replaces_unconfirmed_flags(): void {
        global $DB;
        $this->resetAfterTest();
        [$instance, $u1, , $book] = $this->setup_activity();
        $quick = $this->make_attempt($instance->id, $u1->id, $book->id, '10.0.0.1', time(), 30);

        $this->assertSame(1, cheatdetector::detect($instance->id));
        $flag = $DB->get_record('moodlereader_cheatlog', ['attemptid' => $quick], '*', MUST_EXIST);
        $this->assertSame('quickfinish', $flag->reason);
        $this->assertEquals(30, $flag->detail);

        $DB->set_field('moodlereader', 'cheatsettings', json_encode(cheatdetector::clean_settings(
            ['sameip' => ['enabled' => 1], 'quickfinish' => ['enabled' => 0]]
        )), ['id' => $instance->id]);
        $this->assertSame(0, cheatdetector::detect($instance->id));
        $this->assertFalse($DB->record_exists('moodlereader_cheatlog', ['attemptid' => $quick]));
    }

    /**
     * A flag on an attempt the teacher marked as cheated survives a rescan.
     *
     * @return void
     */
    public function test_confirmed_flags_are_kept(): void {
        global $DB;
        $this->resetAfterTest();
        [$instance, $u1, , $book] = $this->setup_activity();
        $quick = $this->make_attempt($instance->id, $u1->id, $book->id, '10.0.0.1', time(), 30);
        cheatdetector::detect($instance->id);
        $this->setAdminUser();
        cheatdetector::set_cheated(reader::from_instanceid($instance->id), $quick, true);

        $DB->set_field('moodlereader', 'cheatsettings', json_encode(cheatdetector::clean_settings(
            ['quickfinish' => ['enabled' => 0]]
        )), ['id' => $instance->id]);
        cheatdetector::detect($instance->id);
        $this->assertTrue($DB->record_exists('moodlereader_cheatlog', ['attemptid' => $quick]));
    }

    /**
     * Many passes in a short time are flagged when the marker is on.
     *
     * @return void
     */
    public function test_burst(): void {
        global $DB;
        $this->resetAfterTest();
        [$instance, $u1] = $this->setup_activity(['burst' => ['enabled' => 1, 'count' => 3, 'window' => 3600]]);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $now = time() - 7200;
        foreach ([0, 600, 1200, 9000] as $offset) {
            $this->make_attempt($instance->id, $u1->id, $gen->create_book()->id, '10.0.0.' . ($offset % 200), $now + $offset);
        }
        // The first three passes fall within an hour; the fourth does not.
        $this->assertSame(3, cheatdetector::detect($instance->id));
        $this->assertSame(3, $DB->count_records('moodlereader_cheatlog', ['reason' => 'burst', 'detail' => 3]));
    }

    /**
     * Settings are cleaned: out-of-range numbers are clamped and bad allow-list entries dropped.
     *
     * @return void
     */
    public function test_clean_settings(): void {
        $clean = cheatdetector::clean_settings([
            'sameip' => ['enabled' => '1', 'window' => 5, 'match' => 9],
            'burst' => ['count' => 1000],
            'allowlist' => "203.0.113.5, bogus\n2001:db8::/32 # office\n10.0.0.0/99",
        ]);
        $this->assertSame(MINSECS, $clean['sameip']['window']);
        $this->assertSame(cheatdetector::MATCH_EXACT, $clean['sameip']['match']);
        $this->assertSame(100, $clean['burst']['count']);
        $this->assertSame("203.0.113.5\n2001:db8::/32", $clean['allowlist']);
        [, $invalid] = cheatdetector::clean_allowlist("bogus\n10.0.0.0/99");
        $this->assertSame(['bogus', '10.0.0.0/99'], $invalid);
    }
}
