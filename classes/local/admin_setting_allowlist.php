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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Admin setting holding the school's own addresses, which cheating detection never compares.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_allowlist extends \admin_setting_configtextarea {
    /**
     * Accept only addresses, ranges, CIDR blocks and prefixes.
     *
     * @param string $data
     * @return bool|string True, or an error message.
     */
    public function validate($data) {
        [, $invalid] = cheatdetector::clean_allowlist((string) $data);
        return $invalid ? get_string('allowlistinvalid', 'mod_moodlereader', s(implode(', ', $invalid))) : true;
    }
}
