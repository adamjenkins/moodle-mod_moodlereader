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

use mod_moodlereader\local\import\importer;

/**
 * Tests for the repeatable mod_reader import: sync, preview, re-sync and cutover.
 *
 * mod_reader is not installed on test sites, so its tables are created as
 * temporary tables from a copy of its schema (tests/fixtures/legacy_reader_install.xml).
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\import\importer::class)]
final class importer_test extends \advanced_testcase {
    /** @var string[] Legacy tables created for the test. */
    protected $legacytables = [];

    /**
     * Create mod_reader's tables.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $xmldb = new \xmldb_file(__DIR__ . '/fixtures/legacy_reader_install.xml');
        $this->assertTrue($xmldb->loadXMLStructure());
        foreach ($xmldb->getStructure()->getTables() as $table) {
            $dbman->create_temp_table($table);
            $this->legacytables[] = $table->getName();
        }
    }

    /**
     * Drop mod_reader's tables.
     */
    protected function tearDown(): void {
        global $DB;
        $dbman = $DB->get_manager();
        foreach ($this->legacytables as $name) {
            $dbman->drop_table(new \xmldb_table($name));
        }
        $this->legacytables = [];
        parent::tearDown();
    }

    /**
     * A course with one mod_reader activity, two students and their data.
     *
     * @return array [course, legacy reader, legacy cm id, students]
     */
    protected function legacy_course(): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $course = $this->getDataGenerator()->create_course(['summary' => '', 'numsections' => 2]);
        $s1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $s2 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $s2->id]);

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'reader']) ?:
            $DB->insert_record('modules', (object) ['name' => 'reader', 'search' => '', 'visible' => 1]);
        $legacy = (object) ['course' => $course->id, 'name' => 'Graded readers', 'intro' => '<p>Read!</p>',
            'introformat' => FORMAT_HTML, 'goal' => 20000, 'maxgrade' => 100, 'minpassgrade' => 60,
            'timecreated' => time(), 'timemodified' => time()];
        $legacy->id = $DB->insert_record('reader', $legacy);
        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 1]);
        $cmid = add_course_module((object) ['course' => $course->id, 'module' => $moduleid, 'instance' => $legacy->id,
            'section' => $section->id, 'visible' => 1, 'visibleoncoursepage' => 1, 'groupmode' => SEPARATEGROUPS]);
        course_add_cm_to_section($course->id, $cmid, 1, null, 'reader');

        $b1 = $DB->insert_record('reader_books', (object) ['publisher' => 'Acme', 'level' => 'Level 2', 'difficulty' => 2,
            'name' => 'The Red Kite', 'words' => 3000, 'image' => 'red_kite.jpg', 'genre' => 'ad', 'fiction' => 'y',
            'quizid' => 11]);
        $b2 = $DB->insert_record('reader_books', (object) ['publisher' => 'Acme', 'level' => 'Level 2', 'difficulty' => 2,
            'name' => 'Blue Hills', 'words' => 4000, 'image' => 'blue_hills.jpg', 'genre' => 'ad', 'fiction' => 'y',
            'quizid' => 0]);
        foreach ([[$s1, 'aaaa1111'], [$s2, 'bbbb2222']] as [$user, $uid]) {
            $DB->insert_record('reader_users', (object) ['userid' => $user->id, 'uniqueid' => $uid, 'uniquename' => 'xx yyyy']);
        }
        $a = ['readerid' => $legacy->id, 'quizid' => 0, 'attempt' => 1, 'timestart' => time() - 3600];
        $DB->insert_record('reader_attempts', (object) ($a + ['userid' => $s1->id, 'bookid' => $b1,
            'uniqueid' => 1, 'percentgrade' => 80,
            'passed' => 1, 'timefinish' => time() - 3000]));
        $DB->insert_record('reader_attempts', (object) ($a + ['userid' => $s1->id, 'bookid' => $b2,
            'uniqueid' => 2, 'percentgrade' => 70,
            'passed' => 1, 'timefinish' => time() - 2000]));
        $DB->insert_record('reader_attempts', (object) ($a + ['userid' => $s2->id, 'bookid' => $b1,
            'uniqueid' => 3, 'percentgrade' => 30,
            'passed' => 0, 'timefinish' => time() - 1000]));
        $DB->insert_record('reader_levels', (object) ['readerid' => $legacy->id, 'userid' => $s1->id, 'currentlevel' => 2,
            'goal' => 15000]);
        $DB->insert_record('reader_levels', (object) ['readerid' => $legacy->id, 'userid' => $s2->id, 'currentlevel' => 2]);
        $DB->insert_record('reader_goals', (object) ['readerid' => $legacy->id, 'groupid' => $group->id, 'level' => 0,
            'goal' => 9000]);
        return [$course, $legacy, (int) $cmid, [$s1, $s2]];
    }

    /**
     * A snapshot of every mod_reader row, to prove the import never writes to them.
     *
     * @return array
     */
    protected function legacy_snapshot(): array {
        global $DB;
        $snapshot = [];
        foreach ($this->legacytables as $table) {
            $snapshot[$table] = $DB->get_records($table, null, 'id');
        }
        $snapshot['course_modules'] = $DB->get_records('course_modules', ['module' =>
            $DB->get_field('modules', 'id', ['name' => 'reader'])], 'id');
        return $snapshot;
    }

    /**
     * The whole workflow: sync, preview, re-sync, cutover, with mod_reader untouched throughout.
     *
     * @return void
     */
    public function test_workflow(): void {
        global $DB;
        [$course, $legacy, $legacycmid, [$s1, $s2]] = $this->legacy_course();
        $importer = new importer();

        // A preview writes nothing and announces the creation.
        $before = $this->legacy_snapshot();
        $preview = $importer->preview($course->id);
        $this->assertSame('create', $preview['activities'][$legacy->id]['action']);
        $this->assertSame(3, $preview['activities'][$legacy->id]['items']['attempt']['new']);
        $this->assertSame(0, $DB->count_records('moodlereader'));
        $this->assertSame(0, $DB->count_records('moodlereader_attempts'));

        // First sync: a hidden copy right after the original, with the data.
        $report = $importer->sync($course->id);
        $this->assertEquals($before, $this->legacy_snapshot());
        $import = $DB->get_record('moodlereader_imports', ['legacyreaderid' => $legacy->id], '*', MUST_EXIST);
        $this->assertSame('synced', $import->status);
        $newcm = $DB->get_record('course_modules', ['id' => $import->newcmid], '*', MUST_EXIST);
        $this->assertEquals(0, $newcm->visible);
        $this->assertEquals(SEPARATEGROUPS, $newcm->groupmode);
        $sequence = explode(',', $DB->get_field('course_sections', 'sequence', ['id' => $newcm->section]));
        $this->assertSame(array_search((string) $legacycmid, $sequence) + 1, array_search((string) $newcm->id, $sequence));
        $this->assertSame(3, $DB->count_records('moodlereader_attempts', ['readerid' => $import->newreaderid]));
        $this->assertSame('aaaa1111', $DB->get_field('moodlereader_users', 'uniqueid', ['userid' => $s1->id]));
        $this->assertEquals(15000, $DB->get_field('moodlereader_levels', 'goal', ['userid' => $s1->id]));
        // The group goal (all levels) reaches the group member.
        $this->assertEquals(9000, $DB->get_field('moodlereader_levels', 'goal', ['userid' => $s2->id]));
        $this->assertSame(2, $report['books']['new']);
        $this->assertSame(0, (int) $DB->get_field('moodlereader_books', 'remoteid', ['image' => 'red_kite.jpg']));
        // A quizid of 0 in mod_reader means the book has no quiz.
        $this->assertSame(1, (int) $DB->get_field('moodlereader_books', 'hasquiz', ['image' => 'red_kite.jpg']));
        $this->assertSame(0, (int) $DB->get_field('moodlereader_books', 'hasquiz', ['image' => 'blue_hills.jpg']));

        // Nothing changed: a preview says so.
        $preview = $importer->preview($course->id);
        $this->assertSame('unchanged', $preview['activities'][$legacy->id]['action']);
        $this->assertSame(
            ['new' => 0, 'changed' => 0, 'unchanged' => 3, 'removed' => 0],
            $preview['activities'][$legacy->id]['items']['attempt']
        );

        // Students keep using mod_reader during the break: one attempt changes, one is new, one is deleted.
        $attempts = array_values($DB->get_records('reader_attempts', ['readerid' => $legacy->id], 'id'));
        $DB->set_field('reader_attempts', 'percentgrade', 90, ['id' => $attempts[0]->id]);
        $DB->delete_records('reader_attempts', ['id' => $attempts[2]->id]);
        $DB->insert_record('reader_attempts', (object) ['readerid' => $legacy->id, 'userid' => $s2->id, 'uniqueid' => 4,
            'bookid' => $attempts[1]->bookid, 'percentgrade' => 75, 'passed' => 1, 'attempt' => 1,
            'timestart' => time() - 100, 'timefinish' => time() - 50]);
        $DB->set_field('reader', 'name', 'Graded readers 2026', ['id' => $legacy->id]);

        $before = $this->legacy_snapshot();
        $preview = $importer->preview($course->id);
        $this->assertSame('update', $preview['activities'][$legacy->id]['action']);
        $this->assertSame(
            ['new' => 1, 'changed' => 1, 'unchanged' => 1, 'removed' => 1],
            $preview['activities'][$legacy->id]['items']['attempt']
        );
        $this->assertEquals($before, $this->legacy_snapshot());

        // Re-sync applies exactly that, and is then in step.
        $importer->sync($course->id);
        $this->assertEquals($before, $this->legacy_snapshot());
        $this->assertSame(3, $DB->count_records('moodlereader_attempts', ['readerid' => $import->newreaderid]));
        $this->assertTrue($DB->record_exists('moodlereader_attempts', ['readerid' => $import->newreaderid,
            'userid' => $s1->id, 'percentgrade' => 90]));
        $this->assertSame('Graded readers 2026', $DB->get_field('moodlereader', 'name', ['id' => $import->newreaderid]));
        $this->assertSame(1, $DB->count_records('moodlereader_imports'));
        $preview = $importer->preview($course->id);
        $this->assertSame('unchanged', $preview['activities'][$legacy->id]['action']);
        $this->assertSame(0, $preview['activities'][$legacy->id]['items']['attempt']['new'] +
            $preview['activities'][$legacy->id]['items']['attempt']['changed'] +
            $preview['activities'][$legacy->id]['items']['attempt']['removed']);

        // Cutover: the copy becomes visible like the original; the original is untouched.
        $report = $importer->cutover($course->id);
        $this->assertSame(1, $report['cutover']);
        $this->assertEquals($before, $this->legacy_snapshot());
        $this->assertEquals(1, $DB->get_field('course_modules', 'visible', ['id' => $import->newcmid]));
        $this->assertEquals(1, $DB->get_field('course_modules', 'visible', ['id' => $legacycmid]));
        $this->assertSame('cutover', $DB->get_field('moodlereader_imports', 'status', ['id' => $import->id]));

        // After cutover the copy is no longer synced.
        $DB->set_field('reader_attempts', 'percentgrade', 10, ['id' => $attempts[0]->id]);
        $report = $importer->sync($course->id);
        $this->assertSame('locked', $report['activities'][$legacy->id]['action']);
        $this->assertTrue($DB->record_exists('moodlereader_attempts', ['readerid' => $import->newreaderid,
            'userid' => $s1->id, 'percentgrade' => 90]));
    }

    /**
     * A student who has already taken a v2 quiz keeps the new identity; the conflict is reported.
     *
     * @return void
     */
    public function test_identity_conflict(): void {
        global $DB;
        [$course, , , [$s1]] = $this->legacy_course();
        $DB->insert_record('moodlereader_users', (object) ['userid' => $s1->id, 'uniqueid' => 'newnew11',
            'uniquename' => 'ab cdef']);
        $DB->insert_record('moodlereader_attempts', (object) ['readerid' => 0, 'userid' => $s1->id, 'bookid' => 0,
            'attempt' => 1, 'state' => 'inprogress', 'ip' => '', 'attemptref' => '1-abc']);
        $report = (new importer())->sync($course->id);
        $this->assertSame(1, $report['users']['conflicts']);
        $this->assertSame('newnew11', $DB->get_field('moodlereader_users', 'uniqueid', ['userid' => $s1->id]));
    }
}
