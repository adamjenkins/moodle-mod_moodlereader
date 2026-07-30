# Changelog — mod_moodlereader

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
