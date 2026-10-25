@mod @mod_moodlereader
Feature: Moodle Reader activity
  In order to run an extensive-reading programme
  As a teacher and student
  I need to add a Moodle Reader activity, see available books, and control access

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
      | student1 | Student   | One      |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity     | name     | course | idnumber | goal | levelcheck |
      | moodlereader | Reader 1 | C1     | reader1  | 5000 | 0          |
    And the following moodlereader books exist:
      | name         | publisher | series  | difficulty | words |
      | The Red Kite | Acme      | Level 1 | 1          | 3000  |
      | Blue Hills   | Acme      | Level 1 | 1          | 4000  |

  Scenario: A student sees their progress and can search for available books
    When I am on the "Reader 1" "moodlereader activity" page logged in as student1
    Then I should see "Reading progress: 0 of 5,000 words"
    And I should see "Search for a book to read"
    And I should not see "The Red Kite"
    When I press "Search"
    Then I should see "The Red Kite"
    And I should see "Blue Hills"
    And I should see "Book quizzes are not available at the moment."
    And I should see "Quiz unavailable"
    And "Take the quiz" "button" should not exist

  Scenario: A student does not see the teacher tools
    When I am on the "Reader 1" "moodlereader activity" page logged in as student1
    Then I should not see "Reading report"
    And I should not see "Manage books"
    And I should not see "Cheating detection"

  Scenario: A teacher sees the report and can message a student
    When I am on the "Reader 1" "moodlereader activity" page logged in as teacher1
    Then I should see "Reading report"
    And I should see "Messages"
    When I follow "Messages"
    And I set the field "Send to" to "Everyone"
    And I set the field "Message" to "Welcome to the reading programme"
    And I press "Send message"
    Then I should see "Message sent"
    And I log out
    When I am on the "Reader 1" "moodlereader activity" page logged in as student1
    Then I should see "Welcome to the reading programme"

  Scenario: On a connected site a student can start a quiz
    Given the site is connected to mreader.org
    When I am on the "Reader 1" "moodlereader activity" page logged in as student1
    And I press "Search"
    Then "Take the quiz" "button" should exist
    And I should not see "Book quizzes are not available at the moment."

  Scenario: A teacher is told why quizzes are unavailable
    When I am on the "Reader 1" "moodlereader activity" page logged in as teacher1
    Then I should see "Students cannot take book quizzes: this site is not connected to mreader.org."

  Scenario: An administrator sees the request form and what it sends
    When I log in as "admin"
    And I visit "/mod/moodlereader/admin/connection.php"
    Then I should see "This site is not connected to mreader.org"
    And I should see "What mreader.org receives about your students."
    And I should see "Request access"
    And the field "Institution" matches value ""
    And I should see "/mod/moodlereader/quiz/return.php"
