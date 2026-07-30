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
 * Book catalogue management: list books and sync from mreader.org.
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
$page = optional_param('page', 0, PARAM_INT);

$reader = reader::from_cmid($id);
require_login($reader->get_course(), true, $reader->get_cm());
$reader->require_cap('managebooks');

$pageurl = new moodle_url('/mod/moodlereader/books.php', ['id' => $id]);
$PAGE->set_url($pageurl);
$PAGE->set_context($reader->get_context());
$PAGE->set_title(get_string('managebooks', 'mod_moodlereader'));
$PAGE->set_heading(format_string($reader->get_course()->fullname));

if ($sync) {
    require_sesskey();
    // Syncing mutates the site-wide catalogue, so require the system-level capability.
    require_capability('mod/moodlereader:manageremotesites', context_system::instance());
    $count = downloader::sync_books();
    redirect(
        $pageurl,
        get_string('synced', 'mod_moodlereader', $count),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Enable (show) or disable (hide) a book for students.
if ($hidebook || $showbook) {
    require_sesskey();
    $bookid = $hidebook ?: $showbook;
    if ($DB->record_exists('moodlereader_books', ['id' => $bookid])) {
        $DB->set_field('moodlereader_books', 'hidden', $hidebook ? 1 : 0, ['id' => $bookid]);
    }
    redirect(new moodle_url($pageurl, ['page' => $page]));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managebooks', 'mod_moodlereader'));

if (has_capability('mod/moodlereader:manageremotesites', context_system::instance())) {
    $syncurl = new moodle_url($pageurl, ['sync' => 1, 'sesskey' => sesskey()]);
    echo $OUTPUT->single_button($syncurl, get_string('syncbooks', 'mod_moodlereader'), 'post');
}

$perpage = 100;
$totalbooks = $DB->count_records('moodlereader_books');
$books = $DB->get_records('moodlereader_books', null, 'publisher, series, name', '*', $page * $perpage, $perpage);
if ($books) {
    $table = new html_table();
    $table->head = [
        get_string('book', 'mod_moodlereader'),
        get_string('publisher', 'mod_moodlereader'),
        get_string('series', 'mod_moodlereader'),
        get_string('words', 'mod_moodlereader'),
        get_string('status', 'mod_moodlereader'),
        '',
    ];
    foreach ($books as $book) {
        if ($book->hidden) {
            $status = html_writer::span(get_string('disabled', 'mod_moodlereader'), 'badge bg-secondary');
            $toggle = $OUTPUT->single_button(
                new moodle_url($pageurl, ['show' => $book->id, 'page' => $page, 'sesskey' => sesskey()]),
                get_string('enablebook', 'mod_moodlereader'),
                'post'
            );
        } else {
            $status = html_writer::span(get_string('enabled', 'mod_moodlereader'), 'badge bg-success');
            $toggle = $OUTPUT->single_button(
                new moodle_url($pageurl, ['hide' => $book->id, 'page' => $page, 'sesskey' => sesskey()]),
                get_string('disablebook', 'mod_moodlereader'),
                'post'
            );
        }
        $row = new html_table_row([
            s($book->name),
            s($book->publisher),
            s($book->series),
            s(number_format((int) $book->words)),
            $status,
            $toggle,
        ]);
        if ($book->hidden) {
            $row->attributes['class'] = 'dimmed_text';
        }
        $table->data[] = $row;
    }
    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($totalbooks, $page, $perpage, $pageurl);
} else {
    echo $OUTPUT->notification(get_string('nobooksavailable', 'mod_moodlereader'), 'info');
}

echo $OUTPUT->footer();
