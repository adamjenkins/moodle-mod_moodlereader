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

/**
 * Settings form for a moodlereader activity instance.
 *
 * The reading-level settings (level check, books at, below and above the level, with their defaults)
 * follow mod_reader's form.
 *
 * @package    mod_moodlereader
 * @copyright  2013 Gordon Bateson (gordon.bateson@gmail.com)
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * The moodlereader instance settings form.
 *
 * @package    mod_moodlereader
 * @copyright  2013 Gordon Bateson (gordon.bateson@gmail.com)
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_moodlereader_mod_form extends moodleform_mod {
    /**
     * Define the form.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        // Reading target.
        $mform->addElement('header', 'gradingheader', get_string('gradingheader', 'mod_moodlereader'));

        $mform->addElement('text', 'goal', get_string('goal', 'mod_moodlereader'), ['size' => 8]);
        $mform->setType('goal', PARAM_INT);
        $mform->setDefault('goal', 30000);
        $mform->addHelpButton('goal', 'goal', 'mod_moodlereader');

        $mform->addElement('select', 'wordsorpoints', get_string('wordsorpoints', 'mod_moodlereader'), [
            0 => get_string('words', 'mod_moodlereader'),
            1 => get_string('points', 'mod_moodlereader'),
        ]);

        // The start of the current term: reading before it counts for no grade, progress or "this term" total.
        $mform->addElement('date_selector', 'ignoredate', get_string('ignoredate', 'mod_moodlereader'), ['optional' => true]);
        $mform->addHelpButton('ignoredate', 'ignoredate', 'mod_moodlereader');

        $mform->addElement('text', 'minpassgrade', get_string('minpassgrade', 'mod_moodlereader'), ['size' => 4]);
        $mform->setType('minpassgrade', PARAM_INT);
        $mform->setDefault('minpassgrade', 60);
        $mform->addHelpButton('minpassgrade', 'minpassgrade', 'mod_moodlereader');

        // Appearance: which sections of the activity page students see.
        $mform->addElement('header', 'appearanceheader', get_string('appearanceheader', 'mod_moodlereader'));
        $mform->addElement('static', 'appearancedesc', '', get_string('appearancedesc', 'mod_moodlereader'));
        foreach (['showprogressbar', 'showlevel', 'showbooksread', 'showreport', 'showsearch'] as $toggle) {
            $mform->addElement('advcheckbox', $toggle, get_string($toggle, 'mod_moodlereader'));
            $mform->setDefault($toggle, 1);
            if ($toggle === 'showprogressbar') {
                $mform->addElement('advcheckbox', 'extraprogressbar', get_string('extraprogressbar', 'mod_moodlereader'));
                $mform->setDefault('extraprogressbar', 1);
                $mform->addHelpButton('extraprogressbar', 'extraprogressbar', 'mod_moodlereader');
                $mform->disabledIf('extraprogressbar', 'showprogressbar');
            }
        }

        // Reading-level control.
        $mform->addElement('header', 'levelheader', get_string('levelheader', 'mod_moodlereader'));

        $mform->addElement('advcheckbox', 'levelcheck', get_string('levelcheck', 'mod_moodlereader'));
        $mform->addHelpButton('levelcheck', 'levelcheck', 'mod_moodlereader');

        // How many books a student reads at, below and above their level (mod_reader's defaults: 6, 3, 1).
        foreach (['thislevel' => 6, 'prevlevel' => 3, 'nextlevel' => 1] as $field => $default) {
            $mform->addElement('text', $field, get_string($field, 'mod_moodlereader'), ['size' => 3]);
            $mform->setType($field, PARAM_INT);
            $mform->setDefault($field, $default);
            $mform->addHelpButton($field, $field, 'mod_moodlereader');
            $mform->disabledIf($field, 'levelcheck');
        }

        $this->add_seb_elements();

        // Availability window.
        $mform->addElement('header', 'availabilityheader', get_string('availability'));
        $mform->addElement(
            'date_time_selector',
            'availablefrom',
            get_string('availablefrom', 'mod_moodlereader'),
            ['optional' => true]
        );
        $mform->addElement(
            'date_time_selector',
            'availableuntil',
            get_string('availableuntil', 'mod_moodlereader'),
            ['optional' => true]
        );

        // Standard grade element (drives $data->maxgrade via 'grade').
        $this->standard_grading_coursemodule_elements();

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Safe Exam Browser for the quizzes, unless the site or the course already decides it.
     *
     * @return void
     */
    protected function add_seb_elements(): void {
        $mform = $this->_form;
        if (!\mod_moodlereader\local\seb::available()) {
            return;
        }
        $mode = \mod_moodlereader\local\seb::site_mode();
        if ($mode === \mod_moodlereader\local\seb::SITE_NEVER) {
            return;
        }
        $mform->addElement('header', 'sebheader', get_string('seb', 'mod_moodlereader'));
        if ($mode === \mod_moodlereader\local\seb::SITE_REQUIRED) {
            $mform->addElement(
                'static',
                'sebforced',
                get_string('sebactivity', 'mod_moodlereader'),
                get_string('sebbysite', 'mod_moodlereader')
            );
            return;
        }
        if (!has_capability('mod/moodlereader:manageseb', $this->context)) {
            $current = (int) ($this->current->seb ?? 0) === \mod_moodlereader\local\seb::ACTIVITY_YES ? 'yes' : 'no';
            $mform->addElement('static', 'sebcurrent', get_string('sebactivity', 'mod_moodlereader'), get_string($current));
            return;
        }
        $mform->addElement('select', 'seb', get_string('sebactivity', 'mod_moodlereader'), [
            \mod_moodlereader\local\seb::ACTIVITY_NO => get_string('no'),
            \mod_moodlereader\local\seb::ACTIVITY_YES => get_string('yes'),
        ]);
        $mform->addHelpButton('seb', 'sebactivity', 'mod_moodlereader');
        $mform->addElement('select', 'sebsource', get_string('sebmethod', 'mod_moodlereader'), $this->seb_sources());
        $mform->addHelpButton('sebsource', 'sebmethod', 'mod_moodlereader');
        $mform->addElement('textarea', 'sebkeys', get_string('sebkeys', 'mod_moodlereader'), ['rows' => 3, 'cols' => 70]);
        $mform->setType('sebkeys', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('sebkeys', 'sebkeys', 'mod_moodlereader');
        $mform->addElement('passwordunmask', 'sebquitpassword', get_string('sebquitpassword', 'mod_moodlereader'));
        $mform->setType('sebquitpassword', PARAM_RAW);
        $mform->addHelpButton('sebquitpassword', 'sebquitpassword', 'mod_moodlereader');
        $mform->hideIf('sebquitpassword', 'sebsource', 'eq', 'client');
        foreach (['sebsource', 'sebkeys', 'sebquitpassword'] as $name) {
            $mform->hideIf($name, 'seb', 'neq', \mod_moodlereader\local\seb::ACTIVITY_YES);
        }
    }

    /**
     * Where the SEB configuration comes from: Moodle, one of the site's SEB templates, a copy of a quiz's
     * configuration in this course, or SEB's own.
     *
     * @return string[] Option value => label.
     */
    protected function seb_sources(): array {
        $options = ['config' => get_string('sebmethod_config', 'mod_moodlereader')];
        foreach (\mod_moodlereader\local\seb::templates() as $id => $name) {
            $options['template:' . $id] = get_string('sebsource_template', 'mod_moodlereader', $name);
        }
        if ((int) ($this->current->sebmethod ?? 0) === \mod_moodlereader\local\seb::METHOD_QUIZ) {
            $options['keep'] = get_string('sebsource_keep', 'mod_moodlereader');
        }
        foreach (\mod_moodlereader\local\seb::quizzes((int) $this->get_course()->id) as $cmid => $name) {
            $options['quiz:' . $cmid] = get_string('sebsource_quiz', 'mod_moodlereader', $name);
        }
        $options['client'] = get_string('sebmethod_client', 'mod_moodlereader');
        return $options;
    }

    /**
     * Validate the settings.
     *
     * @param array $data
     * @param array $files
     * @return array Errors by element name.
     */
    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        foreach (['thislevel', 'prevlevel', 'nextlevel'] as $field) {
            if (isset($data[$field]) && ((int) $data[$field] < 0 || (int) $data[$field] > 99)) {
                $errors[$field] = get_string('levelcountrange', 'mod_moodlereader');
            }
        }
        if (isset($data['minpassgrade']) && ((int) $data['minpassgrade'] < 0 || (int) $data['minpassgrade'] > 100)) {
            $errors['minpassgrade'] = get_string('minpassgraderange', 'mod_moodlereader');
        }
        if (isset($data['goal']) && ((int) $data['goal'] < 0 || (int) $data['goal'] > 9999999)) {
            $errors['goal'] = get_string('goalrange', 'mod_moodlereader');
        }
        $from = (int) ($data['availablefrom'] ?? 0);
        $until = (int) ($data['availableuntil'] ?? 0);
        if ($from > 0 && $until > 0 && $until <= $from) {
            $errors['availableuntil'] = get_string('availableuntilbeforefrom', 'mod_moodlereader');
        }
        if (isset($data['sebkeys']) && !\mod_moodlereader\local\seb::valid_keys($data['sebkeys'])) {
            $errors['sebkeys'] = get_string('sebkeysinvalid', 'mod_moodlereader');
        } else if (
            (int) ($data['seb'] ?? 0) === \mod_moodlereader\local\seb::ACTIVITY_YES
                && ($data['sebsource'] ?? '') === 'client'
                && trim($data['sebkeys'] ?? '') === ''
        ) {
            $errors['sebkeys'] = get_string('sebkeysneeded', 'mod_moodlereader');
        }
        // The mreader.org catalogue has no points, so grading by points needs books that have them.
        if (!empty($data['wordsorpoints']) && !$DB->record_exists_select('moodlereader_books', 'points > 0 AND hidden = 0')) {
            $errors['wordsorpoints'] = get_string('nopointsbooks', 'mod_moodlereader');
        }
        return $errors;
    }

    /**
     * Add custom completion rules.
     *
     * @return array Element names.
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $mform->addElement(
            'advcheckbox',
            'completionpass',
            '',
            get_string('completionpass', 'mod_moodlereader')
        );
        $group = [
            $mform->createElement(
                'advcheckbox',
                'completiontotalwordsenabled',
                '',
                get_string('completiontotalwords', 'mod_moodlereader')
            ),
            $mform->createElement('text', 'completiontotalwords', '', ['size' => 8]),
        ];
        $mform->addGroup(
            $group,
            'completiontotalwordsgroup',
            get_string('completiontotalwords', 'mod_moodlereader'),
            [' '],
            false
        );
        $mform->setType('completiontotalwords', PARAM_INT);
        $mform->disabledIf('completiontotalwords', 'completiontotalwordsenabled', 'notchecked');
        return ['completionpass', 'completiontotalwordsgroup'];
    }

    /**
     * Whether any custom completion rule is enabled.
     *
     * @param array $data Form data.
     * @return bool
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionpass']) ||
            (!empty($data['completiontotalwordsenabled']) && $data['completiontotalwords'] > 0);
    }

    /**
     * Preload derived fields when editing an existing instance.
     *
     * @param array $defaultvalues
     * @return void
     */
    public function data_preprocessing(&$defaultvalues) {
        // The gradebook element is 'grade'; the DB column is 'maxgrade'.
        if (isset($defaultvalues['maxgrade'])) {
            $defaultvalues['grade'] = $defaultvalues['maxgrade'];
        }
        // Reflect the stored word threshold in its enable checkbox.
        if (!empty($defaultvalues['completiontotalwords'])) {
            $defaultvalues['completiontotalwordsenabled'] = 1;
        }
        // The SEB configuration source, from what is stored.
        $method = (int) ($defaultvalues['sebmethod'] ?? 0);
        $defaultvalues['sebsource'] = match ($method) {
            \mod_moodlereader\local\seb::METHOD_CLIENT => 'client',
            \mod_moodlereader\local\seb::METHOD_TEMPLATE => 'template:' . (int) ($defaultvalues['sebtemplateid'] ?? 0),
            \mod_moodlereader\local\seb::METHOD_QUIZ => 'keep',
            default => 'config',
        };
    }

    /**
     * Normalise submitted completion data.
     *
     * @return stdClass|null
     */
    public function get_data() {
        $data = parent::get_data();
        if ($data && !empty($data->completionunlocked)) {
            $autocompletion = !empty($data->completion) && $data->completion == COMPLETION_TRACKING_AUTOMATIC;
            if (empty($data->completiontotalwordsenabled) || !$autocompletion) {
                $data->completiontotalwords = 0;
            }
            if (!$autocompletion) {
                $data->completionpass = 0;
            }
        }
        return $data;
    }
}
