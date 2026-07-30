# Changelog — mod_moodlereader

## 1.1.0 (2026-07-30)

Feature pass, independent-review fixes, and the corrections found by running the
plugin end-to-end.

Features:
- Expanded teacher reports: attempts, per-student summary, per-book summary.
- Cheating detection: records attempt IP; flags same-book / same-IP / close-time
  passes; teacher mark/unmark (cheated words are excluded from the grade).
- Teacher-to-student messaging shown on the activity page.
- Teacher enable/disable of books in the catalogue.
- Student "My reading records" page (per-book results and running total).

Correctness & security fixes (from an independent review and live testing):
- Grade integrity: a finished attempt excludes its book; each book's words count
  once; in-progress books stay listed to resume.
- Custom completion rules now evaluate (custom_completion class + coursemodule
  info); the form grade element maps to the gradebook max.
- Availability window enforced; migration made idempotent; restore remaps book
  ids by identity (cross-site safe); backup element renamed to avoid a
  final-element name collision.
- Book sync rewritten to mreader.org's real JSON response (was expecting the
  legacy XML); required libraries (`filelib`, plugin `lib.php`) loaded on the
  live curl/grade paths.
- Privacy metadata completed; progress bar and reset aligned; misc hardening.

Schema: added `moodlereader_attempts.ip`, `moodlereader_cheatlog`,
`moodlereader_messages` (with an upgrade step).

Tests: PHPUnit 24 tests; Behat 4 scenarios.

## 1.0.0 (2026-07-30)

Initial release. A security-focused, single-purpose rebuild of the legacy
`mod_reader` plugin, keeping the mreader.org iframe quiz integration and removing
the obsolete local Moodle-quiz engine.

- Student book-quiz flow via the mreader.org iframe, with a constant-time
  verified grade callback.
- Reading goal, per-user reading levels and level-filtered book availability.
- Grades pushed to the gradebook (words in passed books ÷ goal).
- Book catalogue sync and registration with mreader.org (TLS-verified).
- Teacher attempts report.
- Files-API book covers, full privacy provider, backup/restore, completion, events.
- PHPUnit tests for token verification, grading and identity.
- CLI migration from `mod_reader` (config, catalogue, identity map, activities,
  attempts, grades, levels).
