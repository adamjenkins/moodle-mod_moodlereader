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
     * An icon link to a student's reading page, as the student sees it.
     *
     * @param int $cmid
     * @param int $userid
     * @param string $name The student's name, for the link's label.
     * @return string HTML.
     */
    public static function reading_page_link(int $cmid, int $userid, string $name): string {
        global $OUTPUT;
        $label = get_string('readingpagefor', 'mod_moodlereader', $name);
        return ' ' . html_writer::link(
            new \moodle_url('/mod/moodlereader/student.php', ['id' => $cmid, 'userid' => $userid]),
            $OUTPUT->pix_icon('i/preview', $label),
            ['title' => $label, 'class' => 'mr-readingpage']
        );
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
     * @param \stdClass $instance The activity, whose term start date "this term" counts from.
     * @return string HTML.
     */
    public static function term_select(\moodle_url $pageurl, string $term, \stdClass $instance): string {
        global $OUTPUT;
        $select = new \single_select(
            new \moodle_url($pageurl),
            'term',
            [
                'thisterm' => $instance->ignoredate
                    ? get_string('thistermsince', 'mod_moodlereader', userdate(
                        $instance->ignoredate,
                        get_string('strftimedate', 'langconfig')
                    ))
                    : get_string('thistermnostart', 'mod_moodlereader'),
                'allterms' => get_string('allterms', 'mod_moodlereader'),
            ],
            $term,
            null
        );
        $select->set_label(get_string('termshown', 'mod_moodlereader'), ['class' => 'me-1']);
        return $OUTPUT->render($select);
    }

    /**
     * The form applying an action to the selected rows (the rows' checkboxes join it by id).
     *
     * Each action has its own button, next to the one value it needs, so nothing is ambiguous.
     *
     * @param string $mode 'usersummary' or 'userdetailed'.
     * @param \moodle_url $pageurl
     * @param \stdClass $instance
     * @return string HTML.
     */
    public static function action_form(string $mode, \moodle_url $pageurl, \stdClass $instance): string {
        $str = fn($k) => get_string($k, 'mod_moodlereader');
        $button = fn(string $action, string $class = 'btn-secondary') => html_writer::tag(
            'button',
            $str('reportaction_' . $action),
            ['type' => 'submit', 'name' => 'reportaction', 'value' => $action, 'class' => "btn $class me-1 mb-1"]
        );
        $line = fn(string $content) => html_writer::div($content, 'd-flex flex-wrap align-items-center mb-3');
        $number = fn(string $name, string $label) => html_writer::label($label, 'mr-' . $name, true, ['class' => 'me-2']) .
            html_writer::empty_tag('input', [
                'type' => 'number', 'min' => 0, 'name' => $name, 'id' => 'mr-' . $name, 'class' => 'form-control w-auto me-2',
            ]);

        $inner = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $inner .= html_writer::tag(
            'legend',
            $str($mode === 'userdetailed' ? 'reportactions_attempts' : 'reportactions_students'),
            ['class' => 'h5']
        );
        if ($mode === 'userdetailed') {
            $inner .= $line(
                $button('pass', 'btn-outline-success') . $button('fail', 'btn-outline-danger') .
                $button('cheat', 'btn-outline-warning') . $button('uncheat', 'btn-outline-secondary')
            );
            $inner .= $line(
                $button('delete', 'btn-outline-danger') . $button('restore', 'btn-outline-secondary') .
                html_writer::span($str('reportaction_delete_help'), 'text-muted small')
            );
        } else {
            if (!empty($instance->levelcheck)) {
                $inner .= $line($number('levelvalue', $str('currentlevel')) . $button('currentlevel') .
                    html_writer::span($str('reportaction_currentlevel_help'), 'text-muted small'));
                $inner .= $line($number('stopvalue', $str('stoplevel')) . $button('stoplevel') .
                    html_writer::span($str('reportaction_stoplevel_help'), 'text-muted small'));
                $inner .= $line($button('promotionon', 'btn-outline-success') . $button('promotionoff', 'btn-outline-secondary'));
            }
            $inner .= $line(
                $number('goalvalue', $str('goaloverride')) . $button('goal') .
                html_writer::span($str('reportaction_goal_help'), 'text-muted small')
            );
            // Awarding credit opens a book search for the ticked students.
            $awardurl = new \moodle_url('/mod/moodlereader/award.php', ['id' => $pageurl->get_param('id')]);
            $inner .= $line(html_writer::tag('button', $str('reportaction_awardbook'), [
                'type' => 'submit', 'class' => 'btn btn-secondary me-1 mb-1',
                'formaction' => $awardurl->out(false), 'formmethod' => 'post',
            ]));
        }
        $inner .= html_writer::div(
            html_writer::label($str('reportaction_messagetext'), 'mr-messagetext', true, ['class' => 'd-block mb-1']) .
            html_writer::tag('textarea', '', [
                'name' => 'messagetext', 'id' => 'mr-messagetext', 'rows' => 2, 'cols' => 50, 'class' => 'form-control mb-1',
            ]) . $button('message', 'btn-primary'),
            'mb-1'
        );
        return html_writer::tag('form', html_writer::tag('fieldset', $inner, ['class' => 'border p-3 mt-3']), [
            'method' => 'post', 'action' => $pageurl, 'id' => 'mr-reportactions',
        ]);
    }
}
