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
 * Cynaris Progress Dashboard page.
 *
 * Renders course grade progress and Chart.js visualisations using Moodle's native Chart API.
 *
 * @package    block_cynaris_stats
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/gradelib.php');

$courseid = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// Authenticate and establish course context
require_login($course);
$context = context_course::instance($course->id);

// Check capability to view dashboard (falls back to course:view if custom cap not yet assigned)
if (has_capability('block/cynaris_stats:viewdashboard', $context)) {
    require_capability('block/cynaris_stats:viewdashboard', $context);
} else {
    require_capability('moodle/course:view', $context);
}

// Check role permissions: teachers/managers see cohort overview, students see personal progress
$canviewall = has_capability('moodle/grade:viewall', $context);

// Page setup
$PAGE->set_course($course);
$PAGE->set_url('/blocks/cynaris_stats/dashboard.php', ['id' => $course->id]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('dashboard_title', 'block_cynaris_stats') . ' - ' . format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('dashboard_title', 'block_cynaris_stats'));

// Initialize analytics service
$analytics = new \block_cynaris_stats\analytics($course->id, $USER->id);
$hasdata = $analytics->has_grade_data();
$cohort = $analytics->get_cohort_summary();
$activities = $analytics->get_activity_averages();
$studentgrades = $analytics->get_student_grades($USER->id);
$studentsummary = $analytics->get_student_summary($USER->id);

echo $OUTPUT->header();

// 1. Navigation & Header bar
echo html_writer::start_div('cynaris-dashboard-wrapper');

echo html_writer::start_div('cynaris-dashboard-header d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom flex-wrap gap-2');
echo html_writer::start_div('cynaris-dashboard-header-text');
echo html_writer::tag('h2', get_string('dashboard_title', 'block_cynaris_stats'), ['class' => 'h3 font-weight-bold mb-1']);
echo html_writer::tag('p', format_string($course->fullname) . ' (' . format_string($course->shortname) . ')', ['class' => 'text-muted mb-0']);
echo html_writer::end_div();

echo html_writer::start_div('cynaris-dashboard-actions d-flex align-items-center gap-2');
$rolelabel = $canviewall
    ? get_string('role_instructor_view', 'block_cynaris_stats')
    : get_string('role_student_view', 'block_cynaris_stats');
$badgeclass = $canviewall ? 'badge-primary' : 'badge-info';
echo html_writer::tag('span', s($rolelabel), ['class' => 'badge ' . $badgeclass . ' p-2 mr-2']);

$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
$recurl = new moodle_url('/blocks/cynaris_stats/recommendations.php', ['id' => $course->id]);
echo html_writer::link($recurl, get_string('ai_recommendations_button', 'block_cynaris_stats'), ['class' => 'btn btn-primary btn-sm mr-1']);
echo html_writer::link($courseurl, get_string('back_to_course', 'block_cynaris_stats'), ['class' => 'btn btn-outline-secondary btn-sm']);
echo html_writer::end_div();
echo html_writer::end_div(); // End header

