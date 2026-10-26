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
 * Book catalogue management for the whole site: filter the catalogue (several publishers, levels
 * and languages at once) and enable or disable books one at a time, the selected ones, or every
 * book matching the filter. A disabled book is offered to no student in any activity.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_moodlereader\local\booklanguage;
use mod_moodlereader\local\downloader;
use mod_moodlereader\local\reader;

$id = required_param('id', PARAM_INT);
$sync = optional_param('sync', 0, PARAM_BOOL);
$hidebook = optional_param('hide', 0, PARAM_INT);
$showbook = optional_param('show', 0, PARAM_INT);
$bulkaction = optional_param('bulkaction', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);

// Filters. Publishers, levels and languages can each have several values.
$filtered = optional_param('filtered', 0, PARAM_BOOL);
$fpublishers = array_values(array_unique(optional_param_array('fpublisher', [], PARAM_TEXT)));
$fseries = optional_param('fseries', '', PARAM_TEXT);
$fdifficulties = array_values(array_unique(array_filter(
    optional_param_array('fdifficulty', [], PARAM_INT),
    fn($d) => $d >= 0
)));
// English is selected until the filter is changed.
$flanguages = $filtered ? optional_param_array('flanguage', [], PARAM_ALPHA) : [booklanguage::DEFAULT];
$flanguages = array_values(array_unique(array_filter(array_map([booklanguage::class, 'clean'], $flanguages))));
$fstatus = optional_param('fstatus', 'all', PARAM_ALPHA);
if (!in_array($fstatus, ['all', 'enabled', 'disabled'], true)) {
    $fstatus = 'all';
}
// Filter values carried on every link/redirect so the view is preserved.
$filterparams = [
    'filtered'    => 1,
    'fpublisher'  => $fpublishers,
    'fseries'     => $fseries,
    'fdifficulty' => $fdifficulties,
    'flanguage'   => $flanguages,
    'fstatus'     => $fstatus,
];

$reader = reader::from_cmid($id);
require_login($reader->get_course(), true, $reader->get_cm());
// The catalogue is the whole site's: only a site administrator enables or disables books.
require_capability('mod/moodlereader:managecatalogue', context_system::instance());

$pageurl = new moodle_url('/mod/moodlereader/books.php', ['id' => $id]);
$viewurl = new moodle_url($pageurl, $filterparams + ['page' => $page]);
$PAGE->set_url($pageurl);
$PAGE->set_context($reader->get_context());
$PAGE->set_title(get_string('managebooks', 'mod_moodlereader'));
$PAGE->set_heading(format_string($reader->get_course()->fullname));
$PAGE->requires->js_call_amd('core/checkbox-toggleall', 'init');

$cansync = has_capability('mod/moodlereader:manageremotesites', context_system::instance());

