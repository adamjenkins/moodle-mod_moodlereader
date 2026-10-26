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
     * Require someone who may take book quizzes here: a student (mod/moodlereader:attempt), or a
     * teacher trying a quiz (viewreports). Seeing the books alone is not enough, so a student whose
     * attempt capability is prohibited cannot start, resume or answer a quiz.
     *
     * @return void
     */
    public function require_quiz_taker(): void {
        $this->require_cap('viewbooks');
        if (!$this->can('attempt') && !$this->can('viewreports')) {
            require_capability('mod/moodlereader:attempt', $this->context);
        }
    }

    /**
     * Before an attempt is launched or its first question shown: the book must still be one the student
     * may take. Otherwise (finished meanwhile in another course or as another edition, disabled, withdrawn,
     * beyond the level quota now) the attempt is deleted and the student is sent back with a message.
     *
     * @param stdClass $attempt An in-progress attempt of the current user.
     * @return void
     */
    public function require_still_available(stdClass $attempt): void {
        global $DB;
        if ($this->book_is_available((int) $attempt->bookid, (int) $attempt->userid)) {
            return;
        }
        $DB->set_field('moodlereader_attempts', 'deleted', 1, ['id' => $attempt->id, 'state' => 'inprogress']);
        redirect(
            $this->view_url(),
            get_string('quiznolongeravailable', 'mod_moodlereader'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
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
     * A user's effective reading goal: their per-user override, or the activity default.
     *
     * @param int $userid
     * @return int Number of words (or points) for full marks.
     */
    public function get_goal(int $userid): int {
        global $DB;
        $override = (int) $DB->get_field(
            'moodlereader_levels',
            'goal',
            ['readerid' => $this->instance->id, 'userid' => $userid]
        );
        return $override > 0 ? $override : (int) $this->instance->goal;
    }

    /**
     * The book difficulties a user is currently allowed to attempt.
     *
     * @param int $userid
     * @return int[] Empty when level checking is off (i.e. all difficulties allowed).
     */
    public function allowed_difficulties(int $userid): array {
        return levels::allowed($this, $userid);
    }

    /**
     * The FROM/WHERE that select the books a user may attempt right now.
     *
     * Excludes hidden books, books the user has already *finished* an attempt on in any
     * reading activity on the site (by book id, and by shared title for title-groups), and books outside the
     * user's reading level when level checking is enabled. Books with only an
     * in-progress attempt stay listed so the user can resume them. Shared by
     * {@see available_books()}, {@see search_books()} and {@see available_publishers()}.
     *
     * @param int $userid
     * @return array [string $from, string[] $where, array $params]
     */
    protected function available_where(int $userid): array {
        global $DB;

        $params = [
            'userid'    => $userid,
            'userid2'   => $userid,
        ];

        // Finished attempts by this user in any reading activity on the site (the ones that "use up" a book):
        // a quiz taken in one course is not taken again in another.
        $doneids = "SELECT a.bookid
                      FROM {moodlereader_attempts} a
                     WHERE a.userid = :userid
                           AND a.deleted = 0 AND a.state = 'finished'";
        $donetitles = "SELECT b2.sametitle
                         FROM {moodlereader_attempts} a
                         JOIN {moodlereader_books} b2 ON b2.id = a.bookid
                        WHERE a.userid = :userid2
                              AND a.deleted = 0 AND a.state = 'finished' AND b2.sametitle <> ''";

        $from = '{moodlereader_books} b';
        // Never a book disabled in Manage books, withdrawn by mreader.org, or without a quiz.
        $where = ['b.hidden = 0', 'b.withdrawn = 0', 'b.hasquiz = 1'];

        if (!empty($this->instance->bookinstances)) {
            $from .= ' JOIN {moodlereader_bookinstances} bi ON bi.bookid = b.id AND bi.readerid = :bireaderid';
            $params['bireaderid'] = $this->instance->id;
        }

        // Exclude books already finished — by book id, and by shared title.
        $where[] = "b.id NOT IN ($doneids)";
        $where[] = "(b.sametitle = '' OR b.sametitle NOT IN ($donetitles))";

        // Level filtering: only the levels the student may still read at (none left means no books).
        if (!empty($this->instance->levelcheck)) {
            $difficulties = $this->allowed_difficulties($userid);
            if (!$difficulties) {
                $where[] = '1 = 0';
            } else {
                $diffcol = empty($this->instance->bookinstances) ? 'b.difficulty' : 'COALESCE(bi.difficulty, b.difficulty)';
                [$insql, $inparams] = $DB->get_in_or_equal($difficulties, SQL_PARAMS_NAMED, 'diff');
                $where[] = "$diffcol $insql";
                $params += $inparams;
            }
        }

        return [$from, $where, $params];
    }

    /**
     * Books the given user may attempt right now.
     *
     * @param int $userid
     * @return stdClass[] Keyed by book id.
     */
    public function available_books(int $userid): array {
        global $DB;
        [$from, $where, $params] = $this->available_where($userid);
        $sql = "SELECT b.* FROM $from WHERE " . implode(' AND ', $where) . ' ORDER BY b.publisher, b.series, b.name';
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Search the books a user may attempt, applying optional filters.
     *
     * Filters (all optional): 'name', 'publisher', 'series' (partial, case-insensitive),
     * 'genre' (a genre code, matched within the book's comma-separated genre list),
     * 'difficulty' (exact reading level; a value below 0 means "any") and 'language'
     * (a language code; a book whose language is unknown always matches). Every filter
     * is layered on top of the same availability gate as {@see available_books()}.
     *
     * @param int $userid
     * @param array $filters
     * @param int $limit Maximum rows to return (0 = no limit).
     * @param int|null $total Set by reference to the full match count (ignoring $limit).
     * @return stdClass[] Keyed by book id.
     */
    public function search_books(int $userid, array $filters, int $limit = 0, ?int &$total = null): array {
        global $DB;
        [$from, $where, $params] = $this->available_where($userid);

        $this->filter_where($filters, $where, $params);

        $wheresql = implode(' AND ', $where);
        $total = $DB->count_records_sql("SELECT COUNT(1) FROM $from WHERE $wheresql", $params);
        $sql = "SELECT b.* FROM $from WHERE $wheresql ORDER BY b.publisher, b.series, b.name";
        return $DB->get_records_sql($sql, $params, 0, $limit);
    }

    /**
     * Add a book search's filters to a query.
     *
     * @param array $filters See {@see self::search_books()}.
     * @param string[] $where Conditions, added to.
     * @param array $params Parameters, added to.
     * @return void
     */
    protected function filter_where(array $filters, array &$where, array &$params): void {
        global $DB;
        $i = 0;
        foreach (['name', 'publisher', 'series', 'genre'] as $field) {
            $value = trim((string) ($filters[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            $where[] = $DB->sql_like("b.$field", ":s$i", false, false);
            $params["s$i"] = '%' . $DB->sql_like_escape($value) . '%';
            $i++;
        }
        $difficulty = $filters['difficulty'] ?? -1;
        if (is_numeric($difficulty) && (int) $difficulty >= 0) {
            $where[] = 'b.difficulty = :sdiff';
            $params['sdiff'] = (int) $difficulty;
        }
        $language = booklanguage::clean((string) ($filters['language'] ?? ''));
        if ($language !== '') {
            $where[] = "(b.language = :slang OR b.language = '')";
            $params['slang'] = $language;
        }
    }

    /**
     * The book search's filters as submitted in this request.
     *
     * @return array [array $filters, bool $searched, bool $hasfilter]
     */
    public static function request_search_filters(): array {
        $searched = (bool) optional_param('searchsubmit', 0, PARAM_INT);
        $difficulty = optional_param('searchdifficulty', -1, PARAM_INT);
        $filters = [
            'name'       => optional_param('searchname', '', PARAM_TEXT),
            'publisher'  => optional_param('searchpublisher', '', PARAM_TEXT),
            'series'     => optional_param('searchseries', '', PARAM_TEXT),
            'genre'      => optional_param('searchgenre', '', PARAM_ALPHA),
            'difficulty' => $difficulty,
            // English is selected until another language (or any) is chosen.
            'language'   => $searched
                ? booklanguage::clean(optional_param('searchlanguage', '', PARAM_ALPHA))
                : booklanguage::DEFAULT,
        ];
        $hasfilter = trim($filters['name']) !== '' || $filters['publisher'] !== '' || trim($filters['series']) !== ''
            || $filters['genre'] !== '' || $difficulty >= 0;
        return [$filters, $searched, $hasfilter];
    }

    /**
     * The search menus over this activity's whole catalogue (see {@see self::search_catalogue()}).
     *
     * @return array publishers (name => book count), difficulties (int[]), languages (string[]).
     */
    public function catalogue_menus(): array {
        global $DB;
        $from = '{moodlereader_books} b';
        $params = [];
        $difficulty = 'b.difficulty';
        if (!empty($this->instance->bookinstances)) {
            $from .= ' JOIN {moodlereader_bookinstances} bi ON bi.bookid = b.id AND bi.readerid = :bireaderid';
            $params['bireaderid'] = $this->instance->id;
            $difficulty = 'COALESCE(bi.difficulty, b.difficulty)';
        }
        $publishers = [];
        $sql = "SELECT b.publisher, COUNT(1) AS cnt FROM $from WHERE b.hidden = 0 GROUP BY b.publisher ORDER BY b.publisher";
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $publishers[$row->publisher] = (int) $row->cnt;
        }
        return [
            'publishers' => $publishers,
            'difficulties' => array_map('intval', $DB->get_fieldset_sql(
                "SELECT DISTINCT $difficulty FROM $from WHERE b.hidden = 0 ORDER BY 1",
                $params
            )),
            'languages' => $DB->get_fieldset_sql(
                "SELECT DISTINCT b.language FROM $from WHERE b.hidden = 0 AND b.language <> '' ORDER BY 1",
                $params
            ),
        ];
    }

    /**
     * Search this activity's books for a teacher's view of a student's page: every book a
     * student could be offered here (not disabled; on the activity's list, if it has one),
     * whatever the student has read or may read.
     *
     * @param array $filters See {@see self::search_books()}.
     * @param int $limit
     * @param int|null $total Set to the full match count.
     * @return stdClass[] Keyed by book id; difficulty is the level the activity gives the book.
     */
    public function search_catalogue(array $filters, int $limit = 0, ?int &$total = null): array {
        global $DB;
        $from = '{moodlereader_books} b';
        $where = ['b.hidden = 0'];
        $params = [];
        $fields = 'b.*';
        if (!empty($this->instance->bookinstances)) {
            $from .= ' JOIN {moodlereader_bookinstances} bi ON bi.bookid = b.id AND bi.readerid = :bireaderid';
            $params['bireaderid'] = $this->instance->id;
            $fields = 'b.*, COALESCE(bi.difficulty, b.difficulty) AS difficulty';
        }
        $this->filter_where($filters, $where, $params);
        $wheresql = implode(' AND ', $where);
        $total = $DB->count_records_sql("SELECT COUNT(1) FROM $from WHERE $wheresql", $params);
        return $DB->get_records_sql(
            "SELECT $fields FROM $from WHERE $wheresql ORDER BY b.publisher, b.series, b.name",
            $params,
            0,
            $limit
        );
    }

    /**
     * The book languages present among the books available to a user.
     *
     * @param int $userid
     * @return string[] Codes, sorted; '' (unknown) is left out.
     */
    public function available_languages(int $userid): array {
        global $DB;
        [$from, $where, $params] = $this->available_where($userid);
        $sql = "SELECT DISTINCT b.language
                  FROM $from WHERE " . implode(' AND ', $where) . " AND b.language <> '' ORDER BY b.language";
        return array_map('strval', array_keys($DB->get_records_sql($sql, $params)));
    }

    /**
     * The publishers that currently have books available to a user, with counts.
     *
     * @param int $userid
     * @return array Map of publisher name => number of available books.
     */
    public function available_publishers(int $userid): array {
        global $DB;
        [$from, $where, $params] = $this->available_where($userid);
        $sql = "SELECT b.publisher, COUNT(1) AS cnt
                  FROM $from WHERE " . implode(' AND ', $where) . '
              GROUP BY b.publisher ORDER BY b.publisher';
        $out = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $out[$row->publisher] = (int) $row->cnt;
        }
        return $out;
    }

    /**
     * The reading difficulties present among the books available to a user.
     *
     * @param int $userid
     * @return int[] Sorted ascending.
     */
    public function available_difficulties(int $userid): array {
        global $DB;
        [$from, $where, $params] = $this->available_where($userid);
        $sql = "SELECT DISTINCT b.difficulty
                  FROM $from WHERE " . implode(' AND ', $where) . ' ORDER BY b.difficulty';
        return array_map('intval', array_keys($DB->get_records_sql($sql, $params)));
    }

    /**
     * The URL that serves (and lazily caches) a book's cover image.
     *
     * @param int $bookid
     * @return moodle_url
     */
    public function cover_url(int $bookid): moodle_url {
        return new moodle_url('/mod/moodlereader/cover.php', ['id' => $this->cm->id, 'book' => $bookid]);
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
