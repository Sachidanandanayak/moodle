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

namespace block_cynaris_stats;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/cynaris_stats/classes/analytics.php');

/**
 * Unit tests for Cynaris Grade Analytics and Chart builders.
 *
 * @package    block_cynaris_stats
 * @category   test
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class analytics_test extends \advanced_testcase {

    /**
     * Test mathematical grade-band classification across standard boundary conditions.
     */
    public function test_calculate_grade_band(): void {
        // Distinction boundary (>= 85.0%)
        $this->assertEquals('distinction', analytics::calculate_grade_band(100.0));
        $this->assertEquals('distinction', analytics::calculate_grade_band(85.0));

        // Merit boundary (>= 70.0% and < 85.0%)
        $this->assertEquals('merit', analytics::calculate_grade_band(84.99));
        $this->assertEquals('merit', analytics::calculate_grade_band(70.0));

        // Pass boundary (>= 50.0% and < 70.0%)
        $this->assertEquals('pass', analytics::calculate_grade_band(69.99));
        $this->assertEquals('pass', analytics::calculate_grade_band(50.0));

        // Fail boundary (< 50.0%)
        $this->assertEquals('fail', analytics::calculate_grade_band(49.99));
        $this->assertEquals('fail', analytics::calculate_grade_band(0.0));
        $this->assertEquals('fail', analytics::calculate_grade_band(-5.0));
    }

    /**
     * Test empty grade database handling: no records, zero counts, null charts.
     */
    public function test_empty_grade_data(): void {
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Empty Grade Course', 'shortname' => 'EGC-01']);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $analytics = new analytics($course->id, $student->id);

        // 1. Data presence check
        $this->assertFalse($analytics->has_grade_data());

        // 2. Cohort summary fallback
        $cohort = $analytics->get_cohort_summary();
        $this->assertFalse($cohort['has_data']);
        $this->assertEquals(1, $cohort['enrolled_students']);
        $this->assertEquals(0, $cohort['graded_students']);
        $this->assertNull($cohort['average_percentage']);
        $this->assertNull($cohort['highest_percentage']);
        $this->assertNull($cohort['lowest_percentage']);
        $this->assertEquals(0, $cohort['grade_bands']['distinction']);
        $this->assertEquals(0, $cohort['grade_bands']['fail']);

        // 3. Chart builders return null gracefully
        $this->assertNull($analytics->build_cohort_distribution_chart());
        $this->assertNull($analytics->build_activity_averages_chart());
        $this->assertNull($analytics->build_student_progress_chart($student->id));

        // 4. Student summary fallback
        $studentsummary = $analytics->get_student_summary($student->id);
        $this->assertFalse($studentsummary['has_grades']);
        $this->assertEquals(0, $studentsummary['completed_items']);
        $this->assertNull($studentsummary['overall_percentage']);
    }

    /**
     * Test populated grade data with cohort statistics and Chart.js builders.
     */
    public function test_populated_grades_and_charts(): void {
        global $DB;
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Analytics Test Course', 'shortname' => 'ATC-01']);
        $teacher = $generator->create_user();
        $student1 = $generator->create_user();
        $student2 = $generator->create_user();

        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student1->id, $course->id, 'student');
        $generator->enrol_user($student2->id, $course->id, 'student');

        // Create 2 mock grade items in the database
        $item1id = $DB->insert_record('grade_items', (object)[
            'courseid' => $course->id,
            'categoryid' => null,
            'itemname' => 'Assignment 1: Database Design',
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => 1,
            'grademin' => 0.0,
            'grademax' => 100.0,
            'gradepass' => 50.0,
            'hidden' => 0,
            'sortorder' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $item2id = $DB->insert_record('grade_items', (object)[
            'courseid' => $course->id,
            'categoryid' => null,
            'itemname' => 'Quiz 1: SQL Basics',
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => 1,
            'grademin' => 0.0,
            'grademax' => 50.0,
            'gradepass' => 25.0,
            'hidden' => 0,
            'sortorder' => 2,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        // Insert grade records for Student 1 (High achiever: 90/100, 45/50 => 90%)
        $DB->insert_record('grade_grades', (object)[
            'itemid' => $item1id,
            'userid' => $student1->id,
            'finalgrade' => 90.0,
            'rawgrade' => 90.0,
            'hidden' => 0,
            'feedback' => 'Excellent schema design!',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('grade_grades', (object)[
            'itemid' => $item2id,
            'userid' => $student1->id,
            'finalgrade' => 45.0,
            'rawgrade' => 45.0,
            'hidden' => 0,
            'feedback' => 'Great query accuracy.',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        // Insert grade records for Student 2 (Pass achiever: 55/100, 25/50 => 53.3%)
        $DB->insert_record('grade_grades', (object)[
            'itemid' => $item1id,
            'userid' => $student2->id,
            'finalgrade' => 55.0,
            'rawgrade' => 55.0,
            'hidden' => 0,
            'feedback' => 'Satisfactory.',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('grade_grades', (object)[
            'itemid' => $item2id,
            'userid' => $student2->id,
            'finalgrade' => 25.0,
            'rawgrade' => 25.0,
            'hidden' => 0,
            'feedback' => 'Passed.',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $analytics = new analytics($course->id, $student1->id);

        // 1. Data presence check
        $this->assertTrue($analytics->has_grade_data());

        // 2. Cohort summary check
        $cohort = $analytics->get_cohort_summary();
        $this->assertTrue($cohort['has_data']);
        $this->assertEquals(2, $cohort['enrolled_students']);
        $this->assertEquals(2, $cohort['graded_students']);
        $this->assertEquals(1, $cohort['grade_bands']['distinction']);
        $this->assertEquals(1, $cohort['grade_bands']['pass']);
        $this->assertEquals(0, $cohort['grade_bands']['fail']);

        // 3. Activity averages check
        $activities = $analytics->get_activity_averages();
        $this->assertCount(2, $activities);
        $this->assertEquals(72.5, $activities[0]['avg_grade']); // (90 + 55) / 2
        $this->assertEquals(35.0, $activities[1]['avg_grade']); // (45 + 25) / 2

        // 4. Chart builders check
        $distchart = $analytics->build_cohort_distribution_chart();
        $this->assertInstanceOf(\core\chart_pie::class, $distchart);
        $this->assertTrue($distchart->get_doughnut());
        $this->assertCount(4, $distchart->get_labels());

        $actchart = $analytics->build_activity_averages_chart();
        $this->assertInstanceOf(\core\chart_bar::class, $actchart);
        $this->assertCount(2, $actchart->get_labels());

        $studentchart = $analytics->build_student_progress_chart($student1->id);
        $this->assertInstanceOf(\core\chart_bar::class, $studentchart);
        $this->assertCount(2, $studentchart->get_labels());

        // 5. Student summary check
        $summary1 = $analytics->get_student_summary($student1->id);
        $this->assertTrue($summary1['has_grades']);
        $this->assertEquals(2, $summary1['completed_items']);
        $this->assertEquals(135.0, $summary1['total_score']); // 90 + 45
        $this->assertEquals(150.0, $summary1['total_max']);   // 100 + 50
        $this->assertEquals(90.0, $summary1['overall_percentage']);
        $this->assertEquals('distinction', $summary1['overall_grade_band']);
    }

    /**
     * Test student privacy isolation to guarantee peer grades are not exposed.
     */
    public function test_student_privacy_isolation(): void {
        global $DB;
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Privacy Course', 'shortname' => 'PRIV-01']);
        $student1 = $generator->create_user();
        $student2 = $generator->create_user();

        $generator->enrol_user($student1->id, $course->id, 'student');
        $generator->enrol_user($student2->id, $course->id, 'student');

        $itemid = $DB->insert_record('grade_items', (object)[
            'courseid' => $course->id,
            'itemname' => 'Confidential Exam',
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'grademin' => 0.0,
            'grademax' => 100.0,
            'sortorder' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $DB->insert_record('grade_grades', (object)[
            'itemid' => $itemid,
            'userid' => $student1->id,
            'finalgrade' => 75.0,
            'feedback' => 'Feedback for student 1 only.',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $DB->insert_record('grade_grades', (object)[
            'itemid' => $itemid,
            'userid' => $student2->id,
            'finalgrade' => 30.0,
            'feedback' => 'Secret feedback for student 2.',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $analytics = new analytics($course->id);

        // Fetch grades explicitly for Student 1
        $grades1 = $analytics->get_student_grades($student1->id);
        $this->assertCount(1, $grades1);
        $this->assertEquals(75.0, $grades1[0]['finalgrade']);
        $this->assertEquals('Feedback for student 1 only.', $grades1[0]['feedback']);

        // Confirm Student 2's grade and feedback are strictly absent from Student 1's payload
        $this->assertNotEquals(30.0, $grades1[0]['finalgrade']);
        $this->assertStringNotContainsString('Secret feedback for student 2', $grades1[0]['feedback']);
    }
}
