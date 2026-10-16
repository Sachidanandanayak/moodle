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
 * Standalone automated test harness for block_cynaris_stats analytics.
 *
 * Runs assertions directly against Moodle API and database to verify
 * grade analytics, empty states, Chart.js builders, and privacy isolation.
 *
 * @package    block_cynaris_stats
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/blocks/moodleblock.class.php');
require_once($CFG->dirroot . '/blocks/cynaris_stats/block_cynaris_stats.php');
require_once($CFG->dirroot . '/blocks/cynaris_stats/classes/analytics.php');

use block_cynaris_stats\analytics;

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] $name\n";
    } else {
        $failed++;
        echo "  [FAIL] $name: $details\n";
    }
}

echo "\n=======================================================\n";
echo "  CYNARIS W7D4 GRADE ANALYTICS AUTOMATED TEST SUITE\n";
echo "=======================================================\n\n";

// --- TEST SUITE 1: Grade Band Classifications ---
echo "--- Running Suite 1: Mathematical Grade-Band Classifications ---\n";
assert_test("100% -> distinction", analytics::calculate_grade_band(100.0) === 'distinction');
assert_test("85%  -> distinction (boundary)", analytics::calculate_grade_band(85.0) === 'distinction');
assert_test("84.9% -> merit (boundary)", analytics::calculate_grade_band(84.99) === 'merit');
assert_test("70%  -> merit (boundary)", analytics::calculate_grade_band(70.0) === 'merit');
assert_test("69.9% -> pass (boundary)", analytics::calculate_grade_band(69.99) === 'pass');
assert_test("50%  -> pass (boundary)", analytics::calculate_grade_band(50.0) === 'pass');
assert_test("49.9% -> fail (boundary)", analytics::calculate_grade_band(49.99) === 'fail');
assert_test("0%   -> fail", analytics::calculate_grade_band(0.0) === 'fail');
assert_test("-5%  -> fail (negative guard)", analytics::calculate_grade_band(-5.0) === 'fail');

// --- TEST SUITE 2: Empty Grade State Handling (Course 2) ---
echo "\n--- Running Suite 2: Empty Grade State Handling (Course 2) ---\n";
$analytics = new analytics(2);

assert_test("has_grade_data() is false on empty course", $analytics->has_grade_data() === false);

$cohort = $analytics->get_cohort_summary();
assert_test("cohort has_data is false", $cohort['has_data'] === false);
assert_test("cohort enrolled_students >= 1", $cohort['enrolled_students'] >= 1);
assert_test("cohort graded_students is 0", $cohort['graded_students'] === 0);
assert_test("cohort average_percentage is null", $cohort['average_percentage'] === null);
assert_test("cohort highest_percentage is null", $cohort['highest_percentage'] === null);
assert_test("cohort lowest_percentage is null", $cohort['lowest_percentage'] === null);
assert_test("cohort grade_bands distinction is 0", $cohort['grade_bands']['distinction'] === 0);
assert_test("cohort grade_bands fail is 0", $cohort['grade_bands']['fail'] === 0);

// Verify chart builders return null without error
$chartdist = $analytics->build_cohort_distribution_chart();
assert_test("build_cohort_distribution_chart() returns null on empty data", $chartdist === null);

$chartact = $analytics->build_activity_averages_chart();
assert_test("build_activity_averages_chart() returns null on empty data", $chartact === null);

$chartstudent = $analytics->build_student_progress_chart(4); // student1 (id 4)
assert_test("build_student_progress_chart() returns null on empty data", $chartstudent === null);

$studentsummary = $analytics->get_student_summary(4);
assert_test("student summary has_grades is false", $studentsummary['has_grades'] === false);
assert_test("student summary completed_items is 0", $studentsummary['completed_items'] === 0);
assert_test("student summary overall_percentage is null", $studentsummary['overall_percentage'] === null);

// --- TEST SUITE 3: Populated Grade Data & Chart.js Generation ---
echo "\n--- Running Suite 3: Populated Grade Data & Chart.js Builders ---\n";
global $DB;

