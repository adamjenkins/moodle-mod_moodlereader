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

use html_table;
use html_writer;
use moodle_url;
use stdClass;
use mod_moodlereader\local\booklanguage;
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
     * Genre codes used by mreader.org mapped to their display labels.
     *
     * @var string[]
     */
    const GENRES = [
        'ad' => 'Adventure', 'an' => 'Animals', 'bi' => 'Biography', 'bu' => 'Business',
        'ch' => "Children's literature", 'cl' => 'Classics', 'co' => 'Comedy', 'cu' => 'Culture',
        'fa' => 'Fantasy', 'ge' => 'Geography / Environment', 'gr' => 'Graphic novel',
        'hf' => 'Historical fiction', 'hi' => 'Historical', 'ho' => 'Horror', 'hu' => 'Human interest',
        'li' => 'Literature in translation', 'mo' => 'Movies', 'mu' => 'Murder mystery',
        'pu' => 'Puzzle', 'ro' => 'Romance', 'sc' => 'Science fiction', 'sh' => 'Short stories',
        'sp' => 'Sports', 'te' => 'Technology & science', 'th' => 'Thriller',
        'yc' => 'Young children', 'yo' => 'Young life / adventure',
    ];

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
     * The words counted so far for a user (each passed book once, honouring ignoredate).
     *
     * @param reader $reader
     * @param int $userid
     * @return int
     */
    protected function words_read(reader $reader, int $userid): int {
        global $DB;
        $instance = $reader->get_instance();
        $countfield = empty($instance->wordsorpoints) ? 'b.words' : 'b.points';
        return (int) $DB->get_field_sql(
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
    }

    /**
     * The reading-progress panel for a user.
     *
     * @param reader $reader
     * @param int $userid
     * @return string HTML.
     */
    public function progress(reader $reader, int $userid): string {
        $instance = $reader->get_instance();
        $goal = $reader->get_goal($userid);
        $total = $this->words_read($reader, $userid);

        $percent = $goal > 0 ? min(100, (int) round(100 * $total / $goal)) : 0;
        $label = get_string('progresslabel', 'mod_moodlereader', [
            'total' => number_format($total),
            'goal'  => number_format($goal),
        ]);

        $out = html_writer::tag('h3', s($label));
        if (!empty($instance->showprogressbar) && $goal > 0) {
            $bar = html_writer::div(s($percent . '%'), 'progress-bar', [
                'role' => 'progressbar', 'style' => "width: {$percent}%",
                'aria-valuenow' => $percent, 'aria-valuemin' => 0, 'aria-valuemax' => 100,
            ]);
            $out .= html_writer::div($bar, 'progress', ['style' => 'height: 1.5rem;']);
        }
        return html_writer::div($out, 'mod-moodlereader-progress mb-3');
    }

    /**
     * The user's current reading-level information.
     *
     * @param reader $reader
     * @param int $userid
     * @return string HTML.
     */
    public function level_panel(reader $reader, int $userid): string {
        if (empty($reader->get_instance()->levelcheck)) {
            $note = get_string('alllevelsavailable', 'mod_moodlereader');
            return html_writer::div(s($note), 'mod-moodlereader-level text-muted mb-3');
        }
        $current = (int) $reader->get_level($userid)->currentlevel;
        $difficulties = $reader->allowed_difficulties($userid);
        $out = html_writer::tag('strong', get_string('yourcurrentlevel', 'mod_moodlereader', $current));
        if ($difficulties) {
            sort($difficulties);
            $out .= ' ' . s(get_string('levelsyoumayattempt', 'mod_moodlereader', implode(', ', $difficulties)));
        }
        return html_writer::div($out, 'mod-moodlereader-level alert alert-info mb-3');
    }

    /**
     * A single book-cover image (served, and lazily cached, by cover.php).
     *
     * @param reader $reader
     * @param stdClass $book
     * @param int $width
     * @param int $height
     * @return string HTML.
     */
    public function book_cover(reader $reader, stdClass $book, int $width = 100, int $height = 150): string {
        return html_writer::empty_tag('img', [
            'src'     => $reader->cover_url($book->id)->out(false),
            'alt'     => format_string($book->name),
            'title'   => format_string($book->name),
            'width'   => $width,
            'height'  => $height,
            'loading' => 'lazy',
            'class'   => 'mod-moodlereader-cover m-1',
        ]);
    }

    /**
     * The covers of the books a user has passed, split into this term and earlier.
     *
     * @param reader $reader
     * @param int $userid
     * @return string HTML.
     */
    public function books_read(reader $reader, int $userid): string {
        global $DB;
        $instance = $reader->get_instance();
        $rows = $DB->get_records_sql(
            "SELECT a.id, a.timefinish, b.id AS bookid, b.name
               FROM {moodlereader_attempts} a
               JOIN {moodlereader_books} b ON b.id = a.bookid
              WHERE a.readerid = :readerid AND a.userid = :userid
                    AND (a.passed = 1 OR a.credit = 1) AND a.deleted = 0 AND a.cheated = 0
           ORDER BY a.timefinish ASC",
            ['readerid' => $instance->id, 'userid' => $userid]
        );
        if (!$rows) {
            return '';
        }

        $thisterm = '';
        $previous = '';
        $seen = [];
        foreach ($rows as $row) {
            if (isset($seen[$row->bookid])) {
                continue;
            }
            $seen[$row->bookid] = true;
            $cover = $this->book_cover($reader, (object) ['id' => $row->bookid, 'name' => $row->name]);
            if ($row->timefinish >= $instance->ignoredate) {
                $thisterm .= $cover;
            } else {
                $previous .= $cover;
            }
        }

        $out = '';
        if ($thisterm !== '') {
            $out .= $this->output->heading(get_string('booksreadthisterm', 'mod_moodlereader'), 4);
            $out .= html_writer::div($thisterm, 'mod-moodlereader-covers d-flex flex-wrap mb-3');
        }
        if ($previous !== '') {
            $out .= $this->output->heading(get_string('booksreadpreviousterms', 'mod_moodlereader'), 4);
            $out .= html_writer::div($previous, 'mod-moodlereader-covers d-flex flex-wrap mb-3');
        }
        return $out;
    }

    /**
     * The reading report: this term's finished attempts with a running total.
     *
     * @param reader $reader
     * @param int $userid
     * @param string $fullname The reader's full name for the heading.
     * @return string HTML.
     */
    public function reading_report(reader $reader, int $userid, string $fullname): string {
        global $DB;
        $instance = $reader->get_instance();
        $attempts = $DB->get_records_sql(
            "SELECT a.id, a.passed, a.credit, a.cheated, a.percentgrade, a.timefinish,
                    b.name AS bookname, b.series, b.difficulty, b.words
               FROM {moodlereader_attempts} a
               JOIN {moodlereader_books} b ON b.id = a.bookid
              WHERE a.readerid = :readerid AND a.userid = :userid
                    AND a.deleted = 0 AND a.state = 'finished' AND a.timefinish >= :ignoredate
           ORDER BY a.timefinish ASC, a.id ASC",
            ['readerid' => $instance->id, 'userid' => $userid, 'ignoredate' => $instance->ignoredate]
        );
        if (!$attempts) {
            return '';
        }

        $table = new html_table();
        $table->attributes['class'] = 'generaltable mod-moodlereader-report';
        $table->head = [
            get_string('date'),
            get_string('book', 'mod_moodlereader'),
            get_string('series', 'mod_moodlereader'),
            get_string('readinglevel', 'mod_moodlereader'),
            get_string('result', 'mod_moodlereader'),
            get_string('words', 'mod_moodlereader'),
            get_string('runningtotal', 'mod_moodlereader'),
        ];
        $table->align = ['left', 'left', 'left', 'center', 'center', 'right', 'right'];

        $running = 0;
        foreach ($attempts as $a) {
            $counts = ($a->passed || $a->credit) && !$a->cheated;
            if ($counts) {
                $running += (int) $a->words;
            }
            $result = \mod_moodlereader\local\report\report::result_label($a, false);
            $table->data[] = [
                $a->timefinish ? userdate($a->timefinish, get_string('strftimedate', 'langconfig')) : '-',
                format_string($a->bookname),
                s($a->series),
                s((int) $a->difficulty),
                $result,
                $counts ? s(number_format((int) $a->words)) : '',
                s(number_format($running)),
            ];
        }

        $out = $this->output->heading(get_string('readingreportfor', 'mod_moodlereader', $fullname), 4);
        $out .= html_writer::table($table);
        return $out;
    }

    /**
     * The search form for finding a book to attempt.
     *
     * @param reader $reader
     * @param int $userid
     * @param array $filters Current filter values (name, publisher, series, genre, difficulty, language).
     * @return string HTML.
     */
    public function search_form(reader $reader, int $userid, array $filters): string {
        $cmid = $reader->get_cm()->id;

        // Publisher select, from the books currently available to this user.
        // Option labels are not escaped by html_writer::select(), so stored values get s().
        $publishers = ['' => get_string('allpublishers', 'mod_moodlereader')];
        foreach ($reader->available_publishers($userid) as $name => $count) {
            $label = ($name === '' ? get_string('nopublisher', 'mod_moodlereader') : $name) . " ($count)";
            $publishers[$name] = s($label);
        }

        // Genre select.
        $genres = ['' => get_string('anygenre', 'mod_moodlereader')] + self::GENRES;

        // Reading-level select, from the levels present in the available books
        // ('-1' is the "any level" sentinel so difficulty 0 stays selectable).
        $levels = ['-1' => get_string('anylevel', 'mod_moodlereader')];
        foreach ($reader->available_difficulties($userid) as $difficulty) {
            $levels[(string) $difficulty] = (string) $difficulty;
        }

        $languages = ['' => get_string('anylanguage', 'mod_moodlereader')]
            + array_map('s', booklanguage::options($reader->available_languages($userid)));

        $row = function (string $labelkey, string $control, string $for): string {
            $label = html_writer::tag('label', get_string($labelkey, 'mod_moodlereader'), [
                'class' => 'col-sm-3 col-form-label', 'for' => $for,
            ]);
            return html_writer::div($label . html_writer::div($control, 'col-sm-9'), 'form-group row mb-2');
        };
        $select = fn(array $options, string $name, string $selected) => html_writer::select(
            $options,
            $name,
            $selected,
            false,
            ['class' => 'form-control', 'id' => 'mr-' . $name]
        );

        $inner = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cmid]);
        // Marks the request as a submitted search so results show only on demand.
        $inner .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'searchsubmit', 'value' => 1]);
        $inner .= $row('booktitle', html_writer::empty_tag('input', [
            'type' => 'text', 'name' => 'searchname', 'value' => $filters['name'] ?? '', 'id' => 'mr-searchname',
            'class' => 'form-control', 'placeholder' => get_string('booktitleplaceholder', 'mod_moodlereader'),
        ]), 'mr-searchname');
        $inner .= $row(
            'booklanguage',
            $select($languages, 'searchlanguage', (string) ($filters['language'] ?? '')),
            'mr-searchlanguage'
        );
        $inner .= $row(
            'publisher',
            $select($publishers, 'searchpublisher', (string) ($filters['publisher'] ?? '')),
            'mr-searchpublisher'
        );
        $inner .= $row('series', html_writer::empty_tag('input', [
            'type' => 'text', 'name' => 'searchseries', 'value' => $filters['series'] ?? '', 'class' => 'form-control',
            'id' => 'mr-searchseries',
        ]), 'mr-searchseries');
        $inner .= $row('genre', $select($genres, 'searchgenre', (string) ($filters['genre'] ?? '')), 'mr-searchgenre');
        $inner .= $row(
            'readinglevel',
            $select($levels, 'searchdifficulty', (string) ($filters['difficulty'] ?? '-1')),
            'mr-searchdifficulty'
        );
        $inner .= html_writer::div(
            html_writer::tag('button', get_string('search'), ['type' => 'submit', 'class' => 'btn btn-primary']) . ' ' .
            html_writer::link($reader->view_url(), get_string('reset'), ['class' => 'btn btn-link']),
            'form-group'
        );

        $form = html_writer::tag('form', $inner, [
            'method' => 'get',
            'action' => new moodle_url('/mod/moodlereader/view.php'),
            'class'  => 'mod-moodlereader-searchform',
        ]);

        return $this->output->heading(get_string('searchforbook', 'mod_moodlereader'), 4) . $form;
    }

    /**
     * The list of books matching a search, each with a "take the quiz" button.
     *
     * @param reader $reader
     * @param stdClass[] $books
     * @param int $total Total matches (may exceed the number shown).
     * @param int $limit The cap applied to the displayed list.
     * @return string HTML.
     */
    public function search_results(reader $reader, array $books, int $total, int $limit): string {
        if (!$books) {
            return $this->output->notification(get_string('nosearchresults', 'mod_moodlereader'), 'info');
        }

        $out = '';
        if ($total > $limit) {
            $out .= $this->output->notification(
                get_string('searchtoomany', 'mod_moodlereader', ['limit' => $limit, 'total' => $total]),
                'info'
            );
        } else {
            $out .= html_writer::tag('p', s(get_string('searchcount', 'mod_moodlereader', $total)), ['class' => 'text-muted']);
        }

        $table = new html_table();
        $table->attributes['class'] = 'generaltable mod-moodlereader-booklist';
        $table->head = [
            '',
            get_string('book', 'mod_moodlereader'),
            get_string('publisher', 'mod_moodlereader'),
            get_string('series', 'mod_moodlereader'),
            get_string('readinglevel', 'mod_moodlereader'),
            get_string('genre', 'mod_moodlereader'),
            get_string('words', 'mod_moodlereader'),
            '',
        ];
        $table->align = ['center', 'left', 'left', 'left', 'center', 'left', 'right', 'center'];

        foreach ($books as $book) {
            $table->data[] = [
                $this->book_cover($reader, $book, 40, 60),
                format_string($book->name),
                s($book->publisher),
                s($book->series),
                s((int) $book->difficulty),
                s($this->genre_labels($book->genre)),
                s(number_format((int) $book->words)),
                $this->take_quiz_button($reader, (int) $book->id),
            ];
        }

        return $out . html_writer::table($table);
    }

    /**
     * The "take the quiz" button/form for a single book.
     *
     * @param reader $reader
     * @param int $bookid
     * @return string HTML.
     */
    protected function take_quiz_button(reader $reader, int $bookid): string {
        if (!\mod_moodlereader\local\connection::allows_launch()) {
            return html_writer::span(get_string('quizunavailable', 'mod_moodlereader'), 'text-muted small');
        }
        $form = html_writer::start_tag('form', [
            'method' => 'post',
            'action' => new moodle_url('/mod/moodlereader/startquiz.php'),
        ]);
        $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $reader->get_cm()->id]);
        $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'book', 'value' => $bookid]);
        $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $form .= html_writer::tag(
            'button',
            get_string('takequiz', 'mod_moodlereader'),
            ['type' => 'submit', 'class' => 'btn btn-primary btn-sm']
        );
        $form .= html_writer::end_tag('form');
        return $form;
    }

    /**
     * Expand a comma-separated list of genre codes into a readable label list.
     *
     * @param string $codes
     * @return string
     */
    protected function genre_labels(string $codes): string {
        $labels = [];
        foreach (array_filter(array_map('trim', explode(',', $codes))) as $code) {
            $labels[] = self::GENRES[$code] ?? $code;
        }
        return implode(', ', array_unique($labels));
    }
}
