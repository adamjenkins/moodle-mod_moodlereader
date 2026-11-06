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

    /**
     * Follow a plain link to a student's unstarted quiz, as a link in a forum post would.
     *
     * @When /^I follow a plain link to the "(launch|first question)" of "([^"]+)" on "([^"]+)" in "([^"]+)"$/
     * @param string $state Which step of the quiz the link points at.
     * @param string $user Username.
     * @param string $book Book title.
     * @param string $activity Activity idnumber.
     * @return void
     */
    public function i_follow_a_plain_link_to_quiz($state, $user, $book, $activity) {
        global $DB;
        $launched = $state === 'first question';
        $now = time();
        $id = $DB->insert_record('moodlereader_attempts', (object) [
            'readerid' => $DB->get_field('course_modules', 'instance', ['idnumber' => $activity], MUST_EXIST),
            'userid' => $DB->get_field('user', 'id', ['username' => $user], MUST_EXIST),
            'bookid' => $DB->get_field('moodlereader_books', 'id', ['name' => $book], MUST_EXIST),
            'attempt' => 1, 'state' => 'inprogress', 'percentgrade' => 0, 'passed' => 0, 'credit' => 0, 'cheated' => 0,
            'deleted' => 0, 'bookrating' => 0, 'timestart' => $now, 'timefinish' => 0, 'timemodified' => $now,
            'ip' => '203.0.113.9', 'delivery' => 'moodle', 'attemptref' => $launched ? 'plainlink' : null,
            'timelimit' => $launched ? 600 : 0, 'slots' => $launched ? 3 : 0, 'currentslot' => 0,
        ]);
        $page = $launched ? 'render' : 'mreader';
        $this->getSession()->visit($this->locate_path('/mod/moodlereader/quiz/' . $page . '.php?attempt=' . $id));
    }

    /**
     * Answer API v2 from a fake mreader.org that draws one three-question quiz for Moodle (31 §11).
     *
     * @Given /^mreader\.org draws quizzes for Moodle$/
     * @return void
     */
    public function mreader_org_draws_quizzes_for_moodle() {
        global $DB;
        $this->the_site_is_connected_to_mreader_org();
        set_config('behatfakemreader', 1, 'mod_moodlereader');
        set_config('behatfakestate', '', 'mod_moodlereader');
        // The fake's books need mreader.org ids.
        $DB->execute('UPDATE {moodlereader_books} SET remoteid = id WHERE remoteid = 0');
    }
}