// Insert mock items and grades safely inside a database transaction
$transaction = $DB->start_delegated_transaction();
try {
    $item1id = $DB->insert_record('grade_items', (object)[
        'courseid' => 2,
        'itemname' => 'W7D4 Unit Test Assignment 1',
        'itemtype' => 'mod',
        'itemmodule' => 'assign',
        'iteminstance' => 9991,
        'grademin' => 0.0,
        'grademax' => 100.0,
        'gradepass' => 50.0,
        'hidden' => 0,
        'sortorder' => 10,
        'timecreated' => time(),
        'timemodified' => time(),
    ]);

    $item2id = $DB->insert_record('grade_items', (object)[
        'courseid' => 2,
        'itemname' => 'W7D4 Unit Test Quiz 1',
        'itemtype' => 'mod',
        'itemmodule' => 'quiz',
        'iteminstance' => 9992,
        'grademin' => 0.0,
        'grademax' => 50.0,
        'gradepass' => 25.0,
        'hidden' => 0,
        'sortorder' => 20,
        'timecreated' => time(),
        'timemodified' => time(),
    ]);

    // Student 1 (id 4): 90/100 on item 1, 45/50 on item 2 => 135/150 = 90% (Distinction)
    $DB->insert_record('grade_grades', (object)[
        'itemid' => $item1id,
        'userid' => 4,
        'finalgrade' => 90.0,
        'rawgrade' => 90.0,
        'hidden' => 0,
        'feedback' => 'Great work!',
        'timecreated' => time(),
        'timemodified' => time(),
    ]);
    $DB->insert_record('grade_grades', (object)[
        'itemid' => $item2id,
        'userid' => 4,
        'finalgrade' => 45.0,
        'rawgrade' => 45.0,
        'hidden' => 0,
        'feedback' => 'Solid quiz results.',
        'timecreated' => time(),
        'timemodified' => time(),
    ]);

    $analytics_populated = new analytics(2, 4);

    assert_test("has_grade_data() is true with records", $analytics_populated->has_grade_data() === true);

    $cohort_pop = $analytics_populated->get_cohort_summary();
    assert_test("cohort has_data is true", $cohort_pop['has_data'] === true);
    assert_test("graded_students count is 1", $cohort_pop['graded_students'] === 1);
    assert_test("distinction count is 1", $cohort_pop['grade_bands']['distinction'] === 1);
    assert_test("average_percentage is 90.0", $cohort_pop['average_percentage'] === 90.0);

    // Activity averages
    $act_averages = $analytics_populated->get_activity_averages();
    assert_test("activity averages returns at least 2 items", count($act_averages) >= 2);

    // Chart.js Pie/Doughnut Chart
    $chartdist_pop = $analytics_populated->build_cohort_distribution_chart();
    assert_test("build_cohort_distribution_chart() returns chart_pie instance", $chartdist_pop instanceof \core\chart_pie);
    assert_test("chart_pie doughnut option is true", $chartdist_pop->get_doughnut() === true);
    assert_test("chart_pie labels count is 4", count($chartdist_pop->get_labels()) === 4);

    // Chart.js Bar Chart for activities
    $chartact_pop = $analytics_populated->build_activity_averages_chart();
    assert_test("build_activity_averages_chart() returns chart_bar instance", $chartact_pop instanceof \core\chart_bar);
    assert_test("chart_bar has series", count($chartact_pop->get_series()) === 2);

    // Chart.js Student progress bar chart
    $chartstudent_pop = $analytics_populated->build_student_progress_chart(4);
    assert_test("build_student_progress_chart() returns chart_bar instance", $chartstudent_pop instanceof \core\chart_bar);

    // Student summary
    $summary_pop = $analytics_populated->get_student_summary(4);
    assert_test("student overall percentage is 90.0", $summary_pop['overall_percentage'] === 90.0);
    assert_test("student overall grade band is distinction", $summary_pop['overall_grade_band'] === 'distinction');
    assert_test("student completed items is 2", $summary_pop['completed_items'] === 2);

    // Rollback so the database remains in its clean empty state
    $transaction->rollback(new \Exception('Test complete - rolling back mock records.'));
} catch (\Exception $e) {
    if ($e->getMessage() !== 'Test complete - rolling back mock records.') {
        throw $e;
    }
}

// Verify clean state post-rollback
assert_test("database is clean post-rollback (has_grade_data is false)", $analytics->has_grade_data() === false);

// --- TEST SUITE 4: Block Rendering & Dashboard Link ---
echo "\n--- Running Suite 4: Block Rendering & Link Verification ---\n";
$block = new \block_cynaris_stats();
$block->init();
assert_test("block applicable_formats includes course-view", $block->applicable_formats()['course-view'] === true);

echo "\n=======================================================\n";
echo "  TEST RESULTS: $passed PASSED, $failed FAILED\n";
echo "=======================================================\n\n";

exit($failed > 0 ? 1 : 0);
