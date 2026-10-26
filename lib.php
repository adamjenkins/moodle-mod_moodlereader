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
            return true;
        case FEATURE_GRADE_OUTCOMES:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
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
    moodlereader_clean_seb($data, context_course::instance($data->course));
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->maxgrade = moodlereader_maxgrade_from_form($data);
    $data->id = $DB->insert_record('moodlereader', $data);
    moodlereader_grade_item_update($data);
    return $data->id;
}

/**
 * Keep the Safe Exam Browser settings only from someone who may set them, and in their allowed form.
 *
 * @param stdClass $data Form data.
 * @param context $context
 * @return void
 */
function moodlereader_clean_seb($data, $context) {
    $fields = ['seb', 'sebmethod', 'sebkeys', 'sebquitpassword'];
    if (!\mod_moodlereader\local\seb::teachers_decide() || !has_capability('mod/moodlereader:manageseb', $context)) {
        foreach ($fields as $field) {
            unset($data->$field);
        }
        return;
    }
    if (isset($data->seb)) {
        $data->seb = in_array((int) $data->seb, [1, 2], true) ? (int) $data->seb : 0;
    }
    if (isset($data->sebmethod)) {
        $data->sebmethod = (int) $data->sebmethod === 1 ? 1 : 0;
    }
    if (isset($data->sebkeys)) {
        $data->sebkeys = implode("\n", \mod_moodlereader\local\seb::split_keys((string) $data->sebkeys));
    }
}

/**
 * Resolve the maxgrade column from the standard grade form element.
 *
 * The grade element yields a positive point value, a negative scale id, or 0
 * (no grade). This plugin grades by word count, so scales are not supported and
 * are treated as "no grade".
 *
 * @param stdClass $data Form data.
 * @return int
 */
