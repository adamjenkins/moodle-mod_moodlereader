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

namespace mod_moodlereader\task;

use mod_moodlereader\local\import\importer;

/**
 * Sync (or cut over) one course's mod_reader activities in the background.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_course extends \core\task\adhoc_task {
    /**
     * Queue a course (once: a course already queued is not queued again).
     *
     * @param int $courseid
     * @param string $action 'sync' or 'cutover'.
     * @return void
     */
    public static function queue(int $courseid, string $action): void {
        $task = new self();
        $task->set_custom_data((object) ['courseid' => $courseid, 'action' => $action]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Run the import.
     */
    public function execute() {
        $data = $this->get_custom_data();
        $importer = new importer();
        $report = ($data->action ?? 'sync') === 'cutover' ?
            $importer->cutover((int) $data->courseid) : $importer->sync((int) $data->courseid);
        mtrace('mod_moodlereader import of course ' . (int) $data->courseid . ': ' . count($report['activities']) .
            ' activities.');
    }
}
