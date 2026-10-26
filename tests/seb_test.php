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
use mod_moodlereader\local\seb;

/**
 * Tests for Safe Exam Browser: which level decides, the configuration, and the key checks.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\seb::class)]
final class seb_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $course;

    /** @var \stdClass */
    protected $instance;

    /** A Browser Exam Key, as SEB's configuration tool shows one. */
    const BEK = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * A course with one reading activity, made by an administrator.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        if (!seb::available()) {
            $this->markTestSkipped('quizaccess_seb is not installed.');
        }
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course(['summary' => '']);
        $this->instance = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader')
            ->create_instance(['course' => $this->course->id, 'goal' => 5000]);
    }

    /**
     * Clear the headers set by a test.
     *
     * @return void
     */
    protected function tearDown(): void {
        unset($_SERVER[seb::CONFIG_KEY_HEADER], $_SERVER[seb::BROWSER_EXAM_KEY_HEADER]);
        parent::tearDown();
    }

    /**
     * Set the activity's own SEB settings.
     *
     * @param int $seb
     * @param int $method
     * @param string $keys
     * @return \stdClass The activity record.
     */
    protected function set_activity(int $seb, int $method = seb::METHOD_CONFIG, string $keys = ''): \stdClass {
        global $DB;
        $DB->update_record('moodlereader', (object) [
            'id' => $this->instance->id, 'seb' => $seb, 'sebmethod' => $method, 'sebkeys' => $keys, 'sebquitpassword' => '',
        ]);
        return $DB->get_record('moodlereader', ['id' => $this->instance->id]);
    }

    /**
     * The site, then the course, then the activity decide.
     *
     * @return void
     */
    public function test_levels(): void {
        global $DB;
        $this->assertNull(seb::rule($this->set_activity(seb::ACTIVITY_COURSE)));
        $this->assertSame('activity', seb::rule($this->set_activity(seb::ACTIVITY_YES))->level);

        // The course's requirement wins over the activity's "No".
        $DB->insert_record('moodlereader_sebcourse', (object) [
            'courseid' => $this->course->id, 'required' => 1, 'method' => seb::METHOD_CLIENT, 'sebkeys' => self::BEK,
        ]);
        $rule = seb::rule($this->set_activity(seb::ACTIVITY_NO));
        $this->assertSame('course', $rule->level);
        $this->assertSame(seb::METHOD_CLIENT, $rule->method);
        $this->assertSame([self::BEK], $rule->keys);

        // The site's "Required everywhere" brings its own keys; "Never" turns it all off.
        set_config('sebmode', seb::SITE_REQUIRED, 'mod_moodlereader');
        $this->assertSame('site', seb::rule($this->set_activity(seb::ACTIVITY_NO))->level);
        set_config('sebmode', seb::SITE_NEVER, 'mod_moodlereader');
        $this->assertNull(seb::rule($this->set_activity(seb::ACTIVITY_YES)));
        $this->assertFalse(seb::teachers_decide());
    }

    /**
     * The configuration starts on the activity, and its Config Key changes with the quit password.
     *
     * @return void
     */
    public function test_config(): void {
        $cm = get_coursemodule_from_instance('moodlereader', $this->instance->id);
        $rule = seb::rule($this->set_activity(seb::ACTIVITY_YES));
        $xml = seb::config_xml((int) $cm->id, $rule);
        $this->assertStringContainsString(seb::start_url((int) $cm->id)->out(false), $xml);
        $this->assertStringNotContainsString('hashedQuitPassword', $xml);
        $key = seb::config_key((int) $cm->id, $rule);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $key);
        $this->assertSame($key, seb::config_key((int) $cm->id, $rule));

        $rule->quitpassword = 'letmeout';
        $this->assertStringContainsString(hash('sha256', 'letmeout'), seb::config_xml((int) $cm->id, $rule));
        $this->assertNotSame($key, seb::config_key((int) $cm->id, $rule));
    }

    /**
     * Each hash is for the page's own URL; Browser Exam Keys are checked when listed.
     *
     * @return void
     */
    public function test_keys(): void {
        $cm = get_coursemodule_from_instance('moodlereader', $this->instance->id);
        $cmid = (int) $cm->id;
        $url = 'https://example.com/mod/moodlereader/quiz/render.php?attempt=4';

        $rule = seb::rule($this->set_activity(seb::ACTIVITY_YES));
        $confighash = hash('sha256', $url . seb::config_key($cmid, $rule));
        $this->assertTrue(seb::check_keys($cmid, $rule, $url, $confighash, null, false));
        $this->assertFalse(seb::check_keys($cmid, $rule, $url . '0', $confighash, null, false));
        $this->assertFalse(seb::check_keys($cmid, $rule, $url, null, null, true));

        // With keys listed, the Browser Exam Key must match too.
        $rule = seb::rule($this->set_activity(seb::ACTIVITY_YES, seb::METHOD_CONFIG, self::BEK));
        $bekhash = hash('sha256', $url . self::BEK);
        $this->assertFalse(seb::check_keys($cmid, $rule, $url, $confighash, null, false));
        $this->assertTrue(seb::check_keys($cmid, $rule, $url, $confighash, $bekhash, false));

        // SEB's own configuration: no Config Key, only the Browser Exam Key.
        $rule = seb::rule($this->set_activity(seb::ACTIVITY_YES, seb::METHOD_CLIENT, self::BEK));
        $this->assertTrue(seb::check_keys($cmid, $rule, $url, null, $bekhash, true));
        $this->assertFalse(seb::check_keys($cmid, $rule, $url, null, hash('sha256', $url . 'other'), true));

        $this->assertTrue(seb::valid_keys(strtoupper(self::BEK) . ", \n" . self::BEK));
        $this->assertFalse(seb::valid_keys('not-a-key'));
    }

    /**
     * A request with the right header passes, and the session then stays open for that activity.
     *
     * @return void
     */
    public function test_allows_by_header_then_session(): void {
        global $FULLME;
        $this->set_activity(seb::ACTIVITY_YES);
        $reader = reader::from_instanceid((int) $this->instance->id);
        $cmid = (int) $reader->get_cm()->id;
        $rule = seb::rule($reader->get_instance());
        $FULLME = 'https://example.com/mod/moodlereader/startquiz.php?id=' . $cmid;

        $this->assertFalse(seb::allows($reader));
        $_SERVER[seb::CONFIG_KEY_HEADER] = hash('sha256', 'https://example.com/elsewhere' . seb::config_key($cmid, $rule));
        $this->assertFalse(seb::allows($reader));
        $_SERVER[seb::CONFIG_KEY_HEADER] = hash('sha256', $FULLME . seb::config_key($cmid, $rule));
        $this->assertTrue(seb::allows($reader));

        unset($_SERVER[seb::CONFIG_KEY_HEADER]);
        $this->assertTrue(seb::allows($reader));
        seb::set_session_access($cmid, false);
        $this->assertFalse(seb::allows($reader));
    }

    /**
     * Only someone who may set SEB changes it through the activity settings.
     *
     * @return void
     */
    public function test_settings_need_the_capability(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/moodlereader/lib.php');
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->setUser($teacher);
        $cm = get_coursemodule_from_instance('moodlereader', $this->instance->id);
        $data = (object) ['seb' => 1, 'sebmethod' => 1, 'sebkeys' => self::BEK];
        moodlereader_clean_seb($data, \context_module::instance($cm->id));
        $this->assertSame([], (array) $data);

        $this->setAdminUser();
        $data = (object) ['seb' => 7, 'sebmethod' => 5, 'sebkeys' => strtoupper(self::BEK) . ',' . self::BEK];
        moodlereader_clean_seb($data, \context_module::instance($cm->id));
        $this->assertSame(['seb' => 0, 'sebmethod' => 0, 'sebkeys' => self::BEK], (array) $data);
        $this->assertSame('0', (string) $DB->get_field('moodlereader', 'seb', ['id' => $this->instance->id]));
    }
}
