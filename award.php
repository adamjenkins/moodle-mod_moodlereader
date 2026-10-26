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
 * Award credit for a book to the students ticked in the student summary.
 *
 * The teacher searches the catalogue as students do, except that books without a quiz
 * (and books whose quiz mreader.org withdrew) are listed too, marked as such. Books
 * disabled in Manage books are never offered.
 *
 * @package    mod_moodlereader
 * @copyright  2013 Gordon Bateson (gordon.bateson@gmail.com)
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_moodlereader\local\attempt_admin;
use mod_moodlereader\local\booklanguage;
use mod_moodlereader\local\reader;

$id = required_param('id', PARAM_INT);
$searched = optional_param('searchsubmit', 0, PARAM_BOOL);
$award = optional_param('award', 0, PARAM_INT);

$reader = reader::from_cmid($id);
require_login($reader->get_course(), true, $reader->get_cm());
$reader->require_cap('viewreports');
$reader->require_cap('manageusers');

$summaryurl = new moodle_url('/mod/moodlereader/report.php', ['id' => $id, 'mode' => 'usersummary']);
// Only people this teacher may act on; the ids travel with every search.
$userids = array_keys(attempt_admin::visible_userids($reader, optional_param_array('userids', [], PARAM_INT)));
if (!$userids) {
    redirect(
        $summaryurl,
        get_string('awardcredit_noneticked', 'mod_moodlereader'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$filters = [
    'name'       => optional_param('searchname', '', PARAM_TEXT),
    'publisher'  => optional_param('searchpublisher', '', PARAM_TEXT),
    'series'     => optional_param('searchseries', '', PARAM_TEXT),
    'difficulty' => optional_param('searchdifficulty', -1, PARAM_INT),
    // English is selected until the teacher chooses another language (or any).
    'language'   => $searched ? booklanguage::clean(optional_param('searchlanguage', '', PARAM_ALPHA)) : booklanguage::DEFAULT,
    // Books with or without a quiz (a book without one can only be given as credit).
    'quiz'       => optional_param('searchquiz', 'all', PARAM_ALPHA),
];
if (!in_array($filters['quiz'], ['all', 'withquiz', 'noquiz'], true)) {
    $filters['quiz'] = 'all';
}

if ($award) {
    require_sesskey();
    $book = $DB->get_record('moodlereader_books', ['id' => $award, 'hidden' => 0], 'id, name', MUST_EXIST);
    $count = attempt_admin::award_book($reader, $userids, (int) $book->id);
    $message = get_string($count ? 'awardcredit_done' : 'awardcredit_already', 'mod_moodlereader', [
        'count' => $count, 'book' => format_string($book->name),
    ]);
    redirect(
        $summaryurl,
        $message,
        null,
        $count ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_INFO
    );
}

$pageurl = new moodle_url('/mod/moodlereader/award.php', ['id' => $id]);
$PAGE->set_url($pageurl);
$PAGE->set_context($reader->get_context());
$PAGE->set_activity_record($reader->get_instance());
$PAGE->set_title(get_string('awardcredit', 'mod_moodlereader'));
$PAGE->set_heading(format_string($reader->get_course()->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('awardcredit', 'mod_moodlereader'));

$names = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;
[$insql, $inparams] = $DB->get_in_or_equal($userids);
$people = $DB->get_records_select('user', "id $insql", $inparams, 'lastname, firstname', "id, $names");
echo html_writer::tag('p', get_string(
    'awardcredit_for',
    'mod_moodlereader',
    implode(', ', array_map('fullname', $people))
));

// The ticked people, carried by every form on this page.
$hiddenusers = '';
foreach ($userids as $userid) {
    $hiddenusers .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userids[]', 'value' => $userid]);
}

// Search form: as the students' search, over the books credit can be given for.
// Option labels are not escaped by html_writer::select(), so stored values get s().
$publishers = ['' => get_string('allpublishers', 'mod_moodlereader')];
foreach ($DB->get_fieldset_sql('SELECT DISTINCT publisher FROM {moodlereader_books} WHERE hidden = 0 ORDER BY publisher') as $p) {
    $publishers[$p] = $p !== '' ? s($p) : get_string('nopublisher', 'mod_moodlereader');
}
$levels = ['-1' => get_string('anylevel', 'mod_moodlereader')];
foreach ($DB->get_fieldset_sql('SELECT DISTINCT difficulty FROM {moodlereader_books} WHERE hidden = 0 ORDER BY difficulty') as $d) {
    $levels[(string) $d] = (string) (int) $d;
}
$languages = ['' => get_string('anylanguage', 'mod_moodlereader')] + array_map('s', booklanguage::options(
    $DB->get_fieldset_sql("SELECT DISTINCT language FROM {moodlereader_books} WHERE hidden = 0 AND language <> ''")
));
$row = function (string $labelkey, string $control, string $for): string {
    return html_writer::div(
        html_writer::tag('label', get_string($labelkey, 'mod_moodlereader'), [
            'class' => 'col-sm-3 col-form-label', 'for' => $for,
        ]) .
        html_writer::div($control, 'col-sm-9'),
        'row mb-2 mx-0'
    );
};
$select = fn(array $options, string $name, string $selected) => html_writer::select(
    $options,
    $name,
    $selected,
    false,
    ['class' => 'form-control', 'id' => 'mr-' . $name]
);
$inner = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]) .
    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'searchsubmit', 'value' => 1]) . $hiddenusers;
