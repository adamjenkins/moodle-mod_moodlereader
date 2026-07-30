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
$string['attempts'] = 'Attempts';
$string['availablefrom'] = 'Available from';
$string['availableuntil'] = 'Available until';
$string['backtoactivity'] = 'Back to the activity';
$string['book'] = 'Book';
$string['booknotavailable'] = 'That book is not available to you.';
$string['bookspassed'] = 'Books passed';
$string['cheated'] = 'Cheated';
$string['cheating'] = 'Cheating detection';
$string['cheatingscan'] = 'Scan for cheating now';
$string['cheatingscanned'] = 'Scan complete: {$a} new suspicious attempt(s) flagged.';
$string['choosebook'] = 'Choose a book to read';
$string['completionpass'] = 'Student must pass at least one book quiz';
$string['completiontotalwords'] = 'Student must read at least this many words';
$string['completiontotalwordsdesc'] = 'Read at least {$a} words in passed books';
$string['currentlevel'] = 'Current level';
$string['disablebook'] = 'Disable';
$string['disabled'] = 'Disabled';
$string['enablebook'] = 'Enable';
$string['enabled'] = 'Enabled';
$string['eventattemptstarted'] = 'Quiz attempt started';
$string['eventattemptsubmitted'] = 'Quiz attempt submitted';
$string['everyone'] = 'Everyone';
$string['existingmessages'] = 'Existing messages';
$string['failed'] = 'Failed';
$string['goal'] = 'Reading goal (words)';
$string['goal_help'] = 'The number of words a student must read to earn full marks for this activity.';
$string['gradingheader'] = 'Grading';
$string['invalidbook'] = 'The quiz result did not match the expected book.';
$string['invalidtoken'] = 'The quiz result could not be verified.';
$string['invaliduser'] = 'The quiz result did not match your account.';
$string['ipaddress'] = 'IP address';
$string['levelcheck'] = 'Restrict books to the student\'s reading level';
$string['levelcheck_help'] = 'When enabled, students may only attempt books at (and optionally just above or below) their current reading level.';
$string['levelheader'] = 'Reading levels';
$string['managebooks'] = 'Manage books';
$string['markcheated'] = 'Mark as cheated';
$string['message'] = 'Message';
$string['messagedeleted'] = 'Message deleted.';
$string['messages'] = 'Messages';
$string['messagesent'] = 'Message sent.';
$string['messagetarget'] = 'Send to';
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
$string['myrecords'] = 'My reading records';
$string['nobooksavailable'] = 'There are no books available for you to read at the moment.';
$string['nocheatingfound'] = 'No suspicious attempts have been flagged.';
$string['norecordsyet'] = 'You have not completed any book quizzes yet.';
$string['noreportdata'] = 'There is no data to report yet.';
$string['notavailablenow'] = 'This activity is not open for quizzes at the moment.';
$string['notconfigured'] = 'This site is not yet registered with mreader.org. Ask an administrator to complete registration.';
$string['notregistered'] = 'This site is not registered with mreader.org.';
$string['notyourattempt'] = 'This is not your quiz attempt.';
$string['passed'] = 'Passed';
$string['passrate'] = 'Pass rate';
$string['pluginadministration'] = 'Moodle Reader administration';
$string['pluginname'] = 'Moodle Reader';
$string['points'] = 'Points';
$string['privacy:metadata:core_grades'] = 'Reading grades awarded for this activity are stored in the gradebook.';
$string['privacy:metadata:moodlereader_attempts'] = 'Records of book-quiz attempts.';
$string['privacy:metadata:moodlereader_attempts:bookid'] = 'The book that was attempted.';
$string['privacy:metadata:moodlereader_attempts:ip'] = 'The IP address from which the attempt was started (used only for cheating detection).';
$string['privacy:metadata:moodlereader_attempts:percentgrade'] = 'The percentage scored on the quiz.';
$string['privacy:metadata:moodlereader_attempts:timefinish'] = 'The time the attempt finished.';
$string['privacy:metadata:moodlereader_attempts:timestart'] = 'The time the attempt started.';
$string['privacy:metadata:moodlereader_cheatlog'] = 'Records of attempts flagged as possible cheating for teacher review.';
$string['privacy:metadata:moodlereader_cheatlog:reason'] = 'Why the attempt was flagged.';
$string['privacy:metadata:moodlereader_cheatlog:userid'] = 'The user whose attempt was flagged.';
$string['privacy:metadata:moodlereader_grades'] = 'Aggregate reading grades per user.';
$string['privacy:metadata:moodlereader_grades:rawgrade'] = 'The aggregate grade.';
$string['privacy:metadata:moodlereader_levels'] = 'Per-user reading levels.';
$string['privacy:metadata:moodlereader_levels:currentlevel'] = 'The user\'s current reading level.';
$string['privacy:metadata:moodlereader_messages'] = 'Messages sent by teachers to individual students.';
$string['privacy:metadata:moodlereader_messages:messagetext'] = 'The message content.';
$string['privacy:metadata:moodlereader_users'] = 'The mapping between a Moodle user and their opaque mreader.org identity.';
$string['privacy:metadata:moodlereader_users:uniqueid'] = 'The opaque identifier sent to mreader.org.';
$string['privacy:metadata:moodlereader_users:uniquename'] = 'The pseudonymous display name sent to mreader.org.';
$string['privacy:metadata:mreader.org'] = 'To deliver a book quiz, an opaque per-user identifier (not the real name or email) and the book identifier are sent to the external mreader.org service.';
$string['privacy:metadata:mreader.org:book'] = 'The identifier of the book being quizzed.';
$string['privacy:metadata:mreader.org:grade'] = 'The quiz grade returned by the service.';
$string['privacy:metadata:mreader.org:sname'] = 'A pseudonymous display name generated for the user.';
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
$string['report_attempts'] = 'Attempts';
$string['report_books'] = 'By book';
$string['report_users'] = 'By student';
$string['reportintro'] = 'Attempts by students in this activity.';
$string['resetattempts'] = 'Delete all quiz attempts, grades and reading levels';
$string['result'] = 'Result';
$string['score'] = 'Score';
$string['sendmessage'] = 'Send message';
$string['series'] = 'Series';
$string['sharedwith'] = 'Shared IP with';
$string['showprogressbar'] = 'Show the reading-progress bar';
$string['status'] = 'Status';
$string['syncbooks'] = 'Sync books from mreader.org';
$string['synced'] = 'Synced {$a} books from mreader.org.';
$string['syncnodata'] = 'mreader.org returned no book data.';
$string['takequiz'] = 'Take the quiz';
$string['takequizfor'] = 'Take the quiz for "{$a}"';
$string['unmarkcheated'] = 'Unmark cheated';
$string['words'] = 'Words';
$string['wordsorpoints'] = 'Grade by';
$string['wordsread'] = 'Words read';
