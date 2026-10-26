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

use context_module;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use mod_moodlereader\local\attempt;
use mod_moodlereader\local\message;
use mod_moodlereader\local\mreader_site;
use mod_moodlereader\local\reader;
use mod_moodlereader\privacy\provider;

/**
 * Tests for the privacy provider.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\privacy\provider::class)]
final class privacy_provider_test extends \advanced_testcase {
    /**
     * Build an activity with a full spread of personal data for one user.
     *
     * @return array [reader, user, context_module]
     */
    protected function seed(): array {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $cm = $gen->create_instance(['course' => $course->id, 'goal' => 5000]);
        $book = $gen->create_book(['words' => 5000]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $reader = reader::from_instanceid($cm->id);

        // Attempt + grade + level + message + identity + cheatlog.
        $a = attempt::start($reader, $user->id, $book->id);
        attempt::finish($reader, $a, 90.0);
        $DB->insert_record('moodlereader_levels', (object) [
            'readerid' => $cm->id, 'userid' => $user->id, 'startlevel' => 0,
            'currentlevel' => 2, 'stoplevel' => 0, 'allowpromotion' => 1, 'time' => time(),
        ]);
        message::create($cm->id, $user->id, 'Personal note', FORMAT_HTML);
        (new mreader_site())->get_identity($user->id);
        $DB->insert_record('moodlereader_cheatlog', (object) [
            'readerid' => $cm->id, 'attemptid' => $a->id, 'userid' => $user->id,
            'otherattemptid' => 0, 'otheruserid' => 0, 'reason' => 'sameip', 'timecreated' => time(),
        ]);

        return [$reader, $user, context_module::instance($cm->cmid)];
    }

    /**
     * The user's module and user contexts are discovered.
     *
     * @return void
     */
    public function test_get_contexts_for_userid(): void {
        [$reader, $user, $modcontext] = $this->seed();
        $contexts = array_map('intval', provider::get_contexts_for_userid($user->id)->get_contextids());
        $this->assertContains((int) $modcontext->id, $contexts);
        $this->assertContains((int) \context_user::instance($user->id)->id, $contexts);
    }

    /**
     * The user is found in the module context.
     *
     * @return void
     */
    public function test_get_users_in_context(): void {
        [$reader, $user, $modcontext] = $this->seed();
        $userlist = new \core_privacy\local\request\userlist($modcontext, 'mod_moodlereader');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $user->id, $userlist->get_userids());
    }

    /**
     * Export writes the user's attempts, level, message and cheat flag.
     *
     * @return void
     */
    public function test_export_user_data(): void {
        [$reader, $user, $modcontext] = $this->seed();
        $contextlist = new approved_contextlist($user, 'mod_moodlereader', [$modcontext->id]);
        provider::export_user_data($contextlist);

        $data = writer::with_context($modcontext)->get_data([]);
        $this->assertNotEmpty($data->attempts);
        $this->assertEquals(2, $data->currentlevel);
        $this->assertNotEmpty($data->messages);
        $this->assertNotEmpty($data->flaggedforcheating);
    }

    /**
     * Deleting one user removes their data across every table.
     *
     * @return void
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        [$reader, $user, $modcontext] = $this->seed();
        $readerid = $reader->get_instance()->id;

        $contextlist = new approved_contextlist(
            $user,
            'mod_moodlereader',
            [$modcontext->id, \context_user::instance($user->id)->id]
        );
        provider::delete_data_for_user($contextlist);

        foreach (
            ['moodlereader_attempts', 'moodlereader_grades', 'moodlereader_levels',
                'moodlereader_messages', 'moodlereader_cheatlog'] as $table
        ) {
            $this->assertEquals(
                0,
                $DB->count_records($table, ['readerid' => $readerid, 'userid' => $user->id]),
                "residual rows in $table"
            );
        }
        $this->assertFalse($DB->record_exists('moodlereader_users', ['userid' => $user->id]));
    }

    /**
     * Deleting everyone in a context clears the per-module tables.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        [$reader, $user, $modcontext] = $this->seed();
        $readerid = $reader->get_instance()->id;

        provider::delete_data_for_all_users_in_context($modcontext);

        foreach (
            ['moodlereader_attempts', 'moodlereader_grades', 'moodlereader_levels',
                'moodlereader_messages', 'moodlereader_cheatlog'] as $table
        ) {
            $this->assertEquals(0, $DB->count_records($table, ['readerid' => $readerid]));
        }
    }

    /**
     * Deleting a list of users in a context removes their rows, cheat-log pairs included.
     *
     * @return void
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        [$reader, $user, $modcontext] = $this->seed();
        $readerid = $reader->get_instance()->id;
        // The user is also the other student of someone else's cheat-log pair.
        $DB->insert_record('moodlereader_cheatlog', (object) [
            'readerid' => $readerid, 'attemptid' => 0, 'userid' => 0,
            'otherattemptid' => 0, 'otheruserid' => $user->id, 'reason' => 'sameip', 'timecreated' => time(),
        ]);

        provider::delete_data_for_users(new \core_privacy\local\request\approved_userlist(
            $modcontext,
            'mod_moodlereader',
            [$user->id]
        ));

        foreach (['moodlereader_attempts', 'moodlereader_grades', 'moodlereader_levels', 'moodlereader_messages'] as $table) {
            $this->assertEquals(0, $DB->count_records($table, ['readerid' => $readerid, 'userid' => $user->id]), $table);
        }
        $this->assertEquals(0, $DB->count_records_select(
            'moodlereader_cheatlog',
            'readerid = ? AND (userid = ? OR otheruserid = ?)',
            [$readerid, $user->id, $user->id]
        ));
    }
}
