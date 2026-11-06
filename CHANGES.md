# Changelog — mod_moodlereader

## [0.1.0] - 2026-11-06

- A link cannot start a student's quiz: starting one asks the student to confirm first,
  unless they pressed the quiz button.
- Restoring a backup holds levels, pass mark and goal to the activity form's ranges, and a
  restored copy of a finished book does not count a second time.
- A teacher limited to their own groups sees "another student" instead of the name of a
  student outside those groups on the cheating page.
- The privacy declaration now lists the quiz answers, time used, survey answers and language
  sent to mreader.org.
- The capability to manage the mreader.org connection carries configuration and personal-data
  risk flags.
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
- "Manage books" (which applies to the whole site) can filter by several publishers,
  reading levels and languages at once, and can enable or disable every book matching the
  filter in one step, not only the ticked books on the current page.
- Books can be filtered by language, with English selected by default, in the student
  book search and in "Manage books". Books' languages come from mreader.org.
- Book covers are served only when they are JPEG, PNG, GIF or WebP images.
- Teachers can take book quizzes to try them: the "Take the quiz" button they were shown
  used to fail with a permission error.
- The reports list everyone who has taken a quiz in the activity, teachers and
  administrators included, so they can change their own results (for example to show
  students how the progress bar fills).
- Awarding credit opens a book search like the students' one. It lists every book except
  disabled ones, including books without a quiz, which are marked "No quiz". The old
  title box refused any title shared by several books, so on a large catalogue credit
  often could not be awarded at all.
- The progress bar is mod_reader's expanding bar again: it turns from red through orange,
  yellow and green to blue as the goal is reached, with quarter marks, and once the goal is
  passed a second, blue bar counts the extra reading. A new activity setting can turn the
  second bar off.
- The student summary has a miniature progress bar for each student.
- Reading levels work as in mod_reader: students move up a level after passing a set
  number of books at their own level, and may read a set number one level below and one
  above (the settings are numbers again, not on/off switches). Teachers can set stop
  levels and turn moving up on or off per student. With levels restricted, students see a
  grid, as on mreader.org, of how many books they may still read at each level and how
  many more they need to move up.
- Teachers can open any student's reading page from the reports (an icon next to each
  name, or a button on a student's attempts), without logging in as them. It shows what
  the student sees; in its book search each book has a badge saying whether that student
  can take the quiz now and, if not, why (already taken, not at their level, level
  allowance used, another edition read, no quiz, withdrawn, activity closed, not connected).
- New activity setting "Term start date": "This term" in the grade, progress bar, levels
  and reports counts from it (the reports show the date). It existed but could not be
  set, so "this term" silently meant every quiz ever taken.
- Book quizzes are taken in Moodle's own pages, in its look and language: the only way they are
  shown. mreader.org still chooses the questions and marks the answers, and Moodle never stores
  the questions. The countdown runs by the clock while a question is on screen (closing or
  reloading the page does not stop it) and pauses only while the next question loads. At the end
  students can answer mreader.org's short book survey. This needs mreader.org v3.8; with an older
  mreader.org, students are told that quizzes are not available yet. The quiz frame (mreader.org's
  own pages inside Moodle) is gone.
- Book quizzes can require Safe Exam Browser (SEB). A site administrator chooses "Each activity
  decides", "Required everywhere" or "Never"; teachers can require it in an activity's settings.
  The configuration can be made by Moodle, be one of the site's SEB templates (the ones quizzes
  use), be copied from a quiz in the same course, or be SEB's own configuration, checked by its
  Browser Exam Keys (at least one is needed). Only the quizzes are locked: progress, the book
  search and records stay open in any browser. Students outside SEB see a "Launch Safe Exam
  Browser" link instead of the quiz.
- A book counts once for a student on the whole site: a quiz taken in one course, or another
  edition of the same title, is not offered again in any other course, and awarding credit skips
  students who already have the book counted anywhere. A quiz opened in two courses before either
  was finished counts only once. A book used up stays used up for life: resetting the course, or
  deleting the activity or the course, does not free it.
- "Same books" (the same title from another publisher, another edition) follow mreader.org's own
  grouping: the book sync takes each book's "same title" key from mreader.org when it sends one,
  compared as mreader.org compares it (case and spacing do not matter). mreader.org does not check
  this rule for Moodle students, so the plugin is where it is applied. Sending the key needs a small
  addition on mreader.org after v3.8; until then keys imported from mod_reader are kept.
- mreader.org's level "99" entries ("Extra points") are not books: they are no longer synced, and
  any brought in by an earlier sync are removed (or hidden, if already used).
- Students now find books without a quiz in their book search, with "There is no quiz for this book.
  Please see your teacher to get credit for reading it." instead of a quiz button, as in mod_reader.
- "Manage books" and "Award credit for a book" can filter books with or without a quiz.
- Only site administrators (and managers) enable or disable books in "Manage books", which applies
  to the whole site; teachers no longer see it.
- A quiz still open when the activity closes is finished with the answers already given.
- A student whose "take book quizzes" permission is removed can no longer start or answer a quiz.
- In separate groups, the Messages page shows a teacher only the messages to students they can see.
- Restoring a backup reuses the site's catalogue books by their mreader.org id and adds new books
  disabled, so a backup cannot add a second copy of a book.
- Fixed: deleting several users' data for privacy (GDPR) failed on the cheating log.
- Import from mod_reader: abandoned mod_reader attempts are imported as finished, and a re-sync no
  longer fails when a student's newest mod_reader level row has changed.
- Books without a quiz are kept in the catalogue (from mod_reader, and from mreader.org
  once it offers them) so credit can be awarded for them; students never see them as quizzes.
- The "read N words" completion rule now counts what the progress bar and the grade
  count: this term's books, each once, with teacher credit. It used to count every term.
- "Grade by points" is refused when no enabled book has points (mreader.org sends none),
  instead of silently giving every student 0.

## [0.0.3] - 2026-10-04

- The plugin's maturity is now Beta (it was Alpha).
- composer.json now requires `moodle/moodle` `^5.2` rather than `>=5.2 <5.4`, so
  later Moodle 5.x releases are no longer excluded.
- Continuous integration now tests against the released Moodle 5.3
  (`MOODLE_503_STABLE`) instead of Moodle's development branch.
