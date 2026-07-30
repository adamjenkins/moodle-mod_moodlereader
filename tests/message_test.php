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

use mod_moodlereader\local\message;

/**
 * Tests for teacher-to-student messaging.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_moodlereader\local\message::class)]
final class message_test extends \advanced_testcase {
    /**
     * A user sees instance-wide messages and their own, but not another user's.
     *
     * @return void
     */
    public function test_for_user_targeting(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $cm = $gen->create_instance(['course' => $course->id]);
        $readerid = $cm->id;
        $alice = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $bob = $this->getDataGenerator()->create_and_enrol($course, 'student');

        message::create($readerid, 0, 'Hello everyone', FORMAT_HTML);
        message::create($readerid, $alice->id, 'Just for Alice', FORMAT_HTML);
        message::create($readerid, $bob->id, 'Just for Bob', FORMAT_HTML);

        $formalice = message::for_user($readerid, $alice->id);
        $texts = array_map(fn($m) => $m->messagetext, $formalice);
        $this->assertContains('Hello everyone', $texts);
        $this->assertContains('Just for Alice', $texts);
        $this->assertNotContains('Just for Bob', $texts);
        $this->assertCount(2, $formalice);
    }

    /**
     * Delete is scoped to the instance and removes the message.
     *
     * @return void
     */
    public function test_create_and_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_moodlereader');
        $cm = $gen->create_instance(['course' => $course->id]);

        $id = message::create($cm->id, 0, 'Ephemeral', FORMAT_PLAIN);
        $this->assertTrue($DB->record_exists('moodlereader_messages', ['id' => $id]));

        // Deleting under the wrong instance id does nothing.
        message::delete($id, $cm->id + 999);
        $this->assertTrue($DB->record_exists('moodlereader_messages', ['id' => $id]));

        message::delete($id, $cm->id);
        $this->assertFalse($DB->record_exists('moodlereader_messages', ['id' => $id]));
    }
}
