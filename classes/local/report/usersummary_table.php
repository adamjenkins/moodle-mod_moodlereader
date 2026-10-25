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
 * The student summary report: one row per student, with levels, goal, counts and totals.
 *
 * Modelled on mod_reader's usersummary report.
 *
 * @package    mod_moodlereader
 * @copyright  2013 Gordon Bateson (gordon.bateson@gmail.com)
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usersummary_table extends \table_sql {
    /** @var reader */
    protected $reader;

    /** @var bool Whether the select column and actions are shown. */
    protected $canmanage;

    /** @var bool Totals are in points rather than words. */
    protected $points;

    /** @var int The activity's default goal. */
    protected $defaultgoal;

    /**
     * Build the table.
     *
     * @param reader $reader
     * @param moodle_url $baseurl
     * @param bool $thisterm Count only attempts since the activity's "ignore before" date.
     * @param int $groupid
     * @param bool $canmanage
     */
    public function __construct(reader $reader, moodle_url $baseurl, bool $thisterm, int $groupid, bool $canmanage) {
        parent::__construct('mod-moodlereader-usersummary-' . $reader->get_cm()->id);
        $this->reader = $reader;
        $this->canmanage = $canmanage;
        $instance = $reader->get_instance();
        $this->points = !empty($instance->wordsorpoints);
        $this->defaultgoal = (int) $instance->goal;

        $columns = [];
        $headers = [];
        if ($canmanage) {
            $columns[] = 'select';
            $headers[] = report::select_all_checkbox('mrusers');
        }
        $columns[] = 'fullname';
        $headers[] = get_string('fullname');
        // Reading levels only matter when books are restricted to them.
        if (!empty($instance->levelcheck)) {
            foreach (['currentlevel', 'stoplevel', 'allowpromotion'] as $field) {
                $columns[] = $field;
                $headers[] = get_string($field, 'mod_moodlereader');
            }
        }
        $unit = $this->points ? 'points' : 'words';
        $more = [
            'goal' => get_string('goal_' . $unit, 'mod_moodlereader'),
            'progress' => get_string('progress', 'mod_moodlereader'),
            'countpassed' => get_string('countpassed', 'mod_moodlereader'),
            'countfailed' => get_string('countfailed', 'mod_moodlereader'),
            'countcheated' => get_string('countcheated', 'mod_moodlereader'),
            'averageduration' => get_string('averageduration', 'mod_moodlereader'),
            'averagegrade' => get_string('averagegrade', 'mod_moodlereader'),
            'totalthisterm' => get_string('total' . $unit . 'thisterm', 'mod_moodlereader'),
            'totalallterms' => get_string('total' . $unit . 'allterms', 'mod_moodlereader'),
        ];
        $this->define_columns(array_merge($columns, array_keys($more)));
        $this->define_headers(array_merge($headers, array_values($more)));
        $this->define_baseurl($baseurl);
        $this->no_sorting('select');
        $this->no_sorting('progress');
        $this->sortable(true, 'lastname', SORT_ASC);
        $this->collapsible(false);
        $this->set_attribute('class', 'generaltable mod-moodlereader-report');

        $readerid = $instance->id;
        $since = $thisterm ? (int) $instance->ignoredate : 0;
        [$esql, $eparams] = attempt_admin::students_sql($reader, $groupid);
        [$tthis, $pthis] = report::totals_sql($instance, (int) $instance->ignoredate, 'tt');
        [$tall, $pall] = report::totals_sql($instance, 0, 'ta');
        $names = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;

        $fields = "u.id, $names,
                   COALESCE(l.currentlevel, 0) AS currentlevel, COALESCE(l.stoplevel, 0) AS stoplevel,
                   COALESCE(l.allowpromotion, 1) AS allowpromotion, COALESCE(l.goal, 0) AS goal,
                   COALESCE(s.countpassed, 0) AS countpassed, COALESCE(s.countfailed, 0) AS countfailed,
                   COALESCE(s.countcheated, 0) AS countcheated,
                   COALESCE(s.averageduration, 0) AS averageduration, COALESCE(s.averagegrade, 0) AS averagegrade,
                   COALESCE(tt.total, 0) AS totalthisterm, COALESCE(ta.total, 0) AS totalallterms";
        $from = "{user} u
                 LEFT JOIN {moodlereader_levels} l ON l.readerid = :lreaderid AND l.userid = u.id
                 LEFT JOIN (SELECT a.userid,
                                   SUM(CASE WHEN a.passed = 1 AND a.cheated = 0 THEN 1 ELSE 0 END) AS countpassed,
                                   SUM(CASE WHEN a.passed = 0 AND a.cheated = 0 THEN 1 ELSE 0 END) AS countfailed,
                                   SUM(CASE WHEN a.cheated = 1 THEN 1 ELSE 0 END) AS countcheated,
                                   AVG(CASE WHEN a.timestart > 0 THEN a.timefinish - a.timestart ELSE NULL END)
                                       AS averageduration,
                                   AVG(a.percentgrade) AS averagegrade
                              FROM {moodlereader_attempts} a
                             WHERE a.readerid = :sreaderid AND a.state = 'finished' AND a.deleted = 0
                                   AND a.credit = 0 AND a.timefinish >= :ssince
                          GROUP BY a.userid) s ON s.userid = u.id
                 LEFT JOIN ($tthis) tt ON tt.userid = u.id
                 LEFT JOIN ($tall) ta ON ta.userid = u.id";
        $where = "u.id IN ($esql)";
        $params = ['lreaderid' => $readerid, 'sreaderid' => $readerid, 'ssince' => $since] + $pthis + $pall + $eparams;
        $this->set_sql($fields, $from, $where, $params);
        $this->set_count_sql("SELECT COUNT(1) FROM {user} u WHERE $where", $eparams);
    }

    /**
     * The select checkbox.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_select($row) {
        return report::row_checkbox('userids[]', (int) $row->id, 'mrusers', fullname($row));
    }

    /**
     * The student's name, linking to their attempts.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_fullname($row) {
        if ($this->is_downloading()) {
            return fullname($row);
        }
        $url = new moodle_url('/mod/moodlereader/report.php', [
            'id' => $this->reader->get_cm()->id, 'mode' => 'userdetailed', 'userid' => $row->id,
        ]);
        return html_writer::link($url, fullname($row));
    }

    /**
     * The stop level (0 means none).
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_stoplevel($row) {
        return (int) $row->stoplevel ? (string) (int) $row->stoplevel : get_string('none');
    }

    /**
     * Whether the student moves up a level automatically.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_allowpromotion($row) {
        return get_string($row->allowpromotion ? 'yes' : 'no');
    }

    /**
     * The goal, or the activity's default when the student has none.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_goal($row) {
        $goal = (int) $row->goal;
        if ($goal > 0) {
            return $this->is_downloading() ? (string) $goal : number_format($goal);
        }
        if ($this->is_downloading()) {
            return (string) $this->defaultgoal;
        }
        return html_writer::span(number_format($this->defaultgoal), 'text-muted', [
            'title' => get_string('defaultgoal', 'mod_moodlereader'),
        ]);
    }

    /**
     * A miniature progress bar: this term's total against the student's goal.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_progress($row) {
        $goal = (int) $row->goal > 0 ? (float) $row->goal : (float) $this->defaultgoal;
        if ($goal <= 0) {
            return '-';
        }
        if ($this->is_downloading()) {
            return (int) round(\mod_moodlereader\local\progressbar::percent((float) $row->totalthisterm, $goal)) . '%';
        }
        return \mod_moodlereader\local\progressbar::mini((float) $row->totalthisterm, $goal, $this->points);
    }

    /**
     * Average quiz time.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_averageduration($row) {
        return (int) $row->averageduration ? format_time((int) round($row->averageduration)) : '-';
    }

    /**
     * Average quiz grade.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_averagegrade($row) {
        return (int) $row->countpassed + (int) $row->countfailed + (int) $row->countcheated
            ? round((float) $row->averagegrade) . '%' : '-';
    }

    /**
     * This term's total.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_totalthisterm($row) {
        return report::format_total($row->totalthisterm, $this->points, $this->is_downloading());
    }

    /**
     * The total of all terms.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_totalallterms($row) {
        return report::format_total($row->totalallterms, $this->points, $this->is_downloading());
    }
}
