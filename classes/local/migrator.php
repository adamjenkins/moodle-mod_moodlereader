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
 * One-shot migration of data from the legacy mod_reader plugin.
 *
 * Copies the mreader.org registration, the book catalogue, the mreader.org
 * identity map and, for every mod_reader activity, a matching mod_moodlereader
 * activity carrying its books, attempts, grades and reading levels. Only the
 * mReader-mode core data is migrated; local-quiz artefacts are intentionally
 * dropped (they no longer exist in this plugin).
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class migrator {
    /** @var bool When true, no writes are performed. */
    protected $dryrun;

    /** @var callable Progress logger. */
    protected $log;

    /** @var int[] Map of legacy reader_books.id => new moodlereader_books.id. */
    protected $bookmap = [];

    /**
     * Constructor.
     *
     * @param bool $dryrun
     * @param callable|null $log Receives a status string.
     */
    public function __construct(bool $dryrun = false, ?callable $log = null) {
        $this->dryrun = $dryrun;
        $this->log = $log ?? function ($msg) {
            mtrace($msg);
        };
    }

    /**
     * Whether the legacy mod_reader tables are present to migrate from.
     *
     * @return bool
     */
    public static function source_available(): bool {
        global $DB;
        return $DB->get_manager()->table_exists('reader') &&
            $DB->get_manager()->table_exists('reader_users');
    }

    /**
     * Run the migration.
     *
     * @return array Counts keyed by what was migrated.
     */
    public function run(): array {
        global $DB;

        $counts = ['config' => 0, 'books' => 0, 'identities' => 0,
            'activities' => 0, 'attempts' => 0, 'grades' => 0, 'levels' => 0];

        $transaction = $this->dryrun ? null : $DB->start_delegated_transaction();

        $counts['config'] = $this->migrate_config();
        $counts['books'] = $this->migrate_books();
        $counts['identities'] = $this->migrate_identities();

        foreach ($DB->get_records('reader') as $oldreader) {
            $result = $this->migrate_activity($oldreader);
            if ($result) {
                $counts['activities']++;
                $counts['attempts'] += $result['attempts'];
                $counts['grades'] += $result['grades'];
                $counts['levels'] += $result['levels'];
            }
        }

        if ($transaction) {
            $transaction->allow_commit();
        }
        return $counts;
    }

    /**
     * Copy the mreader.org registration config.
     *
     * @return int
     */
    protected function migrate_config(): int {
        $old = get_config('mod_reader');
        $migrated = 0;
        foreach (['mreaderurl', 'mreadersiteid', 'mreadersitekey'] as $name) {
            if (!empty($old->$name)) {
                $this->write(fn() => set_config($name, $old->$name, 'mod_moodlereader'));
                $migrated++;
            }
        }
        ($this->log)("Config: migrated $migrated mreader.org settings.");
        return $migrated;
    }

    /**
     * Copy the book catalogue, building the legacy-id => new-id map.
     *
     * @return int
     */
    protected function migrate_books(): int {
        global $DB;
        $count = 0;
        foreach ($DB->get_recordset('reader_books') as $old) {
            $new = (object) [
                'publisher'  => $old->publisher,
                'series'     => $old->level, // Legacy 'level' string => 'series'.
                'difficulty' => (int) $old->difficulty,
                'name'       => $old->name,
                'words'      => (int) $old->words,
                'genre'      => $old->genre,
                'fiction'    => $old->fiction,
                'points'     => (float) $old->points,
                'image'      => $old->image,
                'sametitle'  => $old->sametitle,
                'hidden'     => (int) $old->hidden,
                'time'       => (int) $old->time,
            ];
            $newid = $this->write(fn() => $DB->insert_record('moodlereader_books', $new), $count + 1);
            $this->bookmap[$old->id] = $newid;
            $count++;
        }
        ($this->log)("Books: migrated $count.");
        return $count;
    }

    /**
     * Copy the mreader.org identity map (userid => uniqueid/uniquename).
     *
     * @return int
     */
    protected function migrate_identities(): int {
        global $DB;
        $count = 0;
        foreach ($DB->get_recordset('reader_users') as $old) {
            if (!$this->dryrun && $DB->record_exists('moodlereader_users', ['userid' => $old->userid])) {
                continue;
            }
            $new = (object) [
                'userid'     => $old->userid,
                'uniqueid'   => $old->uniqueid,
                'uniquename' => $old->uniquename,
            ];
            $this->write(fn() => $DB->insert_record('moodlereader_users', $new));
            $count++;
        }
        ($this->log)("Identities: migrated $count mreader.org user mappings.");
        return $count;
    }

    /**
     * Create a moodlereader activity mirroring a legacy reader activity, with its data.
     *
     * @param \stdClass $oldreader A {reader} record.
     * @return array|null Counts, or null if the source course module was missing.
     */
    protected function migrate_activity(\stdClass $oldreader): ?array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/lib/gradelib.php');
        require_once($CFG->dirroot . '/mod/moodlereader/lib.php');

        $oldcm = get_coursemodule_from_instance('reader', $oldreader->id, 0, false, IGNORE_MISSING);
        if (!$oldcm) {
            ($this->log)("  Skipped reader id {$oldreader->id}: no course module.");
            return null;
        }

        $instance = (object) [
            'course'         => $oldreader->course,
            'name'           => $oldreader->name,
            'intro'          => $oldreader->intro ?? '',
            'introformat'    => $oldreader->introformat ?? FORMAT_HTML,
            'availablefrom'  => $oldreader->availablefrom ?? 0,
            'availableuntil' => $oldreader->availableuntil ?? 0,
            'maxgrade'       => $oldreader->maxgrade ?? 100,
            'goal'           => $oldreader->goal ?? 0,
            'wordsorpoints'  => $oldreader->wordsorpoints ?? 0,
            'minpassgrade'   => $oldreader->minpassgrade ?? 60,
            'ignoredate'     => $oldreader->ignoredate ?? 0,
            'levelcheck'     => $oldreader->levelcheck ?? 0,
            'thislevel'      => $oldreader->thislevel ?? 6,
            'prevlevel'      => $oldreader->prevlevel ?? 0,
            'nextlevel'      => $oldreader->nextlevel ?? 1,
            'bookinstances'  => $oldreader->bookinstances ?? 0,
            'showprogressbar' => $oldreader->showprogressbar ?? 1,
            'completionpass' => $oldreader->completionpass ?? 0,
            'completiontotalwords' => $oldreader->completiontotalwords ?? 0,
            'timecreated'    => $oldreader->timecreated ?? time(),
            'timemodified'   => time(),
        ];

        $newreaderid = $this->write(fn() => $DB->insert_record('moodlereader', $instance), 1);
        $this->create_course_module($oldreader->course, $oldcm, (int) $newreaderid);

        // Per-instance data.
        $attempts = $this->migrate_per_user(
            'reader_attempts',
            'moodlereader_attempts',
            $oldreader->id,
            (int) $newreaderid,
            fn($old) => [
                'readerid'     => $newreaderid,
                'userid'       => $old->userid,
                'bookid'       => $this->bookmap[$old->bookid] ?? 0,
                'attempt'      => (int) $old->attempt,
                'state'        => ($old->timefinish > 0) ? 'finished' : 'inprogress',
                'percentgrade' => (float) $old->percentgrade,
                'passed'       => (int) $old->passed,
                'credit'       => (int) $old->credit,
                'cheated'      => (int) $old->cheated,
                'deleted'      => (int) $old->deleted,
                'bookrating'   => (int) $old->bookrating,
                'timestart'    => (int) $old->timestart,
                'timefinish'   => (int) $old->timefinish,
                'timemodified' => (int) $old->timemodified,
            ],
            fn($old) => isset($this->bookmap[$old->bookid])
        );

        $grades = $this->migrate_per_user(
            'reader_grades',
            'moodlereader_grades',
            $oldreader->id,
            (int) $newreaderid,
            fn($old) => [
                'readerid'      => $newreaderid,
                'userid'        => $old->userid,
                'rawgrade'      => (float) $old->rawgrade,
                'datesubmitted' => (int) $old->datesubmitted,
                'dategraded'    => (int) $old->dategraded,
            ]
        );

        $levels = $this->migrate_per_user(
            'reader_levels',
            'moodlereader_levels',
            $oldreader->id,
            (int) $newreaderid,
            fn($old) => [
                'readerid'       => $newreaderid,
                'userid'         => $old->userid,
                'startlevel'     => (int) $old->startlevel,
                'currentlevel'   => (int) $old->currentlevel,
                'stoplevel'      => (int) $old->stoplevel,
                'allowpromotion' => (int) $old->allowpromotion,
                'time'           => (int) $old->time,
            ]
        );

        // Book-instance links.
        foreach ($DB->get_records('reader_book_instances', ['readerid' => $oldreader->id]) as $old) {
            if (!isset($this->bookmap[$old->bookid])) {
                continue;
            }
            $this->write(fn() => $DB->insert_record('moodlereader_bookinstances', (object) [
                'readerid'   => $newreaderid,
                'bookid'     => $this->bookmap[$old->bookid],
                'difficulty' => (int) $old->difficulty,
                'words'      => (int) $old->words,
                'points'     => (float) $old->points,
            ]));
        }

        if (!$this->dryrun) {
            $newinstance = $DB->get_record('moodlereader', ['id' => $newreaderid]);
            \moodlereader_grade_item_update($newinstance);
            \moodlereader_update_grades($newinstance);
        }

        ($this->log)("  Activity '{$oldreader->name}': {$attempts} attempts, {$grades} grades, {$levels} levels.");
        return ['attempts' => $attempts, 'grades' => $grades, 'levels' => $levels];
    }

    /**
     * Create the course module for a migrated instance, in the same section as the old one.
     *
     * @param int $courseid
     * @param \stdClass $oldcm The legacy course module.
     * @param int $newinstanceid
     * @return void
     */
    protected function create_course_module(int $courseid, \stdClass $oldcm, int $newinstanceid): void {
        global $DB;
        if ($this->dryrun) {
            return;
        }
        $moduleid = $DB->get_field('modules', 'id', ['name' => 'moodlereader'], MUST_EXIST);
        $newcm = (object) [
            'course'     => $courseid,
            'module'     => $moduleid,
            'instance'   => $newinstanceid,
            'section'    => $oldcm->section,
            'visible'    => $oldcm->visible,
            'visibleold' => $oldcm->visible,
            'groupmode'  => $oldcm->groupmode,
            'groupingid' => $oldcm->groupingid,
        ];
        $cmid = add_course_module($newcm);
        $sectionnum = $DB->get_field('course_sections', 'section', ['id' => $oldcm->section]);
        course_add_cm_to_section($courseid, $cmid, $sectionnum);
        rebuild_course_cache($courseid, true);
    }

    /**
     * Copy per-user rows from a legacy table into a new one via a mapping callback.
     *
     * @param string $fromtable
     * @param string $totable
     * @param int $oldreaderid
     * @param int $newreaderid
     * @param callable $map Maps an old record to a new-record array.
     * @param callable|null $filter Optional predicate; rows failing it are skipped.
     * @return int Rows migrated.
     */
    protected function migrate_per_user(
        string $fromtable,
        string $totable,
        int $oldreaderid,
        int $newreaderid,
        callable $map,
        ?callable $filter = null
    ): int {
        global $DB;
        $count = 0;
        foreach ($DB->get_recordset($fromtable, ['readerid' => $oldreaderid]) as $old) {
            if ($filter && !$filter($old)) {
                continue;
            }
            $this->write(fn() => $DB->insert_record($totable, (object) $map($old)));
            $count++;
        }
        return $count;
    }

    /**
     * Execute a write unless this is a dry run.
     *
     * @param callable $fn
     * @param int $dryrunreturn Value to return in dry-run mode (fake id).
     * @return mixed
     */
    protected function write(callable $fn, int $dryrunreturn = 0) {
        return $this->dryrun ? $dryrunreturn : $fn();
    }
}
