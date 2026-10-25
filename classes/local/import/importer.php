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

namespace mod_moodlereader\local\import;

/**
 * Imports mod_reader activities into mod_moodlereader, repeatably.
 *
 * The workflow is preview → sync → (preview → re-sync)* → cutover:
 *
 * - A sync builds, for every mod_reader activity in a course, a HIDDEN
 *   mod_moodlereader copy next to it, with its settings, books, students'
 *   attempts, levels, goals and (optionally) cheating log and messages.
 * - It is idempotent: every imported row is recorded in {moodlereader_importmap}
 *   with a hash of what it was made from, so a re-sync inserts what is new, updates
 *   what changed, removes what mod_reader no longer has, and leaves the rest alone.
 *   A preview runs the same comparison without writing.
 * - A cutover (approved by a site admin) runs a last sync, then makes the copies
 *   visible exactly as their mod_reader originals are, and stops syncing them.
 *
 * mod_reader's own tables and activities are only ever READ. Nothing is sent to
 * mreader.org: student identities are copied unchanged, so each student keeps
 * their opaque mreader.org id.
 *
 * The goal resolution and data mapping follow mod_reader's data model.
 *
 * @package    mod_moodlereader
 * @copyright  2013 Gordon Bateson (gordon.bateson@gmail.com)
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class importer {
    /** @var string An activity whose copy is hidden and can be re-synced. */
    const STATUS_SYNCED = 'synced';

    /** @var string An activity whose copy is live and is no longer synced. */
    const STATUS_CUTOVER = 'cutover';

    /** @var string[] The per-activity item types, in the order they are synced. */
    const ITEMTYPES = ['bookinst', 'attempt', 'level', 'cheat', 'message'];

    /** @var bool Import the cheating log. */
    protected $importcheatlog;

    /** @var bool Import teacher messages. */
    protected $importmessages;

    /** @var bool Write, or only compare. */
    protected $write = false;

    /** @var int[] legacy reader_books.id => moodlereader_books.id. */
    protected $bookmap = [];

    /** @var array The report being built. */
    protected $report = [];

    /**
     * Constructor.
     *
     * @param array $options 'importcheatlog' (default on), 'importmessages' (default off).
     */
    public function __construct(array $options = []) {
        $config = get_config('mod_moodlereader');
        $this->importcheatlog = (bool) ($options['importcheatlog'] ?? ($config->importcheatlog ?? 1));
        $this->importmessages = (bool) ($options['importmessages'] ?? ($config->importmessages ?? 0));
    }

    /**
     * Whether mod_reader's tables are present to import from.
     *
     * @return bool
     */
    public static function source_available(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        return $dbman->table_exists('reader') && $dbman->table_exists('reader_users') &&
            $dbman->table_exists('reader_attempts') && $dbman->table_exists('reader_books');
    }

    /**
     * Every course that has mod_reader activities, with its import state.
     *
     * @return array[] Keyed by course id: courseid, fullname, activities, students, attempts,
     *     synced, cutover, timesynced, timecutover.
     */
    public static function courses(): array {
        global $DB;
        if (!self::source_available()) {
            return [];
        }
        $rows = $DB->get_records_sql(
            "SELECT c.id AS courseid, c.fullname, COUNT(DISTINCT r.id) AS activities
               FROM {reader} r
               JOIN {course} c ON c.id = r.course
           GROUP BY c.id, c.fullname
           ORDER BY c.fullname"
        );
        $result = [];
        foreach ($rows as $row) {
            $stats = $DB->get_record_sql(
                "SELECT COUNT(DISTINCT a.userid) AS students, COUNT(a.id) AS attempts
                   FROM {reader_attempts} a
                   JOIN {reader} r ON r.id = a.readerid
                  WHERE r.course = ?",
                [$row->courseid]
            );
            $imports = $DB->get_records('moodlereader_imports', ['courseid' => $row->courseid]);
            $synced = count(array_filter($imports, fn($i) => $i->status === self::STATUS_SYNCED));
            $cutover = count(array_filter($imports, fn($i) => $i->status === self::STATUS_CUTOVER));
            $result[$row->courseid] = [
                'courseid'    => (int) $row->courseid,
                'fullname'    => $row->fullname,
                'activities'  => (int) $row->activities,
                'students'    => (int) $stats->students,
                'attempts'    => (int) $stats->attempts,
                'synced'      => $synced,
                'cutover'     => $cutover,
                'timesynced'  => $imports ? max(array_map(fn($i) => (int) $i->timesynced, $imports)) : 0,
                'timecutover' => $imports ? max(array_map(fn($i) => (int) $i->timecutover, $imports)) : 0,
            ];
        }
        return $result;
    }

    /**
     * Compare a course's mod_reader data with its copies, writing nothing.
     *
     * @param int $courseid
     * @return array The report (see {@see self::run()}).
     */
    public function preview(int $courseid): array {
        return $this->run($courseid, false);
    }

    /**
     * Create or update the hidden copies of a course's mod_reader activities.
     *
     * @param int $courseid
     * @return array The report.
     */
    public function sync(int $courseid): array {
        return $this->run($courseid, true);
    }

    /**
     * Make a course's copies live: a final sync, then the visibility of each mod_reader
     * original is copied to its copy, and the copy stops being synced.
     *
     * mod_reader's activities are left exactly as they are.
     *
     * @param int $courseid
     * @return array The report of the final sync, with 'cutover' => count.
     */
    public function cutover(int $courseid): array {
        global $DB;
        $report = $this->sync($courseid);
        $count = 0;
        foreach ($DB->get_records('moodlereader_imports', ['courseid' => $courseid, 'status' => self::STATUS_SYNCED]) as $import) {
            $legacycm = $DB->get_record('course_modules', ['id' => $import->legacycmid]);
            if (!$legacycm || !$DB->record_exists('course_modules', ['id' => $import->newcmid])) {
                continue;
            }
            \core_courseformat\formatactions::cm($courseid)->set_visibility(
                (int) $import->newcmid,
                (int) $legacycm->visible,
                (int) $legacycm->visibleoncoursepage,
                false
            );
            $DB->update_record('moodlereader_imports', (object) [
                'id' => $import->id,
                'status' => self::STATUS_CUTOVER,
                'timecutover' => time(),
                'timemodified' => time(),
            ]);
            $count++;
        }
        rebuild_course_cache($courseid, true);
        $report['cutover'] = $count;
        return $report;
    }

    /**
     * Compare (and, when writing, apply) one course.
     *
     * @param int $courseid
     * @param bool $write
     * @return array ['courseid', 'write', 'books' => counts, 'users' => counts, 'activities' => [...], 'warnings' => [...]]
     */
    protected function run(int $courseid, bool $write): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/moodlereader/lib.php');
        require_once($CFG->libdir . '/gradelib.php');

        if (!self::source_available()) {
            throw new \moodle_exception('importnosource', 'mod_moodlereader');
        }
        \core_php_time_limit::raise(0);
        $this->write = $write;
        $this->bookmap = [];
        $this->report = ['courseid' => $courseid, 'write' => $write, 'activities' => [], 'warnings' => []];

        $legacyreaders = $DB->get_records('reader', ['course' => $courseid], 'id');
        $transaction = $write ? $DB->start_delegated_transaction() : null;

        $this->report['books'] = $this->sync_books($legacyreaders);
        $this->report['users'] = $this->sync_identities($legacyreaders);
        $touched = [];
        foreach ($legacyreaders as $legacy) {
            $result = $this->sync_activity($legacy);
            $this->report['activities'][$legacy->id] = $result;
            if ($write && !empty($result['newreaderid']) && $result['action'] !== 'locked') {
                $touched[] = (int) $result['newreaderid'];
            }
        }

        if ($transaction) {
            $transaction->allow_commit();
            // Grades and caches only after the data is committed (a rollback must not leave them behind).
            foreach ($touched as $newreaderid) {
                if ($instance = $DB->get_record('moodlereader', ['id' => $newreaderid])) {
                    \moodlereader_grade_item_update($instance);
                    \moodlereader_update_grades($instance);
                }
            }
            rebuild_course_cache($courseid, true);
        }
        foreach ($this->report['activities'] as $legacyid => &$activity) {
            $activity['gradesold'] = $this->grade_total('reader', (int) $legacyid, $courseid);
            $activity['gradesnew'] = empty($activity['newreaderid']) ? null :
                $this->grade_total('moodlereader', (int) $activity['newreaderid'], $courseid);
        }
        unset($activity);
        return $this->report;
    }

    /**
     * Map the books the course's activities use (catalogue rows are shared and never removed).
     *
     * @param \stdClass[] $legacyreaders
     * @return array Counts.
     */
    protected function sync_books(array $legacyreaders): array {
        global $DB;
        $counts = self::counts();
        if (!$legacyreaders) {
            return $counts;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($legacyreaders));
        $books = $DB->get_records_sql(
            "SELECT b.*
               FROM {reader_books} b
              WHERE b.id IN (SELECT bookid FROM {reader_attempts} WHERE readerid $insql)
                 OR b.id IN (SELECT bookid FROM {reader_book_instances} WHERE readerid $insql)",
            array_merge($params, $params)
        );
        $maps = $this->load_maps(0, 'book');
        foreach ($books as $old) {
            // Imported text is cleaned exactly as a book synced from mreader.org is.
            $text = fn($value, $length) => \core_text::substr(clean_param((string) $value, PARAM_TEXT), 0, $length);
            $values = [
                'publisher'  => $text($old->publisher, 100),
                'series'     => $text($old->level, 100),
                'difficulty' => (int) $old->difficulty,
                'name'       => $text($old->name, 255),
                'words'      => max(0, min(999999, (int) $old->words)),
                'genre'      => $text($old->genre, 255),
                'fiction'    => $text($old->fiction, 255),
                'points'     => (float) $old->points,
                'image'      => $text($old->image, 255),
                'sametitle'  => $text($old->sametitle, 255),
            ];
            $hash = self::hash($values);
            $map = $maps[$old->id . ':0'] ?? null;
            if ($map && $DB->record_exists('moodlereader_books', ['id' => $map->newid])) {
                $this->bookmap[$old->id] = (int) $map->newid;
                if ($map->hash === $hash) {
                    $counts['unchanged']++;
                    continue;
                }
                $counts['changed']++;
                // Catalogue fields are refreshed only on books the import created (mreader.org owns the rest).
                if ($this->write) {
                    if ((int) $DB->get_field('moodlereader_books', 'remoteid', ['id' => $map->newid]) === 0) {
                        $DB->update_record('moodlereader_books', (object) (['id' => $map->newid] + $values));
                    }
                    $DB->set_field('moodlereader_importmap', 'hash', $hash, ['id' => $map->id]);
                }
                continue;
            }
            // An existing catalogue book: same legacy image, else same publisher/series/title.
            $newid = 0;
            if ($values['image'] !== '') {
                $newid = (int) $DB->get_field('moodlereader_books', 'id', ['image' => $values['image']], IGNORE_MULTIPLE);
            }
            if (!$newid) {
                $newid = (int) $DB->get_field('moodlereader_books', 'id', [
                    'publisher' => $values['publisher'], 'series' => $values['series'], 'name' => $values['name'],
                ], IGNORE_MULTIPLE);
            }
            $counts['new']++;
            if (!$this->write) {
                $this->bookmap[$old->id] = $newid ?: -$old->id;
                continue;
            }
            if (!$newid) {
                $newid = $DB->insert_record('moodlereader_books', (object) ($values + [
                    'remoteid' => 0, 'hidden' => (int) $old->hidden, 'time' => time(), 'withdrawn' => 0, 'language' => '',
                ]));
            }
            $this->bookmap[$old->id] = (int) $newid;
            $this->save_map($map, 0, 'book', (int) $old->id, 0, (int) $newid, $hash);
        }
        return $counts;
    }

    /**
     * Copy the opaque mreader.org identities of the course's students.
     *
     * A student who already has a mod_moodlereader identity takes the mod_reader one,
     * unless they have already taken a quiz with the new one (then both are kept as
     * they are, and the conflict is reported).
     *
     * @param \stdClass[] $legacyreaders
     * @return array Counts, plus 'conflicts'.
     */
    protected function sync_identities(array $legacyreaders): array {
        global $DB;
        $counts = self::counts() + ['conflicts' => 0];
        if (!$legacyreaders) {
            return $counts;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($legacyreaders));
        $users = $DB->get_records_sql(
            "SELECT u.*
               FROM {reader_users} u
              WHERE u.userid IN (SELECT userid FROM {reader_attempts} WHERE readerid $insql)
                 OR u.userid IN (SELECT userid FROM {reader_levels} WHERE readerid $insql)",
            array_merge($params, $params)
        );
        $maps = $this->load_maps(0, 'user');
        foreach ($users as $old) {
            $values = ['userid' => (int) $old->userid, 'uniqueid' => (string) $old->uniqueid,
                'uniquename' => (string) $old->uniquename];
            $hash = self::hash($values);
            $map = $maps[$old->id . ':0'] ?? null;
            $current = $DB->get_record('moodlereader_users', ['userid' => $old->userid]);
            if ($map && $current && $current->uniqueid === $values['uniqueid'] && $map->hash === $hash) {
                $counts['unchanged']++;
                continue;
            }
            if ($current && $current->uniqueid !== $values['uniqueid']) {
                $launched = $DB->record_exists_select(
                    'moodlereader_attempts',
                    'userid = ? AND attemptref IS NOT NULL',
                    [$old->userid]
                );
                if ($launched) {
                    $counts['conflicts']++;
                    continue;
                }
                $counts['changed']++;
                if ($this->write) {
                    $DB->update_record('moodlereader_users', (object) (['id' => $current->id] + $values));
                    $this->save_map($map, 0, 'user', (int) $old->id, 0, (int) $current->id, $hash);
                }
                continue;
            }
            if ($current) {
                $counts['unchanged']++;
                if ($this->write) {
                    $this->save_map($map, 0, 'user', (int) $old->id, 0, (int) $current->id, $hash);
                }
                continue;
            }
            $counts['new']++;
            if ($this->write) {
                $newid = $DB->insert_record('moodlereader_users', (object) $values);
                $this->save_map($map, 0, 'user', (int) $old->id, 0, (int) $newid, $hash);
            }
        }
        return $counts;
    }

    /**
     * Compare and apply one mod_reader activity.
     *
     * @param \stdClass $legacy A {reader} record.
     * @return array name, legacycmid, newcmid, newreaderid, status, action, items => [type => counts]
     */
    protected function sync_activity(\stdClass $legacy): array {
        global $DB;
        $result = ['name' => $legacy->name, 'legacycmid' => 0, 'newcmid' => 0, 'newreaderid' => 0,
            'status' => '', 'action' => 'unchanged', 'items' => []];
        $legacycm = get_coursemodule_from_instance('reader', $legacy->id, $legacy->course, false, IGNORE_MISSING);
        if (!$legacycm) {
            $result['action'] = 'skipped';
            $this->report['warnings'][] = get_string('importnocm', 'mod_moodlereader', format_string($legacy->name));
            return $result;
        }
        $result['legacycmid'] = (int) $legacycm->id;

        $import = $DB->get_record('moodlereader_imports', ['legacyreaderid' => $legacy->id]);
        if ($import && !$DB->record_exists('moodlereader', ['id' => $import->newreaderid])) {
            // The copy was deleted by hand: start again.
            if ($this->write) {
                $DB->delete_records('moodlereader_importmap', ['importid' => $import->id]);
                $DB->delete_records('moodlereader_imports', ['id' => $import->id]);
            }
            $import = null;
        }
        if ($import) {
            $result['newreaderid'] = (int) $import->newreaderid;
            $result['newcmid'] = (int) $import->newcmid;
            $result['status'] = $import->status;
            if ($import->status === self::STATUS_CUTOVER) {
                $result['action'] = 'locked';
                return $result;
            }
        }

        $instance = $this->instance_values($legacy);
        $hash = self::hash($instance + self::cm_values($legacycm));
        $previous = $import ? json_decode((string) $import->counts, true) : null;
        if (!$import) {
            $result['action'] = 'create';
        } else if (($previous['hash'] ?? '') !== $hash) {
            $result['action'] = 'update';
        }

        if ($this->write) {
            if (!$import) {
                $newreaderid = (int) $DB->insert_record('moodlereader', (object) ($instance + ['timecreated' => time()]));
                $newcmid = $this->create_cm($legacycm, $newreaderid);
                $import = (object) [
                    'courseid' => (int) $legacy->course,
                    'legacyreaderid' => (int) $legacy->id,
                    'legacycmid' => (int) $legacycm->id,
                    'newreaderid' => $newreaderid,
                    'newcmid' => $newcmid,
                    'status' => self::STATUS_SYNCED,
                    'timecreated' => time(),
                ];
                $import->id = $DB->insert_record('moodlereader_imports', $import);
                $result['newreaderid'] = $newreaderid;
                $result['newcmid'] = $newcmid;
                $result['status'] = self::STATUS_SYNCED;
            } else if ($result['action'] === 'update') {
                $DB->update_record('moodlereader', (object) (['id' => $import->newreaderid] + $instance));
                $this->update_cm($legacycm, (int) $import->newcmid);
            }
        }

        $newreaderid = $import ? (int) $import->newreaderid : 0;
        $importid = $import ? (int) $import->id : 0;
        foreach (self::ITEMTYPES as $type) {
            if (($type === 'cheat' && !$this->importcheatlog) || ($type === 'message' && !$this->importmessages)) {
                continue;
            }
            $rows = $this->legacy_rows($type, $legacy, $importid);
            $result['items'][$type] = $this->sync_items($importid, $type, $rows, $newreaderid);
        }

        if ($this->write) {
            $DB->update_record('moodlereader_imports', (object) [
                'id' => $import->id,
                'counts' => json_encode(['hash' => $hash, 'items' => $result['items']]),
                'timesynced' => time(),
                'timemodified' => time(),
            ]);
        }
        return $result;
    }

    /**
     * The mod_moodlereader instance fields for a mod_reader activity.
     *
     * @param \stdClass $legacy
     * @return array
     */
    protected function instance_values(\stdClass $legacy): array {
        return [
            'course'         => (int) $legacy->course,
            'name'           => (string) $legacy->name,
            'intro'          => (string) ($legacy->intro ?? ''),
            'introformat'    => (int) ($legacy->introformat ?? FORMAT_HTML),
            'availablefrom'  => (int) ($legacy->availablefrom ?? 0),
            'availableuntil' => (int) ($legacy->availableuntil ?? 0),
            'maxgrade'       => (int) ($legacy->maxgrade ?? 100),
            'goal'           => (int) ($legacy->goal ?? 0),
            'wordsorpoints'  => (int) ($legacy->wordsorpoints ?? 0),
            'minpassgrade'   => (int) ($legacy->minpassgrade ?? 60),
            'ignoredate'     => (int) ($legacy->ignoredate ?? 0),
            'levelcheck'     => (int) ($legacy->levelcheck ?? 0),
            'thislevel'      => (int) ($legacy->thislevel ?? 6),
            'prevlevel'      => (int) ($legacy->prevlevel ?? 0),
            'nextlevel'      => (int) ($legacy->nextlevel ?? 1),
            'bookinstances'  => (int) ($legacy->bookinstances ?? 0),
            'showprogressbar' => (int) ($legacy->showprogressbar ?? 1),
            'completionpass' => (int) ($legacy->completionpass ?? 0),
            'completiontotalwords' => (int) ($legacy->completiontotalwords ?? 0),
            'timemodified'   => (int) ($legacy->timemodified ?? 0),
        ];
    }

    /**
     * The course-module settings copied from the mod_reader activity (visibility excepted).
     *
     * @param \stdClass $legacycm
     * @return array
     */
    protected static function cm_values(\stdClass $legacycm): array {
        $values = [];
        foreach (
            ['groupmode', 'groupingid', 'completion', 'completionview', 'completionexpected',
                'completionpassgrade', 'showdescription', 'availability', 'indent'] as $field
        ) {
            if (property_exists($legacycm, $field)) {
                $values[$field] = $legacycm->$field;
            }
        }
        return $values;
    }

    /**
     * Create the hidden course module for a copy, directly after the original.
     *
     * @param \stdClass $legacycm
     * @param int $newreaderid
     * @return int New course-module id.
     */
    protected function create_cm(\stdClass $legacycm, int $newreaderid): int {
        global $DB;
        $moduleid = $DB->get_field('modules', 'id', ['name' => 'moodlereader'], MUST_EXIST);
        $newcm = (object) (self::cm_values($legacycm) + [
            'course'   => $legacycm->course,
            'module'   => $moduleid,
            'instance' => $newreaderid,
            'section'  => $legacycm->section,
            'visible'  => 0,
            'visibleold' => 0,
            'visibleoncoursepage' => 1,
        ]);
        $cmid = add_course_module($newcm);

        // Place it straight after the original.
        $sequence = array_values(array_filter(explode(
            ',',
            (string) $DB->get_field('course_sections', 'sequence', ['id' => $legacycm->section])
        )));
        $position = array_search((string) $legacycm->id, $sequence, true);
        $before = ($position !== false && isset($sequence[$position + 1])) ? (int) $sequence[$position + 1] : null;
        $sectionnum = $DB->get_field('course_sections', 'section', ['id' => $legacycm->section]);
        course_add_cm_to_section((int) $legacycm->course, $cmid, (int) $sectionnum, $before, 'moodlereader');
        \context_module::instance($cmid);

        // The gradebook item mirrors the original's category and pass mark.
        $instance = $DB->get_record('moodlereader', ['id' => $newreaderid], '*', MUST_EXIST);
        \moodlereader_grade_item_update($instance);
        $this->copy_grade_item_settings((int) $legacycm->instance, $newreaderid, (int) $legacycm->course);
        return (int) $cmid;
    }

    /**
     * Refresh a copy's course-module settings from its original (visibility excepted).
     *
     * @param \stdClass $legacycm
     * @param int $newcmid
     * @return void
     */
    protected function update_cm(\stdClass $legacycm, int $newcmid): void {
        global $DB;
        $DB->update_record('course_modules', (object) (['id' => $newcmid] + self::cm_values($legacycm)));
        $this->copy_grade_item_settings((int) $legacycm->instance, (int) $DB->get_field(
            'course_modules',
            'instance',
            ['id' => $newcmid]
        ), (int) $legacycm->course);
    }

    /**
     * Give the copy's grade item the original's category and pass mark.
     *
     * @param int $legacyid
     * @param int $newreaderid
     * @param int $courseid
     * @return void
     */
    protected function copy_grade_item_settings(int $legacyid, int $newreaderid, int $courseid): void {
        $old = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'reader', 'iteminstance' => $legacyid,
            'courseid' => $courseid, 'itemnumber' => 0]);
        $new = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'moodlereader', 'iteminstance' => $newreaderid,
            'courseid' => $courseid, 'itemnumber' => 0]);
        if (!$old || !$new) {
            return;
        }
        if ((int) $new->categoryid !== (int) $old->categoryid) {
            $new->set_parent($old->categoryid);
        }
        if ((float) $new->gradepass !== (float) $old->gradepass) {
            $new->gradepass = $old->gradepass;
            $new->update('mod/moodlereader');
        }
    }

    /**
     * The legacy rows of one item type for an activity, each mapped to its new values.
     *
     * @param string $type
     * @param \stdClass $legacy
     * @param int $importid
     * @return array Keyed "legacyid:sub" => ['legacyid', 'sub', 'values' => array] (values without readerid).
     */
    protected function legacy_rows(string $type, \stdClass $legacy, int $importid): array {
        global $DB;
        $rows = [];
        $add = function (int $legacyid, int $sub, array $values) use (&$rows) {
            $rows[$legacyid . ':' . $sub] = ['legacyid' => $legacyid, 'sub' => $sub, 'values' => $values];
        };
        switch ($type) {
            case 'bookinst':
                foreach ($DB->get_records('reader_book_instances', ['readerid' => $legacy->id]) as $old) {
                    if (isset($this->bookmap[$old->bookid])) {
                        $add((int) $old->id, 0, ['bookid' => $this->bookmap[$old->bookid],
                            'difficulty' => (int) $old->difficulty, 'words' => (int) $old->words,
                            'points' => (float) $old->points]);
                    }
                }
                break;

            case 'attempt':
                foreach ($DB->get_records('reader_attempts', ['readerid' => $legacy->id]) as $old) {
                    if (!isset($this->bookmap[$old->bookid])) {
                        continue;
                    }
                    $add((int) $old->id, 0, [
                        'userid'       => (int) $old->userid,
                        'bookid'       => $this->bookmap[$old->bookid],
                        'attempt'      => (int) $old->attempt,
                        'state'        => ((int) $old->timefinish > 0) ? 'finished' : 'inprogress',
                        'percentgrade' => (float) $old->percentgrade,
                        'passed'       => (int) $old->passed,
                        'credit'       => (int) $old->credit,
                        'cheated'      => (int) $old->cheated,
                        'deleted'      => (int) $old->deleted,
                        'bookrating'   => (int) $old->bookrating,
                        'timestart'    => (int) $old->timestart,
                        'timefinish'   => (int) $old->timefinish,
                        'timemodified' => (int) $old->timemodified,
                        'ip'           => (string) ($old->ip ?? ''),
                    ]);
                }
                break;

            case 'level':
                $goals = $DB->get_records('reader_goals', ['readerid' => $legacy->id]);
                // One level row per student (the newest, if mod_reader holds duplicates).
                $levels = [];
                foreach ($DB->get_records('reader_levels', ['readerid' => $legacy->id], 'id') as $old) {
                    $levels[$old->userid] = $old;
                }
                foreach ($levels as $old) {
                    $add((int) $old->id, 0, [
                        'userid'         => (int) $old->userid,
                        'goal'           => $this->resolve_goal($old, $goals, (int) $legacy->course),
                        'startlevel'     => (int) $old->startlevel,
                        'currentlevel'   => (int) $old->currentlevel,
                        'stoplevel'      => (int) $old->stoplevel,
                        'allowpromotion' => (int) $old->allowpromotion,
                        'time'           => (int) $old->time,
                    ]);
                }
                break;

            case 'cheat':
                // Only confirmed cheating; "cleared" entries need no row (the attempts' flags carry it).
                $attempts = $this->load_maps($importid, 'attempt');
                $logs = $DB->get_records('reader_cheated_log', ['readerid' => $legacy->id, 'status' => 'cheated']);
                foreach ($logs as $old) {
                    $a1 = $attempts[$old->attempt1 . ':0'] ?? null;
                    $a2 = $attempts[$old->attempt2 . ':0'] ?? null;
                    if (!$a1 || !$a2) {
                        continue;
                    }
                    $add((int) $old->id, 0, [
                        'attemptid'      => (int) $a1->newid,
                        'userid'         => (int) $old->userid1,
                        'otherattemptid' => (int) $a2->newid,
                        'otheruserid'    => (int) $old->userid2,
                        'reason'         => 'legacy',
                        'timecreated'    => (int) $old->date,
                    ]);
                }
                break;

            case 'message':
                $now = time();
                foreach ($DB->get_records('reader_messages', ['readerid' => $legacy->id]) as $old) {
                    if ((int) $old->timefinish > 0 && (int) $old->timefinish < $now) {
                        continue;
                    }
                    $values = ['messagetext' => (string) $old->message, 'messageformat' => FORMAT_HTML,
                        'timecreated' => (int) $old->timemodified, 'timemodified' => (int) $old->timemodified];
                    $groupids = array_filter(array_map('intval', explode(',', (string) $old->groupids)));
                    if (!$groupids) {
                        $add((int) $old->id, 0, $values + ['userid' => 0]);
                        continue;
                    }
                    // A group message becomes one message per member (the new model has no groups).
                    foreach ($groupids as $groupid) {
                        foreach (groups_get_members($groupid, 'u.id') as $member) {
                            $add((int) $old->id, (int) $member->id, $values + ['userid' => (int) $member->id]);
                        }
                    }
                }
                break;
        }
        return $rows;
    }

    /**
     * A student's reading goal, as mod_reader would have resolved it.
     *
     * Order: the student's own goal; their group's goal for their level; their group's
     * goal for all levels; the activity's goal for their level; 0 (= the activity default).
     *
     * @param \stdClass $level A {reader_levels} record.
     * @param \stdClass[] $goals {reader_goals} records of the activity.
     * @param int $courseid
     * @return int
     */
    protected function resolve_goal(\stdClass $level, array $goals, int $courseid): int {
        if (!empty($level->goal)) {
            return (int) $level->goal;
        }
        if (!$goals) {
            return 0;
        }
        $groups = groups_get_user_groups($courseid, (int) $level->userid)[0] ?? [];
        $candidates = [];
        foreach ($groups as $groupid) {
            $candidates[] = [(int) $groupid, (int) $level->currentlevel];
            $candidates[] = [(int) $groupid, 0];
        }
        $candidates[] = [0, (int) $level->currentlevel];
        foreach ($candidates as [$groupid, $lvl]) {
            foreach ($goals as $goal) {
                if ((int) $goal->groupid === $groupid && (int) $goal->level === $lvl && (int) $goal->goal > 0) {
                    return (int) $goal->goal;
                }
            }
        }
        return 0;
    }

    /**
     * Compare (and apply) one item type for an activity.
     *
     * @param int $importid 0 when the activity has no copy yet (everything is new).
     * @param string $type
     * @param array $rows From {@see self::legacy_rows()}.
     * @param int $newreaderid
     * @return array Counts.
     */
    protected function sync_items(int $importid, string $type, array $rows, int $newreaderid): array {
        global $DB;
        $table = self::table_for($type);
        $counts = self::counts();
        $maps = $importid ? $this->load_maps($importid, $type) : [];

        foreach ($rows as $key => $row) {
            $hash = self::hash($row['values']);
            $map = $maps[$key] ?? null;
            unset($maps[$key]);
            $exists = $map && $DB->record_exists($table, ['id' => $map->newid]);
            if ($exists && $map->hash === $hash) {
                $counts['unchanged']++;
                continue;
            }
            $counts[$exists ? 'changed' : 'new']++;
            if (!$this->write) {
                continue;
            }
            $record = (object) ($row['values'] + ['readerid' => $newreaderid]);
            if ($exists) {
                $record->id = $map->newid;
                $DB->update_record($table, $record);
                $DB->set_field('moodlereader_importmap', 'hash', $hash, ['id' => $map->id]);
            } else {
                $newid = (int) $DB->insert_record($table, $record);
                $this->save_map($map, $importid, $type, $row['legacyid'], $row['sub'], $newid, $hash);
            }
        }

        // What mod_reader no longer has is removed from the (still hidden) copy.
        foreach ($maps as $map) {
            $counts['removed']++;
            if ($this->write) {
                $DB->delete_records($table, ['id' => $map->newid, 'readerid' => $newreaderid]);
                $DB->delete_records('moodlereader_importmap', ['id' => $map->id]);
            }
        }
        return $counts;
    }

    /**
     * The new table for an item type.
     *
     * @param string $type
     * @return string
     */
    protected static function table_for(string $type): string {
        return [
            'bookinst' => 'moodlereader_bookinstances',
            'attempt'  => 'moodlereader_attempts',
            'level'    => 'moodlereader_levels',
            'cheat'    => 'moodlereader_cheatlog',
            'message'  => 'moodlereader_messages',
        ][$type];
    }

    /**
     * The import-map rows of one type, keyed "legacyid:sub".
     *
     * @param int $importid
     * @param string $type
     * @return \stdClass[]
     */
    protected function load_maps(int $importid, string $type): array {
        global $DB;
        $maps = [];
        $rs = $DB->get_recordset('moodlereader_importmap', ['importid' => $importid, 'itemtype' => $type]);
        foreach ($rs as $map) {
            $maps[$map->legacyid . ':' . $map->legacysub] = $map;
        }
        $rs->close();
        return $maps;
    }

    /**
     * Insert or update one import-map row.
     *
     * @param \stdClass|null $map The existing row, if any.
     * @param int $importid
     * @param string $type
     * @param int $legacyid
     * @param int $sub
     * @param int $newid
     * @param string $hash
     * @return void
     */
    protected function save_map(
        ?\stdClass $map,
        int $importid,
        string $type,
        int $legacyid,
        int $sub,
        int $newid,
        string $hash
    ): void {
        global $DB;
        if ($map) {
            $DB->update_record('moodlereader_importmap', (object) ['id' => $map->id, 'newid' => $newid, 'hash' => $hash]);
            return;
        }
        $DB->insert_record('moodlereader_importmap', (object) [
            'importid' => $importid, 'itemtype' => $type, 'legacyid' => $legacyid, 'legacysub' => $sub,
            'newid' => $newid, 'hash' => $hash,
        ]);
    }

    /**
     * Sum of final grades in an activity's grade item.
     *
     * @param string $module 'reader' or 'moodlereader'.
     * @param int $instanceid
     * @param int $courseid
     * @return float|null Null if there is no grade item.
     */
    protected function grade_total(string $module, int $instanceid, int $courseid): ?float {
        global $DB;
        $itemid = $DB->get_field('grade_items', 'id', ['itemtype' => 'mod', 'itemmodule' => $module,
            'iteminstance' => $instanceid, 'courseid' => $courseid, 'itemnumber' => 0]);
        if (!$itemid) {
            return null;
        }
        return (float) $DB->get_field_sql(
            'SELECT COALESCE(SUM(finalgrade), 0) FROM {grade_grades} WHERE itemid = ?',
            [$itemid]
        );
    }

    /**
     * An empty set of counts.
     *
     * @return int[]
     */
    protected static function counts(): array {
        return ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'removed' => 0];
    }

    /**
     * Content hash of one mapped row.
     *
     * @param array $values
     * @return string
     */
    protected static function hash(array $values): string {
        ksort($values);
        return sha1(json_encode($values));
    }
}
