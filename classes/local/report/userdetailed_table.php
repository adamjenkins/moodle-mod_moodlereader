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

namespace mod_moodlereader\local\report;

use html_writer;
use moodle_url;
use mod_moodlereader\local\attempt_admin;
use mod_moodlereader\local\reader;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/tablelib.php');

/**
 * The student detailed report: one row per finished attempt, with its result.
 *
 * Modelled on mod_reader's userdetailed report.
 *
 * @package    mod_moodlereader
 * @copyright  2013 Gordon Bateson (gordon.bateson@gmail.com)
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class userdetailed_table extends \table_sql {
    /** @var reader */
    protected $reader;

    /** @var bool Totals are in points rather than words. */
    protected $points;

    /**
     * Build the table.
     *
     * @param reader $reader
     * @param moodle_url $baseurl
     * @param bool $thisterm Show only attempts since the activity's "ignore before" date.
     * @param int $groupid
     * @param int $userid One student only, or 0 for all.
     * @param bool $showdeleted Include deleted attempts.
     * @param bool $canmanage Show the select column.
     */
    public function __construct(
        reader $reader,
        moodle_url $baseurl,
        bool $thisterm,
        int $groupid,
        int $userid,
        bool $showdeleted,
        bool $canmanage
    ) {
        parent::__construct('mod-moodlereader-userdetailed-' . $reader->get_cm()->id);
        $this->reader = $reader;
        $instance = $reader->get_instance();
        $this->points = !empty($instance->wordsorpoints);
        $unit = $this->points ? 'points' : 'words';

        $columns = [];
        $headers = [];
        if ($canmanage) {
            $columns[] = 'select';
            $headers[] = report::select_all_checkbox('mrattempts');
        }
        $more = [
            'fullname' => get_string('fullname'),
            'bookname' => get_string('book', 'mod_moodlereader'),
            'difficulty' => get_string('readinglevel', 'mod_moodlereader'),
            'bookcount' => get_string($unit, 'mod_moodlereader'),
            'timefinish' => get_string('date'),
            'duration' => get_string('duration', 'mod_moodlereader'),
            'percentgrade' => get_string('score', 'mod_moodlereader'),
            'result' => get_string('result', 'mod_moodlereader'),
            'totalsofar' => get_string('total' . $unit . 'sofar', 'mod_moodlereader'),
        ];
        $this->define_columns(array_merge($columns, array_keys($more)));
        $this->define_headers(array_merge($headers, array_values($more)));
        $this->define_baseurl($baseurl);
        $this->no_sorting('select');
        $this->no_sorting('result');
        $this->no_sorting('totalsofar');
        $this->sortable(true, 'timefinish', SORT_DESC);
        $this->collapsible(false);
        $this->set_attribute('class', 'generaltable mod-moodlereader-report');

        $field = $this->points ? 'points' : 'words';
        $since = $thisterm ? (int) $instance->ignoredate : 0;
        [$esql, $eparams] = attempt_admin::students_sql($reader, $groupid);
        $names = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;

        // What has counted so far: the books counted by attempts finished up to this one.
        $sofar = "(SELECT SUM(b2.$field)
                     FROM {moodlereader_attempts} a2
                     JOIN {moodlereader_books} b2 ON b2.id = a2.bookid
                    WHERE a2.readerid = a.readerid AND a2.userid = a.userid AND (a2.passed = 1 OR a2.credit = 1)
                          AND a2.deleted = 0 AND a2.cheated = 0
                          AND a2.timefinish >= :sofarsince AND a2.timefinish <= a.timefinish)";
        $fields = "a.id, a.userid, a.passed, a.credit, a.cheated, a.deleted, a.timestart, a.timefinish, a.percentgrade,
                   CASE WHEN a.timestart > 0 THEN a.timefinish - a.timestart ELSE 0 END AS duration,
                   b.name AS bookname, b.difficulty, b.$field AS bookcount, $sofar AS totalsofar, $names";
        $from = "{moodlereader_attempts} a
                 JOIN {moodlereader_books} b ON b.id = a.bookid
                 JOIN {user} u ON u.id = a.userid";
        $where = "a.readerid = :readerid AND a.state = 'finished' AND a.timefinish >= :since AND u.id IN ($esql)";
        $params = ['readerid' => $instance->id, 'since' => $since, 'sofarsince' => (int) $instance->ignoredate] + $eparams;
        if (!$showdeleted) {
            $where .= ' AND a.deleted = 0';
        }
        if ($userid) {
            $where .= ' AND a.userid = :userid';
            $params['userid'] = $userid;
        }
        $this->set_sql($fields, $from, $where, $params);
        $this->set_count_sql("SELECT COUNT(1) FROM $from WHERE $where", $params);
    }

    /**
     * The select checkbox.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_select($row) {
        return report::row_checkbox('attemptids[]', (int) $row->id, 'mrattempts', $row->bookname);
    }

    /**
     * The student's name, linking to their attempts only.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_fullname($row) {
        if ($this->is_downloading()) {
            return fullname($row);
        }
        $url = new moodle_url($this->baseurl, ['userid' => $row->userid]);
        return html_writer::link($url, fullname($row));
    }

    /**
     * The book title.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_bookname($row) {
        return $this->is_downloading() ? $row->bookname : format_string($row->bookname);
    }

    /**
     * The book's words or points.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_bookcount($row) {
        return report::format_total($row->bookcount, $this->points, $this->is_downloading());
    }

    /**
     * When the quiz was finished.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_timefinish($row) {
        return userdate($row->timefinish, get_string('strftimedatetimeshort', 'core_langconfig'));
    }

    /**
     * How long the quiz took.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_duration($row) {
        return !empty($row->credit) || (int) $row->duration <= 0 ? '-' : format_time((int) $row->duration);
    }

    /**
     * The quiz score.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_percentgrade($row) {
        return !empty($row->credit) ? '-' : round((float) $row->percentgrade) . '%';
    }

    /**
     * Passed, failed, cheated or credited, and whether deleted.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_result($row) {
        return report::result_label($row, $this->is_downloading());
    }

    /**
     * This term's running total, after this attempt.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_totalsofar($row) {
        return report::format_total($row->totalsofar, $this->points, $this->is_downloading());
    }
}
