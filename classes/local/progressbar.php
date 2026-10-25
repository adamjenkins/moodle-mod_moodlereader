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

use html_writer;

/**
 * The reading-progress bar, as in mod_reader's "expanding" bar.
 *
 * The bar changes colour as the student reads: red up to a quarter of the goal, then
 * orange, yellow and green, and blue once the goal is reached. Past the goal a second
 * bar appears for the words read beyond it, so there is always more to aim for (an
 * activity can switch the second bar off).
 *
 * @package    mod_moodlereader
 * @copyright  2013 Gordon Bateson (gordon.bateson@gmail.com)
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class progressbar {
    /**
     * The colour band for a percentage of the goal.
     *
     * @param float $percent
     * @return string One of red, orange, yellow, green, blue.
     */
    public static function band(float $percent): string {
        if ($percent >= 100) {
            return 'blue';
        }
        if ($percent > 75) {
            return 'green';
        }
        if ($percent > 50) {
            return 'yellow';
        }
        return $percent > 25 ? 'orange' : 'red';
    }

    /**
     * Percentage of a goal, not capped (it can pass 100).
     *
     * @param float $total
     * @param float $goal
     * @return float
     */
    public static function percent(float $total, float $goal): float {
        return $goal > 0 ? 100 * max(0, $total) / $goal : 0;
    }

    /**
     * The full bar(s) for the activity page.
     *
     * @param float $total Words (or points) read this term.
     * @param float $goal The student's goal.
     * @param bool $points Count points rather than words.
     * @param bool $second Show the second bar once the goal is passed.
     * @return string HTML.
     */
    public static function render(float $total, float $goal, bool $points, bool $second): string {
        $unit = $points ? 'points' : 'words';
        $percent = self::percent($total, $goal);
        $label = $total > $goal
            ? get_string('progresslabelreached_' . $unit, 'mod_moodlereader', self::number($goal, $points))
            : get_string('progresslabel_' . $unit, 'mod_moodlereader', [
                'total' => self::number($total, $points), 'goal' => self::number($goal, $points),
            ]);
        $out = html_writer::tag('h3', s($label), ['class' => 'h4']);
        $out .= self::bar($total, $goal, $points, get_string('percentcomplete', 'mod_moodlereader', (int) min(100, $percent)));

        // Past the goal: a second bar for the extra reading, against the next multiple of the goal.
        if ($second && $total > $goal) {
            $extra = $total - $goal;
            $nextgoal = $goal * max(1, (int) floor($total / $goal));
            $out .= html_writer::tag('h3', s(get_string(
                'progressextra_' . $unit,
                'mod_moodlereader',
                self::number($extra, $points)
            )), ['class' => 'h5 mt-3']);
            $out .= self::bar($extra, $nextgoal, $points, get_string(
                'progressextracaption_' . $unit,
                'mod_moodlereader',
                self::number($extra, $points)
            ));
        }
        return html_writer::div($out, 'mod-moodlereader-progress mb-3');
    }

    /**
     * One bar with its quarter marks.
     *
     * @param float $total
     * @param float $goal
     * @param bool $points
     * @param string $caption Text on the coloured part.
     * @return string HTML.
     */
    protected static function bar(float $total, float $goal, bool $points, string $caption): string {
        $percent = self::percent($total, $goal);
        $width = round(min(100, $percent), 1);
        $fill = html_writer::div(s($caption), 'progress-bar mr-progress-' . self::band($percent), [
            'role' => 'progressbar', 'style' => "width: {$width}%;",
            'aria-valuenow' => (int) min(100, $percent), 'aria-valuemin' => 0, 'aria-valuemax' => 100,
            'aria-label' => $caption,
        ]);
        $marks = '';
        foreach ([0, 0.25, 0.5, 0.75, 1] as $fraction) {
            $marks .= html_writer::span(s(self::number($goal * $fraction, $points)));
        }
        return html_writer::div($fill, 'progress mr-progress') .
            html_writer::div($marks, 'mr-progress-marks', ['aria-hidden' => 'true']);
    }

    /**
     * The miniature bar for a report row.
     *
     * @param float $total
     * @param float $goal
     * @param bool $points
     * @return string HTML.
     */
    public static function mini(float $total, float $goal, bool $points): string {
        $percent = self::percent($total, $goal);
        $text = get_string('progressmini', 'mod_moodlereader', [
            'total' => self::number($total, $points), 'goal' => self::number($goal, $points),
            'percent' => (int) round($percent),
        ]);
        $fill = html_writer::div('', 'progress-bar mr-progress-' . self::band($percent), [
            'style' => 'width: ' . round(min(100, $percent), 1) . '%;',
        ]);
        // Bar and percentage on one line.
        return html_writer::span(
            html_writer::div($fill, 'progress mr-progress-mini', ['role' => 'img', 'aria-label' => $text, 'title' => $text]) .
            html_writer::span(s((int) round($percent) . '%'), 'mr-progress-mini-label'),
            'text-nowrap'
        );
    }

    /**
     * Format words (whole) or points (two decimals).
     *
     * @param float $value
     * @param bool $points
     * @return string
     */
    public static function number(float $value, bool $points): string {
        return $points ? format_float($value, 2, true, true) : number_format((int) round($value));
    }
}
