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
     * Pretend the site is registered with mreader.org (dummy id/key for UI tests).
     *
     * @Given /^the site is registered with mreader\.org$/
     * @return void
     */
    public function the_site_is_registered_with_mreader_org() {
        set_config('mreaderurl', 'https://mreader.org', 'mod_moodlereader');
        set_config('mreadersiteid', '1', 'mod_moodlereader');
        set_config('mreadersitekey', 'behat-test-key', 'mod_moodlereader');
    }
}
