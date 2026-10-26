# Changelog — mod_moodlereader

The plugin is in pre-release development. The version stays at `0.0.z` until a
`1.0.0` release is explicitly cut; `x`/`y` are not bumped without sign-off.

## [0.1.0] - 2026-10-25

### Added

- mreader.org API v2 client: HMAC-SHA256 request signing (timestamp + nonce), the
  authorization request, status, credential rotation and legacy-registration claim
  (`classes/local/api/`, `classes/local/connection.php`).
- `admin/connection.php`: request access, see the state, check now, sync books, rotate
  the credential; site admins are messaged when the state changes
  (`db/messages.php`, provider `siteconnection`).
- `quiz/return.php`: where a student lands after a quiz; the result is fetched
  server-to-server.
- `siteproof.php`: answers mreader.org's optional site-ownership check.
- Scheduled tasks `reconcile_attempts` (every 15 min), `sync_books` (daily) and
  `refresh_connection` (daily); ad-hoc task `import_course`.
- `admin/import.php`, `cli/import.php` and `classes/local/import/importer.php`: the
  repeatable mod_reader import (preview, sync, re-sync, cutover), with the
  `moodlereader_imports` and `moodlereader_importmap` tables.
- Fields `attemptref`, `remotestate`, `timeresultchecked` on attempts and `withdrawn`,
  `coveretag` on books.
- Reports `usersummary` and `userdetailed` (`classes/local/report/`, on `table_sql`,
  downloadable) with teacher actions in `classes/local/attempt_admin.php`, and the
  `attempt_updated` event.
