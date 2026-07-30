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
 * Library callbacks for mod_moodlereader.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Declare which optional features the module supports.
 *
 * @param string $feature One of the FEATURE_* constants.
 * @return mixed True/false for a supported/unsupported boolean feature, or null.
 */
function moodlereader_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_COMPLETION_HAS_RULES:
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_GRADE_OUTCOMES:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return false;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;
        default:
            return null;
    }
}

/**
 * Create a new moodlereader instance.
 *
 * @param stdClass $data Form data.
 * @param \mod_moodlereader_mod_form|null $mform The form (unused).
 * @return int New instance id.
 */
function moodlereader_add_instance($data, $mform = null) {
    global $DB;
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->id = $DB->insert_record('moodlereader', $data);
    moodlereader_grade_item_update($data);
    return $data->id;
}

/**
 * Update an existing moodlereader instance.
 *
 * @param stdClass $data Form data (with course-module id in $data->coursemodule).
 * @param \mod_moodlereader_mod_form|null $mform The form (unused).
 * @return bool
 */
function moodlereader_update_instance($data, $mform = null) {
    global $DB;
    $data->timemodified = time();
    $data->id = $data->instance;
    $result = $DB->update_record('moodlereader', $data);
    moodlereader_grade_item_update($data);
    moodlereader_update_grades($data);
    return $result;
}

/**
 * Delete a moodlereader instance and its per-user data.
 *
 * @param int $id Instance id.
 * @return bool
 */
function moodlereader_delete_instance($id) {
    global $DB;
    if (!$instance = $DB->get_record('moodlereader', ['id' => $id])) {
        return false;
    }
    $params = ['readerid' => $id];
    $DB->delete_records('moodlereader_attempts', $params);
    $DB->delete_records('moodlereader_grades', $params);
    $DB->delete_records('moodlereader_levels', $params);
    $DB->delete_records('moodlereader_bookinstances', $params);
    $DB->delete_records('moodlereader', ['id' => $id]);
    moodlereader_grade_item_delete($instance);
    return true;
}

/**
 * Return the aggregate grade for one or all users in an instance.
 *
 * The grade is maxgrade * (words read in passed books / goal), capped at maxgrade.
 *
 * @param stdClass $instance The {moodlereader} record.
 * @param int $userid A single user, or 0 for all.
 * @return array Grades keyed by user id, each an object with userid/rawgrade/...
 */
function moodlereader_get_user_grades($instance, $userid = 0) {
    global $DB;

    if (empty($instance->goal) || $instance->maxgrade <= 0) {
        return [];
    }

    $countfield = empty($instance->wordsorpoints) ? 'b.words' : 'b.points';
    $params = ['readerid' => $instance->id, 'ignoredate' => $instance->ignoredate];
    $usersql = '';
    if ($userid) {
        $usersql = ' AND a.userid = :userid';
        $params['userid'] = $userid;
    }

    $sql = "SELECT a.userid,
                   SUM($countfield) AS totalcount,
                   MAX(a.timefinish) AS datesubmitted,
                   MAX(a.timemodified) AS dategraded
              FROM {moodlereader_attempts} a
              JOIN {moodlereader_books} b ON b.id = a.bookid
             WHERE a.readerid = :readerid
                   AND (a.passed = 1 OR a.credit = 1)
                   AND a.deleted = 0 AND a.cheated = 0
                   AND a.timefinish >= :ignoredate $usersql
          GROUP BY a.userid";

    $grades = [];
    foreach ($DB->get_records_sql($sql, $params) as $row) {
        $rawgrade = $instance->maxgrade * min(1, $row->totalcount / $instance->goal);
        $grades[$row->userid] = (object) [
            'userid'        => $row->userid,
            'rawgrade'      => round($rawgrade, 2),
            'datesubmitted' => $row->datesubmitted,
            'dategraded'    => $row->dategraded,
        ];
    }
    return $grades;
}

