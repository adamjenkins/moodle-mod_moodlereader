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
     * @param int $templateid
     * @param string|null $configxml
     * @return \stdClass The activity record.
     */
    protected function set_activity(
        int $seb,
        int $method = seb::METHOD_CONFIG,
        string $keys = '',
        int $templateid = 0,
        ?string $configxml = null
    ): \stdClass {
        global $DB;
        $DB->update_record('moodlereader', (object) [
            'id' => $this->instance->id, 'seb' => $seb, 'sebmethod' => $method, 'sebkeys' => $keys, 'sebquitpassword' => '',
            'sebtemplateid' => $templateid, 'sebconfigxml' => $configxml,
        ]);
        return $DB->get_record('moodlereader', ['id' => $this->instance->id]);
    }

    /**
     * A SEB template as an administrator makes one (quizaccess_seb).
     *
     * @param bool $enabled
     * @return int Its id.
     */
    protected function make_template(bool $enabled = true): int {
        $xml = (new \quizaccess_seb\property_list())->to_xml();
        $plist = new \quizaccess_seb\property_list($xml);
        $plist->set_or_update_value('allowSpellCheck', new \CFPropertyList\CFBoolean(true));
        $template = new \quizaccess_seb\template(0, (object) [
            'name' => 'Exam room', 'description' => '', 'content' => $plist->to_xml(), 'enabled' => (int) $enabled,
        ]);
        $template->create();
        return (int) $template->get('id');
    }

    /**
     * The site decides first, then the activity; "No" is the activity's default.
     *
     * @return void
     */
    public function test_levels(): void {
        $this->assertNull(seb::rule($this->set_activity(seb::ACTIVITY_NO)));
        $rule = seb::rule($this->set_activity(seb::ACTIVITY_YES, seb::METHOD_CLIENT, self::BEK));
        $this->assertSame('activity', $rule->level);
        $this->assertSame(seb::METHOD_CLIENT, $rule->method);
        $this->assertSame([self::BEK], $rule->keys);

        // The site's "Required everywhere" brings its own settings; "Never" turns it all off.
        set_config('sebmode', seb::SITE_REQUIRED, 'mod_moodlereader');
        $this->assertSame('site', seb::rule($this->set_activity(seb::ACTIVITY_NO))->level);
        set_config('sebmode', seb::SITE_NEVER, 'mod_moodlereader');
        $this->assertNull(seb::rule($this->set_activity(seb::ACTIVITY_YES)));
        $this->assertFalse(seb::teachers_decide());
    }

    /**
     * A site template, or a quiz's configuration copied into the activity, is the base of the configuration;
     * the activity's start page, keys and quit password are set on it. A template that is gone falls back to
     * the configuration Moodle makes, so SEB stays required.
     *
     * @return void
     */
    public function test_template_and_copied_quiz_configuration(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/moodlereader/lib.php');
        $cm = get_coursemodule_from_instance('moodlereader', $this->instance->id);
        $templateid = $this->make_template();
        $this->assertSame([$templateid => 'Exam room'], seb::templates());
        $this->make_template(false);
        $this->assertCount(1, seb::templates());

        $rule = seb::rule($this->set_activity(seb::ACTIVITY_YES, seb::METHOD_TEMPLATE, '', $templateid));
        $this->assertSame(seb::METHOD_TEMPLATE, $rule->method);
        $this->assertTrue(seb::has_config($rule));
        $xml = seb::config_xml((int) $cm->id, $rule);
        $this->assertStringContainsString('allowSpellCheck', $xml);
        $this->assertStringContainsString(seb::start_url((int) $cm->id)->out(false), $xml);
        $this->assertNotSame(
            seb::config_key((int) $cm->id, seb::rule($this->set_activity(seb::ACTIVITY_YES))),
            seb::config_key((int) $cm->id, $rule)
        );

        // A quiz in the course with its own SEB configuration (from the template) can be copied.
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'name' => 'Unit test']);
        (new \quizaccess_seb\seb_quiz_settings(0, (object) [
            'quizid' => $quiz->id, 'cmid' => $quiz->cmid, 'templateid' => $templateid, 'requiresafeexambrowser' => 2,
            'showsebtaskbar' => 1, 'showwificontrol' => 0, 'showreloadbutton' => 1, 'showtime' => 0,
            'showkeyboardlayout' => 1, 'allowuserquitseb' => 1, 'quitpassword' => '', 'linkquitseb' => '',
            'userconfirmquit' => 1, 'enableaudiocontrol' => 0, 'muteonstartup' => 0, 'allowspellchecking' => 0,
            'allowreloadinexam' => 1, 'activateurlfiltering' => 0, 'filterembeddedcontent' => 0,
            'expressionsallowed' => '', 'regexallowed' => '', 'expressionsblocked' => '', 'regexblocked' => '',
            'allowedbrowserexamkeys' => '', 'showsebdownloadlink' => 1,
        ]))->create();
        $this->assertSame([(int) $quiz->cmid => 'Unit test'], seb::quizzes((int) $this->course->id));
        $this->assertNull(seb::quiz_config((int) $quiz->cmid, (int) $this->getDataGenerator()->create_course()->id));

        $data = (object) ['course' => $this->course->id, 'seb' => 1, 'sebsource' => 'quiz:' . $quiz->cmid, 'sebkeys' => ''];
        moodlereader_clean_seb($data, \context_module::instance($cm->id));
        $this->assertSame(seb::METHOD_QUIZ, $data->sebmethod);
        $this->assertStringContainsString('allowSpellCheck', $data->sebconfigxml);
        $rule = seb::rule($this->set_activity(seb::ACTIVITY_YES, seb::METHOD_QUIZ, '', 0, $data->sebconfigxml));
        $this->assertStringContainsString(seb::start_url((int) $cm->id)->out(false), seb::config_xml((int) $cm->id, $rule));
        $this->assertStringNotContainsString('/mod/quiz/view.php', seb::config_xml((int) $cm->id, $rule));

        // A template that is not offered is refused when saving, and one that has gone falls back.
        $data = (object) ['course' => $this->course->id, 'seb' => 1, 'sebsource' => 'template:99999', 'sebkeys' => ''];
        moodlereader_clean_seb($data, \context_module::instance($cm->id));
        $this->assertSame(seb::METHOD_CONFIG, $data->sebmethod);
        $gone = $this->set_activity(seb::ACTIVITY_YES, seb::METHOD_TEMPLATE, '', 99999);
        $this->assertSame(seb::METHOD_CONFIG, seb::rule($gone)->method);
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

        // Without any Browser Exam Key, SEB's own configuration lets nothing through, whatever the user agent says.
        $rule = seb::rule($this->set_activity(seb::ACTIVITY_YES, seb::METHOD_CLIENT));
        $this->assertFalse(seb::check_keys($cmid, $rule, $url, null, null, true));

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
        $data = (object) ['seb' => 1, 'sebsource' => 'client', 'sebmethod' => 1, 'sebkeys' => self::BEK];
        moodlereader_clean_seb($data, \context_module::instance($cm->id));
        $this->assertSame([], (array) $data);

        // An unknown source becomes the configuration Moodle makes; a method sent on its own is ignored.
        $this->setAdminUser();
        $data = (object) ['course' => $this->course->id, 'seb' => 7, 'sebsource' => 'other', 'sebmethod' => 5,
            'sebkeys' => strtoupper(self::BEK) . ',' . self::BEK];
        moodlereader_clean_seb($data, \context_module::instance($cm->id));
        $this->assertSame(['course' => $this->course->id, 'seb' => 0, 'sebmethod' => seb::METHOD_CONFIG,
            'sebkeys' => self::BEK, 'sebtemplateid' => 0, 'sebconfigxml' => null], (array) $data);
        $this->assertSame('0', (string) $DB->get_field('moodlereader', 'seb', ['id' => $this->instance->id]));
    }
}
