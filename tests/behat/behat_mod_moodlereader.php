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

// phpcs:disable moodle.Files.RequireLogin.Missing

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode;

/**
 * Behat step definitions for mod_moodlereader.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_moodlereader extends behat_base {
    /**
     * Create books in the shared catalogue.
     *
     * @Given /^the following moodlereader books exist:$/
     * @param TableNode $data
     * @return void
     */
    public function the_following_moodlereader_books_exist(TableNode $data) {
        $generator = behat_util::get_data_generator()->get_plugin_generator('mod_moodlereader');
        foreach ($data->getHash() as $row) {
            $generator->create_book($row);
        }
    }

    /**
     * Pretend mreader.org has approved this site (dummy credential, for UI tests; no request is made).
     *
     * @Given /^the site is connected to mreader\.org$/
     * @return void
     */
    public function the_site_is_connected_to_mreader_org() {
        set_config('v2siteid', 'behat-site', 'mod_moodlereader');
        set_config('v2secret', 'YmVoYXQtdGVzdC1zZWNyZXQtMDEyMzQ1Njc4OWFiY2RlZg', 'mod_moodlereader');
        set_config('v2state', 'approved', 'mod_moodlereader');
        set_config('v2lastchecked', time(), 'mod_moodlereader');
    }

    /**
     * Create finished attempts: columns user (username), activity (idnumber), book (title), percent, passed.
     *
     * @Given /^the following moodlereader attempts exist:$/
     * @param TableNode $data
     * @return void
     */
    public function the_following_moodlereader_attempts_exist(TableNode $data) {
        global $DB;
        foreach ($data->getHash() as $row) {
            $userid = $DB->get_field('user', 'id', ['username' => $row['user']], MUST_EXIST);
            $cmid = $DB->get_field('course_modules', 'instance', ['idnumber' => $row['activity']], MUST_EXIST);
            $bookid = $DB->get_field('moodlereader_books', 'id', ['name' => $row['book']], MUST_EXIST);
            $now = time();
            $DB->insert_record('moodlereader_attempts', (object) [
                'readerid' => $cmid, 'userid' => $userid, 'bookid' => $bookid, 'attempt' => 1,
                'state' => 'finished', 'percentgrade' => (float) $row['percent'], 'passed' => (int) $row['passed'],
                'credit' => 0, 'cheated' => 0, 'deleted' => 0, 'bookrating' => 0,
                'timestart' => $now - 900, 'timefinish' => $now, 'timemodified' => $now, 'ip' => '203.0.113.9',
            ]);
        }
    }
}
