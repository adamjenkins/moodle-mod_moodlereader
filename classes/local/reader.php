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

use context_module;
use moodle_url;
use stdClass;

/**
 * A moodlereader activity instance and the operations that depend on it.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reader {
    /** @var stdClass The {moodlereader} record. */
    protected $instance;

    /** @var \cm_info|stdClass The course module. */
    protected $cm;

    /** @var stdClass The course. */
    protected $course;

    /** @var context_module */
    protected $context;

    /**
     * Constructor.
     *
     * @param stdClass $instance The {moodlereader} record.
     * @param \cm_info|stdClass $cm The course module.
     * @param stdClass $course The course.
     */
    public function __construct(stdClass $instance, $cm, stdClass $course) {
        $this->instance = $instance;
        $this->cm = $cm;
        $this->course = $course;
        $this->context = context_module::instance($cm->id);
    }

    /**
     * Build an instance from a course-module id.
     *
     * @param int $cmid
     * @return self
     */
    public static function from_cmid(int $cmid): self {
        global $DB;
        $cm = get_coursemodule_from_id('moodlereader', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $instance = $DB->get_record('moodlereader', ['id' => $cm->instance], '*', MUST_EXIST);
        return new self($instance, $cm, $course);
    }

    /**
     * Build an instance from a {moodlereader} id.
     *
     * @param int $readerid
     * @return self
     */
    public static function from_instanceid(int $readerid): self {
        global $DB;
        $instance = $DB->get_record('moodlereader', ['id' => $readerid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('moodlereader', $readerid, $instance->course, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $instance->course], '*', MUST_EXIST);
        return new self($instance, $cm, $course);
    }

    /**
     * The {moodlereader} record.
     *
     * @return stdClass
     */
    public function get_instance(): stdClass {
        return $this->instance;
    }

    /**
     * The course module.
     *
     * @return \cm_info|stdClass
     */
    public function get_cm() {
        return $this->cm;
    }

    /**
     * The course.
     *
     * @return stdClass
     */
    public function get_course(): stdClass {
        return $this->course;
    }

    /**
     * The module context.
     *
     * @return context_module
     */
    public function get_context(): context_module {
        return $this->context;
    }

    /**
     * Whether the current user holds a mod/moodlereader capability in this context.
     *
     * @param string $capability Capability short name, e.g. 'viewbooks'.
     * @return bool
     */
    public function can(string $capability): bool {
        return has_capability("mod/moodlereader:$capability", $this->context);
    }

    /**
     * Require a mod/moodlereader capability in this context.
     *
     * @param string $capability Capability short name, e.g. 'manageusers'.
     * @return void
     */
    public function require_cap(string $capability): void {
        require_capability("mod/moodlereader:$capability", $this->context);
    }

    /**
     * URL of the activity view page.
     *
     * @return moodle_url
     */
    public function view_url(): moodle_url {
        return new moodle_url('/mod/moodlereader/view.php', ['id' => $this->cm->id]);
    }

    /**
     * The reading-level record for a user, defaulting a fresh one if absent.
     *
     * @param int $userid
     * @return stdClass
     */
    public function get_level(int $userid): stdClass {
        global $DB;
        if ($level = $DB->get_record('moodlereader_levels', ['readerid' => $this->instance->id, 'userid' => $userid])) {
            return $level;
        }
        return (object) [
            'readerid'       => $this->instance->id,
            'userid'         => $userid,
            'startlevel'     => 0,
            'currentlevel'   => 0,
            'stoplevel'      => 0,
            'allowpromotion' => 1,
            'time'           => 0,
        ];
    }

    /**
     * The book difficulties a user is currently allowed to attempt.
     *
     * @param int $userid
     * @return int[] Empty when level checking is off (i.e. all difficulties allowed).
     */
    public function allowed_difficulties(int $userid): array {
        if (empty($this->instance->levelcheck)) {
            return [];
        }
        $current = (int) $this->get_level($userid)->currentlevel;
        $levels = [$this->instance->thislevel ? $current : null];
        if ($this->instance->prevlevel && $current >= 1) {
            $levels[] = $current - 1;
        }
        if ($this->instance->nextlevel) {
            $levels[] = $current + 1;
        }
        $levels = array_values(array_unique(array_filter($levels, fn($l) => $l !== null)));
        return $levels ?: [$current];
    }

    /**
     * Books the given user may attempt right now.
     *
     * Excludes hidden books, books the user has already *finished* an attempt on
     * (by book id, and by shared title for title-groups), and books outside the
     * user's reading level when level checking is enabled. Books with only an
     * in-progress attempt stay listed so the user can resume them.
     *
     * @param int $userid
     * @return stdClass[] Keyed by book id.
     */
    public function available_books(int $userid): array {
        global $DB;

        $params = ['readerid' => $this->instance->id, 'userid' => $userid];

        // Finished attempts by this user (the ones that "use up" a book).
        $finished = "FROM {moodlereader_attempts} a
                    WHERE a.readerid = :readerid AND a.userid = :userid
                          AND a.deleted = 0 AND a.state = 'finished'";
        $doneids = "SELECT a.bookid $finished";
        $donetitles = "SELECT b2.sametitle
                         FROM {moodlereader_attempts} a
                         JOIN {moodlereader_books} b2 ON b2.id = a.bookid
                        WHERE a.readerid = :readerid2 AND a.userid = :userid2
                              AND a.deleted = 0 AND a.state = 'finished' AND b2.sametitle <> ''";
        $params['readerid2'] = $this->instance->id;
        $params['userid2'] = $userid;

        $from = '{moodlereader_books} b';
        $where = ['b.hidden = 0'];

        if (!empty($this->instance->bookinstances)) {
            $from .= ' JOIN {moodlereader_bookinstances} bi ON bi.bookid = b.id AND bi.readerid = :bireaderid';
            $params['bireaderid'] = $this->instance->id;
        }

        // Exclude books already finished — by book id, and by shared title.
        $where[] = "b.id NOT IN ($doneids)";
        $where[] = "(b.sametitle = '' OR b.sametitle NOT IN ($donetitles))";

        // Level filtering.
        $difficulties = $this->allowed_difficulties($userid);
        if ($difficulties) {
            [$insql, $inparams] = $DB->get_in_or_equal($difficulties, SQL_PARAMS_NAMED, 'diff');
            $where[] = "b.difficulty $insql";
            $params += $inparams;
        }

        $sql = "SELECT b.* FROM $from WHERE " . implode(' AND ', $where) . ' ORDER BY b.publisher, b.series, b.name';
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Whether the current time is inside the activity's availability window.
     *
     * @return bool
     */
    public function within_availability_window(): bool {
        $now = time();
        if (!empty($this->instance->availablefrom) && $now < $this->instance->availablefrom) {
            return false;
        }
        if (!empty($this->instance->availableuntil) && $now > $this->instance->availableuntil) {
            return false;
        }
        return true;
    }

    /**
     * Require that quizzes can be attempted now, unless the user manages the activity.
     *
     * @return void
     */
    public function require_available(): void {
        if (!$this->within_availability_window() && !$this->can('manageusers')) {
            throw new \moodle_exception('notavailablenow', 'mod_moodlereader', $this->view_url()->out());
        }
    }

    /**
     * Verify that a book is currently available to a user (server-side gate).
     *
     * @param int $bookid
     * @param int $userid
     * @return bool
     */
    public function book_is_available(int $bookid, int $userid): bool {
        return array_key_exists($bookid, $this->available_books($userid));
    }
}
