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
use mod_moodlereader\local\attempt_admin;

/**
 * Helpers shared by the student reports.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report {
    /**
     * SQL totalling the words (or points) of each student's counted books since a time.
     *
     * Counts each book once per student, exactly as the grade does
     * ({@see moodlereader_get_user_grades()}).
     *
     * @param \stdClass $instance The {moodlereader} record.
     * @param int $since
     * @param string $prefix Parameter-name prefix, unique within the query.
     * @return array [string $sql (columns userid, total), array $params]
     */
    public static function totals_sql(\stdClass $instance, int $since, string $prefix): array {
        $field = empty($instance->wordsorpoints) ? 'words' : 'points';
        $count = empty($instance->bookinstances) ? "b.$field" : "COALESCE(bi.$field, b.$field)";
        $bijoin = empty($instance->bookinstances) ? ''
            : "LEFT JOIN {moodlereader_bookinstances} bi ON bi.bookid = b.id AND bi.readerid = a.readerid";
        $sql = "SELECT perbook.userid, SUM(perbook.bookcount) AS total
                  FROM (SELECT a.userid, a.bookid, MAX($count) AS bookcount
                          FROM {moodlereader_attempts} a
                          JOIN {moodlereader_books} b ON b.id = a.bookid
                          $bijoin
                         WHERE a.readerid = :{$prefix}readerid AND (a.passed = 1 OR a.credit = 1)
                               AND a.deleted = 0 AND a.cheated = 0 AND a.timefinish >= :{$prefix}since
                      GROUP BY a.userid, a.bookid) perbook
              GROUP BY perbook.userid";
        return [$sql, ["{$prefix}readerid" => $instance->id, "{$prefix}since" => $since]];
    }

    /**
     * Format a words or points total.
     *
     * @param mixed $value
     * @param bool $points
     * @param bool $downloading Plain numbers for a download.
     * @return string
     */
    public static function format_total($value, bool $points, bool $downloading): string {
        $value = $points ? round((float) $value, 2) : (int) $value;
        if ($downloading) {
            return (string) $value;
        }
        return $points ? format_float($value, 2, true, true) : number_format($value);
    }

    /**
     * The "select all" checkbox for a report column.
     *
     * @param string $group The toggle group shared with the row checkboxes.
     * @return string
     */
    public static function select_all_checkbox(string $group): string {
        return html_writer::empty_tag('input', [
            'type' => 'checkbox', 'data-action' => 'toggle', 'data-toggle' => 'toggler',
            'data-togglegroup' => $group, 'aria-label' => get_string('selectall'),
            'data-toggle-selectall' => get_string('selectall'),
            'data-toggle-deselectall' => get_string('deselectall'),
        ]);
    }

    /**
     * One row's checkbox.
     *
     * @param string $name Form field name, e.g. 'userids[]'.
     * @param int $value
     * @param string $group The toggle group.
     * @param string $label Accessible label.
     * @return string
     */
    public static function row_checkbox(string $name, int $value, string $group, string $label): string {
        $id = 'mr-select-' . $group . '-' . $value;
        return html_writer::label(s($label), $id, false, ['class' => 'accesshide']) .
            html_writer::empty_tag('input', [
                'type' => 'checkbox', 'name' => $name, 'value' => $value, 'form' => 'mr-reportactions', 'id' => $id,
                'data-action' => 'toggle', 'data-toggle' => 'target', 'data-togglegroup' => $group,
            ]);
    }

    /**
     * An attempt's result as a short label.
     *
     * @param \stdClass $row With passed, credit, cheated and deleted.
     * @param bool $plain Text without markup (for downloads).
     * @return string
     */
    public static function result_label(\stdClass $row, bool $plain): string {
        if (!empty($row->cheated)) {
            [$key, $class] = ['cheated', 'bg-warning text-dark'];
        } else if (!empty($row->credit)) {
            [$key, $class] = ['credited', 'bg-info text-dark'];
        } else if (!empty($row->passed)) {
            [$key, $class] = ['passed', 'bg-success'];
        } else {
            [$key, $class] = ['failed', 'bg-danger'];
        }
        $labels = [get_string($key, 'mod_moodlereader')];
        if (!empty($row->deleted)) {
            $labels[] = get_string('deleted', 'mod_moodlereader');
        }
        if ($plain) {
            return implode(', ', $labels);
        }
        $out = html_writer::span(s($labels[0]), 'badge ' . $class);
        if (!empty($row->deleted)) {
            $out .= ' ' . html_writer::span(s($labels[1]), 'badge bg-secondary');
        }
        return $out;
    }

    /**
     * The this-term / all-terms selector.
     *
     * @param \moodle_url $pageurl
     * @param string $term
     * @return string HTML.
     */
    public static function term_select(\moodle_url $pageurl, string $term): string {
        global $OUTPUT;
        $select = new \single_select(
            new \moodle_url($pageurl),
            'term',
            [
                'thisterm' => get_string('thisterm', 'mod_moodlereader'),
                'allterms' => get_string('allterms', 'mod_moodlereader'),
            ],
            $term,
            null
        );
        $select->set_label(get_string('termshown', 'mod_moodlereader'), ['class' => 'mr-1']);
        return $OUTPUT->render($select);
    }

    /**
     * The form applying an action to the selected rows (the rows' checkboxes join it by id).
     *
     * @param string $mode 'usersummary' or 'userdetailed'.
     * @param \moodle_url $pageurl
     * @param \stdClass $instance
     * @return string HTML.
     */
    public static function action_form(string $mode, \moodle_url $pageurl, \stdClass $instance): string {
        $str = fn($k) => get_string($k, 'mod_moodlereader');
        if ($mode === 'userdetailed') {
            $actions = [];
            foreach (attempt_admin::ATTEMPT_ACTIONS as $a) {
                $actions[$a] = $str('reportaction_' . $a);
            }
            $actions['message'] = $str('reportaction_message');
        } else {
            $actions = [];
            if (!empty($instance->levelcheck)) {
                foreach (['startlevel', 'currentlevel', 'stoplevel'] as $a) {
                    $actions[$a] = $str('reportaction_' . $a);
                }
            }
            $actions['goal'] = $str('reportaction_goal');
            $actions['awardbook'] = $str('reportaction_awardbook');
            $actions['message'] = $str('reportaction_message');
        }

        $inner = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $inner .= html_writer::tag('legend', $str('reportactions'), ['class' => 'h5']);
        $inner .= html_writer::div(
            html_writer::label($str('reportactionlabel'), 'mr-reportaction', true, ['class' => 'mr-2']) .
            html_writer::select($actions, 'reportaction', '', ['' => 'choosedots'], [
                'id' => 'mr-reportaction', 'class' => 'form-select w-auto', 'required' => 'required',
            ]),
            'mb-2 d-flex align-items-center'
        );
        if ($mode === 'usersummary') {
            $inner .= html_writer::div(
                html_writer::label($str('reportactionvalue'), 'mr-levelvalue', true, ['class' => 'mr-2']) .
                html_writer::empty_tag('input', [
                    'type' => 'number', 'min' => 0, 'name' => 'levelvalue', 'id' => 'mr-levelvalue',
                    'class' => 'form-control w-auto',
                ]) .
                html_writer::span($str('reportactionvalue_help'), 'text-muted small ml-2'),
                'mb-2 d-flex align-items-center'
            );
            $inner .= html_writer::div(
                html_writer::label($str('awardbooktitle'), 'mr-awardbooktitle', true, ['class' => 'mr-2']) .
                html_writer::empty_tag('input', [
                    'type' => 'text', 'name' => 'awardbooktitle', 'id' => 'mr-awardbooktitle',
                    'class' => 'form-control w-auto', 'size' => 40,
                ]),
                'mb-2 d-flex align-items-center'
            );
        }
        $inner .= html_writer::div(
            html_writer::label($str('reportaction_message'), 'mr-messagetext', true, ['class' => 'mr-2']) .
            html_writer::tag('textarea', '', [
                'name' => 'messagetext', 'id' => 'mr-messagetext', 'rows' => 2, 'cols' => 50, 'class' => 'form-control',
            ]),
            'mb-2'
        );
        $inner .= html_writer::tag('button', $str('reportapply'), ['type' => 'submit', 'class' => 'btn btn-primary']);
        return html_writer::tag('form', html_writer::tag('fieldset', $inner, ['class' => 'border p-3 mt-3']), [
            'method' => 'post', 'action' => $pageurl, 'id' => 'mr-reportactions',
        ]);
    }
}
