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
 * This site's mreader.org connection: request access, see its state, manage it.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core\output\notification;
use mod_moodlereader\local\admin_nav;
use mod_moodlereader\local\api\api_exception;
use mod_moodlereader\local\api\client;
use mod_moodlereader\local\connection;
use mod_moodlereader\local\downloader;

admin_externalpage_setup('mod_moodlereader_connection');
$context = context_system::instance();
require_capability('mod/moodlereader:manageremotesites', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$pageurl = new moodle_url('/mod/moodlereader/admin/connection.php');

/**
 * Run an API action and report its outcome on the page.
 *
 * @param callable $fn
 * @param string $successkey Lang string shown on success.
 * @param mixed $a
 * @return void
 */
function moodlereader_connection_action(callable $fn, string $successkey, $a = null): void {
    global $pageurl;
    try {
        $result = $fn();
        redirect($pageurl, get_string($successkey, 'mod_moodlereader', $a ?? $result), null, notification::NOTIFY_SUCCESS);
    } catch (api_exception $e) {
        redirect($pageurl, get_string('apierror', 'mod_moodlereader', (object) [
            'code' => s($e->apicode),
            'message' => s($e->a->message ?? ''),
        ]), null, notification::NOTIFY_ERROR);
    }
}

if ($action !== '') {
    require_sesskey();
    switch ($action) {
        case 'refresh':
            moodlereader_connection_action(fn() => connection::refresh(), 'connrefreshed');
            break;
        case 'rotate':
            moodlereader_connection_action(fn() => connection::rotate(), 'connrotated');
            break;
        case 'claimlegacy':
            moodlereader_connection_action(fn() => connection::claim_legacy(), 'connlegacyclaimed');
            break;
        case 'syncbooks':
            moodlereader_connection_action(fn() => downloader::sync_books(), 'synced');
            break;
        case 'forget':
            if (in_array(connection::state(), [connection::STATE_REJECTED, connection::STATE_UNKNOWN], true)) {
                connection::forget();
            }
            redirect($pageurl);
            break;
    }
}

$state = connection::state();
$details = connection::site_details();

if ($state === connection::STATE_NONE) {
    $mform = new \mod_moodlereader\form\connection_request_form($pageurl, [
        'details' => $details,
        'haslegacy' => connection::legacy_registration() !== null,
    ]);
    $mform->set_data([
        'contactname' => fullname($USER),
        'contactemail' => $USER->email,
    ]);
    if ($data = $mform->get_data()) {
        try {
            connection::request(
                trim($data->institution),
                trim($data->contactname),
                trim($data->contactemail),
                (string) $data->message,
                !empty($data->claimlegacy)
            );
            redirect($pageurl, get_string('connrequestsent', 'mod_moodlereader'), null, notification::NOTIFY_SUCCESS);
        } catch (api_exception $e) {
            $a = (object) ['code' => s($e->apicode), 'message' => s($e->a->message ?? '')];
            if ($e->apicode === 'duplicate_request') {
                $a->message = s(get_string(
                    'connduplicate',
                    'mod_moodlereader',
                    clean_param((string) ($e->response['request_ref'] ?? ''), PARAM_ALPHANUMEXT)
                ));
            }
            \core\notification::error(get_string('apierror', 'mod_moodlereader', $a));
        }
    }
} else if ($action === '') {
    // Opening the page refreshes the state; a failure leaves the cached state on show.
    try {
        $state = connection::refresh();
    } catch (api_exception $e) {
        \core\notification::warning(get_string('apierror', 'mod_moodlereader', (object) [
            'code' => s($e->apicode),
            'message' => s($e->a->message ?? ''),
        ]));
    }
}

$config = get_config('mod_moodlereader');
$button = function (string $action, string $labelkey, ?string $confirmkey = null) use ($pageurl): string {
    global $OUTPUT;
    $url = new moodle_url($pageurl, ['action' => $action]);
    $button = new \core\output\single_button(
        $url,
        get_string($labelkey, 'mod_moodlereader'),
        'post',
        \core\output\single_button::BUTTON_SECONDARY
    );
    if ($confirmkey !== null) {
        $button->add_confirm_action(get_string($confirmkey, 'mod_moodlereader'));
    }
    return $OUTPUT->render($button);
};
$row = fn(string $labelkey, string $value): string =>
    html_writer::tag('dt', get_string($labelkey, 'mod_moodlereader'), ['class' => 'col-sm-3']) .
    html_writer::tag('dd', $value, ['class' => 'col-sm-9']);
$date = fn($t): string => $t ? userdate((int) $t) : '-';

echo $OUTPUT->header();
echo $OUTPUT->render(admin_nav::tabs('connection'));

$service = s((new client())->get_baseurl());
switch ($state) {
    case connection::STATE_NONE:
        echo $OUTPUT->notification(get_string('connintro_none', 'mod_moodlereader', $service), notification::NOTIFY_INFO, false);
        echo $OUTPUT->box(get_string('connprivacy', 'mod_moodlereader'), 'generalbox');
        $mform->display();
        break;

    case connection::STATE_PENDING:
        echo $OUTPUT->notification(get_string('connintro_pending', 'mod_moodlereader'), notification::NOTIFY_WARNING, false);
        break;

    case connection::STATE_APPROVED:
        echo $OUTPUT->notification(get_string('connintro_approved', 'mod_moodlereader'), notification::NOTIFY_SUCCESS, false);
        break;

    case connection::STATE_REJECTED:
        echo $OUTPUT->notification(get_string('connintro_rejected', 'mod_moodlereader'), notification::NOTIFY_ERROR, false);
        break;

    case connection::STATE_REVOKED:
        echo $OUTPUT->notification(get_string('connintro_revoked', 'mod_moodlereader'), notification::NOTIFY_ERROR, false);
        break;

    default:
        echo $OUTPUT->notification(get_string('connintro_unknown', 'mod_moodlereader'), notification::NOTIFY_ERROR, false);
}

if ($state !== connection::STATE_NONE) {
    $rows = $row('connservice', $service);
    $rows .= $row('connstatus', html_writer::span(get_string('connstate_' . $state, 'mod_moodlereader'), 'badge ' . match ($state) {
        connection::STATE_APPROVED => 'bg-success',
        connection::STATE_PENDING => 'bg-warning text-dark',
        default => 'bg-danger',
    }));
    if (!empty($config->v2reason) && $state !== connection::STATE_APPROVED) {
        $rows .= $row('connreason', s($config->v2reason));
    }
    $rows .= $row('connrequestref', html_writer::tag('code', s($config->v2requestref ?? '')));
    $rows .= $row('connrequestedat', $date($config->v2requestedat ?? 0));
    if ($state === connection::STATE_APPROVED) {
        $rows .= $row('connapprovedat', $date($config->v2approvedat ?? 0));
        $rows .= $row('connlastbooksync', $date($config->v2lastbooksync ?? 0));
    }
    if ($state === connection::STATE_REVOKED) {
        $rows .= $row('connrevokedat', $date($config->v2revokedat ?? 0));
    }
    $links = connection::legacy_links();
    if ($links) {
        $items = array_map(fn($l) => get_string('connlegacylink', 'mod_moodlereader', (object) [
            'sid' => (int) $l['legacy_sid'],
            'state' => s($l['state']),
        ]), $links);
        $rows .= $row('connlegacy', implode('<br>', $items));
    }
    $rows .= $row('connlastchecked', $date($config->v2lastchecked ?? 0));
    $skew = (int) ($config->v2clockskew ?? 0);
    $rows .= $row('connclock', abs($skew) > 120
        ? html_writer::span(get_string('connclockskewed', 'mod_moodlereader', $skew), 'text-danger')
        : get_string('connclockok', 'mod_moodlereader'));
    echo $OUTPUT->box(html_writer::tag('dl', $rows, ['class' => 'row']), 'generalbox');

    $buttons = $button('refresh', 'conncheck');
    if ($state === connection::STATE_APPROVED) {
        $buttons .= $button('syncbooks', 'syncbooks');
        $buttons .= $button('rotate', 'connrotate', 'connrotateconfirm');
    }
    if (
        in_array($state, [connection::STATE_PENDING, connection::STATE_APPROVED], true) &&
            connection::legacy_registration() && !$links
    ) {
        $buttons .= $button('claimlegacy', 'connclaimlegacybutton');
    }
    if (in_array($state, [connection::STATE_REJECTED, connection::STATE_UNKNOWN], true)) {
        $buttons .= $button('forget', 'connnewrequest');
    }
    echo html_writer::div($buttons, 'd-flex flex-wrap gap-2');
}

echo $OUTPUT->footer();
