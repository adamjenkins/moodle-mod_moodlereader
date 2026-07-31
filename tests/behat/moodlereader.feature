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
    And "Take the quiz" "button" should exist

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

  Scenario: A student launches a quiz and is taken to the mreader.org quiz page
    Given the site is registered with mreader.org
    When I am on the "Reader 1" "moodlereader activity" page logged in as student1
    And I press "Search"
    And I press "Take the quiz"
    Then I should see "Take the quiz for"
    And "iframe" "css_element" should exist
