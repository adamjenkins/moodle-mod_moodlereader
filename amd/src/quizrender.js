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
 * One question of a Moodle-drawn book quiz: the countdown, the heartbeat, and ordering by buttons.
 *
 * The countdown only shows the time; the server keeps the clock (quiz/heartbeat.php tells it the page is
 * still open and answers with the true time left). When the student moves on, the countdown stops and
 * "Loading the next question…" shows until the next page arrives, which is the pause the clock makes
 * while mreader.org answers. At zero the form is sent with what has been chosen so far.
 *
 * @module     mod_moodlereader/quizrender
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';

/**
 * Format seconds as m:ss.
 *
 * @param {number} seconds
 * @returns {string}
 */
const format = (seconds) => {
    const s = Math.max(0, Math.floor(seconds));
    return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
};

/**
 * Tell the server the page is still open; it answers with the seconds left.
 *
 * @param {Object} config
 * @returns {Promise<number|null>}
 */
const beat = async(config) => {
    const body = new URLSearchParams({attempt: config.attemptid, sesskey: config.sesskey});
    try {
        const response = await fetch(config.heartbeaturl, {method: 'POST', body: body, credentials: 'same-origin'});
        if (!response.ok) {
            return null;
        }
        const data = await response.json();
        return typeof data.timeleft === 'number' ? data.timeleft : null;
    } catch (e) {
        // A missed beat is retried by the next one; the server counts only up to the last one that arrived.
        return null;
    }
};

/**
 * Let the ordering items be moved with their up and down buttons (keyboard and touch friendly).
 *
 * @param {HTMLElement} form
 * @returns {void}
 */
const initOrdering = (form) => {
    const list = form.querySelector('[data-region="mr-order"]');
    if (!list) {
        return;
    }
    const status = form.querySelector('[data-region="mr-orderstatus"]');
    list.addEventListener('click', async(e) => {
        const button = e.target.closest('[data-action="mr-up"], [data-action="mr-down"]');
        if (!button) {
            return;
        }
        const item = button.closest('li');
        const up = button.dataset.action === 'mr-up';
        const other = up ? item.previousElementSibling : item.nextElementSibling;
        if (!other) {
            return;
        }
        if (up) {
            list.insertBefore(item, other);
        } else {
            list.insertBefore(other, item);
        }
        button.focus();
        if (status) {
            const position = Array.prototype.indexOf.call(list.children, item) + 1;
            status.textContent = await getString('orderingmoved', 'mod_moodlereader', position);
        }
    });
};

/**
 * Start the page.
 *
 * @param {Object} config timeleft, heartbeat (seconds), heartbeaturl, attemptid, sesskey.
 * @returns {void}
 */
export const init = (config) => {
    const form = document.querySelector('[data-region="mr-quizform"]');
    const display = document.querySelector('[data-region="mr-timeleft"]');
    if (!form || !display) {
        return;
    }
    initOrdering(form);

    let left = config.timeleft;
    let running = true;
    let sent = false;

    const send = (timeout) => {
        if (sent) {
            return;
        }
        sent = true;
        running = false;
        if (timeout) {
            form.querySelector('[data-region="mr-timeout"]').value = '1';
        }
        form.querySelector('[data-region="mr-next"]').disabled = true;
        form.querySelector('[data-region="mr-loading"]').classList.remove('d-none');
        // Radios left unchecked at the time limit must not block the submission.
        form.querySelectorAll('[required]').forEach((input) => input.removeAttribute('required'));
        form.submit();
    };

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        send(false);
    });

    // The countdown, corrected by the server at each heartbeat.
    setInterval(() => {
        if (!running) {
            return;
        }
        left -= 1;
        display.textContent = format(left);
        if (left <= 0) {
            send(true);
        }
    }, 1000);
    setInterval(async() => {
        if (!running) {
            return;
        }
        const server = await beat(config);
        if (server !== null && running) {
            left = server;
            display.textContent = format(left);
        }
    }, config.heartbeat * 1000);
};
