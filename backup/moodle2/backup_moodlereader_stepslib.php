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
 * Backup structure step for mod_moodlereader.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Defines the backup structure for a moodlereader activity.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_moodlereader_activity_structure_step extends backup_activity_structure_step {
    /**
     * Define the backup structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $reader = new backup_nested_element('moodlereader', ['id'], [
            'name', 'intro', 'introformat', 'availablefrom', 'availableuntil', 'maxgrade',
            'goal', 'wordsorpoints', 'minpassgrade', 'ignoredate', 'levelcheck', 'thislevel',
            'prevlevel', 'nextlevel', 'bookinstances', 'showprogressbar', 'showlevel',
            'showbooksread', 'showreport', 'showsearch', 'completionpass',
            'completiontotalwords', 'cheatsettings', 'timecreated', 'timemodified',
        ]);

        // The books this activity references, carried by natural identity so the
        // restore can re-link to (or recreate) the right book on another site.
        $books = new backup_nested_element('books');
        $book = new backup_nested_element('book', ['id'], [
            'remoteid', 'publisher', 'series', 'difficulty', 'name', 'words', 'genre',
            'fiction', 'points', 'image', 'sametitle', 'language', 'hidden', 'time',
        ]);

        // Named 'bookinstancelinks' (not 'bookinstances') to avoid colliding with the
        // moodlereader.bookinstances flag column, which is also a final element here.
        $bookinstances = new backup_nested_element('bookinstancelinks');
        $bookinstance = new backup_nested_element(
            'bookinstance',
            ['id'],
            ['bookid', 'difficulty', 'words', 'points']
        );

        $attempts = new backup_nested_element('attempts');
        $attempt = new backup_nested_element('attempt', ['id'], [
            'userid', 'bookid', 'attempt', 'state', 'percentgrade', 'passed', 'credit',
            'cheated', 'deleted', 'bookrating', 'timestart', 'timefinish', 'timemodified', 'ip',
        ]);

        $grades = new backup_nested_element('grades');
        $grade = new backup_nested_element(
            'grade',
            ['id'],
            ['userid', 'rawgrade', 'datesubmitted', 'dategraded']
        );

        $levels = new backup_nested_element('levels');
        $level = new backup_nested_element(
            'level',
            ['id'],
            ['userid', 'goal', 'startlevel', 'currentlevel', 'stoplevel', 'allowpromotion', 'time']
        );

        $messages = new backup_nested_element('messages');
        $message = new backup_nested_element(
            'message',
            ['id'],
            ['userid', 'messagetext', 'messageformat', 'timecreated', 'timemodified']
        );

        $reader->add_child($books);
        $books->add_child($book);
        $reader->add_child($bookinstances);
        $bookinstances->add_child($bookinstance);
        $reader->add_child($attempts);
        $attempts->add_child($attempt);
        $reader->add_child($grades);
        $grades->add_child($grade);
        $reader->add_child($levels);
        $levels->add_child($level);
        $reader->add_child($messages);
        $messages->add_child($message);

        $reader->set_source_table('moodlereader', ['id' => backup::VAR_ACTIVITYID]);
        $bookinstance->set_source_table('moodlereader_bookinstances', ['readerid' => backup::VAR_PARENTID]);

        // Books referenced by this activity's bookinstances or (when included) attempts.
        $book->set_source_sql(
            "SELECT b.* FROM {moodlereader_books} b
              WHERE b.id IN (SELECT bookid FROM {moodlereader_bookinstances} WHERE readerid = ?)
                 OR b.id IN (SELECT bookid FROM {moodlereader_attempts} WHERE readerid = ?)",
            [backup::VAR_ACTIVITYID, backup::VAR_ACTIVITYID]
        );

        if ($userinfo) {
            $attempt->set_source_table('moodlereader_attempts', ['readerid' => backup::VAR_PARENTID]);
            $grade->set_source_table('moodlereader_grades', ['readerid' => backup::VAR_PARENTID]);
            $level->set_source_table('moodlereader_levels', ['readerid' => backup::VAR_PARENTID]);
            $message->set_source_table('moodlereader_messages', ['readerid' => backup::VAR_PARENTID]);
        }

        $attempt->annotate_ids('user', 'userid');
        $grade->annotate_ids('user', 'userid');
        $level->annotate_ids('user', 'userid');
        $message->annotate_ids('user', 'userid');

        $reader->annotate_files('mod_moodlereader', 'intro', null);

        return $this->prepare_activity_structure($reader);
    }
}
