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

namespace mod_moodlereader\local;

/**
 * Tabs shared by the plugin's site-administration pages.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_nav {
    /**
     * The tab row, with one tab selected.
     *
     * @param string $current 'settings', 'connection', 'books' or 'import'.
     * @return \tabtree
     */
    public static function tabs(string $current): \tabtree {
        $tabs = [
            new \tabobject(
                'settings',
                new \moodle_url('/admin/settings.php', ['section' => 'modsettingmoodlereader']),
                get_string('settings')
            ),
            new \tabobject(
                'connection',
                new \moodle_url('/mod/moodlereader/admin/connection.php'),
                get_string('connection', 'mod_moodlereader')
            ),
        ];
        if (has_capability('mod/moodlereader:managecatalogue', \context_system::instance())) {
            $tabs[] = new \tabobject(
                'books',
                new \moodle_url('/mod/moodlereader/admin/books.php'),
                get_string('managebooks', 'mod_moodlereader')
            );
        }
        if (\mod_moodlereader\local\import\importer::source_available()) {
            $tabs[] = new \tabobject(
                'import',
                new \moodle_url('/mod/moodlereader/admin/import.php'),
                get_string('import', 'mod_moodlereader')
            );
        }
        return new \tabtree($tabs, $current);
    }
}
