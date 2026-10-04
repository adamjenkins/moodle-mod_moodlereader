# Changelog — mod_moodlereader

The plugin is in pre-release development. The version stays at `0.0.z` until a
`1.0.0` release is explicitly cut; `x`/`y` are not bumped without sign-off.

## [0.0.3] - 2026-10-04

### Changed

- Maturity is now Beta (`MATURITY_BETA`); it was Alpha.
- composer.json requires `moodle/moodle` `^5.2` instead of `>=5.2 <5.4`: the
  explicit upper cap is dropped so later Moodle 5.x releases are not excluded.
- CI tests `MOODLE_503_STABLE` (PHP 8.3–8.4, PostgreSQL 17, MariaDB 11.4)
  instead of Moodle `main`, now that Moodle 5.3 is released.

## [0.0.2] - 2026-10-04

First release.

A security-focused, single-purpose rebuild of the legacy `mod_reader` plugin,
keeping the mreader.org iframe quiz integration and removing the obsolete local
Moodle-quiz engine.

Student activity page (rebuilt to match the original mod_reader experience):
- Reading-progress bar toward a per-user reading goal.
- "Books read this term" and "…previous terms" book-cover galleries (covers are
  fetched from mreader.org on demand and cached via the Files API).
- Reading report of this term's finished attempts with a running word total.
- Current reading-level panel.
- Book search (title, publisher, series, genre, reading level) — results are
  shown only after a search and include a cover thumbnail and a "take the quiz"
  button. Books already read, hidden books, and out-of-level books are excluded.

Teacher tools:
- Reports: attempts, per-student summary, per-book summary.
- Cheating detection: records attempt IP; flags same-book / same-IP / close-time
  passes; mark/unmark (cheated words are excluded from the grade).
- Teacher-to-student messaging shown on the activity page.
- Enable/disable individual books in the catalogue.
- Per-student reading-goal overrides (default applies to everyone).
- Appearance settings: choose which activity-page sections students see.

Platform:
- mreader.org iframe quiz flow with a constant-time verified grade callback.
- Level-filtered book availability; grades pushed to the gradebook.
- Book catalogue sync from mreader.org's JSON API (TLS-verified).
- Full privacy provider, backup/restore, activity completion, and events.
- CLI migration from `mod_reader` (config, catalogue, identity map, activities,
  attempts, grades, levels) — read-only on the legacy data, idempotent.
- PHPUnit and Behat coverage.
- Declare Moodle 5.3 support (`supported = [502, 503]`), audited against 5.3beta.
- Schema definitions no longer give CHAR NOT NULL columns an empty-string default
  (XMLDB already stored them without one), so installs no longer print XMLDB debugging.
- composer.json, so the plugin can be installed with Composer as
  `adamjenkins/moodle-mod_moodlereader` (Moodle 5.2 – 5.3).
