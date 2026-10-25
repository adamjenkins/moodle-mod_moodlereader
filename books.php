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
 * Book catalogue management: filter the catalogue and enable/disable books for this activity
 * (or, for site administrators, the whole site), one at a time or in bulk.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_moodlereader\local\reader;
use mod_moodlereader\local\downloader;

$id = required_param('id', PARAM_INT);
$sync = optional_param('sync', 0, PARAM_BOOL);
$hidebook = optional_param('hide', 0, PARAM_INT);
$showbook = optional_param('show', 0, PARAM_INT);
$bulkaction = optional_param('bulkaction', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);

// Filters.
$fpublisher = optional_param('fpublisher', '', PARAM_TEXT);
$fseries = optional_param('fseries', '', PARAM_TEXT);
$fdifficulty = optional_param('fdifficulty', -1, PARAM_INT);
$fstatus = optional_param('fstatus', 'all', PARAM_ALPHA);
$flanguage = \mod_moodlereader\local\booklanguage::clean(optional_param('flanguage', '', PARAM_ALPHA));
if (!in_array($fstatus, ['all', 'enabled', 'disabled'], true)) {
    $fstatus = 'all';
}
// Filter values carried on every link/redirect so the view is preserved.
$filterparams = [
    'fpublisher'  => $fpublisher,
    'fseries'     => $fseries,
    'fdifficulty' => $fdifficulty,
    'fstatus'     => $fstatus,
    'flanguage'   => $flanguage,
];

$reader = reader::from_cmid($id);
require_login($reader->get_course(), true, $reader->get_cm());
$reader->require_cap('managebooks');

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

// Single-book enable/disable for this activity (row action).
if ($hidebook || $showbook) {
    require_sesskey();
    $reader->set_books_hidden([$hidebook ?: $showbook], (bool) $hidebook);
    redirect($viewurl);
}

// Bulk enable/disable of the selected books: for this activity, or (site administrators) the whole site.
if ($bulkaction && data_submitted() && confirm_sesskey()) {
    $bookids = array_filter(optional_param_array('bookids', [], PARAM_INT));
    if ($bookids && in_array($bulkaction, ['enable', 'disable'], true)) {
        $count = $reader->set_books_hidden($bookids, $bulkaction === 'disable');
    } else if ($bookids && in_array($bulkaction, ['siteenable', 'sitedisable'], true)) {
        require_capability('mod/moodlereader:manageremotesites', context_system::instance());
        [$insql, $inparams] = $DB->get_in_or_equal($bookids, SQL_PARAMS_NAMED, 'b');
        $DB->set_field_select('moodlereader_books', 'hidden', $bulkaction === 'sitedisable' ? 1 : 0, "id $insql", $inparams);
        $count = count($bookids);
    }
    if (!empty($count)) {
        redirect(
            $viewurl,
            get_string('booksupdated', 'mod_moodlereader', $count),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    redirect($viewurl);
}

// Build the filtered query.
$where = [];
$params = ['hbreaderid' => $reader->get_instance()->id];
if ($fpublisher !== '') {
    $where[] = 'b.publisher = :fp';
    $params['fp'] = $fpublisher;
}
if (trim($fseries) !== '') {
    $where[] = $DB->sql_like('b.series', ':fs', false, false);
    $params['fs'] = '%' . $DB->sql_like_escape(trim($fseries)) . '%';
}
if ($fdifficulty >= 0) {
    $where[] = 'b.difficulty = :fd';
    $params['fd'] = $fdifficulty;
}
if ($flanguage !== '') {
    $where[] = 'b.language = :fl';
    $params['fl'] = $flanguage;
}
// Disabled means disabled here: in this activity, or for the whole site.
if ($fstatus === 'enabled') {
    $where[] = 'b.hidden = 0 AND hb.id IS NULL';
} else if ($fstatus === 'disabled') {
    $where[] = '(b.hidden = 1 OR hb.id IS NOT NULL)';
}
$wheresql = $where ? implode(' AND ', $where) : '1 = 1';
$fromsql = '{moodlereader_books} b
       LEFT JOIN {moodlereader_hiddenbooks} hb ON hb.bookid = b.id AND hb.readerid = :hbreaderid';

$perpage = 50;
$totalbooks = $DB->count_records_sql("SELECT COUNT(1) FROM $fromsql WHERE $wheresql", $params);
$books = $DB->get_records_sql(
    "SELECT b.*, CASE WHEN hb.id IS NULL THEN 0 ELSE 1 END AS activityhidden
       FROM $fromsql
      WHERE $wheresql
   ORDER BY b.publisher, b.series, b.name",
    $params,
    $page * $perpage,
    $perpage
);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managebooks', 'mod_moodlereader'));

if ($cansync) {
    $syncurl = new moodle_url($pageurl, $filterparams + ['sync' => 1, 'sesskey' => sesskey()]);
    echo html_writer::div(
        $OUTPUT->single_button($syncurl, get_string('syncbooks', 'mod_moodlereader'), 'post') .
        html_writer::span(get_string('syncbooks_help', 'mod_moodlereader'), 'text-muted ml-2 align-self-center'),
        'd-flex mb-3'
    );
}

// Filter form (GET).
$publishers = ['' => get_string('allpublishers', 'mod_moodlereader')];
foreach (
    $DB->get_records_sql(
        'SELECT DISTINCT publisher FROM {moodlereader_books} ORDER BY publisher'
    ) as $row
) {
    // Option labels are not escaped by html_writer::select().
    $publishers[$row->publisher] = $row->publisher !== '' ? s($row->publisher) : get_string('nopublisher', 'mod_moodlereader');
}
$difficulties = ['-1' => get_string('anylevel', 'mod_moodlereader')];
foreach (
    $DB->get_records_sql(
        'SELECT DISTINCT difficulty FROM {moodlereader_books} ORDER BY difficulty'
    ) as $row
) {
    $difficulties[(string) $row->difficulty] = (string) $row->difficulty;
}
$languages = ['' => get_string('anylanguage', 'mod_moodlereader')] + array_map('s', \mod_moodlereader\local\booklanguage::options(
    $DB->get_fieldset_sql("SELECT DISTINCT language FROM {moodlereader_books} WHERE language <> ''")
));
$statuses = [
    'all'      => get_string('allbooks', 'mod_moodlereader'),
    'enabled'  => get_string('enabledonly', 'mod_moodlereader'),
    'disabled' => get_string('disabledonly', 'mod_moodlereader'),
];

$filterrow = function (string $label, string $control): string {
    return html_writer::div(
        html_writer::tag('label', $label, ['class' => 'mr-1']) . $control,
        'form-inline mr-3 mb-2 d-inline-flex'
    );
};
$filterinner = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
$filterinner .= $filterrow(
    get_string('publisher', 'mod_moodlereader'),
    html_writer::select($publishers, 'fpublisher', $fpublisher, false, ['class' => 'form-control'])
);
$filterinner .= $filterrow(
    get_string('series', 'mod_moodlereader'),
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'fseries', 'value' => $fseries, 'class' => 'form-control'])
);
$filterinner .= $filterrow(
    get_string('readinglevel', 'mod_moodlereader'),
    html_writer::select($difficulties, 'fdifficulty', (string) $fdifficulty, false, ['class' => 'form-control'])
);
$filterinner .= $filterrow(
    get_string('booklanguage', 'mod_moodlereader'),
    html_writer::select($languages, 'flanguage', $flanguage, false, ['class' => 'form-control'])
);
$filterinner .= $filterrow(
    get_string('status', 'mod_moodlereader'),
    html_writer::select($statuses, 'fstatus', $fstatus, false, ['class' => 'form-control'])
);
$filterinner .= html_writer::tag('button', get_string('filter'), ['type' => 'submit', 'class' => 'btn btn-secondary mb-2']);
$filterinner .= ' ' . html_writer::link(
    new moodle_url($pageurl),
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

// Bulk form (POST) wrapping the table.
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'page', 'value' => $page]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
foreach ($filterparams as $fname => $fvalue) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $fname, 'value' => $fvalue]);
}