/**
 * Update the gradebook for one or all users in an instance.
 *
 * @param stdClass $instance The {moodlereader} record.
 * @param int $userid A single user, or 0 for all.
 * @param bool $nullifnone Insert a null grade when a user has none.
 * @return void
 */
function moodlereader_update_grades($instance, $userid = 0, $nullifnone = true) {
    $grades = moodlereader_get_user_grades($instance, $userid);
    if ($grades) {
        moodlereader_grade_item_update($instance, $grades);
    } else if ($userid && $nullifnone) {
        moodlereader_grade_item_update($instance, (object) ['userid' => $userid, 'rawgrade' => null]);
    } else {
        moodlereader_grade_item_update($instance);
    }
}

/**
 * Create or update the gradebook item for an instance.
 *
 * @param stdClass $instance The {moodlereader} record.
 * @param mixed $grades A grade object/array, 'reset', or null.
 * @return int GRADE_UPDATE_OK etc.
 */
function moodlereader_grade_item_update($instance, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = ['itemname' => $instance->name];
    if (property_exists($instance, 'cmidnumber')) {
        $params['idnumber'] = $instance->cmidnumber;
    }
    if ($instance->maxgrade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $instance->maxgrade;
        $params['grademin']  = 0;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }
    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }
    return grade_update('mod/moodlereader', $instance->course, 'mod', 'moodlereader', $instance->id, 0, $grades, $params);
}

/**
 * Delete the gradebook item for an instance.
 *
 * @param stdClass $instance The {moodlereader} record.
 * @return int
 */
function moodlereader_grade_item_delete($instance) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    return grade_update(
        'mod/moodlereader',
        $instance->course,
        'mod',
        'moodlereader',
        $instance->id,
        0,
        null,
        ['deleted' => 1]
    );
}

/**
 * Add the reset-course-form controls for this module.
 *
 * @param MoodleQuickForm $mform
 * @return void
 */
function moodlereader_reset_course_form_definition($mform) {
    $mform->addElement('header', 'moodlereaderheader', get_string('modulenameplural', 'mod_moodlereader'));
    $mform->addElement('advcheckbox', 'reset_moodlereader_attempts', get_string('resetattempts', 'mod_moodlereader'));
}

/**
 * Default reset selections.
 *
 * @param stdClass $course
 * @return array
 */
function moodlereader_reset_course_form_defaults($course) {
    return ['reset_moodlereader_attempts' => 1];
}

/**
 * Reset per-user data for all instances in a course.
 *
 * @param stdClass $data Reset form data.
 * @return array Status lines for the reset report.
 */
function moodlereader_reset_userdata($data) {
    global $DB;
    $status = [];
    if (empty($data->reset_moodlereader_attempts)) {
        return $status;
    }
    $instances = $DB->get_records('moodlereader', ['course' => $data->courseid]);
    foreach ($instances as $instance) {
        $params = ['readerid' => $instance->id];
        $DB->delete_records('moodlereader_attempts', $params);
        $DB->delete_records('moodlereader_grades', $params);
        $DB->delete_records('moodlereader_levels', $params);
        moodlereader_grade_item_update($instance, 'reset');
    }
    $status[] = [
        'component' => get_string('modulenameplural', 'mod_moodlereader'),
        'item'      => get_string('resetattempts', 'mod_moodlereader'),
        'error'     => false,
    ];
    return $status;
}

/**
 * Serve book-cover files through the Files API.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool False on failure; otherwise sends the file and exits.
 */
function moodlereader_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_MODULE || $filearea !== 'bookcover') {
        return false;
    }
    require_login($course, true, $cm);
    if (!has_capability('mod/moodlereader:viewbooks', $context)) {
        return false;
    }
    $relativepath = implode('/', $args);
    $fullpath = "/{$context->id}/mod_moodlereader/bookcover/$relativepath";
    $fs = get_file_storage();
    $file = $fs->get_file_by_hash(sha1($fullpath));
    if (!$file || $file->is_directory()) {
        return false;
    }
    // Book covers are curated (synced from mreader.org), so may be served inline.
    send_stored_file($file, 86400, 0, $forcedownload, $options);
}