- `cheatsettings.php` and `cheatsettings_form`: per-activity markers `sameip` (with
  whole-address, /24 or /16 matching), `quickfinish` and `burst`, stored in
  `moodlereader.cheatsettings`; the allow-list (site setting `cheatallowlist` plus the
  activity's own); field `detail` on `moodlereader_cheatlog`.
- Book `language` (from API v2's `language`; '' when unknown) and a language filter,
  English by default, in the student search and in Manage books
  (`classes/local/booklanguage.php`).
- Manage books: multi-select publisher, level and language filters, and "enable/disable
  all N matching books", which acts on every page of the filtered result.

### Changed

- Quiz launches use single-use launch URLs requested server-to-server; the grade
  callback branch of `quiz/mreader.php` is removed.
- Book sync uses API v2 with stable ids and `changed_since`; imported books are linked
  by their legacy image name.
- Plugin settings are a folder: Settings, mreader.org connection, Import from mod_reader.
- Privacy metadata describes the v2 data sent to mreader.org.
- `report.php` shows the student summary, student details and books tabs (the old
  `attempts`/`users` modes open the new reports).
- A scan replaces the flags no teacher has acted on, so changed settings apply.
- The student summary shows and sets only the current reading level (start and stop
  levels have no effect in this plugin); each report action has its own button.
- `startquiz.php` and `quiz/mreader.php` need `viewbooks` (the button's audience), not `attempt`.
- `award.php`: credit is awarded from a search (`attempt_admin::search_award_books()`);
  `find_books()` and the title box are gone. `award_book()` refuses disabled books.
- `moodlereader_books.hasquiz` (version 2026102503): book sync also walks `/books-noquiz`
  (404 = not offered yet), keyed by (remoteid, hasquiz); `has_quiz: false` no longer sets
  `withdrawn`; the importer maps `reader_books.quizid = 0` to `hasquiz = 0`; the student
  search requires `hasquiz = 1`.
- `attempt_admin::students_sql()` also includes everyone with attempts in the activity
  (only oneself when a group narrows the list).
- `classes/local/progressbar.php` and `styles.css`: the colour-banded bar, the second bar
  (`moodlereader.extraprogressbar`, version 2026102504) and the summary's mini bar.
- `classes/local/levels.php`: quotas from `thislevel`/`prevlevel`/`nextlevel` (counts,
  defaults 6/3/1; 0.1.0's on/off values are converted on upgrade), passes since the last
  promotion, `maybe_promote()` from `attempt::finish()`, and the level grid on the activity
  page. The summary shows and sets stop level and promotion again.
- `student.php`, `renderer::reading_page()` (shared with `view.php`) and
  `classes/local/eligibility.php` (the search gate's rules as reasons);
  `reader::search_catalogue()`/`catalogue_menus()`; `records.php?userid=` for teachers.
- Moodle-drawn quizzes (API v2 31 §11, agreed with mreader.org as plan/32), the only delivery:
  `classes/local/quizsession.php` (launch with `"delivery": "moodle"`, the ref saved before it is
  sent and an `attempt_open` naming it taken up from `/attempt/current`; a `launch_url` reply is
  refused as `nomoodledrawing`; start, current, answer, finish, survey; the Moodle-run clock counts
  wall time from the page being served to the answer, never while waiting for mreader.org),
  `quiz/mreader.php` (launcher only), `quiz/render.php`, `quiz/heartbeat.php`, templates
  `quiz_page` and `quiz_survey`, AMD `quizrender` (countdown, heartbeat, ordering by buttons, submit
  at zero). Attempt fields `delivery`, `timelimit`, `slots`, `currentslot`, `secondsused`,
  `timeserved`, `timeheartbeat` (version 2026102505). No question text is stored; pages are sent
  `no-store`. Behat runs against a fake mreader.org (`tests/behat/fake_mreader.php`, Behat site only).
- Safe Exam Browser (plan/50 §5, §9.4): `classes/local/seb.php` (site, then activity; the
  configuration made with core's `quizaccess_seb\property_list`, or based on a `quizaccess_seb`
  template or a quiz's `seb_quiz_settings::get_config()` copied at save, with the activity's
  startURL, quit password and keys; its Config Key from `config_key`; header and JS-API key checks
  as core's `seb_access_manager`, with access kept per session and activity; METHOD_CLIENT without
  keys fails closed), checked on `startquiz.php`, `quiz/mreader.php` and every `quiz/render.php`
  request (not the heartbeat, which serves nothing). `sebconfig.php` serves the `.seb` file,
  `sebcheck.php` and AMD `sebcheck` check SEB's JS-API keys, template `seb_required`, capability
  `mod/moodlereader:manageseb`, site settings `sebmode`, `sebmethod`, `sebtemplate`, `sebkeys`,
  `sebquitpassword`, `sebdownloadlink`. Fields `moodlereader.seb`, `sebmethod`, `sebkeys`,
  `sebquitpassword` (version 2026102600), `sebtemplateid`, `sebconfigxml` (2026102601). Restore
  drops Browser Exam Keys and templates from another site and keeps a copied configuration only if
  it parses.
- Books count once per student site-wide: `reader::available_where()` and `eligibility` read
  finished attempts in every activity; `attempt::finish()` deletes a later duplicate result
  (`already_finished()`); `reader::require_still_available()` re-checks before a launch and before
  the first question; `award_book()` skips books counted anywhere. Table `moodlereader_booksused`
  (version 2026102602) keeps the books used up when a course reset, `moodlereader_delete_instance()`
  or a course deletion removes the attempts (`attempt::keep_books_used()`); every check reads it
  too. It is the student's own data: privacy export and delete in the user context.
- `classes/local/sametitle.php`: the "same book" key normalised as mreader.org's `SameTitleKey::normalise()`
  (lower-case, trimmed, white-space runs incl. no-break and full-width spaces to one space). The sync reads
  `same_title` (proposed for API v2 after v3.8; `sametitle` accepted) and keeps the stored key when an item
  carries neither; the importer and restore normalise; upgrade 2026102603 normalises stored keys.
- Capability `mod/moodlereader:managecatalogue` (system, manager) replaces `managebooks` for
  Manage books (version 2026102601).
- `reader::require_quiz_taker()`: quiz pages need `attempt` (or `viewreports` for a teacher trying
  a quiz), not only `viewbooks`. `quiz/render.php` finishes an open attempt once the activity has
  closed, refuses deleted attempts, and takes the survey only after the quiz has finished.
- Completion rules use `ignoredate`, count each book once and include credit.
- `mod_form` validation refuses `wordsorpoints = 1` when no enabled book has points.
- `moodlereader_books.language` is NOT NULL with no default (XMLDB rejects `''` as a
  CHAR default; CI's install failed on it).
- Covers: only raster images (checked with `getimagesizefromstring`) are cached, stored as
  `cover.<type>`; `pluginfile` force-downloads any other cover file.
- Restore cleans every restored field, skips attempts whose book or user it did not map
  (it no longer falls back to the backup's own ids), and does not restore attempt refs.
- Restore matches catalogue books by `(remoteid, hasquiz)` before the title, never inserts a
  second row for an mreader.org book, and inserts new books hidden.
- Privacy `delete_data_for_users()`: the cheat-log delete used one set of named placeholders twice
  and always threw; it now uses two (test added).
- `messages.php`: in separate groups, only messages to visible students are listed and deletable;
  a message to everyone only by a teacher who sees every student.
- Importer: legacy `abandoned`/`overdue` attempts are imported as finished; a student's level row
  is re-keyed instead of inserted twice when the newest legacy `reader_levels` row changes.
- `attempt::finish()` takes mreader.org's `finished_at` (within the attempt's start and now).
- `mod_form` validation: pass mark 0-100, goal 0-9,999,999, the activity must close after it opens.

### Removed

- The quiz frame: `quiz/status.php`, AMD `quizframe`, `results::launch()`, the signed return link
  in `quiz/return.php`, the activity setting `quizdelivery` (dropped, version 2026102601).
- The course level of Safe Exam Browser: `sebcourse.php`, `sebcourse_form`, the course navigation
  node, the hook `before_course_deleted` and table `moodlereader_sebcourse` (dropped, version
  2026102601; activity value 2 "not required" becomes 0).
- Capability `mod/moodlereader:managebooks`.
- The legacy registration (`admin/register.php`, `register_form`) and md5 signing.
- `cli/migrate.php` and `classes/local/migrator.php` (replaced by the importer).

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
