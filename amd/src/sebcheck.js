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
 * Inside Safe Exam Browser versions that send no key headers, check the keys from SEB's JS API.
 *
 * The page asks sebcheck.php to compare the hashes SEB made for this URL; when they match, the quiz is
 * open for this session and the page reloads into it. Modelled on core's quizaccess_seb/validate_quiz_access.
 *
 * @module     mod_moodlereader/sebcheck
 * @copyright  2021 Catalyst IT
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Whether SEB has not computed a key yet (its empty value is ':').
 *
 * @param {string} key
 * @returns {boolean}
 */
const isEmpty = (key) => !key || key === ':';

/**
 * Send the keys; reload into the quiz when they match.
 *
 * @param {Object} config cmid, checkurl, sesskey.
 * @returns {Promise<void>}
 */
const check = async(config) => {
    const security = window.SafeExamBrowser.security;
    const body = new URLSearchParams({
        cmid: config.cmid,
        sesskey: config.sesskey,
        url: window.location.href,
        configkey: isEmpty(security.configKey) ? '' : security.configKey,
        browserexamkey: isEmpty(security.browserExamKey) ? '' : security.browserExamKey,
    });
    const status = document.querySelector('[data-region="mr-sebchecking"]');
    try {
        const response = await fetch(config.checkurl, {method: 'POST', body: body, credentials: 'same-origin'});
        const data = response.ok ? await response.json() : {};
        if (data.allowed) {
            // A GET of the same page, so a blocked answer post is not sent again.
            window.location.replace(window.location.href);
            return;
        }
    } catch (e) {
        // The page already says what to do; nothing more to show.
    }
    if (status) {
        status.classList.add('d-none');
    }
};

/**
 * Start the check when the page runs inside SEB with its JS API.
 *
 * @param {Object} config cmid, checkurl, sesskey.
 * @returns {void}
 */
export const init = (config) => {
    if (!window.SafeExamBrowser || !window.SafeExamBrowser.security) {
        return;
    }
    const status = document.querySelector('[data-region="mr-sebchecking"]');
    if (status) {
        status.classList.remove('d-none');
    }
    const security = window.SafeExamBrowser.security;
    if (!isEmpty(security.configKey) || !isEmpty(security.browserExamKey)) {
        check(config);
    } else if (typeof security.updateKeys === 'function') {
        security.updateKeys(() => check(config));
    }
};
