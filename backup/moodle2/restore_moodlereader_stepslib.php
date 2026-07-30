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
 * Restore structure step for mod_moodlereader.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Defines the restore structure for a moodlereader activity.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_moodlereader_activity_structure_step extends restore_activity_structure_step {
    /**
     * Define what is restored.
     *
     * @return array
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');
        $paths = [];
        $paths[] = new restore_path_element('moodlereader', '/activity/moodlereader');
        // Books are processed first so bookinstances/attempts can remap their bookid.
        $paths[] = new restore_path_element(
            'moodlereader_book',
            '/activity/moodlereader/books/book'
        );
        $paths[] = new restore_path_element(
            'moodlereader_bookinstance',
            '/activity/moodlereader/bookinstancelinks/bookinstance'
        );
        if ($userinfo) {
            $paths[] = new restore_path_element(
                'moodlereader_attempt',
                '/activity/moodlereader/attempts/attempt'
            );
            $paths[] = new restore_path_element(
                'moodlereader_grade',
                '/activity/moodlereader/grades/grade'
            );
            $paths[] = new restore_path_element(
                'moodlereader_level',
                '/activity/moodlereader/levels/level'
            );
            $paths[] = new restore_path_element(
                'moodlereader_message',
                '/activity/moodlereader/messages/message'
            );
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the instance.
     *
     * @param array $data
     * @return void
     */
    protected function process_moodlereader($data) {
        global $DB;
        $data = (object) $data;
        $data->course = $this->get_courseid();
        $data->availablefrom = $this->apply_date_offset($data->availablefrom);
        $data->availableuntil = $this->apply_date_offset($data->availableuntil);
        $newid = $DB->insert_record('moodlereader', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * Restore a book: re-link to a matching catalogue book, or create it, and map its id.
     *
     * @param array $data
     * @return void
     */
    protected function process_moodlereader_book($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        unset($data->id);
        $existing = $DB->get_record('moodlereader_books', [
            'publisher' => $data->publisher, 'series' => $data->series, 'name' => $data->name,
        ]);
        $newid = $existing ? $existing->id : $DB->insert_record('moodlereader_books', $data);
        $this->set_mapping('moodlereader_book', $oldid, $newid);
    }

    /**
     * Restore a book-instance link, remapping the book id by identity.
     *
     * @param array $data
     * @return void
     */
    protected function process_moodlereader_bookinstance($data) {
        global $DB;
        $data = (object) $data;
        $data->readerid = $this->get_new_parentid('moodlereader');
        $data->bookid = $this->get_mappingid('moodlereader_book', $data->bookid) ?: $data->bookid;
        if ($DB->record_exists('moodlereader_books', ['id' => $data->bookid])) {
            $DB->insert_record('moodlereader_bookinstances', $data);
        }
    }

    /**
     * Restore an attempt.
     *
     * @param array $data
     * @return void
     */
    protected function process_moodlereader_attempt($data) {
        global $DB;
        $data = (object) $data;
        $data->readerid = $this->get_new_parentid('moodlereader');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->bookid = $this->get_mappingid('moodlereader_book', $data->bookid) ?: $data->bookid;
        // Skip attempts whose book could not be resolved on the target site.
        if (!$DB->record_exists('moodlereader_books', ['id' => $data->bookid])) {
            $this->log("skipped attempt for unresolved book", backup::LOG_WARNING);
            return;
        }
        $data->timestart = $this->apply_date_offset($data->timestart);
        $data->timefinish = $this->apply_date_offset($data->timefinish);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $DB->insert_record('moodlereader_attempts', $data);
    }

    /**
     * Restore an aggregate grade.
     *
     * @param array $data
     * @return void
     */
    protected function process_moodlereader_grade($data) {
        global $DB;
        $data = (object) $data;
        $data->readerid = $this->get_new_parentid('moodlereader');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $DB->insert_record('moodlereader_grades', $data);
    }

    /**
     * Restore a reading level.
     *
     * @param array $data
     * @return void
     */
    protected function process_moodlereader_level($data) {
        global $DB;
        $data = (object) $data;
        $data->readerid = $this->get_new_parentid('moodlereader');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $DB->insert_record('moodlereader_levels', $data);
    }

    /**
     * Restore a teacher message.
     *
     * @param array $data
     * @return void
     */
    protected function process_moodlereader_message($data) {
        global $DB;
        $data = (object) $data;
        $data->readerid = $this->get_new_parentid('moodlereader');
        if (!empty($data->userid)) {
            $data->userid = $this->get_mappingid('user', $data->userid);
        }
        $DB->insert_record('moodlereader_messages', $data);
    }

    /**
     * After restore: re-attach intro files.
     *
     * @return void
     */
    protected function after_execute() {
        $this->add_related_files('mod_moodlereader', 'intro', null);
    }
}
