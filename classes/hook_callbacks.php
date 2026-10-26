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

/**
 * Hook callbacks of mod_moodlereader.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * A course is deleted: its Safe Exam Browser settings go with it.
     *
     * @param \core_course\hook\before_course_deleted $hook
     * @return void
     */
    public static function before_course_deleted(\core_course\hook\before_course_deleted $hook): void {
        global $DB;
        $DB->delete_records('moodlereader_sebcourse', ['courseid' => $hook->course->id]);
    }
}
