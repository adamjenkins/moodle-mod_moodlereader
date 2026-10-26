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

use CFPropertyList\CFBoolean;
use CFPropertyList\CFNumber;
use CFPropertyList\CFString;
use moodle_url;

/**
 * Safe Exam Browser for the book quizzes: which rule is in force, its configuration, and the key checks.
 *
 * Three levels, the higher one winning: the site (an administrator may require SEB everywhere or turn it
 * off), the course, and the activity. The level that requires SEB also says how it is checked:
 *
 * - METHOD_CONFIG: Moodle makes the SEB configuration; SEB's Config Key header (or JS API value) must match
 *   it, and Browser Exam Keys too when any are listed;
 * - METHOD_CLIENT: the student's SEB uses its own configuration; the browser must be SEB, and its Browser
 *   Exam Key must be one of those listed when any are.
 *
 * The key checks and the configuration follow core's quizaccess_seb (seb_access_manager, seb_quiz_settings),
 * whose general classes (property_list, config_key) are used directly.
 *
 * @package    mod_moodlereader
 * @copyright  2019 Catalyst IT
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seb {
    /** Site: teachers decide per course and activity. */
    const SITE_TEACHERS = 0;
    /** Site: required for every quiz. */
    const SITE_REQUIRED = 1;
    /** Site: never; the course and activity settings are hidden. */
    const SITE_NEVER = 2;

    /** Activity: as the course says. */
    const ACTIVITY_COURSE = 0;
    /** Activity: required. */
    const ACTIVITY_YES = 1;
    /** Activity: not required. */
    const ACTIVITY_NO = 2;

    /** Moodle makes the configuration; its Config Key is checked. */
    const METHOD_CONFIG = 0;
    /** The student's SEB uses its own configuration; only Browser Exam Keys are checked. */
    const METHOD_CLIENT = 1;

    /** Header sent by SEB with the Config Key hash. */
    const CONFIG_KEY_HEADER = 'HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH';
    /** Header sent by SEB with the Browser Exam Key hash. */
    const BROWSER_EXAM_KEY_HEADER = 'HTTP_X_SAFEEXAMBROWSER_REQUESTHASH';

    /**
     * Whether this Moodle can check SEB at all (core's quizaccess_seb provides the configuration classes).
     *
     * @return bool
     */
    public static function available(): bool {
        return class_exists('\quizaccess_seb\property_list') && class_exists('\quizaccess_seb\config_key');
    }

    /**
     * The site setting.
     *
     * @return int SITE_*
     */
    public static function site_mode(): int {
        $mode = (int) get_config('mod_moodlereader', 'sebmode');
        return in_array($mode, [self::SITE_TEACHERS, self::SITE_REQUIRED, self::SITE_NEVER], true) ? $mode : self::SITE_TEACHERS;
    }

    /**
     * Whether teachers may set SEB for courses and activities.
     *
     * @return bool
     */
    public static function teachers_decide(): bool {
        return self::available() && self::site_mode() === self::SITE_TEACHERS;
    }

    /**
     * The course's SEB settings, or null when it has none.
     *
     * @param int $courseid
     * @return \stdClass|null
     */
    public static function course_settings(int $courseid): ?\stdClass {
        global $DB;
        return $DB->get_record('moodlereader_sebcourse', ['courseid' => $courseid]) ?: null;
    }

    /**
     * The rule in force for an activity, or null when SEB is not required.
     *
     * @param \stdClass $instance The moodlereader record.
     * @return \stdClass|null level ('site', 'course' or 'activity'), method, keys (list of lower-case hex), quitpassword
     */
    public static function rule(\stdClass $instance): ?\stdClass {
        if (!self::available()) {
            return null;
        }
        $mode = self::site_mode();
        if ($mode === self::SITE_NEVER) {
            return null;
        }
        if ($mode === self::SITE_REQUIRED) {
            return self::make_rule(
                'site',
                (int) get_config('mod_moodlereader', 'sebmethod'),
                (string) get_config('mod_moodlereader', 'sebkeys'),
                (string) get_config('mod_moodlereader', 'sebquitpassword')
            );
        }
        $course = self::course_settings((int) $instance->course);
        if ($course && !empty($course->required)) {
            return self::make_rule('course', (int) $course->method, (string) $course->sebkeys, (string) $course->quitpassword);
        }
        if ((int) ($instance->seb ?? 0) === self::ACTIVITY_YES) {
            return self::make_rule(
                'activity',
                (int) $instance->sebmethod,
                (string) $instance->sebkeys,
                (string) $instance->sebquitpassword
            );
        }
        return null;
    }

    /**
     * Build a rule.
     *
     * @param string $level
     * @param int $method
     * @param string $keys Browser Exam Keys as typed: separated by spaces, commas, semicolons or new lines.
     * @param string $quitpassword
     * @return \stdClass
     */
    protected static function make_rule(string $level, int $method, string $keys, string $quitpassword): \stdClass {
        return (object) [
            'level' => $level,
            'method' => $method === self::METHOD_CLIENT ? self::METHOD_CLIENT : self::METHOD_CONFIG,
            'keys' => self::split_keys($keys),
            'quitpassword' => $quitpassword,
        ];
    }

    /**
     * Split a list of Browser Exam Keys (as core's seb_quiz_settings::split_keys()).
     *
     * @param string $keys
     * @return string[]
     */
    public static function split_keys(string $keys): array {
        $list = preg_split('~[ \t\n\r,;]+~', $keys, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_map('strtolower', $list)));
    }

    /**
     * Whether a list of Browser Exam Keys is well formed (each a SHA-256 hash in hex).
     *
     * @param string $keys
     * @return bool
     */
    public static function valid_keys(string $keys): bool {
        foreach (self::split_keys($keys) as $key) {
            if (!preg_match('/^[a-f0-9]{64}$/', $key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The page SEB starts on: the activity, where the student chooses a book.
     *
     * @param int $cmid
     * @return moodle_url
     */
    public static function start_url(int $cmid): moodle_url {
        return new moodle_url('/mod/moodlereader/view.php', ['id' => $cmid]);
    }

    /**
     * The link that closes SEB (its quitURL); SEB stops at it, so it never reaches Moodle from SEB.
     *
     * @param int $cmid
     * @return moodle_url
     */
    public static function quit_url(int $cmid): moodle_url {
        return new moodle_url('/mod/moodlereader/view.php', ['id' => $cmid, 'sebquit' => 1]);
    }

    /**
     * The configuration file Moodle makes for an activity (METHOD_CONFIG), as plist XML.
     *
     * Only the settings a reading quiz needs: start on the activity, send the keys, allow quitting (by the
     * quit link, or with the password when one is set), keep Moodle's login, and no preferences window.
     *
     * @param int $cmid
     * @param \stdClass $rule
     * @return string
     */
    public static function config_xml(int $cmid, \stdClass $rule): string {
        $plist = new \quizaccess_seb\property_list();
        $plist->set_or_update_value('startURL', new CFString(self::start_url($cmid)->out(false)));
        $plist->set_or_update_value('sendBrowserExamKey', new CFBoolean(true));
        // The modern WebView and the JS API, as core sets them.
        $plist->set_or_update_value('browserWindowWebView', new CFNumber(3));
        $plist->set_or_update_value('allowQuit', new CFBoolean(true));
        $plist->set_or_update_value('quitURL', new CFString(self::quit_url($cmid)->out(false)));
        $plist->set_or_update_value('quitURLConfirm', new CFBoolean(true));
        $plist->set_or_update_value('showTaskBar', new CFBoolean(true));
        $plist->set_or_update_value('showReloadButton', new CFBoolean(true));
        $plist->set_or_update_value('browserWindowAllowReload', new CFBoolean(true));
        $plist->set_or_update_value('URLFilterEnable', new CFBoolean(false));
        $plist->set_or_update_value('examSessionClearCookiesOnStart', new CFBoolean(false));
        $plist->set_or_update_value('allowPreferencesWindow', new CFBoolean(false));
        if ($rule->quitpassword !== '') {
            $plist->set_or_update_value('hashedQuitPassword', new CFString(hash('sha256', $rule->quitpassword)));
        }
        return $plist->to_xml();
    }

    /**
     * The Config Key of the configuration Moodle makes for an activity.
     *
     * @param int $cmid
     * @param \stdClass $rule
     * @return string
     */
    public static function config_key(int $cmid, \stdClass $rule): string {
        return \quizaccess_seb\config_key::generate(self::config_xml($cmid, $rule))->get_hash();
    }

    /**
     * Check SEB's key hashes for a URL against the rule.
     *
     * Each hash is SHA-256 of the URL followed by the key, as SEB sends it in its headers and in its
     * JS API (https://safeexambrowser.org/developer/seb-config-key.html).
     *
     * @param int $cmid
     * @param \stdClass $rule
     * @param string $url The page's full URL.
     * @param string|null $confighash
     * @param string|null $bekhash
     * @param bool $isseb Whether the browser says it is SEB (only used for METHOD_CLIENT without keys).
     * @return bool
     */
    public static function check_keys(
        int $cmid,
        \stdClass $rule,
        string $url,
        ?string $confighash,
        ?string $bekhash,
        bool $isseb
    ): bool {
        if ($rule->method === self::METHOD_CONFIG) {
            if ($confighash === null || !hash_equals(hash('sha256', $url . self::config_key($cmid, $rule)), $confighash)) {
                return false;
            }
        } else if (!$rule->keys && !$isseb) {
            return false;
        }
        if ($rule->keys) {
            if ($bekhash === null) {
                return false;
            }
            foreach ($rule->keys as $key) {
                if (hash_equals(hash('sha256', $url . $key), $bekhash)) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }

    /**
     * Whether the current request may reach the activity's quizzes.
     *
     * The headers of this request are checked first; failing that, a check already passed in this session
     * through SEB's JS API (sebcheck.php), as core does for SEB versions that do not send headers.
     *
     * @param reader $reader
     * @return bool
     */
    public static function allows(reader $reader): bool {
        global $SESSION;
        $rule = self::rule($reader->get_instance());
        if ($rule === null) {
            return true;
        }
        $cmid = (int) $reader->get_cm()->id;
        $confighash = self::header(self::CONFIG_KEY_HEADER);
        $bekhash = self::header(self::BROWSER_EXAM_KEY_HEADER);
        if (
            ($confighash !== null || $bekhash !== null || $rule->method === self::METHOD_CLIENT)
                && self::check_keys($cmid, $rule, self::this_url(), $confighash, $bekhash, self::is_seb())
        ) {
            self::set_session_access($cmid, true);
            return true;
        }
        return !empty($SESSION->mod_moodlereader_seb[$cmid]);
    }

    /**
     * Remember for this session that SEB's keys were checked for an activity.
     *
     * @param int $cmid
     * @param bool $allowed
     * @return void
     */
    public static function set_session_access(int $cmid, bool $allowed): void {
        global $SESSION;
        if (!isset($SESSION->mod_moodlereader_seb)) {
            $SESSION->mod_moodlereader_seb = [];
        }
        if ($allowed) {
            $SESSION->mod_moodlereader_seb[$cmid] = true;
        } else {
            unset($SESSION->mod_moodlereader_seb[$cmid]);
        }
    }

    /**
     * Stop here with the "needs Safe Exam Browser" page unless the request may reach the quizzes.
     *
     * @param reader $reader
     * @return void
     */
    public static function require_access(reader $reader): void {
        global $OUTPUT, $PAGE;
        if (self::allows($reader)) {
            return;
        }
        $rule = self::rule($reader->get_instance());
        $cmid = (int) $reader->get_cm()->id;
        // A page about this request only: never cached.
        $PAGE->set_url(self::this_url());
        $PAGE->set_context($reader->get_context());
        $PAGE->set_title(get_string('sebrequired', 'mod_moodlereader'));
        $PAGE->set_heading(format_string($reader->get_course()->fullname));
        $PAGE->set_activity_record($reader->get_instance());
        $PAGE->set_cacheable(false);
        $PAGE->requires->js_call_amd('mod_moodlereader/sebcheck', 'init', [[
            'cmid' => $cmid,
            'checkurl' => (new moodle_url('/mod/moodlereader/sebcheck.php'))->out(false),
            'sesskey' => sesskey(),
        ]]);
        echo $OUTPUT->header();
        echo $OUTPUT->render_from_template('mod_moodlereader/seb_required', self::export_required($reader, $rule));
        echo $OUTPUT->footer();
        exit;
    }

    /**
     * The template context of the "needs Safe Exam Browser" page and notice.
     *
     * @param reader $reader
     * @param \stdClass $rule
     * @return array
     */
    public static function export_required(reader $reader, \stdClass $rule): array {
        $cmid = (int) $reader->get_cm()->id;
        $download = clean_param((string) get_config('mod_moodlereader', 'sebdownloadlink'), PARAM_URL);
        return [
            'insideseb' => self::is_seb(),
            'isconfig' => $rule->method === self::METHOD_CONFIG,
            'launchurl' => $rule->method === self::METHOD_CONFIG ? self::config_link($cmid, true) : '',
            'configurl' => $rule->method === self::METHOD_CONFIG ? self::config_link($cmid, false) : '',
            'downloadurl' => $download,
            'backurl' => $reader->view_url()->out(false),
        ];
    }

    /**
     * The link to the activity's configuration: seb(s):// opens SEB with it, http(s):// downloads it.
     *
     * @param int $cmid
     * @param bool $seb
     * @return string
     */
    public static function config_link(int $cmid, bool $seb): string {
        $url = new moodle_url('/mod/moodlereader/sebconfig.php', ['id' => $cmid]);
        if ($seb) {
            $url->set_scheme(is_https() ? 'sebs' : 'seb');
        }
        return $url->out(false);
    }

    /**
     * Whether the browser says it is SEB.
     *
     * @return bool
     */
    public static function is_seb(): bool {
        return strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'SEB') !== false;
    }

    /**
     * A SEB header of this request, lower-cased, or null.
     *
     * @param string $name
     * @return string|null
     */
    protected static function header(string $name): ?string {
        $value = trim((string) ($_SERVER[$name] ?? ''));
        return preg_match('/^[a-fA-F0-9]{64}$/', $value) ? strtolower($value) : null;
    }

    /**
     * The full URL of this request, which SEB hashes into its headers.
     *
     * @return string
     */
    protected static function this_url(): string {
        global $CFG, $FULLME;
        return $FULLME ?: $CFG->wwwroot;
    }
}