// 2. State Check: Empty Grade Data vs Populated Data
if (!$hasdata) {
    // Graceful Empty State Presentation
    echo html_writer::start_div('cynaris-empty-state-card border rounded p-4 mb-4 text-center bg-light');
    echo html_writer::div('📊', 'display-4 mb-2');
    echo html_writer::tag('h3', get_string('empty_grade_title', 'block_cynaris_stats'), ['class' => 'h4 font-weight-bold text-dark']);
    echo html_writer::tag('p', get_string('empty_grade_desc', 'block_cynaris_stats'), ['class' => 'text-muted max-w-600 mx-auto mb-4']);

    if ($canviewall) {
        echo html_writer::start_div('alert alert-info text-left mx-auto max-w-700');
        echo html_writer::tag('strong', get_string('instructor_guidance_title', 'block_cynaris_stats') . ': ');
        echo html_writer::tag('span', get_string('instructor_guidance_desc', 'block_cynaris_stats'));
        echo html_writer::end_div();
    } else {
        echo html_writer::start_div('alert alert-secondary text-left mx-auto max-w-700');
        echo html_writer::tag('strong', get_string('student_guidance_title', 'block_cynaris_stats') . ': ');
        echo html_writer::tag('span', get_string('student_guidance_desc', 'block_cynaris_stats'));
        echo html_writer::end_div();
    }

    // Still provide Course Summary Cards so the page is genuinely informative
    echo html_writer::start_div('row g-3 justify-content-center mt-3');

    echo html_writer::start_div('col-md-3 col-sm-6');
    echo html_writer::start_div('card border shadow-sm p-3 text-center');
    echo html_writer::div(s($cohort['enrolled_students']), 'h3 text-primary font-weight-bold mb-1');
    echo html_writer::div(get_string('enrolledstudents', 'block_cynaris_stats'), 'small text-muted text-uppercase');
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('col-md-3 col-sm-6');
    echo html_writer::start_div('card border shadow-sm p-3 text-center');
    echo html_writer::div(s($cohort['total_items']), 'h3 text-success font-weight-bold mb-1');
    echo html_writer::div(get_string('graded_activities_count', 'block_cynaris_stats'), 'small text-muted text-uppercase');
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('col-md-3 col-sm-6');
    echo html_writer::start_div('card border shadow-sm p-3 text-center');
    echo html_writer::div('0', 'h3 text-secondary font-weight-bold mb-1');
    echo html_writer::div(get_string('graded_submissions_count', 'block_cynaris_stats'), 'small text-muted text-uppercase');
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::end_div(); // End row
    echo html_writer::end_div(); // End empty state card

} else {
    // 3. Populated View
    if ($canviewall) {
        // --- INSTRUCTOR / COHORT VIEW ---
        echo html_writer::start_div('cynaris-cohort-overview mb-4');

        // Cohort Summary Cards
        echo html_writer::start_div('row g-3 mb-4');

        // Card 1: Enrolled Students
        echo html_writer::start_div('col-lg-3 col-md-6');
        echo html_writer::start_div('card border shadow-sm p-3');
        echo html_writer::div(get_string('enrolledstudents', 'block_cynaris_stats'), 'small text-muted text-uppercase');
        echo html_writer::div(s($cohort['enrolled_students']), 'h2 text-primary font-weight-bold my-1');
        echo html_writer::div(get_string('cohort_size', 'block_cynaris_stats'), 'small text-secondary');
        echo html_writer::end_div();
        echo html_writer::end_div();

        // Card 2: Graded Students
        echo html_writer::start_div('col-lg-3 col-md-6');
        echo html_writer::start_div('card border shadow-sm p-3');
        echo html_writer::div(get_string('graded_students_title', 'block_cynaris_stats'), 'small text-muted text-uppercase');
        echo html_writer::div(s($cohort['graded_students']), 'h2 text-success font-weight-bold my-1');
        $completionrate = ($cohort['enrolled_students'] > 0)
            ? round(($cohort['graded_students'] / $cohort['enrolled_students']) * 100, 1)
            : 0;
        echo html_writer::div($completionrate . '% ' . get_string('participation_rate', 'block_cynaris_stats'), 'small text-secondary');
        echo html_writer::end_div();
        echo html_writer::end_div();

        // Card 3: Class Average Percentage
        echo html_writer::start_div('col-lg-3 col-md-6');
        echo html_writer::start_div('card border shadow-sm p-3');
        echo html_writer::div(get_string('class_average', 'block_cynaris_stats'), 'small text-muted text-uppercase');
        $avgstr = ($cohort['average_percentage'] !== null) ? $cohort['average_percentage'] . '%' : 'N/A';
        echo html_writer::div(s($avgstr), 'h2 text-info font-weight-bold my-1');
        $band = ($cohort['average_percentage'] !== null) ? \block_cynaris_stats\analytics::calculate_grade_band($cohort['average_percentage']) : 'pending';
        echo html_writer::div(get_string('band_' . $band, 'block_cynaris_stats'), 'badge badge-pill badge-info');
        echo html_writer::end_div();
        echo html_writer::end_div();

        // Card 4: High / Low Score Range
        echo html_writer::start_div('col-lg-3 col-md-6');
        echo html_writer::start_div('card border shadow-sm p-3');
        echo html_writer::div(get_string('score_range', 'block_cynaris_stats'), 'small text-muted text-uppercase');
        $rangestr = ($cohort['highest_percentage'] !== null)
            ? $cohort['lowest_percentage'] . '% - ' . $cohort['highest_percentage'] . '%'
            : 'N/A';
        echo html_writer::div(s($rangestr), 'h2 text-dark font-weight-bold my-1');
        echo html_writer::div(get_string('highest_lowest', 'block_cynaris_stats'), 'small text-secondary');
        echo html_writer::end_div();
        echo html_writer::end_div();

        echo html_writer::end_div(); // End cards row

        // Chart.js Visualisations Section
        echo html_writer::start_div('row g-4 mb-4');

        // Chart 1: Grade Band Distribution (Doughnut)
        $chartdist = $analytics->build_cohort_distribution_chart();
        if ($chartdist) {
            echo html_writer::start_div('col-lg-5 col-md-12');
            echo html_writer::start_div('card border shadow-sm p-3 h-100');
            echo html_writer::tag('h4', get_string('chart_distribution_title', 'block_cynaris_stats'), ['class' => 'h5 font-weight-bold mb-3']);
            echo html_writer::start_div('cynaris-chart-container');
            echo $OUTPUT->render($chartdist);
            echo html_writer::end_div();
            echo html_writer::end_div();
            echo html_writer::end_div();
        }

        // Chart 2: Activity Averages (Bar)
        $chartact = $analytics->build_activity_averages_chart();
        if ($chartact) {
            $colclass = $chartdist ? 'col-lg-7 col-md-12' : 'col-12';
            echo html_writer::start_div($colclass);
            echo html_writer::start_div('card border shadow-sm p-3 h-100');
            echo html_writer::tag('h4', get_string('chart_activities_title', 'block_cynaris_stats'), ['class' => 'h5 font-weight-bold mb-3']);
            echo html_writer::start_div('cynaris-chart-container');
            echo $OUTPUT->render($chartact);
            echo html_writer::end_div();
            echo html_writer::end_div();
            echo html_writer::end_div();
        }

        echo html_writer::end_div(); // End charts row

        // Cohort Activity Table
        if (!empty($activities)) {
            echo html_writer::start_div('card border shadow-sm p-3 mb-4');
            echo html_writer::tag('h4', get_string('activity_breakdown_title', 'block_cynaris_stats'), ['class' => 'h5 font-weight-bold mb-3']);

            $table = new html_table();
            $table->attributes['class'] = 'table table-hover table-striped align-middle';
            $table->head = [
                get_string('activity_name', 'block_cynaris_stats'),
                get_string('activity_type', 'block_cynaris_stats'),
                get_string('max_grade', 'block_cynaris_stats'),
                get_string('class_average', 'block_cynaris_stats'),
                get_string('average_percentage', 'block_cynaris_stats'),
                get_string('graded_submissions_count', 'block_cynaris_stats'),
                get_string('status', 'block_cynaris_stats'),
            ];

            foreach ($activities as $act) {
                $statusbadge = $act['has_grades']
                    ? html_writer::tag('span', get_string('band_' . $act['grade_band'], 'block_cynaris_stats'), ['class' => 'badge badge-success'])
                    : html_writer::tag('span', get_string('no_grades_recorded', 'block_cynaris_stats'), ['class' => 'badge badge-secondary']);

                $table->data[] = [
                    html_writer::tag('strong', s($act['name'])),
                    s(ucfirst($act['module'] ?: $act['type'])),
                    s($act['grademax']),
                    $act['has_grades'] ? s($act['avg_grade']) : '-',
                    $act['has_grades'] ? s($act['percentage']) . '%' : '-',
                    s($act['graded_count']),
                    $statusbadge,
                ];
            }

            echo html_writer::table($table);
            echo html_writer::end_div();
        }

        echo html_writer::end_div(); // End cohort overview

    } else {
        // --- STUDENT PERSONAL VIEW (PRIVACY-PRESERVING) ---
        echo html_writer::start_div('cynaris-student-overview mb-4');

        // Student Metric Cards
        echo html_writer::start_div('row g-3 mb-4');

        // Card 1: Overall Percentage
        echo html_writer::start_div('col-md-4');
        echo html_writer::start_div('card border shadow-sm p-3 text-center');
        echo html_writer::div(get_string('my_overall_grade', 'block_cynaris_stats'), 'small text-muted text-uppercase');
        $myoverallstr = ($studentsummary['overall_percentage'] !== null) ? $studentsummary['overall_percentage'] . '%' : get_string('pending', 'block_cynaris_stats');
        echo html_writer::div(s($myoverallstr), 'h2 text-primary font-weight-bold my-1');
        echo html_writer::div(get_string('total_points_earned', 'block_cynaris_stats') . ': ' . $studentsummary['total_score'] . ' / ' . $studentsummary['total_max'], 'small text-secondary');
        echo html_writer::end_div();
        echo html_writer::end_div();

        // Card 2: Standing / Grade Band
        echo html_writer::start_div('col-md-4');
        echo html_writer::start_div('card border shadow-sm p-3 text-center');
        echo html_writer::div(get_string('my_standing', 'block_cynaris_stats'), 'small text-muted text-uppercase');
        $bandlabel = get_string('band_' . $studentsummary['overall_grade_band'], 'block_cynaris_stats');
        $bandcolor = ($studentsummary['overall_grade_band'] === 'distinction' || $studentsummary['overall_grade_band'] === 'merit') ? 'text-success' : 'text-info';
        echo html_writer::div(s($bandlabel), 'h2 ' . $bandcolor . ' font-weight-bold my-1');
        echo html_writer::div(get_string('grade_scale_reference', 'block_cynaris_stats'), 'small text-secondary');
        echo html_writer::end_div();
        echo html_writer::end_div();

        // Card 3: Completion Progress
        echo html_writer::start_div('col-md-4');
        echo html_writer::start_div('card border shadow-sm p-3 text-center');
        echo html_writer::div(get_string('activities_completed', 'block_cynaris_stats'), 'small text-muted text-uppercase');
        echo html_writer::div($studentsummary['completed_items'] . ' / ' . $studentsummary['total_items'], 'h2 text-dark font-weight-bold my-1');
        $pctcomplete = ($studentsummary['total_items'] > 0) ? round(($studentsummary['completed_items'] / $studentsummary['total_items']) * 100, 1) : 0;
        echo html_writer::div($pctcomplete . '% ' . get_string('completiontracking', 'block_cynaris_stats'), 'small text-secondary');
        echo html_writer::end_div();
        echo html_writer::end_div();

        echo html_writer::end_div(); // End student cards

        // Student Progress Chart (Bar Chart of My Score vs Max Grade)
        $chartstudent = $analytics->build_student_progress_chart($USER->id);
        if ($chartstudent) {
            echo html_writer::start_div('card border shadow-sm p-3 mb-4');
            echo html_writer::tag('h4', get_string('chart_student_title', 'block_cynaris_stats'), ['class' => 'h5 font-weight-bold mb-3']);
            echo html_writer::start_div('cynaris-chart-container');
            echo $OUTPUT->render($chartstudent);
            echo html_writer::end_div();
            echo html_writer::end_div();
        }

        // Student Grades Breakdown Table
        if (!empty($studentgrades)) {
            echo html_writer::start_div('card border shadow-sm p-3 mb-4');
            echo html_writer::tag('h4', get_string('my_grades_breakdown', 'block_cynaris_stats'), ['class' => 'h5 font-weight-bold mb-3']);

            $table = new html_table();
            $table->attributes['class'] = 'table table-hover table-striped align-middle';
            $table->head = [
                get_string('activity_name', 'block_cynaris_stats'),
                get_string('max_grade', 'block_cynaris_stats'),
                get_string('my_score', 'block_cynaris_stats'),
                get_string('percentage', 'block_cynaris_stats'),
                get_string('result', 'block_cynaris_stats'),
                get_string('feedback', 'block_cynaris_stats'),
            ];

            foreach ($studentgrades as $g) {
                if ($g['has_grade']) {
                    $scorestr = s($g['finalgrade']);
                    $pctstr = s($g['percentage']) . '%';
                    $statusbadge = html_writer::tag('span', get_string('band_' . $g['grade_band'], 'block_cynaris_stats'), ['class' => 'badge badge-success']);
                } else {
                    $scorestr = '-';
                    $pctstr = '-';
                    $statusbadge = html_writer::tag('span', get_string('pending', 'block_cynaris_stats'), ['class' => 'badge badge-secondary']);
                }

                $feedbackstr = !empty($g['feedback']) ? format_text($g['feedback'], FORMAT_HTML) : '-';

                $table->data[] = [
                    html_writer::tag('strong', s($g['name'])),
                    s($g['grademax']),
                    $scorestr,
                    $pctstr,
                    $statusbadge,
                    $feedbackstr,
                ];
            }

            echo html_writer::table($table);
            echo html_writer::end_div();
        }

        echo html_writer::end_div(); // End student overview
    }
}

echo html_writer::end_div(); // End cynaris-dashboard-wrapper

echo $OUTPUT->footer();
