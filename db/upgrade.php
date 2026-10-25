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
 * Upgrade steps for mod_moodlereader.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Perform the upgrade for a given old version.
 *
 * @param int $oldversion The currently installed version.
 * @return bool
 */
function xmldb_moodlereader_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026073001) {
        // Record the remote address on attempts (for cheating detection).
        $table = new xmldb_table('moodlereader_attempts');
        $field = new xmldb_field('ip', XMLDB_TYPE_CHAR, '45', null, XMLDB_NOTNULL, null, null, 'timemodified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Cheating-detection log.
        $table = new xmldb_table('moodlereader_cheatlog');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('readerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('otherattemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('otheruserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('reason', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('readerid', XMLDB_KEY_FOREIGN, ['readerid'], 'moodlereader', ['id']);
            $table->add_index('attemptid', XMLDB_INDEX_NOTUNIQUE, ['attemptid']);
            $dbman->create_table($table);
        }

        // Teacher messages to students.
        $table = new xmldb_table('moodlereader_messages');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('readerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('messagetext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('messageformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('readerid', XMLDB_KEY_FOREIGN, ['readerid'], 'moodlereader', ['id']);
            $table->add_index('readerid_userid', XMLDB_INDEX_NOTUNIQUE, ['readerid', 'userid']);
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026073001, 'moodlereader');
    }

    if ($oldversion < 2026073002) {
        // Store the mreader.org book id so covers can be fetched on demand.
        $table = new xmldb_table('moodlereader_books');
        $field = new xmldb_field('remoteid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026073002, 'moodlereader');
    }

    if ($oldversion < 2026073003) {
        // Appearance toggles: which activity-page sections a teacher shows.
        $table = new xmldb_table('moodlereader');
        foreach (['showlevel', 'showbooksread', 'showreport', 'showsearch'] as $name) {
            $field = new xmldb_field($name, XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Per-user reading-goal override (0 = use the activity default).
        $table = new xmldb_table('moodlereader_levels');
        $field = new xmldb_field('goal', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'userid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026073003, 'moodlereader');
    }

    if ($oldversion < 2026102500) {
        // API v2: attempts are identified to mreader.org by an opaque reference.
        $table = new xmldb_table('moodlereader_attempts');
        $fields = [
            new xmldb_field('attemptref', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'ip'),
            new xmldb_field('remotestate', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'attemptref'),
            new xmldb_field('timeresultchecked', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'remotestate'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $index = new xmldb_index('attemptref', XMLDB_INDEX_NOTUNIQUE, ['attemptref']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Books: withdrawn flag, cover ETag, and an index on the stable mreader.org id.
        $table = new xmldb_table('moodlereader_books');
        $fields = [
            new xmldb_field('withdrawn', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'time'),
            new xmldb_field('coveretag', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'withdrawn'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $index = new xmldb_index('remoteid', XMLDB_INDEX_NOTUNIQUE, ['remoteid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // The mod_reader import ledger.
        $table = new xmldb_table('moodlereader_imports');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('legacyreaderid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('legacycmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('newreaderid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('newcmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'synced');
            $table->add_field('counts', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timesynced', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecutover', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('legacyreaderid', XMLDB_INDEX_UNIQUE, ['legacyreaderid']);
            $table->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('moodlereader_importmap');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('importid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('itemtype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('legacyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('legacysub', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('newid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('hash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('item', XMLDB_INDEX_UNIQUE, ['importid', 'itemtype', 'legacyid', 'legacysub']);
            $dbman->create_table($table);
        }

        // The legacy md5 registration is no longer used: connections are made through API v2.
        unset_config('mreadersiteid', 'mod_moodlereader');
        unset_config('mreadersitekey', 'mod_moodlereader');

        upgrade_mod_savepoint(true, 2026102500, 'moodlereader');
    }

    if ($oldversion < 2026102501) {
        // Per-activity cheating-detection settings.
        $table = new xmldb_table('moodlereader');
        $field = new xmldb_field('cheatsettings', XMLDB_TYPE_TEXT, null, null, null, null, null, 'completiontotalwords');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Book language, for the book search.
        $table = new xmldb_table('moodlereader_books');
        $field = new xmldb_field('language', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null, 'sametitle');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // What each cheating flag measured.
        $table = new xmldb_table('moodlereader_cheatlog');
        $field = new xmldb_field('detail', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'reason');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026102501, 'moodlereader');
    }

    if ($oldversion < 2026102502) {
        // A per-activity book list existed briefly in development; Manage books is site-wide.
        $table = new xmldb_table('moodlereader_hiddenbooks');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        upgrade_mod_savepoint(true, 2026102502, 'moodlereader');
    }

    if ($oldversion < 2026102503) {
        // Books without a quiz (credit can be awarded for them; they are never offered as a quiz).
        $table = new xmldb_table('moodlereader_books');
        $field = new xmldb_field('hasquiz', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'withdrawn');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026102503, 'moodlereader');
    }

    return true;
}
