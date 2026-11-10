# Changelog — mod_moodlereader

## [0.1.2] - 2026-11-10

- Book covers are shown only for books the activity offers (books not disabled in Manage books and,
  when the activity has a book list, on that list) and for books someone has already taken a quiz on
  in the activity. Asking for the cover of any other book now gives "That book is not available to
  you."
- When mreader.org has no cover for a book, the activity remembers that for a day instead of asking
  mreader.org again every time the book is shown. When a book sync reports a changed cover, it is
  fetched the next time the book is shown.
- The plugin's Composer metadata now names the Moodle versions it supports (5.2 and 5.3).
