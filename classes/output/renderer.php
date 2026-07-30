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

namespace mod_moodlereader\output;

use html_writer;
use moodle_url;
use mod_moodlereader\local\reader;

/**
 * Renderer for mod_moodlereader.
 *
 * All dynamic values are escaped at the point of output (s()/format_string()).
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /**
     * The teacher messages currently visible to a user.
     *
     * @param reader $reader
     * @param int $userid
     * @return string HTML.
     */
    public function messages(reader $reader, int $userid): string {
        $messages = \mod_moodlereader\local\message::for_user($reader->get_instance()->id, $userid);
        if (!$messages) {
            return '';
        }
        $out = '';
        foreach ($messages as $message) {
            // The stored HTML is sanitised by format_text() on output.
            $body = format_text(
                $message->messagetext,
                $message->messageformat,
                ['context' => $reader->get_context()]
            );
            $out .= $this->output->notification($body, 'info', false);
        }
        return html_writer::div($out, 'mod-moodlereader-messages mb-3');
    }

    /**
     * The reading-progress panel for a user.
     *
     * @param reader $reader
     * @param int $userid
     * @return string HTML.
     */
    public function progress(reader $reader, int $userid): string {
        global $DB;
        $instance = $reader->get_instance();
        $goal = (int) $instance->goal;

        $countfield = empty($instance->wordsorpoints) ? 'b.words' : 'b.points';
        // Count each passed book once, honouring ignoredate — matches the gradebook.
        $total = (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(bookcount), 0) FROM (
                SELECT MAX($countfield) AS bookcount
                  FROM {moodlereader_attempts} a
                  JOIN {moodlereader_books} b ON b.id = a.bookid
                 WHERE a.readerid = :readerid AND a.userid = :userid
                       AND (a.passed = 1 OR a.credit = 1) AND a.deleted = 0 AND a.cheated = 0
                       AND a.timefinish >= :ignoredate
              GROUP BY a.bookid) perbook",
            ['readerid' => $instance->id, 'userid' => $userid, 'ignoredate' => $instance->ignoredate]
        );

        $percent = $goal > 0 ? min(100, (int) round(100 * $total / $goal)) : 0;
        $label = get_string('progresslabel', 'mod_moodlereader', [
            'total' => number_format($total),
            'goal'  => number_format($goal),
        ]);

        $out = html_writer::tag('h3', s($label));
        if (!empty($instance->showprogressbar) && $goal > 0) {
            $bar = html_writer::div('', 'progress-bar', [
                'role' => 'progressbar', 'style' => "width: {$percent}%",
                'aria-valuenow' => $percent, 'aria-valuemin' => 0, 'aria-valuemax' => 100,
            ]);
            $out .= html_writer::div($bar, 'progress', ['style' => 'height: 1.5rem;']);
        }
        return html_writer::div($out, 'mod-moodlereader-progress mb-4');
    }

    /**
     * The list of books the user may attempt, each with a "take quiz" button.
     *
     * @param reader $reader
     * @param \stdClass[] $books
     * @return string HTML.
     */
    public function book_list(reader $reader, array $books): string {
        if (!$books) {
            return $this->output->notification(get_string('nobooksavailable', 'mod_moodlereader'), 'info');
        }

        $rows = [];
        foreach ($books as $book) {
            $form = html_writer::start_tag('form', [
                'method' => 'post',
                'action' => new moodle_url('/mod/moodlereader/startquiz.php'),
            ]);
            $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $reader->get_cm()->id]);
            $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'book', 'value' => $book->id]);
            $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $form .= html_writer::tag(
                'button',
                get_string('takequiz', 'mod_moodlereader'),
                ['type' => 'submit', 'class' => 'btn btn-primary btn-sm']
            );
            $form .= html_writer::end_tag('form');

            $rows[] = html_writer::tag(
                'tr',
                html_writer::tag('td', s($book->name)) .
                html_writer::tag('td', s($book->publisher)) .
                html_writer::tag('td', s($book->series)) .
                html_writer::tag('td', s(number_format((int) $book->words))) .
                html_writer::tag('td', $form)
            );
        }

        $head = html_writer::tag(
            'tr',
            html_writer::tag('th', get_string('book', 'mod_moodlereader')) .
            html_writer::tag('th', get_string('publisher', 'mod_moodlereader')) .
            html_writer::tag('th', get_string('series', 'mod_moodlereader')) .
            html_writer::tag('th', get_string('words', 'mod_moodlereader')) .
            html_writer::tag('th', '')
        );

        return html_writer::tag(
            'table',
            html_writer::tag('thead', $head) . html_writer::tag('tbody', implode('', $rows)),
            ['class' => 'table table-striped generaltable']
        );
    }
}
