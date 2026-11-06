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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/mod/moodlereader/backup/moodle2/restore_moodlereader_stepslib.php');

/**
 * Restore treats a backup as untrusted and keeps the "a book counts once" rule.
 *
 * @package    mod_moodlereader
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_moodlereader_activity_structure_step::class)]
final class restore_test extends \advanced_testcase {
    /**
     * Settings a form would refuse are held to the form's ranges.
     *
     * @return void
     */
    public function test_ranges_from_a_backup_are_held_to_the_form(): void {
        $data = (object) [
            'thislevel' => 32767, 'prevlevel' => -5, 'nextlevel' => 100, 'minpassgrade' => 500, 'goal' => -1,
            'maxgrade' => -10, 'completiontotalwords' => -3, 'wordsorpoints' => 7,
        ];
        \restore_moodlereader_activity_structure_step::clean_ranges($data);
        $this->assertSame(99, $data->thislevel);
        $this->assertSame(0, $data->prevlevel);
        $this->assertSame(99, $data->nextlevel);
        $this->assertSame(100, $data->minpassgrade);
        $this->assertSame(0, $data->goal);
        $this->assertSame(0.0, $data->maxgrade);
        $this->assertSame(0, $data->completiontotalwords);
        $this->assertSame(1, $data->wordsorpoints);
    }

    /**
     * A course restored with user data keeps the copy of a finished book, marked as not counting.
     *
     * @return void
     */
    public function test_restored_copy_of_a_finished_book_does_not_count(): void {
        global $CFG, $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enableavailability = 1;

        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $instance = $gen->create_instance(['course' => $course->id, 'goal' => 5000]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $book = $gen->create_book(['remoteid' => 77]);
        $now = time();
        $DB->insert_record('moodlereader_attempts', (object) [
            'readerid' => $instance->id, 'userid' => $student->id, 'bookid' => $book->id, 'attempt' => 1,
            'state' => 'finished', 'percentgrade' => 90, 'passed' => 1, 'credit' => 0, 'cheated' => 0, 'deleted' => 0,
            'bookrating' => 0, 'timestart' => $now - 900, 'timefinish' => $now, 'timemodified' => $now,
            'ip' => '203.0.113.9', 'delivery' => 'moodle',
        ]);

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $backupid = 'moodlereader_restore_test';
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), make_backup_temp_directory($backupid));

        $newcourseid = \restore_dbops::create_new_course('Restored copy', 'RESTOREDCOPY', $course->category);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $newinstance = $DB->get_record('moodlereader', ['course' => $newcourseid], '*', MUST_EXIST);
        $copy = $DB->get_record('moodlereader_attempts', ['readerid' => $newinstance->id], '*', MUST_EXIST);
        $this->assertSame('finished', $copy->state);
        $this->assertEquals(1, $copy->deleted);
        $this->assertEquals(0, $DB->get_field('moodlereader_attempts', 'deleted', ['readerid' => $instance->id]));
    }
}
