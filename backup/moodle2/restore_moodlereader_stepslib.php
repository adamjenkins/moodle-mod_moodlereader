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
        // A backup file is untrusted: keep only valid detection settings.
        $settings = json_decode((string) ($data->cheatsettings ?? ''), true);
        $data->cheatsettings = is_array($settings)
            ? json_encode(\mod_moodlereader\local\cheatdetector::clean_settings($settings)) : null;
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
        // A backup file is untrusted: clean every catalogue field as a sync does.
        $text = fn($field, $length) => \core_text::substr(clean_param((string) ($data->$field ?? ''), PARAM_TEXT), 0, $length);
        $data = (object) [
            'remoteid'   => max(0, (int) ($data->remoteid ?? 0)),
            'publisher'  => $text('publisher', 100),
            'series'     => $text('series', 100),
            'difficulty' => (int) ($data->difficulty ?? 0),
            'name'       => $text('name', 255),
            'words'      => max(0, min(999999, (int) ($data->words ?? 0))),
            'genre'      => $text('genre', 255),
            'fiction'    => $text('fiction', 255),
            'points'     => (float) ($data->points ?? 0),
            'image'      => $text('image', 255),
            'sametitle'  => $text('sametitle', 255),
            'language'   => \mod_moodlereader\local\booklanguage::clean((string) ($data->language ?? '')),
            'hidden'     => empty($data->hidden) ? 0 : 1,
            'time'       => (int) ($data->time ?? 0),
        ];
        $existing = $DB->get_record('moodlereader_books', [
            'publisher' => $data->publisher, 'series' => $data->series, 'name' => $data->name,
        ], 'id', IGNORE_MULTIPLE);
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
        $data->bookid = (int) $this->get_mappingid('moodlereader_book', $data->bookid);
        if ($data->bookid && $DB->record_exists('moodlereader_books', ['id' => $data->bookid])) {
            $DB->insert_record('moodlereader_bookinstances', (object) [
                'readerid'   => $data->readerid,
                'bookid'     => $data->bookid,
                'difficulty' => (int) ($data->difficulty ?? 0),
                'words'      => max(0, min(999999, (int) ($data->words ?? 0))),
                'points'     => (float) ($data->points ?? 0),
            ]);
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
        $userid = (int) $this->get_mappingid('user', $data->userid);
        // Only a book this restore mapped: an unmapped id may be another book on this site.
        $bookid = (int) $this->get_mappingid('moodlereader_book', $data->bookid);
        if (!$userid || !$bookid || !$DB->record_exists('moodlereader_books', ['id' => $bookid])) {
            $this->log("skipped attempt for unresolved book or user", backup::LOG_WARNING);
            return;
        }
        $flag = fn($field) => empty($data->$field) ? 0 : 1;
        $ip = (string) ($data->ip ?? '');
        $attempt = (object) [
            'readerid'     => $this->get_new_parentid('moodlereader'),
            'userid'       => $userid,
            'bookid'       => $bookid,
            'attempt'      => max(1, (int) ($data->attempt ?? 1)),
            'state'        => ($data->state ?? '') === 'finished' ? 'finished' : 'inprogress',
            'percentgrade' => max(0.0, min(100.0, (float) ($data->percentgrade ?? 0))),
            'passed'       => $flag('passed'),
            'credit'       => $flag('credit'),
            'cheated'      => $flag('cheated'),
            'deleted'      => $flag('deleted'),
            'bookrating'   => max(0, min(99, (int) ($data->bookrating ?? 0))),
            'timestart'    => $this->apply_date_offset((int) ($data->timestart ?? 0)),
            'timefinish'   => $this->apply_date_offset((int) ($data->timefinish ?? 0)),
            'timemodified' => $this->apply_date_offset((int) ($data->timemodified ?? 0)),
            'ip'           => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '',
            // A restored attempt is not linked to mreader.org: its ref belongs to the original site.
            'attemptref'   => null,
            'remotestate'  => null,
            'timeresultchecked' => 0,
        ];
        $DB->insert_record('moodlereader_attempts', $attempt);
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
        $userid = (int) $this->get_mappingid('user', $data->userid);
        if (!$userid) {
            return;
        }
        $DB->insert_record('moodlereader_grades', (object) [
            'readerid'      => $this->get_new_parentid('moodlereader'),
            'userid'        => $userid,
            'rawgrade'      => max(0.0, (float) ($data->rawgrade ?? 0)),
            'datesubmitted' => (int) ($data->datesubmitted ?? 0),
            'dategraded'    => (int) ($data->dategraded ?? 0),
        ]);
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
        $userid = (int) $this->get_mappingid('user', $data->userid);
        if (!$userid) {
            return;
        }
        $level = fn($field) => max(0, min(99, (int) ($data->$field ?? 0)));
        $DB->insert_record('moodlereader_levels', (object) [
            'readerid'       => $this->get_new_parentid('moodlereader'),
            'userid'         => $userid,
            'goal'           => max(0, min(9999999, (int) ($data->goal ?? 0))),
            'startlevel'     => $level('startlevel'),
            'currentlevel'   => $level('currentlevel'),
            'stoplevel'      => $level('stoplevel'),
            'allowpromotion' => empty($data->allowpromotion) ? 0 : 1,
            'time'           => (int) ($data->time ?? 0),
        ]);
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
        $userid = 0;
        if (!empty($data->userid)) {
            $userid = (int) $this->get_mappingid('user', $data->userid);
            if (!$userid) {
                // Never widen a message for one student into a message for everyone.
                return;
            }
        }
        $format = (int) ($data->messageformat ?? FORMAT_HTML);
        $DB->insert_record('moodlereader_messages', (object) [
            'readerid'      => $data->readerid,
            'userid'        => $userid,
            'messagetext'   => (string) ($data->messagetext ?? ''),
            'messageformat' => in_array($format, [FORMAT_MOODLE, FORMAT_HTML, FORMAT_PLAIN, FORMAT_MARKDOWN], true)
                ? $format : FORMAT_HTML,
            'timecreated'   => (int) ($data->timecreated ?? 0),
            'timemodified'  => (int) ($data->timemodified ?? 0),
        ]);
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
