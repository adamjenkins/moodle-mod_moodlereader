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
 * Watch an mreader.org quiz frame and leave it once the result is in.
 *
 * Two signals lead to a check: mreader.org's "finished" message from the frame,
 * and a timer. Either way the result itself is fetched by the server
 * (quiz/status.php), never taken from the message.
 *
 * @module     mod_moodlereader/quizframe
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @var {number} Milliseconds between two polls. */
const POLL_INTERVAL = 10000;

/**
 * Ask the server for the attempt's state and leave the page when it is finished.
 *
 * @param {Object} config
 * @param {boolean} force Skip the server's rate limit (after a "finished" message).
 * @returns {Promise<boolean>} True when the page is being left.
 */
const check = async(config, force) => {
    const params = new URLSearchParams({
        attempt: config.attemptid,
        sesskey: config.sesskey,
        force: force ? 1 : 0,
    });
    try {
        const response = await fetch(config.statusurl + '?' + params.toString(), {credentials: 'same-origin'});
        if (!response.ok) {
            return false;
        }
        const data = await response.json();
        if (data.state === 'finished' && data.redirect) {
            window.location.href = data.redirect;
            return true;
        }
    } catch (e) {
        // A failed poll is retried by the next tick.
    }
    return false;
};

/**
 * Start watching.
 *
 * @param {Object} config
 * @param {number} config.attemptid
 * @param {string} config.attemptref
 * @param {string} config.origin The mreader.org origin allowed to post messages.
 * @param {string} config.statusurl
 * @param {string} config.sesskey
 */
export const init = (config) => {
    let leaving = false;

    window.addEventListener('message', async(event) => {
        if (leaving || event.origin !== config.origin) {
            return;
        }
        const data = event.data;
        if (!data || data.type !== 'mreader:finished' || data.ref !== config.attemptref) {
            return;
        }
        leaving = await check(config, true);
    });

    const timer = window.setInterval(async() => {
        if (leaving) {
            window.clearInterval(timer);
            return;
        }
        leaving = await check(config, false);
    }, POLL_INTERVAL);
};
