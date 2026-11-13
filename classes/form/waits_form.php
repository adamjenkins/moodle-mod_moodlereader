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

namespace mod_moodlereader\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use mod_moodlereader\local\waits;

/**
 * Wait times between quizzes: a default rule and rules for single reading levels.
 *
 * Modelled on mod_reader's "Set reading rates" page.
 *
 * @package    mod_moodlereader
 * @copyright  2013 Gordon Bateson (gordon.bateson@gmail.com)
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class waits_form extends \moodleform {
    /** @var int Highest reading level a rule can name. */
    const MAX_LEVEL = 20;

    /**
     * The form.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $str = fn(string $key) => get_string($key, 'mod_moodlereader');

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('static', 'waitsintro', '', $str('waits_intro'));

        $mform->addElement('select', 'waitlevel', $str('waitlevel'), [
            waits::BY_STUDENT => $str('waitlevel_student'),
            waits::BY_BOOK => $str('waitlevel_book'),
        ]);
        $mform->addHelpButton('waitlevel', 'waitlevel', 'mod_moodlereader');

        // The default rule.
        $mform->addElement('header', 'waitdefaultheader', $str('waitdefault'));
        $mform->setExpanded('waitdefaultheader');
        $this->add_rule_group('default', null);

        // Rules for single levels.
        $mform->addElement('header', 'waitlevelsheader', $str('waitlevels'));
        $mform->setExpanded('waitlevelsheader');
        $mform->addElement('static', 'waitlevelsintro', '', $str('waitlevels_intro'));
        $levels = [-1 => get_string('choosedots')];
        for ($i = 0; $i <= self::MAX_LEVEL; $i++) {
            $levels[$i] = get_string('levelnumber', 'mod_moodlereader', $i);
        }
        $repeat = [
            $mform->createElement('group', 'rule', $str('waitlevelrule'), [
                $mform->createElement('select', 'level', $str('readinglevel'), $levels),
                $mform->createElement('text', 'attempts', $str('waitattempts'), ['size' => 3]),
                $mform->createElement('static', 'within', '', $str('waitwithin')),
                $mform->createElement('duration', 'duration', $str('waitduration'), [
                    'optional' => false, 'defaultunit' => HOURSECS,
                ]),
            ], ' ', false),
        ];
        $count = max(3, (int) ($this->_customdata['levelrules'] ?? 0) + 1);
        $this->repeat_elements($repeat, $count, [
            'attempts' => ['type' => PARAM_INT, 'default' => 1],
            'level' => ['default' => -1],
        ], 'rulecount', 'ruleadd', 3, $str('waitaddlevels'), true);

        $this->add_action_buttons();
    }

    /**
     * One rule's fields on a line: "no more than [N] quiz(zes) within [time]".
     *
     * @param string $prefix Field-name prefix.
     * @param string|null $label
     * @return void
     */
    protected function add_rule_group(string $prefix, ?string $label): void {
        $mform = $this->_form;
        $str = fn(string $key) => get_string($key, 'mod_moodlereader');
        $mform->addGroup([
            $mform->createElement('text', $prefix . 'attempts', $str('waitattempts'), ['size' => 3]),
            $mform->createElement('static', $prefix . 'within', '', $str('waitwithin')),
            $mform->createElement('duration', $prefix . 'duration', $str('waitduration'), [
                'optional' => false, 'defaultunit' => HOURSECS,
            ]),
        ], $prefix . 'group', $label ?? $str('waitrule'), ' ', false);
        $mform->setType($prefix . 'attempts', PARAM_INT);
        $mform->setDefault($prefix . 'attempts', 1);
        $mform->addHelpButton($prefix . 'group', 'waitrule', 'mod_moodlereader');
    }

    /**
     * The stored rules as form data.
     *
     * @param \stdClass $instance The activity record.
     * @param \stdClass[] $rules From {@see waits::rules()}.
     * @return array
     */
    public static function to_form(\stdClass $instance, array $rules): array {
        $data = ['waitlevel' => (int) ($instance->waitlevel ?? 0), 'defaultattempts' => 1, 'defaultduration' => 0];
        if (isset($rules[waits::DEFAULT_LEVEL])) {
            $data['defaultattempts'] = (int) $rules[waits::DEFAULT_LEVEL]->attempts;
            $data['defaultduration'] = (int) $rules[waits::DEFAULT_LEVEL]->duration;
        }
        $i = 0;
        foreach ($rules as $level => $rule) {
            if ($level === waits::DEFAULT_LEVEL) {
                continue;
            }
            $data["level[$i]"] = $level;
            $data["attempts[$i]"] = (int) $rule->attempts;
            $data["duration[$i]"] = (int) $rule->duration;
            $i++;
        }
        return $data;
    }

    /**
     * The submitted rules.
     *
     * @param \stdClass $data From get_data().
     * @return array level => [attempts, duration], for {@see waits::save()}.
     */
    public static function from_form(\stdClass $data): array {
        $rules = [];
        if ((int) ($data->defaultduration ?? 0) > 0) {
            $rules[waits::DEFAULT_LEVEL] = [(int) $data->defaultattempts, (int) $data->defaultduration];
        }
        foreach ((array) ($data->level ?? []) as $i => $level) {
            $level = (int) $level;
            $duration = (int) ($data->duration[$i] ?? 0);
            if ($level >= 0 && $level <= self::MAX_LEVEL && $duration > 0) {
                $rules[$level] = [(int) ($data->attempts[$i] ?? 1), $duration];
            }
        }
        return $rules;
    }

    /**
     * Counts and times in range; one rule per level.
     *
     * @param array $data
     * @param array $files
     * @return array Errors by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $check = function (int $attempts, int $duration, string $element) use (&$errors) {
            if ($duration <= 0) {
                return;
            }
            if ($attempts < 1 || $attempts > waits::MAX_ATTEMPTS) {
                $errors[$element] = get_string('waitattemptsrange', 'mod_moodlereader', waits::MAX_ATTEMPTS);
            } else if ($duration > waits::MAX_DURATION) {
                $errors[$element] = get_string('waitdurationrange', 'mod_moodlereader');
            }
        };
        $check((int) ($data['defaultattempts'] ?? 1), (int) ($data['defaultduration'] ?? 0), 'defaultgroup');
        $seen = [];
        foreach ((array) ($data['level'] ?? []) as $i => $level) {
            $level = (int) $level;
            $duration = (int) ($data['duration'][$i] ?? 0);
            if ($level < 0 || $duration <= 0) {
                continue;
            }
            if (isset($seen[$level])) {
                $errors["rule[$i]"] = get_string('waitleveltwice', 'mod_moodlereader', $level);
                continue;
            }
            $seen[$level] = true;
            $check((int) ($data['attempts'][$i] ?? 1), $duration, "rule[$i]");
        }
        return $errors;
    }
}
