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

- Students see the books available to them (filtered by reading level), launch a
  book quiz delivered by mreader.org in an iframe, watch a reading-progress bar
  advance as they pass books, and review their own **reading records**.
- The signed grade callback from mreader.org is verified (constant-time token check)
  and recorded; each passed book's word count contributes once to the gradebook
  grade.
- Teachers can **sync the book catalogue** from mreader.org, **enable/disable**
  individual books, view **reports** (attempts, per-student and per-book summaries),
  run **cheating detection**, and **message** students.
- Per-user reading levels, reading goal, availability window, and activity
  completion rules (pass a book / read N words).
- Full privacy provider, backup/restore, and events.

Not yet ported from the legacy plugin: student import/export, goals/rates, and
view-as-student.

## Migrating from mod_reader

A CLI tool converts an existing `mod_reader` installation's core data — the
mreader.org registration, book catalogue, the **mreader.org identity map**
(so students keep their remote identity), activities, attempts, grades and reading
levels — into `mod_moodlereader`:

```
php mod/moodlereader/cli/migrate.php --dry-run   # report only
php mod/moodlereader/cli/migrate.php --run        # apply
```

After migrating and confirming, the legacy `mod_reader` plugin can be uninstalled.
The local-quiz-only artefacts of `mod_reader` are intentionally not migrated.

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