if ($sync) {
    require_sesskey();
    require_capability('mod/moodlereader:manageremotesites', context_system::instance());
    try {
        $count = downloader::sync_books();
    } catch (\moodle_exception $e) {
        redirect($viewurl, s($e->getMessage()), null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect($viewurl, get_string('synced', 'mod_moodlereader', $count), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Build the filtered query (also used by "enable/disable all matching").
$where = [];
$params = [];
if ($fpublishers) {
    [$insql, $inparams] = $DB->get_in_or_equal($fpublishers, SQL_PARAMS_NAMED, 'fp');
    $where[] = "publisher $insql";
    $params += $inparams;
}
if (trim($fseries) !== '') {
    $where[] = $DB->sql_like('series', ':fs', false, false);
    $params['fs'] = '%' . $DB->sql_like_escape(trim($fseries)) . '%';
}
if ($fdifficulties) {
    [$insql, $inparams] = $DB->get_in_or_equal($fdifficulties, SQL_PARAMS_NAMED, 'fd');
    $where[] = "difficulty $insql";
    $params += $inparams;
}
if ($flanguages) {
    // A book whose language mreader.org has not sent matches every language.
    [$insql, $inparams] = $DB->get_in_or_equal($flanguages, SQL_PARAMS_NAMED, 'fl');
    $where[] = "(language $insql OR language = '')";
    $params += $inparams;
}
if ($fstatus === 'enabled') {
    $where[] = 'hidden = 0';
} else if ($fstatus === 'disabled') {
    $where[] = 'hidden = 1';
}
$wheresql = $where ? implode(' AND ', $where) : '1 = 1';

// Single-book enable/disable (row action).
if ($hidebook || $showbook) {
    require_sesskey();
    $DB->set_field('moodlereader_books', 'hidden', $hidebook ? 1 : 0, ['id' => $hidebook ?: $showbook]);
    redirect($viewurl);
}

// Bulk enable/disable: the ticked books, or every book matching the filter (all pages).
if ($bulkaction && data_submitted() && confirm_sesskey()) {
    $count = 0;
    if (in_array($bulkaction, ['enable', 'disable'], true)) {
        $bookids = array_filter(optional_param_array('bookids', [], PARAM_INT));
        if ($bookids) {
            [$insql, $inparams] = $DB->get_in_or_equal($bookids, SQL_PARAMS_NAMED, 'b');
            $count = $DB->count_records_select('moodlereader_books', "id $insql", $inparams);
            $DB->set_field_select('moodlereader_books', 'hidden', $bulkaction === 'disable' ? 1 : 0, "id $insql", $inparams);
        }
    } else if (in_array($bulkaction, ['enableall', 'disableall'], true)) {
        $count = $DB->count_records_select('moodlereader_books', $wheresql, $params);
        $DB->set_field_select('moodlereader_books', 'hidden', $bulkaction === 'disableall' ? 1 : 0, $wheresql, $params);
    }
    if ($count) {
        redirect(
            $viewurl,
            get_string('booksupdated', 'mod_moodlereader', $count),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    redirect($viewurl);
}

$perpage = 50;
$totalbooks = $DB->count_records_select('moodlereader_books', $wheresql, $params);
$books = $DB->get_records_select(
    'moodlereader_books',
    $wheresql,
    $params,
    'publisher, series, name',
    '*',
    $page * $perpage,
    $perpage
);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managebooks', 'mod_moodlereader'));
echo html_writer::tag('p', get_string('managebooks_intro', 'mod_moodlereader'));

if ($cansync) {
    $syncurl = new moodle_url($pageurl, $filterparams + ['sync' => 1, 'sesskey' => sesskey()]);
    echo html_writer::div(
        $OUTPUT->single_button($syncurl, get_string('syncbooks', 'mod_moodlereader'), 'post') .
        html_writer::span(get_string('syncbooks_help', 'mod_moodlereader'), 'text-muted ms-2 align-self-center'),
        'd-flex mb-3'
    );
}

// Filter form (GET). Option labels are not escaped by html_writer::select(), so stored values get s().
$publishers = [];
foreach ($DB->get_fieldset_sql('SELECT DISTINCT publisher FROM {moodlereader_books} ORDER BY publisher') as $name) {
    $publishers[$name] = $name !== '' ? s($name) : get_string('nopublisher', 'mod_moodlereader');
}
$difficulties = [];
foreach ($DB->get_fieldset_sql('SELECT DISTINCT difficulty FROM {moodlereader_books} ORDER BY difficulty') as $difficulty) {
    $difficulties[(string) $difficulty] = (string) (int) $difficulty;
}
$languages = array_map('s', booklanguage::options(
    $DB->get_fieldset_sql("SELECT DISTINCT language FROM {moodlereader_books} WHERE language <> ''")
));
$statuses = [
    'all'      => get_string('allbooks', 'mod_moodlereader'),
    'enabled'  => get_string('enabledonly', 'mod_moodlereader'),
    'disabled' => get_string('disabledonly', 'mod_moodlereader'),
];

$filterrow = function (string $label, string $control, string $for): string {
    return html_writer::div(
        html_writer::tag('label', $label, ['class' => 'me-1 align-self-start', 'for' => $for]) . $control,
        'me-3 mb-2 d-inline-flex'
    );
};
$multi = function (array $options, string $name, array $selected): string {
    return html_writer::select($options, $name . '[]', array_map('strval', $selected), false, [
        'class' => 'form-control', 'multiple' => 'multiple', 'id' => 'mr-' . $name,
        'size' => max(2, min(8, count($options))),
    ]);
};
$filterinner = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
$filterinner .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filtered', 'value' => 1]);
$filterinner .= $filterrow(
    get_string('publisher', 'mod_moodlereader'),
    $multi($publishers, 'fpublisher', $fpublishers),
    'mr-fpublisher'
);
$filterinner .= $filterrow(
    get_string('readinglevel', 'mod_moodlereader'),
    $multi($difficulties, 'fdifficulty', $fdifficulties),
    'mr-fdifficulty'
);
$filterinner .= $filterrow(
    get_string('booklanguage', 'mod_moodlereader'),
    $multi($languages, 'flanguage', $flanguages),
    'mr-flanguage'
);
$filterinner .= $filterrow(
    get_string('series', 'mod_moodlereader'),
    html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'fseries', 'value' => $fseries, 'class' => 'form-control', 'id' => 'mr-fseries',
    ]),
    'mr-fseries'
);
$filterinner .= $filterrow(
    get_string('status', 'mod_moodlereader'),
    html_writer::select($statuses, 'fstatus', $fstatus, false, ['class' => 'form-control', 'id' => 'mr-fstatus']),
    'mr-fstatus'
);
$filterinner .= html_writer::div(get_string('multiselecthint', 'mod_moodlereader'), 'form-text text-muted small mb-2');
$filterinner .= html_writer::tag('button', get_string('filter'), ['type' => 'submit', 'class' => 'btn btn-secondary mb-2']);
$filterinner .= ' ' . html_writer::link(
    new moodle_url($pageurl, ['filtered' => 1]),
    get_string('reset'),
    ['class' => 'btn btn-link mb-2']
);
echo html_writer::tag('form', $filterinner, ['method' => 'get', 'action' => $pageurl, 'class' => 'mb-3']);

echo html_writer::tag('p', get_string('bookcount', 'mod_moodlereader', $totalbooks), ['class' => 'text-muted']);

if (!$books) {
    echo $OUTPUT->notification(get_string('nobooksmatch', 'mod_moodlereader'), 'info');
    echo $OUTPUT->footer();
    exit;
}

// Bulk form (POST) wrapping the table; the filter travels with it so "all matching" means what is shown.
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'page', 'value' => $page]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
foreach ($filterparams as $fname => $fvalue) {
    foreach ((array) $fvalue as $one) {
        echo html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => is_array($fvalue) ? $fname . '[]' : $fname, 'value' => $one,
        ]);
    }
}

$button = fn(string $action, string $label, string $class, array $extra = []) => html_writer::tag(
    'button',
    $label,
    ['type' => 'submit', 'name' => 'bulkaction', 'value' => $action, 'class' => "btn btn-sm $class"] + $extra
);
$confirm = fn(string $key) => ['onclick' => 'return confirm(' . json_encode(
    get_string($key, 'mod_moodlereader', $totalbooks)
) . ');'];
echo html_writer::div(
    $button('enable', get_string('bulkenable', 'mod_moodlereader'), 'btn-outline-success me-1') .
    $button('disable', get_string('bulkdisable', 'mod_moodlereader'), 'btn-outline-secondary me-3') .
    $button(
        'enableall',
        get_string('bulkenableall', 'mod_moodlereader', $totalbooks),
        'btn-success me-1',
        $confirm('bulkenableall_confirm')
    ) .
    $button(
        'disableall',
        get_string('bulkdisableall', 'mod_moodlereader', $totalbooks),
        'btn-secondary',
        $confirm('bulkdisableall_confirm')
    ),
    'mb-2'
);

$master = html_writer::empty_tag('input', [
    'type' => 'checkbox', 'data-action' => 'toggle', 'data-toggle' => 'toggler',
    'data-togglegroup' => 'mrbooks', 'aria-label' => get_string('selectall'),
    'data-toggle-selectall' => get_string('selectall'),
    'data-toggle-deselectall' => get_string('deselectall'),
]);

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->head = [
    $master,
    get_string('book', 'mod_moodlereader'),
    get_string('publisher', 'mod_moodlereader'),
    get_string('series', 'mod_moodlereader'),
    get_string('readinglevel', 'mod_moodlereader'),
    get_string('booklanguage', 'mod_moodlereader'),
    get_string('words', 'mod_moodlereader'),
    get_string('status', 'mod_moodlereader'),
    '',
];
$table->align = ['center', 'left', 'left', 'left', 'center', 'center', 'right', 'center', 'center'];

foreach ($books as $book) {
    $checkboxid = 'mr-book-' . $book->id;
    $checkbox = html_writer::label(s($book->name), $checkboxid, false, ['class' => 'accesshide']) .
        html_writer::empty_tag('input', [
            'type' => 'checkbox', 'name' => 'bookids[]', 'value' => $book->id, 'id' => $checkboxid,
            'data-action' => 'toggle', 'data-toggle' => 'target', 'data-togglegroup' => 'mrbooks',
        ]);
    if ($book->hidden) {
        $status = html_writer::span(get_string('disabled', 'mod_moodlereader'), 'badge bg-secondary');
        $toggle = html_writer::link(
            new moodle_url($pageurl, $filterparams + ['show' => $book->id, 'page' => $page, 'sesskey' => sesskey()]),
            get_string('enablebook', 'mod_moodlereader'),
            ['class' => 'btn btn-outline-success btn-sm']
        );
    } else {
        $status = html_writer::span(get_string('enabled', 'mod_moodlereader'), 'badge bg-success');
        $toggle = html_writer::link(
            new moodle_url($pageurl, $filterparams + ['hide' => $book->id, 'page' => $page, 'sesskey' => sesskey()]),
            get_string('disablebook', 'mod_moodlereader'),
            ['class' => 'btn btn-outline-secondary btn-sm']
        );
    }
    $row = new html_table_row([
        $checkbox,
        s($book->name),
        s($book->publisher),
        s($book->series),
        s((int) $book->difficulty),
        s(booklanguage::label((string) $book->language)),
        s(number_format((int) $book->words)),
        $status,
        $toggle,
    ]);
    if ($book->hidden) {
        $row->attributes['class'] = 'dimmed_text';
    }
    $table->data[] = $row;
}
echo html_writer::div(html_writer::table($table), 'table-responsive');
echo html_writer::end_tag('form');

echo $OUTPUT->paging_bar($totalbooks, $page, $perpage, new moodle_url($pageurl, $filterparams));

echo $OUTPUT->footer();
