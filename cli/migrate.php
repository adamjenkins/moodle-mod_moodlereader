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
 * CLI: migrate data from the legacy mod_reader plugin into mod_moodlereader.
 *
 * Usage:
 *   php mod/moodlereader/cli/migrate.php --dry-run
 *   php mod/moodlereader/cli/migrate.php --run
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['dry-run' => false, 'run' => false, 'help' => false],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

if ($options['help'] || (!$options['dry-run'] && !$options['run'])) {
    cli_writeln("Migrate mod_reader data into mod_moodlereader.\n");
    cli_writeln("Options:");
    cli_writeln("  --dry-run   Report what would be migrated, writing nothing.");
    cli_writeln("  --run       Perform the migration.");
    cli_writeln("  -h, --help  Show this help.");
    exit(0);
}

if (!\mod_moodlereader\local\migrator::source_available()) {
    cli_error('The legacy mod_reader tables were not found; nothing to migrate.');
}

$dryrun = !$options['run'];
if ($dryrun) {
    cli_heading('DRY RUN — no data will be written');
} else {
    cli_heading('Migrating mod_reader -> mod_moodlereader');
}

$migrator = new \mod_moodlereader\local\migrator($dryrun, fn($msg) => cli_writeln($msg));
$counts = $migrator->run();

cli_heading('Summary');
foreach ($counts as $what => $n) {
    cli_writeln(sprintf('  %-12s %d', $what, $n));
}
cli_writeln($dryrun ? "\nDry run complete. Re-run with --run to apply." : "\nMigration complete.");
exit(0);
