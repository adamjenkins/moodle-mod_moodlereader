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
 * Backup task for mod_moodlereader.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/moodlereader/backup/moodle2/backup_moodlereader_stepslib.php');

/**
 * Backup task definition for a moodlereader activity.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_moodlereader_activity_task extends backup_activity_task {
    /**
     * No task-specific settings.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * Define the backup steps.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new backup_moodlereader_activity_structure_step('moodlereader_structure', 'moodlereader.xml'));
    }

    /**
     * Encode links to this activity so they can be restored.
     *
     * @param string $content
     * @return string
     */
    public static function encode_content_links($content) {
        global $CFG;
        $base = preg_quote($CFG->wwwroot, '#');
        $search = "#($base/mod/moodlereader/view\.php\?id=)([0-9]+)#";
        return preg_replace($search, '$@MOODLEREADERVIEWBYID*$2@$', $content);
    }
}
