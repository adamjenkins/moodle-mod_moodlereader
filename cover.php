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
 * Serve a book-cover image, fetching it from mreader.org and caching it on first use.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_moodlereader\local\reader;
use mod_moodlereader\local\api\api_exception;
use mod_moodlereader\local\api\client;
use mod_moodlereader\local\connection;
use mod_moodlereader\local\covermiss;

$id = required_param('id', PARAM_INT);        // Course-module id.
$bookid = required_param('book', PARAM_INT);  // Book id.

$reader = reader::from_cmid($id);
require_login($reader->get_course(), false, $reader->get_cm());
$reader->require_cap('viewbooks');

$book = $DB->get_record('moodlereader_books', ['id' => $bookid], '*', MUST_EXIST);
// Only the covers of books this activity offers, or that someone has an attempt on here.
if (!$reader->shows_cover($book)) {
    throw new moodle_exception('booknotavailable', 'mod_moodlereader');
}
$context = $reader->get_context();

// Only raster images are served inline: never SVG or anything else that can carry script.
$types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
$fs = get_file_storage();
$file = null;
foreach ($fs->get_area_files($context->id, 'mod_moodlereader', 'bookcover', $book->id, 'id', false) as $cached) {
    if (in_array($cached->get_mimetype(), ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        $file = $cached;
        break;
    }
}

if (!$file) {
    // Not cached yet: fetch it from mreader.org and store it for next time. A cover that
    // mreader.org did not supply in the last day is not asked for again (covermiss).
    $bytes = null;
    $fetched = false;
    if (connection::allows_launch() && (int) $book->remoteid > 0 && !covermiss::is_known($book)) {
        $fetched = true;
        try {
            $response = (new client())->get_raw('/book-cover', ['id' => (int) $book->remoteid]);
            if ($response['status'] === 200 && strpos($response['headers']['content-type'] ?? '', 'image/') === 0) {
                $bytes = $response['body'];
            }
        } catch (api_exception $e) {
            $bytes = null;
        }
    }
    // The bytes must be an image of an allowed type; the name is taken from that type.
    $info = $bytes !== null && $bytes !== '' ? @getimagesizefromstring($bytes) : false;
    if ($info && isset($types[$info[2]])) {
        $fs->delete_area_files($context->id, 'mod_moodlereader', 'bookcover', $book->id);
        $file = $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_moodlereader',
            'filearea'  => 'bookcover',
            'itemid'    => $book->id,
            'filepath'  => '/',
            'filename'  => 'cover.' . $types[$info[2]],
        ], $bytes);
        covermiss::forget((int) $book->id);
    } else if ($fetched) {
        covermiss::remember($book);
    }
}

if ($file) {
    // Covers are curated public images; cache for a day and serve inline.
    header('X-Content-Type-Options: nosniff');
    send_stored_file($file, DAYSECS, 0, false, ['immutable' => true]);
}

// No cover available: render a lightweight placeholder so layout stays intact.
moodlereader_send_cover_placeholder($book->name);

/**
 * Emit an SVG placeholder cover bearing the book's title.
 *
 * @param string $name Book title.
 * @return never
 */
function moodlereader_send_cover_placeholder(string $name) {
    $title = shorten_text(trim($name) !== '' ? $name : get_string('book', 'mod_moodlereader'), 60);
    // Split the title across up to four centred lines.
    $words = preg_split('/\s+/', $title);
    $lines = [];
    $current = '';
    foreach ($words as $word) {
        if ($current !== '' && \core_text::strlen($current . ' ' . $word) > 14) {
            $lines[] = $current;
            $current = $word;
        } else {
            $current = $current === '' ? $word : "$current $word";
        }
        if (count($lines) >= 3) {
            break;
        }
    }
    if ($current !== '' && count($lines) < 4) {
        $lines[] = $current;
    }

    $tspans = '';
    $y = 70 - (count($lines) - 1) * 11;
    foreach ($lines as $line) {
        $tspans .= '<tspan x="50" y="' . $y . '">' . s($line) . '</tspan>';
        $y += 22;
    }

    $svg = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="150" viewBox="0 0 100 150" role="img">'
        . '<rect width="100" height="150" rx="6" fill="#495a6b"/>'
        . '<rect x="10" y="0" width="4" height="150" fill="#3a4855"/>'
        . '<text x="50" y="60" fill="#ffffff" font-family="sans-serif" font-size="11" '
        . 'text-anchor="middle" font-weight="600">' . $tspans . '</text>'
        . '</svg>';

    header('Content-Type: image/svg+xml');
    header('Content-Length: ' . strlen($svg));
    header('Cache-Control: public, max-age=' . DAYSECS);
    echo $svg;
    die;
}