$inner .= $row('booktitle', html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'searchname', 'value' => $filters['name'], 'id' => 'mr-searchname', 'class' => 'form-control',
]), 'mr-searchname');
$inner .= $row('booklanguage', $select($languages, 'searchlanguage', $filters['language']), 'mr-searchlanguage');
$inner .= $row('publisher', $select($publishers, 'searchpublisher', $filters['publisher']), 'mr-searchpublisher');
$inner .= $row('series', html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'searchseries', 'value' => $filters['series'], 'id' => 'mr-searchseries', 'class' => 'form-control',
]), 'mr-searchseries');
$inner .= $row('readinglevel', $select($levels, 'searchdifficulty', (string) $filters['difficulty']), 'mr-searchdifficulty');
$quizoptions = [
    'all'      => get_string('quizfilter_all', 'mod_moodlereader'),
    'withquiz' => get_string('quizfilter_withquiz', 'mod_moodlereader'),
    'noquiz'   => get_string('quizfilter_noquiz', 'mod_moodlereader'),
];
$inner .= $row('quizfilter', $select($quizoptions, 'searchquiz', $filters['quiz']), 'mr-searchquiz');
$inner .= html_writer::div(
    html_writer::tag('button', get_string('search'), ['type' => 'submit', 'class' => 'btn btn-primary']) . ' ' .
    html_writer::link($summaryurl, get_string('cancel'), ['class' => 'btn btn-link']),
    'mb-3'
);
echo html_writer::tag('form', $inner, ['method' => 'post', 'action' => $pageurl, 'class' => 'mb-3']);

if ($searched) {
    $total = 0;
    $limit = 100;
    $books = attempt_admin::search_award_books($filters, $limit, $total);
    if (!$books) {
        echo $OUTPUT->notification(get_string('nosearchresults', 'mod_moodlereader'), 'info');
    } else {
        $note = $total > $limit
            ? get_string('searchtoomany', 'mod_moodlereader', ['limit' => $limit, 'total' => $total])
            : get_string('awardsearchcount', 'mod_moodlereader', $total);
        echo html_writer::tag('p', s($note), ['class' => 'text-muted']);

        // How many of the ticked people already have each listed book counted.
        [$bsql, $bparams] = $DB->get_in_or_equal(array_keys($books), SQL_PARAMS_NAMED, 'bk');
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'us');
        $counted = $DB->get_records_sql_menu(
            "SELECT bookid, COUNT(DISTINCT userid)
               FROM {moodlereader_attempts}
              WHERE readerid = :readerid AND bookid $bsql AND userid $usql
                    AND deleted = 0 AND cheated = 0 AND (passed = 1 OR credit = 1)
           GROUP BY bookid",
            ['readerid' => $reader->get_instance()->id] + $bparams + $uparams
        );

        $table = new html_table();
        $table->attributes['class'] = 'generaltable';
        $table->head = [
            '', get_string('book', 'mod_moodlereader'), get_string('publisher', 'mod_moodlereader'),
            get_string('series', 'mod_moodlereader'), get_string('readinglevel', 'mod_moodlereader'),
            get_string('booklanguage', 'mod_moodlereader'), get_string('words', 'mod_moodlereader'),
            get_string('quiz', 'mod_moodlereader'), get_string('alreadycounted', 'mod_moodlereader'),
        ];
        foreach ($books as $book) {
            if (!$book->hasquiz) {
                $quiz = html_writer::span(get_string('quiz_none', 'mod_moodlereader'), 'badge bg-info text-dark');
            } else if ($book->withdrawn) {
                $quiz = html_writer::span(get_string('quiz_withdrawn', 'mod_moodlereader'), 'badge bg-warning text-dark');
            } else {
                $quiz = html_writer::span(get_string('quiz_has', 'mod_moodlereader'), 'badge bg-success');
            }
            $done = (int) ($counted[$book->id] ?? 0);
            $button = $done >= count($userids) ? '' : html_writer::tag(
                'form',
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'award', 'value' => $book->id]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                $hiddenusers .
                html_writer::tag('button', get_string('awardcredit_button', 'mod_moodlereader'), [
                    'type' => 'submit', 'class' => 'btn btn-primary btn-sm',
                    'aria-label' => get_string('awardcredit_buttonfor', 'mod_moodlereader', format_string($book->name)),
                ]),
                ['method' => 'post', 'action' => $pageurl]
            );
            // The button comes first so it is in view on narrow screens.
            $table->data[] = [
                $button, format_string($book->name), s($book->publisher), s($book->series), s((int) $book->difficulty),
                s(booklanguage::label((string) $book->language)), s(number_format((int) $book->words)), $quiz,
                s(get_string('alreadycounted_n', 'mod_moodlereader', ['done' => $done, 'of' => count($userids)])),
            ];
        }
        echo html_writer::div(html_writer::table($table), 'table-responsive');
    }
}

echo $OUTPUT->footer();