echo html_writer::div(
    html_writer::tag(
        'button',
        get_string('bulkenable', 'mod_moodlereader'),
        ['type' => 'submit', 'name' => 'bulkaction', 'value' => 'enable', 'class' => 'btn btn-outline-success btn-sm mr-1']
    ) .
    html_writer::tag(
        'button',
        get_string('bulkdisable', 'mod_moodlereader'),
        ['type' => 'submit', 'name' => 'bulkaction', 'value' => 'disable', 'class' => 'btn btn-outline-secondary btn-sm']
    ) . ($cansync ? ' ' .
    html_writer::tag(
        'button',
        get_string('bulksiteenable', 'mod_moodlereader'),
        ['type' => 'submit', 'name' => 'bulkaction', 'value' => 'siteenable', 'class' => 'btn btn-outline-success btn-sm ml-3']
    ) . ' ' .
    html_writer::tag(
        'button',
        get_string('bulksitedisable', 'mod_moodlereader'),
        ['type' => 'submit', 'name' => 'bulkaction', 'value' => 'sitedisable', 'class' => 'btn btn-outline-danger btn-sm']
    ) : ''),
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
    $checkbox = html_writer::empty_tag('input', [
        'type' => 'checkbox', 'name' => 'bookids[]', 'value' => $book->id,
        'data-action' => 'toggle', 'data-toggle' => 'target', 'data-togglegroup' => 'mrbooks',
        'aria-label' => s($book->name),
    ]);
    if ($book->hidden) {
        // Disabled for the whole site: only a site administrator can enable it again.
        $status = html_writer::span(get_string('disabledsite', 'mod_moodlereader'), 'badge bg-dark');
        $toggle = '';
    } else if ($book->activityhidden) {
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
        s(\mod_moodlereader\local\booklanguage::label((string) $book->language)),
        s(number_format((int) $book->words)),
        $status,
        $toggle,
    ]);
    if ($book->hidden || $book->activityhidden) {
        $row->attributes['class'] = 'dimmed_text';
    }
    $table->data[] = $row;
}
echo html_writer::table($table);
echo html_writer::end_tag('form');

echo $OUTPUT->paging_bar($totalbooks, $page, $perpage, new moodle_url($pageurl, $filterparams));

echo $OUTPUT->footer();
