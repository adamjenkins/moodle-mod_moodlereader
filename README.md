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
  CI-tested (GitHub Actions, moodle-plugin-ci) on MOODLE_502_STABLE and MOODLE_503_STABLE
  with PHP 8.3 and 8.4.
- mreader.org with API v2 (the mreader.org release after v3.6). If the connection page
  reports `http404` when a request is sent, the mreader.org instance does not offer API v2
  yet; `503 not_configured` means v2 is deployed but not configured there.
- An approved connection to mreader.org (see below). Cron must run, for the result,
  book-sync and connection-check tasks.

## Connecting to mreader.org

Site administration → Plugins → Activity modules → Moodle Reader → **mreader.org
connection**. A site administrator fills in the institution and a contact, sees exactly
what will be sent, and presses **Send request**. An mreader.org administrator reviews the
request and approves or declines it; the page (and an email to the site administrators)
shows the outcome. Until the site is approved, students see the books but no quiz button.
mreader.org can revoke a site's access at any time; the page then shows the reason.

- The connection uses the mreader.org **API v2**: every request is signed with a
  per-site secret (HMAC-SHA256, timestamp and nonce). The secret can be rotated from
  the connection page.
- **No student names, usernames, email addresses or Moodle user ids are ever sent.**
  Each student is known to mreader.org only by a random 8-character code and a random
  display name.
- The quiz runs on mreader.org in a frame. The score is then fetched by this site
  directly from mreader.org with its own credential, never taken from the browser, and a
  task collects any result whose browser hand-back was lost (a closed tab no longer loses
  a grade).
- If mod_reader is installed and registered with mreader.org, the request can ask
  mreader.org to link that registration's quiz history to the new connection (proved by
  a signature made with the old key; the key itself is never sent).
- A server whose clock is more than 300 seconds out is refused; the connection page shows
  the difference.
- When testing against a mreader.org instance on a private network address, Moodle's
  *cURL blocked hosts list* (`curlsecurityblockedhosts`) must allow it.

## What it does

- The student activity page shows a reading-progress bar, the student's current
  reading level, a **"books read this term"** cover gallery (covers fetched from
  mreader.org and cached), a reading report of this term's attempts, and a **book
  search** (title, language, publisher, series, genre, reading level; English is
  selected until the student picks another language). Search results appear
  only after a search and each carries a cover thumbnail and a "take the quiz"
  button; the quiz is delivered by mreader.org in a frame.
- Each passed book's word count contributes once to the gradebook grade.
- Teachers can **manage the site's catalogue** (filter by several publishers, levels and
  languages at once, plus series and status, and enable or disable single books, the ticked
  books, or every book matching the filter; a disabled book is offered to no student),
  **sync** it from mreader.org,
  **message** students, and set **per-student reading goals**.
- **Reports** (modelled on mod_reader's): the *student summary* (levels, goal, passed,
  failed and cheated counts, average time and score, words this term and in all terms)
  and *student details* (every attempt with its result and running total), both
  downloadable. From the summary a teacher can set start/current/stop levels and goals,
  award credit for a book and message students; from the details, set attempts to
  passed or failed, mark or unmark them as cheated, delete or restore them. Every change
  regrades the student and is logged.
- **Cheating detection** with a settings page per activity: three markers (same book
  from the same address or network, a quiz finished too quickly, many quizzes in a short
  time), each can be switched off and tuned. Addresses on an allow-list (the site's
  school addresses plus the activity's own) are never compared, for schools whose
  students share one public address. Flags are advisory until a teacher marks an attempt.
- **Appearance** settings let a teacher choose which sections of the activity page
  students see.
- Per-user reading levels, a reading goal (default for everyone, overridable per
  student), availability window, and activity completion rules (pass a book /
  read N words).
- Full privacy provider, backup/restore, and events.

Not yet ported from the legacy plugin: student import/export, reading rates, and
view-as-student.

## Moving from mod_reader

Site administration → Plugins → Activity modules → Moodle Reader → **Import from
mod_reader** (shown when mod_reader is installed). The workflow is designed to run during a
semester break, while mod_reader stays in use:

1. **Preview** a course: what a sync would create or change, per activity and per kind of
   data. A preview writes nothing.
2. **Sync**: each mod_reader activity gets a **hidden** Moodle Reader copy directly after
   it, with its settings (including group mode, availability and completion), gradebook
   category and pass mark, books, students' attempts, reading levels and goals, and
   optionally the cheating log and teacher messages. Students' mreader.org identities are
   copied unchanged, so their history carries on.
3. **Preview and sync again** as often as you like. Each sync is idempotent: it adds what
   is new in mod_reader, updates what changed, removes what mod_reader no longer has, and
   leaves everything else alone. The preview shows the gradebook totals of the original
   and the copy side by side.
4. **Cut over** (a site administrator confirms it): a final sync runs, then each copy
   becomes visible exactly as its original is, and is no longer synced.

**mod_reader is never changed.** Its tables, activities and settings are only read. The
cutover does not hide or delete the old activities: do that yourself when you are ready.
Nothing is sent to mreader.org during an import.

The same workflow is available from the command line:

```
php mod/moodlereader/cli/import.php --list
php mod/moodlereader/cli/import.php --preview --course=ID
php mod/moodlereader/cli/import.php --sync --course=ID     # or --course=all
php mod/moodlereader/cli/import.php --cutover --course=ID --confirm
```

Not imported, by design: per-student IP locks and attempt-rate limits (not features of
this plugin) and the local-quiz questions (the local quiz engine was removed).

## Security

- Every request handler enforces the appropriate capability and, for state changes,
  `sesskey`.
- API v2 requests are signed (HMAC-SHA256 with timestamp and nonce); quiz results are
  fetched server-to-server and checked against the attempt's student and book.
- Book covers are served through the Files API (no direct dataroot access).
- Ships a complete privacy (GDPR) provider and PHPUnit tests, including the shared
  signature test vectors.

## Licence

GNU GPL v3 or later.
