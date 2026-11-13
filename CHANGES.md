# Changelog — mod_moodlereader

## [Unreleased]

- Wait times between quizzes (GitHub issue #4): on the new "Wait times between quizzes" page of an
  activity, a teacher allows no more than a number of quizzes within a time, by default and for single
  reading levels, picked by the student's reading level (as in mod_reader) or by the book's level.
  Students see when they can take their next quiz. mod_reader's quiz attempt rates are imported.
- Manage books (GitHub issue #3): sort by any column heading, filter by title, and edit a book's
  title, publisher, series, reading level and words for the site. Changes are kept through every
  sync, mreader.org's own value is shown beside a changed field, and "Use mreader.org's details"
  undoes them.

## [0.1.3] - 2026-11-11

- Manage books: the Publisher, Reading level and Language filters are now searchable pickers, each on
  a row of its own. Type part of a name to narrow the list, and each choice shows as a tag you can
  remove. Long publisher names no longer squeeze the list or need sideways scrolling.
