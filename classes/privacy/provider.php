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

namespace mod_moodlereader\privacy;

use context;
use context_module;
use context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\helper;

/**
 * Privacy provider for mod_moodlereader.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('moodlereader_attempts', [
            'bookid'       => 'privacy:metadata:moodlereader_attempts:bookid',
            'percentgrade' => 'privacy:metadata:moodlereader_attempts:percentgrade',
            'timestart'    => 'privacy:metadata:moodlereader_attempts:timestart',
            'timefinish'   => 'privacy:metadata:moodlereader_attempts:timefinish',
            'ip'           => 'privacy:metadata:moodlereader_attempts:ip',
        ], 'privacy:metadata:moodlereader_attempts');

        $collection->add_database_table('moodlereader_cheatlog', [
            'userid' => 'privacy:metadata:moodlereader_cheatlog:userid',
            'reason' => 'privacy:metadata:moodlereader_cheatlog:reason',
        ], 'privacy:metadata:moodlereader_cheatlog');

        $collection->add_database_table('moodlereader_messages', [
            'messagetext' => 'privacy:metadata:moodlereader_messages:messagetext',
        ], 'privacy:metadata:moodlereader_messages');

        $collection->add_database_table('moodlereader_grades', [
            'rawgrade' => 'privacy:metadata:moodlereader_grades:rawgrade',
        ], 'privacy:metadata:moodlereader_grades');

        $collection->add_database_table('moodlereader_levels', [
            'currentlevel' => 'privacy:metadata:moodlereader_levels:currentlevel',
        ], 'privacy:metadata:moodlereader_levels');

        $collection->add_database_table('moodlereader_users', [
            'uniqueid' => 'privacy:metadata:moodlereader_users:uniqueid',
        ], 'privacy:metadata:moodlereader_users');

        $collection->add_external_location_link('mreader.org', [
            'uname' => 'privacy:metadata:mreader.org:uname',
            'book'  => 'privacy:metadata:mreader.org:book',
        ], 'privacy:metadata:mreader.org');

        $collection->link_subsystem('core_grades', 'privacy:metadata:core_grades');

        return $collection;
    }

    /**
     * Contexts that hold data for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Per-module data (attempts, grades, levels).
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :modulelevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'moodlereader'
                  JOIN {moodlereader} r ON r.id = cm.instance
             LEFT JOIN {moodlereader_attempts} a ON a.readerid = r.id AND a.userid = :uid1
             LEFT JOIN {moodlereader_grades} g ON g.readerid = r.id AND g.userid = :uid2
             LEFT JOIN {moodlereader_levels} l ON l.readerid = r.id AND l.userid = :uid3
             LEFT JOIN {moodlereader_cheatlog} c ON c.readerid = r.id AND (c.userid = :uid4 OR c.otheruserid = :uid5)
             LEFT JOIN {moodlereader_messages} msg ON msg.readerid = r.id AND msg.userid = :uid6
                 WHERE a.id IS NOT NULL OR g.id IS NOT NULL OR l.id IS NOT NULL
                       OR c.id IS NOT NULL OR msg.id IS NOT NULL";
        $contextlist->add_from_sql($sql, [
            'modulelevel' => CONTEXT_MODULE,
            'uid1' => $userid, 'uid2' => $userid, 'uid3' => $userid,
            'uid4' => $userid, 'uid5' => $userid, 'uid6' => $userid,
        ]);

        // Site-wide identity map lives in the user's own context.
        global $DB;
        if ($DB->record_exists('moodlereader_users', ['userid' => $userid])) {
            $contextlist->add_user_context($userid);
        }

        return $contextlist;
    }

    /**
     * Users who have data in a context.
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if ($context instanceof context_module) {
            $params = ['cmid' => $context->instanceid];
            $sql = "SELECT %s
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module AND m.name = 'moodlereader'
                      JOIN {moodlereader} r ON r.id = cm.instance
                      JOIN {%s} t ON t.readerid = r.id
                     WHERE cm.id = :cmid";
            foreach (
                ['moodlereader_attempts', 'moodlereader_grades', 'moodlereader_levels',
                    'moodlereader_messages'] as $table
            ) {
                $userlist->add_from_sql('userid', sprintf($sql, 't.userid', $table), $params);
            }
            // Cheat-log rows reference two users.
            $cheatsql = "SELECT %s
                           FROM {course_modules} cm
                           JOIN {modules} m ON m.id = cm.module AND m.name = 'moodlereader'
                           JOIN {moodlereader} r ON r.id = cm.instance
                           JOIN {moodlereader_cheatlog} t ON t.readerid = r.id
                          WHERE cm.id = :cmid";
            $userlist->add_from_sql('userid', sprintf($cheatsql, 't.userid'), $params);
            $userlist->add_from_sql('otheruserid', sprintf($cheatsql, 't.otheruserid'), $params);
        } else if ($context instanceof context_user) {
            global $DB;
            if ($DB->record_exists('moodlereader_users', ['userid' => $context->instanceid])) {
                $userlist->add_user($context->instanceid);
            }
        }
    }

    /**
     * Export a user's data for the approved contexts.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_module) {
                $cm = get_coursemodule_from_id('moodlereader', $context->instanceid);
                if (!$cm) {
                    continue;
                }
                $data = helper::get_context_data($context, $contextlist->get_user());
                helper::export_context_files($context, $contextlist->get_user());

                $attempts = $DB->get_records(
                    'moodlereader_attempts',
                    ['readerid' => $cm->instance, 'userid' => $userid]
                );
                $data->attempts = array_values(array_map(function ($a) {
                    return [
                        'bookid'       => $a->bookid,
                        'percentgrade' => $a->percentgrade,
                        'passed'       => $a->passed,
                        'timestart'    => $a->timestart ? \core_privacy\local\request\transform::datetime($a->timestart) : null,
                        'timefinish'   => $a->timefinish ? \core_privacy\local\request\transform::datetime($a->timefinish) : null,
                    ];
                }, $attempts));

                if ($level = $DB->get_record('moodlereader_levels', ['readerid' => $cm->instance, 'userid' => $userid])) {
                    $data->currentlevel = $level->currentlevel;
                }
                if ($grade = $DB->get_record('moodlereader_grades', ['readerid' => $cm->instance, 'userid' => $userid])) {
                    $data->grade = $grade->rawgrade;
                }

                $cheats = $DB->get_records_select(
                    'moodlereader_cheatlog',
                    'readerid = :readerid AND (userid = :uid1 OR otheruserid = :uid2)',
                    ['readerid' => $cm->instance, 'uid1' => $userid, 'uid2' => $userid]
                );
                $data->flaggedforcheating = array_values(array_map(fn($c) => [
                    'reason'      => $c->reason,
                    'timecreated' => \core_privacy\local\request\transform::datetime($c->timecreated),
                ], $cheats));

                $messages = $DB->get_records(
                    'moodlereader_messages',
                    ['readerid' => $cm->instance, 'userid' => $userid]
                );
                $data->messages = array_values(array_map(fn($m) => [
                    'message'     => format_text($m->messagetext, $m->messageformat, ['context' => $context]),
                    'timecreated' => \core_privacy\local\request\transform::datetime($m->timecreated),
                ], $messages));

                writer::with_context($context)->export_data([], $data);
            } else if ($context instanceof context_user && $context->instanceid == $userid) {
                if ($identity = $DB->get_record('moodlereader_users', ['userid' => $userid])) {
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'mod_moodlereader')],
                        (object) ['uniqueid' => $identity->uniqueid]
                    );
                }
            }
        }
    }

    /**
     * Delete all users' data in a context.
     *
     * @param context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;
        if (!$context instanceof context_module) {
            return;
        }
        if (!$cm = get_coursemodule_from_id('moodlereader', $context->instanceid)) {
            return;
        }
        $params = ['readerid' => $cm->instance];
        $DB->delete_records('moodlereader_attempts', $params);
        $DB->delete_records('moodlereader_grades', $params);
        $DB->delete_records('moodlereader_levels', $params);
        $DB->delete_records('moodlereader_cheatlog', $params);
        $DB->delete_records('moodlereader_messages', $params);
    }

    /**
     * Delete one user's data across the approved contexts.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_module) {
                if (!$cm = get_coursemodule_from_id('moodlereader', $context->instanceid)) {
                    continue;
                }
                $params = ['readerid' => $cm->instance, 'userid' => $userid];
                $DB->delete_records('moodlereader_attempts', $params);
                $DB->delete_records('moodlereader_grades', $params);
                $DB->delete_records('moodlereader_levels', $params);
                $DB->delete_records('moodlereader_messages', $params);
                $DB->delete_records_select(
                    'moodlereader_cheatlog',
                    'readerid = :readerid AND (userid = :uid1 OR otheruserid = :uid2)',
                    ['readerid' => $cm->instance, 'uid1' => $userid, 'uid2' => $userid]
                );
            } else if ($context instanceof context_user && $context->instanceid == $userid) {
                $DB->delete_records('moodlereader_users', ['userid' => $userid]);
            }
        }
    }

    /**
     * Delete data for a list of users in a context.
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }

        if ($context instanceof context_module) {
            if (!$cm = get_coursemodule_from_id('moodlereader', $context->instanceid)) {
                return;
            }
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $params = ['readerid' => $cm->instance] + $inparams;
            foreach (
                ['moodlereader_attempts', 'moodlereader_grades', 'moodlereader_levels',
                    'moodlereader_messages'] as $table
            ) {
                $DB->delete_records_select($table, "readerid = :readerid AND userid $insql", $params);
            }
            $DB->delete_records_select(
                'moodlereader_cheatlog',
                "readerid = :readerid AND (userid $insql OR otheruserid $insql)",
                $params
            );
        } else if ($context instanceof context_user) {
            if (in_array($context->instanceid, $userids)) {
                $DB->delete_records('moodlereader_users', ['userid' => $context->instanceid]);
            }
        }
    }
}
