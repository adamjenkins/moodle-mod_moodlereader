# Moodle Reader (mod_moodlereader)

An activity module for extensive-reading programmes. Students read graded books
and take a short comprehension quiz — **hosted on [mreader.org](https://mreader.org)
and shown in an iframe** — to confirm they have read each one. Words in passed
books count toward a reading goal that is reported to the Moodle gradebook.

This is a security-focused, single-purpose rebuild of the legacy `mod_reader`
plugin. It keeps the live mreader.org integration and drops the obsolete local
Moodle-quiz engine that `mod_reader` carried (dead since the 2018 migration to the
mreader.org iframe model).

## Requirements

- Moodle 5.2 – 5.3.
- An account on mreader.org, used once to register the site (Site administration →
  Plugins → Activity modules → Moodle Reader → *Register this site*).

## What it does

- The student activity page shows a reading-progress bar, the student's current
  reading level, a **"books read this term"** cover gallery (covers fetched from
  mreader.org and cached), a reading report of this term's attempts, and a **book
  search** (title, publisher, series, genre, reading level). Search results appear
  only after a search and each carries a cover thumbnail and a "take the quiz"
  button; the quiz is delivered by mreader.org in an iframe.
- The signed grade callback from mreader.org is verified (constant-time token check)
  and recorded; each passed book's word count contributes once to the gradebook
  grade.
- Teachers can **manage the catalogue** (filter by publisher/series/level/status and
  enable or disable books individually or in bulk), **sync** it from mreader.org,
  view **reports** (attempts, per-student and per-book summaries), run **cheating
  detection**, **message** students, and set **per-student reading goals**.
- **Appearance** settings let a teacher choose which sections of the activity page
  students see.
- Per-user reading levels, a reading goal (default for everyone, overridable per
  student), availability window, and activity completion rules (pass a book /
  read N words).
- Full privacy provider, backup/restore, and events.

Not yet ported from the legacy plugin: student import/export, reading rates, and
view-as-student.

## Migrating from mod_reader

A CLI tool converts an existing `mod_reader` installation's core data — the
mreader.org registration, book catalogue, the **mreader.org identity map**
(so students keep their remote identity), activities, attempts, grades and reading
levels — into `mod_moodlereader`:

```
php mod/moodlereader/cli/migrate.php --dry-run   # report only, writes nothing
php mod/moodlereader/cli/migrate.php --run        # apply the migration
php mod/moodlereader/cli/migrate.php --help       # (also -h) show usage
```

`--dry-run` (the default if you pass neither flag) reports what would be migrated
without writing anything. `--run` performs the migration inside a single database
transaction, so it either completes fully or rolls back — you are never left with a
half-migrated site.

**Read-only on the legacy data.** The tool only ever *reads* from the `reader_*`
tables and *writes* to the `moodlereader_*` tables. It never deletes, drops or
alters anything in `mod_reader`, so your original data stays intact. After
migrating and confirming the result, you can uninstall the legacy `mod_reader`
plugin yourself — that is a separate, manual step. The local-quiz-only artefacts of
`mod_reader` are intentionally not migrated.

**Safe to re-run.** The migration is idempotent: books are matched on their natural
key (publisher / series / title), and identities and activities that were already
migrated are skipped, so running `--run` twice does not create duplicates. A second
run also acts as a partial sync — it imports **new** activities, books and identity
mappings added to `mod_reader` since the last run. Note, however, that new attempts,
grades or levels added to an activity that was *already* migrated are not
back-filled: re-running skips such activities whole. Migrate once `mod_reader` is no
longer being actively used, or delete a partially-migrated activity and re-run to
refresh it.

**No built-in undo.** There is no rollback or undo command. To reverse a completed
migration you would remove the migrated rows from the `moodlereader`,
`moodlereader_books`, `moodlereader_users`, `moodlereader_attempts`,
`moodlereader_grades`, `moodlereader_levels` and `moodlereader_bookinstances`
tables, along with the course modules and gradebook items the migration created and
the copied mreader.org settings. In practice, uninstalling the `mod_moodlereader`
plugin is the cleanest way to start over — your untouched `mod_reader` data remains
the source of truth until you choose to remove it.

## Security

- Every request handler enforces the appropriate capability and, for state changes,
  `sesskey`.
- The mreader.org grade callback is verified with a constant-time signature check.
- Book covers are served through the Files API (no direct dataroot access).
- Ships a complete privacy (GDPR) provider and PHPUnit tests covering the token
  verification and grading.

## The mreader.org contract (important)

The grade callback path `mod/moodlereader/quiz/mreader.php` is fixed by mreader.org,
which appends `quiz/mreader.php` to the registered site URL. See
`dev-docs` for the integration notes and the one open item to confirm with the
mreader.org service owner before production use.

## Licence

GNU GPL v3 or later.