function moodlereader_maxgrade_from_form($data) {
    if (!isset($data->grade)) {
        // No grade element (e.g. programmatic creation): keep any maxgrade already set.
        return isset($data->maxgrade) ? (int) $data->maxgrade : 100;
    }
    return $data->grade > 0 ? (int) $data->grade : 0;
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
    $data->maxgrade = moodlereader_maxgrade_from_form($data);
    moodlereader_clean_seb(
        $data,
        empty($data->coursemodule) ? context_course::instance($data->course) : context_module::instance($data->coursemodule)
    );
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
    $DB->delete_records('moodlereader_cheatlog', $params);
    $DB->delete_records('moodlereader_messages', $params);
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

    // Per-instance overrides take precedence over the catalogue values when set.
    if (empty($instance->wordsorpoints)) {
        $countfield = empty($instance->bookinstances) ? 'b.words' : 'COALESCE(bi.words, b.words)';
    } else {
        $countfield = empty($instance->bookinstances) ? 'b.points' : 'COALESCE(bi.points, b.points)';
    }
    $bijoin = empty($instance->bookinstances) ? ''
        : 'LEFT JOIN {moodlereader_bookinstances} bi ON bi.bookid = b.id AND bi.readerid = a.readerid';

    $params = ['readerid' => $instance->id, 'ignoredate' => $instance->ignoredate];
    $usersql = '';
    if ($userid) {
        $usersql = ' AND a.userid = :userid';
        $params['userid'] = $userid;
    }

    // Inner query counts each book once per user (a re-passed book is not doubled);
    // the outer query aggregates per user.
    $sql = "SELECT userid,
                   SUM(bookcount) AS totalcount,
                   MAX(datesubmitted) AS datesubmitted,
                   MAX(dategraded) AS dategraded
              FROM (SELECT a.userid,
                           a.bookid,
                           MAX($countfield) AS bookcount,
                           MAX(a.timefinish) AS datesubmitted,
                           MAX(a.timemodified) AS dategraded
                      FROM {moodlereader_attempts} a
                      JOIN {moodlereader_books} b ON b.id = a.bookid
                      $bijoin
                     WHERE a.readerid = :readerid
                           AND (a.passed = 1 OR a.credit = 1)
                           AND a.deleted = 0 AND a.cheated = 0
                           AND a.timefinish >= :ignoredate $usersql
                  GROUP BY a.userid, a.bookid) perbook
          GROUP BY userid";

    // Per-user reading-goal overrides (0 = use the activity default).
    $goaloverrides = $DB->get_records_menu(
        'moodlereader_levels',
        ['readerid' => $instance->id],
        '',
        'userid, goal'
    );

    $grades = [];
    foreach ($DB->get_records_sql($sql, $params) as $row) {
        $goal = !empty($goaloverrides[$row->userid]) ? $goaloverrides[$row->userid] : $instance->goal;
        $rawgrade = $instance->maxgrade * min(1, $row->totalcount / $goal);
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
    global $DB;
    $grades = moodlereader_get_user_grades($instance, $userid);

    // Maintain the cached moodlereader_grades rows so exports/reports match the gradebook.
    $selector = ['readerid' => $instance->id];
    if ($userid) {
        $selector['userid'] = $userid;
    }
    $DB->delete_records('moodlereader_grades', $selector);
    foreach ($grades as $grade) {
        $DB->insert_record('moodlereader_grades', (object) [
            'readerid'      => $instance->id,
            'userid'        => $grade->userid,
            'rawgrade'      => $grade->rawgrade,
            'datesubmitted' => $grade->datesubmitted ?? 0,
            'dategraded'    => $grade->dategraded ?? 0,
        ]);
    }

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
        $DB->delete_records('moodlereader_cheatlog', $params);
        $DB->delete_records('moodlereader_messages', $params);
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
    if ($context->contextlevel != CONTEXT_MODULE || !in_array($filearea, ['bookcover', 'intro'], true)) {
        return false;
    }
    require_login($course, true, $cm);
    if (!has_capability('mod/moodlereader:viewbooks', $context)) {
        return false;
    }
    $relativepath = implode('/', $args);
    $fullpath = "/{$context->id}/mod_moodlereader/$filearea/$relativepath";
    $fs = get_file_storage();
    $file = $fs->get_file_by_hash(sha1($fullpath));
    if (!$file || $file->is_directory()) {
        return false;
    }
    // Intro media is teacher content. A cover is served inline only when it is a raster image,
    // never SVG or anything else that can carry script.
    $raster = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if ($filearea === 'bookcover' && !in_array($file->get_mimetype(), $raster, true)) {
        $forcedownload = true;
    }
    send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
}

/**
 * Expose custom completion rules and intro to the course-module cache.
 *
 * @param stdClass $coursemodule
 * @return cached_cm_info|null
 */
function moodlereader_get_coursemodule_info($coursemodule) {
    global $DB;

    $fields = 'id, name, intro, introformat, completionpass, completiontotalwords';
    if (!$instance = $DB->get_record('moodlereader', ['id' => $coursemodule->instance], $fields)) {
        return null;
    }

    $info = new cached_cm_info();
    $info->name = $instance->name;
    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('moodlereader', $instance, $coursemodule->id, false);
    }

    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completionpass'] = (int) $instance->completionpass;
        $info->customdata['customcompletionrules']['completiontotalwords'] = (int) $instance->completiontotalwords;
    }

    return $info;
}

/**
 * Add the course's Safe Exam Browser page to its More menu, for teachers who may set it.
 *
 * @param navigation_node $navigation The course settings node.
 * @param stdClass $course
 * @param context $context The course context.
 * @return void
 */
function moodlereader_extend_navigation_course($navigation, $course, $context) {
    if (!\mod_moodlereader\local\seb::teachers_decide() || !has_capability('mod/moodlereader:manageseb', $context)) {
        return;
    }
    if (empty(get_fast_modinfo($course)->get_instances_of('moodlereader'))) {
        return;
    }
    $navigation->add_node(navigation_node::create(
        get_string('sebcourse', 'mod_moodlereader'),
        new moodle_url('/mod/moodlereader/sebcourse.php', ['id' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'moodlereaderseb',
        new pix_icon('i/settings', '')
    ));
}
