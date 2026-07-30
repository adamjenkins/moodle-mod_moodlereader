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

namespace mod_moodlereader\completion;

use core_completion\activity_custom_completion;

/**
 * Custom activity-completion rules for mod_moodlereader.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * The completion state of a single rule for this user.
     *
     * @param string $rule One of the rules from get_defined_custom_rules().
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE.
     */
    public function get_state(string $rule): int {
        global $DB;
        $this->validate_rule($rule);

        $instance = $DB->get_record('moodlereader', ['id' => $this->cm->instance], '*', MUST_EXIST);
        $readerid = $instance->id;

        if ($rule === 'completionpass') {
            $passed = $DB->record_exists_select(
                'moodlereader_attempts',
                'readerid = :readerid AND userid = :userid AND passed = 1 AND deleted = 0 AND cheated = 0',
                ['readerid' => $readerid, 'userid' => $this->userid]
            );
            return $passed ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
        }

        // Rule completiontotalwords: total words in passed books meets the threshold.
        $threshold = (int) $instance->completiontotalwords;
        $total = (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(b.words), 0)
               FROM {moodlereader_attempts} a
               JOIN {moodlereader_books} b ON b.id = a.bookid
              WHERE a.readerid = :readerid AND a.userid = :userid
                    AND a.passed = 1 AND a.deleted = 0 AND a.cheated = 0",
            ['readerid' => $readerid, 'userid' => $this->userid]
        );
        return $total >= $threshold ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * The rules this plugin defines.
     *
     * @return string[]
     */
    public static function get_defined_custom_rules(): array {
        return ['completionpass', 'completiontotalwords'];
    }

    /**
     * Human-readable descriptions of each rule.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        $words = (int) ($this->cm->customdata['customcompletionrules']['completiontotalwords'] ?? 0);
        return [
            'completionpass' => get_string('completionpass', 'mod_moodlereader'),
            'completiontotalwords' => get_string('completiontotalwordsdesc', 'mod_moodlereader', $words),
        ];
    }

    /**
     * The order rules are evaluated/displayed in.
     *
     * @return string[]
     */
    public function get_sort_order(): array {
        return ['completionpass', 'completiontotalwords'];
    }
}
