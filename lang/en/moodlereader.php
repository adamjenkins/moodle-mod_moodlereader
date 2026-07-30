<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Strings for mod_moodlereader.
 *
 * @package    mod_moodlereader
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['allownextlevel'] = 'Allow books one level above the reading level';
$string['allowprevlevel'] = 'Allow books one level below the reading level';
$string['allowthislevel'] = 'Allow books at the reading level';
$string['availablefrom'] = 'Available from';
$string['availableuntil'] = 'Available until';
$string['book'] = 'Book';
$string['booknotavailable'] = 'That book is not available to you.';
$string['completionpass'] = 'Student must pass at least one book quiz';
$string['completiontotalwords'] = 'Student must read at least this many words';
$string['eventattemptstarted'] = 'Quiz attempt started';
$string['eventattemptsubmitted'] = 'Quiz attempt submitted';
$string['goal'] = 'Reading goal (words)';
$string['goal_help'] = 'The number of words a student must read to earn full marks for this activity.';
$string['gradingheader'] = 'Grading';
$string['invalidbook'] = 'The quiz result did not match the expected book.';
$string['invalidtoken'] = 'The quiz result could not be verified.';
$string['invaliduser'] = 'The quiz result did not match your account.';
$string['levelcheck'] = 'Restrict books to the student\'s reading level';
$string['levelcheck_help'] = 'When enabled, students may only attempt books at (and optionally just above or below) their current reading level.';
$string['levelheader'] = 'Reading levels';
$string['managebooks'] = 'Manage books';
$string['minpassgrade'] = 'Pass mark (%)';
$string['minpassgrade_help'] = 'The minimum quiz percentage a student must score for a book to count toward their reading total.';
$string['modulename'] = 'Moodle Reader';
$string['modulename_help'] = 'The Moodle Reader activity tracks extensive reading. Students read graded books and take a short quiz (hosted on mreader.org) to confirm they have read each one; the words in passed books count toward a reading goal.';
$string['modulenameplural'] = 'Moodle Readers';
$string['moodlereader:addinstance'] = 'Add a new Moodle Reader activity';
$string['moodlereader:attempt'] = 'Take book quizzes';
$string['moodlereader:managebooks'] = 'Manage the book catalogue';
$string['moodlereader:manageremotesites'] = 'Register the site with, and download books from, mreader.org';
$string['moodlereader:manageusers'] = 'Manage students\' reading levels and attempts';
$string['moodlereader:viewbooks'] = 'View and attempt books';
$string['moodlereader:viewreports'] = 'View reading reports';
$string['mreaderpassword'] = 'mreader.org password';
$string['mreaderurl'] = 'mreader.org service URL';
$string['mreaderurl_desc'] = 'The base URL of the mreader.org quiz service. Change only if advised to.';
$string['mreaderusername'] = 'mreader.org username';
$string['nobooksavailable'] = 'There are no books available for you to read at the moment.';
$string['notconfigured'] = 'This site is not yet registered with mreader.org. Ask an administrator to complete registration.';
$string['notregistered'] = 'This site is not registered with mreader.org.';
$string['notyourattempt'] = 'This is not your quiz attempt.';
$string['pluginadministration'] = 'Moodle Reader administration';
$string['pluginname'] = 'Moodle Reader';
$string['points'] = 'Points';
$string['privacy:metadata:core_grades'] = 'Reading grades awarded for this activity are stored in the gradebook.';
$string['privacy:metadata:moodlereader_attempts'] = 'Records of book-quiz attempts.';
$string['privacy:metadata:moodlereader_attempts:bookid'] = 'The book that was attempted.';
$string['privacy:metadata:moodlereader_attempts:percentgrade'] = 'The percentage scored on the quiz.';
$string['privacy:metadata:moodlereader_attempts:timefinish'] = 'The time the attempt finished.';
$string['privacy:metadata:moodlereader_attempts:timestart'] = 'The time the attempt started.';
$string['privacy:metadata:moodlereader_grades'] = 'Aggregate reading grades per user.';
$string['privacy:metadata:moodlereader_grades:rawgrade'] = 'The aggregate grade.';
$string['privacy:metadata:moodlereader_levels'] = 'Per-user reading levels.';
$string['privacy:metadata:moodlereader_levels:currentlevel'] = 'The user\'s current reading level.';
$string['privacy:metadata:moodlereader_users'] = 'The mapping between a Moodle user and their opaque mreader.org identity.';
$string['privacy:metadata:moodlereader_users:uniqueid'] = 'The opaque identifier sent to mreader.org.';
$string['privacy:metadata:mreader.org'] = 'To deliver a book quiz, an opaque per-user identifier (not the real name or email) and the book identifier are sent to the external mreader.org service.';
$string['privacy:metadata:mreader.org:book'] = 'The identifier of the book being quizzed.';
$string['privacy:metadata:mreader.org:uname'] = 'An opaque identifier generated for the user, used in place of their real username.';
$string['progresslabel'] = 'Reading progress: {$a->total} of {$a->goal} words';
$string['publisher'] = 'Publisher';
$string['quizfinished'] = 'Your quiz result has been recorded.';
$string['register'] = 'Register';
$string['registeredas'] = 'This site is registered with mreader.org (site id {$a}).';
$string['registerlink'] = 'Register this site';
$string['registration'] = 'mreader.org registration';
$string['registration_help'] = 'Enter the username and password of your account on mreader.org. The site will exchange them for a site id and secret key, which are then stored. Your password is not retained.';
$string['registrationfailed'] = 'Could not contact mreader.org: {$a}';
$string['registrationinvalid'] = 'mreader.org did not return a valid site id and key. Check the username and password.';
$string['registrationok'] = 'This site is now registered with mreader.org.';
$string['report'] = 'Reading report';
$string['reportintro'] = 'Attempts by students in this activity.';
$string['resetattempts'] = 'Delete all quiz attempts, grades and reading levels';
$string['series'] = 'Series';
$string['showprogressbar'] = 'Show the reading-progress bar';
$string['syncbooks'] = 'Sync books from mreader.org';
$string['synced'] = 'Synced {$a} books from mreader.org.';
$string['takequiz'] = 'Take the quiz';
$string['takequizfor'] = 'Take the quiz for "{$a}"';
$string['words'] = 'Words';
$string['wordsorpoints'] = 'Grade by';
