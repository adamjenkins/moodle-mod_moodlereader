# Changelog — mod_moodlereader

## [0.1.1] - 2026-11-09

- The language filter (student book search, Award credit, Manage books) appears only when the books
  are in more than one language, and lists only those languages.
- Manage books is now in Site administration only (Plugins → Activity modules → Moodle Reader →
  Manage books); activities no longer link to it.
- The book sync brings in only the languages an administrator chooses ("Languages to sync"). Books
  with no language (such as those without a quiz) always come; books in a language no longer chosen
  are removed, or hidden if a student or an activity has used them.
- Reading levels: an activity can open every level below the student's, each up to the number of books
  allowed below, as mreader.org does (GitHub issue #1, suggested by Martin Meadows). "One level below"
  stays the default.
- A student who moves up a level is told so, once, on their reading page, with the old and new level
  side by side.
