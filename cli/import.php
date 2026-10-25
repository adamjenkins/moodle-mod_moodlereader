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
 * CLI: import mod_reader activities into mod_moodlereader (the same workflow as the admin page).
 *
 * Usage:
 *   php mod/moodlereader/cli/import.php --list
 *   php mod/moodlereader/cli/import.php --preview --course=12
 *   php mod/moodlereader/cli/import.php --sync --course=12      (or --course=all)
 *   php mod/moodlereader/cli/import.php --cutover --course=12 --confirm
 *
 * mod_reader's activities and data are only read, never changed.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use mod_moodlereader\local\import\importer;

[$options, $unrecognised] = cli_get_params(
    ['list' => false, 'preview' => false, 'sync' => false, 'cutover' => false, 'course' => '', 'confirm' => false,
        'help' => false],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

$actions = array_filter([$options['list'], $options['preview'], $options['sync'], $options['cutover']]);
if ($options['help'] || count($actions) !== 1) {
    cli_writeln("Import mod_reader activities into mod_moodlereader.\n");
    cli_writeln("Options (exactly one action):");
    cli_writeln("  --list                    List the courses that have mod_reader activities.");
    cli_writeln("  --preview --course=ID     Show what a sync would change (writes nothing).");
    cli_writeln("  --sync --course=ID|all    Create or update the hidden copies.");
    cli_writeln("  --cutover --course=ID --confirm");
    cli_writeln("                            Final sync, then make the copies visible like their originals.");
    cli_writeln("  -h, --help                Show this help.");
    exit(0);
}

if (!importer::source_available()) {
    cli_error(get_string('importnosource', 'mod_moodlereader'));
}

$courses = importer::courses();
if ($options['list']) {
    foreach ($courses as $c) {
        cli_writeln(sprintf(
            '%6d  %-40s activities %d, students %d, attempts %d, synced %d, cut over %d',
            $c['courseid'],
            core_text::substr($c['fullname'], 0, 40),
            $c['activities'],
            $c['students'],
            $c['attempts'],
            $c['synced'],
            $c['cutover']
        ));
    }
    exit(0);
}

$ids = $options['course'] === 'all' && !$options['cutover'] ? array_keys($courses) : [(int) $options['course']];
foreach ($ids as $courseid) {
    if (!isset($courses[$courseid])) {
        cli_error("Course $courseid has no mod_reader activities.");
    }
}
if ($options['cutover'] && !$options['confirm']) {
    cli_error('A cutover makes the copies visible to students. Add --confirm to go ahead.');
}

$importer = new importer();
foreach ($ids as $courseid) {
    cli_heading(format_string($courses[$courseid]['fullname']) . " ($courseid)");
    if ($options['preview']) {
        $report = $importer->preview($courseid);
    } else if ($options['sync']) {
        $report = $importer->sync($courseid);
    } else {
        $report = $importer->cutover($courseid);
    }
    $line = fn(string $what, array $c) => cli_writeln(sprintf(
        '  %-10s new %d, changed %d, unchanged %d, removed %d',
        $what,
        $c['new'],
        $c['changed'],
        $c['unchanged'],
        $c['removed']
    ));
    $line('books', $report['books']);
    $line('students', $report['users']);
    if ($report['users']['conflicts']) {
        cli_writeln('  ' . get_string('importconflicts', 'mod_moodlereader', $report['users']['conflicts']));
    }
    foreach ($report['activities'] as $activity) {
        cli_writeln('  ' . $activity['name'] . ': ' . $activity['action'] .
            ($activity['newcmid'] ? ' (copy cmid ' . $activity['newcmid'] . ')' : ''));
        foreach ($activity['items'] as $type => $c) {
            $line('  ' . $type, $c);
        }
        cli_writeln(sprintf(
            '    grades: mod_reader %s, copy %s',
            $activity['gradesold'] ?? '-',
            $activity['gradesnew'] ?? '-'
        ));
    }
    foreach ($report['warnings'] as $warning) {
        cli_writeln('  ! ' . $warning);
    }
    if (isset($report['cutover'])) {
        cli_writeln("  Cut over: {$report['cutover']} activities.");
    }
}
exit(0);
