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

use mod_moodlereader\local\cheatdetector;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Cheating-detection settings of one activity: which markers run, their limits, and the allow-list.
 *
 * Custom data: 'siteallowlist' (string), the site-wide allow-list shown for reference.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cheatsettings_form extends \moodleform {
    /**
     * Define the form.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $str = fn(string $key, $a = null) => get_string($key, 'mod_moodlereader', $a);

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('static', 'cheatintro', '', $str('cheatsettings_intro'));

        // Same address.
        $mform->addElement('header', 'sameipheader', $str('marker_sameip'));
        $mform->setExpanded('sameipheader');
        $mform->addElement('static', 'sameipdesc', '', $str('marker_sameip_desc'));
        $mform->addElement('advcheckbox', 'sameip_enabled', $str('markerenabled'));
        $mform->addElement('duration', 'sameip_window', $str('sameip_window'), ['units' => [MINSECS, HOURSECS, DAYSECS]]);
        $mform->addHelpButton('sameip_window', 'sameip_window', 'mod_moodlereader');
        $mform->addElement('select', 'sameip_match', $str('sameip_match'), [
            cheatdetector::MATCH_EXACT => $str('sameip_match_exact'),
            cheatdetector::MATCH_NETWORK => $str('sameip_match_network'),
            cheatdetector::MATCH_WIDE => $str('sameip_match_wide'),
        ]);
        $mform->addHelpButton('sameip_match', 'sameip_match', 'mod_moodlereader');
        $mform->addElement('textarea', 'allowlist', $str('allowlist'), ['rows' => 4, 'cols' => 40]);
        $mform->setType('allowlist', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('allowlist', 'allowlist', 'mod_moodlereader');
        $site = trim((string) ($this->_customdata['siteallowlist'] ?? ''));
        $mform->addElement(
            'static',
            'siteallowlist',
            $str('siteallowlist'),
            $site === '' ? $str('siteallowlist_none') : nl2br(s($site))
        );
        foreach (['sameip_window', 'sameip_match', 'allowlist'] as $name) {
            $mform->disabledIf($name, 'sameip_enabled');
        }

        // Quick finish.
        $mform->addElement('header', 'quickfinishheader', $str('marker_quickfinish'));
        $mform->setExpanded('quickfinishheader');
        $mform->addElement('static', 'quickfinishdesc', '', $str('marker_quickfinish_desc'));
        $mform->addElement('advcheckbox', 'quickfinish_enabled', $str('markerenabled'));
        $mform->addElement('duration', 'quickfinish_minduration', $str('quickfinish_minduration'), ['units' => [1, MINSECS]]);
        $mform->addHelpButton('quickfinish_minduration', 'quickfinish_minduration', 'mod_moodlereader');
        $mform->disabledIf('quickfinish_minduration', 'quickfinish_enabled');

        // Many quizzes in a short time.
        $mform->addElement('header', 'burstheader', $str('marker_burst'));
        $mform->setExpanded('burstheader');
        $mform->addElement('static', 'burstdesc', '', $str('marker_burst_desc'));
        $mform->addElement('advcheckbox', 'burst_enabled', $str('markerenabled'));
        $mform->addElement('text', 'burst_count', $str('burst_count'), ['size' => 4]);
        $mform->setType('burst_count', PARAM_INT);
        $mform->addHelpButton('burst_count', 'burst_count', 'mod_moodlereader');
        $mform->addElement('duration', 'burst_window', $str('burst_window'), ['units' => [MINSECS, HOURSECS, DAYSECS]]);
        $mform->disabledIf('burst_count', 'burst_enabled');
        $mform->disabledIf('burst_window', 'burst_enabled');

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Check the limits and the allow-list.
     *
     * @param array $data
     * @param array $files
     * @return array Errors by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        // A switched-off marker's fields are disabled, so the browser does not send them.
        $checks = [
            'sameip_window' => ['sameip_enabled', MINSECS, WEEKSECS, true],
            'quickfinish_minduration' => ['quickfinish_enabled', 10, HOURSECS, true],
            'burst_count' => ['burst_enabled', 2, 100, false],
            'burst_window' => ['burst_enabled', MINSECS, WEEKSECS, true],
        ];
        foreach ($checks as $name => [$enabled, $min, $max, $istime]) {
            if (empty($data[$enabled]) || !isset($data[$name])) {
                continue;
            }
            if ($data[$name] < $min || $data[$name] > $max) {
                $errors[$name] = get_string('cheatrange', 'mod_moodlereader', [
                    'min' => $istime ? format_time($min) : $min,
                    'max' => $istime ? format_time($max) : $max,
                ]);
            }
        }
        [, $invalid] = cheatdetector::clean_allowlist((string) ($data['allowlist'] ?? ''));
        if ($invalid) {
            $errors['allowlist'] = get_string('allowlistinvalid', 'mod_moodlereader', s(implode(', ', $invalid)));
        }
        return $errors;
    }

    /**
     * Form values from stored settings.
     *
     * @param array $settings From {@see cheatdetector::settings()}.
     * @return array
     */
    public static function to_form(array $settings): array {
        return [
            'sameip_enabled' => $settings['sameip']['enabled'],
            'sameip_window' => $settings['sameip']['window'],
            'sameip_match' => $settings['sameip']['match'],
            'allowlist' => $settings['allowlist'],
            'quickfinish_enabled' => $settings['quickfinish']['enabled'],
            'quickfinish_minduration' => $settings['quickfinish']['minduration'],
            'burst_enabled' => $settings['burst']['enabled'],
            'burst_count' => $settings['burst']['count'],
            'burst_window' => $settings['burst']['window'],
        ];
    }

    /**
     * Stored settings from submitted form values.
     *
     * @param \stdClass $data
     * @param array $current The settings before the change, kept for fields the browser did not send.
     * @return array Cleaned by {@see cheatdetector::clean_settings()}.
     */
    public static function from_form(\stdClass $data, array $current): array {
        $value = fn(string $name, string $marker, string $key) => $data->$name ?? $current[$marker][$key];
        return cheatdetector::clean_settings([
            'sameip' => [
                'enabled' => $data->sameip_enabled ?? 0,
                'window' => $value('sameip_window', 'sameip', 'window'),
                'match' => $value('sameip_match', 'sameip', 'match'),
            ],
            'quickfinish' => [
                'enabled' => $data->quickfinish_enabled ?? 0,
                'minduration' => $value('quickfinish_minduration', 'quickfinish', 'minduration'),
            ],
            'burst' => [
                'enabled' => $data->burst_enabled ?? 0,
                'count' => $value('burst_count', 'burst', 'count'),
                'window' => $value('burst_window', 'burst', 'window'),
            ],
            'allowlist' => (string) ($data->allowlist ?? $current['allowlist']),
        ]);
    }
}
