# Changelog — mod_moodlereader

The plugin is in pre-release development. The version stays at `0.0.z` until a
`1.0.0` release is explicitly cut; `x`/`y` are not bumped without sign-off.

## [0.1.0] - 2026-10-25

- The site now connects to mreader.org through its new API v2. A site administrator
  requests access on the new "mreader.org connection" page, and an mreader.org
  administrator approves, declines or revokes it. The old registration with a
  moodlereader.com username and password is gone. Students see quiz buttons only while
  the site is approved.
- Quiz results are fetched by the site directly from mreader.org, never taken from the
  student's browser, and a task every 15 minutes collects any result that did not come
  back, so a closed tab no longer loses a grade.
- No student name, username, email address or Moodle user id is sent to mreader.org;
  students are known there only by a random code and a random display name.
- New "Import from mod_reader" page (and `cli/import.php`): preview, sync and re-sync
  hidden copies of mod_reader activities as often as needed, then cut over when ready.
  The import now also brings reading goals, the cheating log and (optionally) teacher
  messages, and keeps group mode, availability, completion and gradebook settings.
  mod_reader is never changed. The old `cli/migrate.php` is replaced.
- The book catalogue is matched on mreader.org's stable book ids; books withdrawn by
  mreader.org are hidden from students.
- Requires cron for the new tasks (results, book sync, connection check).
- New student summary and student details reports, as in mod_reader: teachers can set
  attempts to passed or failed, mark them as cheated, delete or restore them, set
  reading levels and goals, award credit for a book and message students. Both reports
  can be downloaded.
- Cheating detection now has a settings page showing what it looks for. Each marker
  (same address, quiz finished too quickly, many quizzes in a short time) can be
  switched off or tuned, and a school can allow-list its own shared addresses for the
  whole site or one activity.
- The book search can select several publishers and reading levels at once and filter
  by language, with English selected by default. Books' languages come from mreader.org.
- Disabling a book on the "Manage books" page now disables it in that activity only. It
  used to hide the book in every course on the site. Site administrators can still
  disable a book for the whole site.
- Book covers are served only when they are JPEG, PNG, GIF or WebP images.

## [0.0.3] - 2026-10-04

- The plugin's maturity is now Beta (it was Alpha).
- composer.json now requires `moodle/moodle` `^5.2` rather than `>=5.2 <5.4`, so
  later Moodle 5.x releases are no longer excluded.
- Continuous integration now tests against the released Moodle 5.3
  (`MOODLE_503_STABLE`) instead of Moodle's development branch.
