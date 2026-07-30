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

/**
 * Restore task for mod_moodlereader.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/moodlereader/backup/moodle2/restore_moodlereader_stepslib.php');

/**
 * Restore task definition for a moodlereader activity.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_moodlereader_activity_task extends restore_activity_task {
    /**
     * No task-specific settings.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * Define the restore steps.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new restore_moodlereader_activity_structure_step('moodlereader_structure', 'moodlereader.xml'));
    }

    /**
     * File areas whose contents are included in the backup.
     *
     * @return array
     */
    public static function define_decode_contents() {
        return [new restore_decode_content('moodlereader', ['intro'], 'moodlereader')];
    }

    /**
     * Decode rules for content links.
     *
     * @return array
     */
    public static function define_decode_rules() {
        return [new restore_decode_rule('MOODLEREADERVIEWBYID', '/mod/moodlereader/view.php?id=$1', 'course_module')];
    }
}
