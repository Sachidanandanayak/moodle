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
 * Cynaris AI Learning Recommendations Portal (W8D3).
 *
 * Renders evidence-based learning recommendations and weak-area analysis
 * computed from verified Moodle gradebook records and activity completions.
 *
 * @package    block_cynaris_stats
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/gradelib.php');

$courseid = required_param('id', PARAM_INT);
$targetuserid = optional_param('userid', 0, PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// Authenticate and establish context
require_login($course);
$context = context_course::instance($course->id);

// Check access capabilities
if (has_capability('block/cynaris_stats:viewrecommendations', $context)) {
    require_capability('block/cynaris_stats:viewrecommendations', $context);
} else {
    require_capability('moodle/course:view', $context);
}

$canviewall = has_capability('moodle/grade:viewall', $context);

// Role enforcement: Students only inspect themselves; instructors can inspect any enrolled student
if (!$canviewall || $targetuserid <= 0) {
    $targetuserid = (int)$USER->id;
}

$targetuser = $DB->get_record('user', ['id' => $targetuserid], '*', MUST_EXIST);

// Page setup
$PAGE->set_course($course);
$PAGE->set_url('/blocks/cynaris_stats/recommendations.php', ['id' => $course->id, 'userid' => $targetuser->id]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('rec_title', 'block_cynaris_stats') . ' - ' . format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('view_progress_dashboard', 'block_cynaris_stats'), new moodle_url('/blocks/cynaris_stats/dashboard.php', ['id' => $course->id]));
$PAGE->navbar->add(get_string('rec_title', 'block_cynaris_stats'));

// Initialize services
$aitutor = new \block_cynaris_stats\ai_tutor();
$engine = new \block_cynaris_stats\recommendation_engine($course->id, $targetuser->id, $aitutor, true);
$report = $engine->get_full_report(format_string($course->fullname));

$grades = $report['grade_summary'];
$completion = $report['completion_summary'];
$weakareas = $report['weak_areas'];
$recommendations = $report['recommendations'];
$studyplan = $report['study_plan'];

echo $OUTPUT->header();

echo html_writer::start_div('cynaris-rec-wrapper p-2');

// 1. Header & Navigation
echo html_writer::start_div('d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom flex-wrap gap-2');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('rec_heading', 'block_cynaris_stats'), ['class' => 'h3 font-weight-bold mb-1 text-dark']);
echo html_writer::tag('p', get_string('rec_desc', 'block_cynaris_stats'), ['class' => 'text-muted mb-0']);
echo html_writer::end_div();

echo html_writer::start_div('d-flex align-items-center gap-2 flex-wrap');
$dashboardurl = new moodle_url('/blocks/cynaris_stats/dashboard.php', ['id' => $course->id]);
echo html_writer::link($dashboardurl, get_string('rec_view_dashboard', 'block_cynaris_stats'), ['class' => 'btn btn-outline-primary btn-sm']);

$tutorurl = new moodle_url('/blocks/cynaris_stats/tutor.php', ['id' => $course->id]);
echo html_writer::link($tutorurl, get_string('rec_open_tutor', 'block_cynaris_stats'), ['class' => 'btn btn-info btn-sm text-white']);

$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
echo html_writer::link($courseurl, get_string('back_to_course', 'block_cynaris_stats'), ['class' => 'btn btn-outline-secondary btn-sm']);
echo html_writer::end_div();
echo html_writer::end_div(); // End header

// 2. Instructor Student Selector (if instructor)
if ($canviewall) {
    // Fetch all enrolled students
    $enrolledstudents = get_enrolled_users($context, 'moodle/course:isincompletionreports');
    if (empty($enrolledstudents)) {
        $enrolledstudents = get_enrolled_users($context);
    }

    echo html_writer::start_div('card border-primary mb-4 bg-light shadow-sm');
    echo html_writer::start_div('card-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-3');
    echo html_writer::tag('div',
        html_writer::tag('strong', '👨‍🏫 ' . get_string('role_instructor_view', 'block_cynaris_stats') . ': ') .
        'Viewing recommendations for ' . html_writer::tag('strong', s(fullname($targetuser))) . ' (' . s($targetuser->email) . ')',
        ['class' => 'text-primary mb-0']
    );

    echo html_writer::start_tag('form', [
        'method' => 'GET',
        'action' => (new moodle_url('/blocks/cynaris_stats/recommendations.php'))->out(false),
        'class' => 'd-flex align-items-center gap-2 m-0',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $course->id]);
    echo html_writer::tag('label', get_string('rec_select_student', 'block_cynaris_stats'), ['for' => 'select_userid', 'class' => 'small font-weight-bold mb-0 mr-2']);

    echo html_writer::start_tag('select', [
        'id' => 'select_userid',
        'name' => 'userid',
        'class' => 'form-control form-control-sm custom-select',
        'onchange' => 'this.form.submit()',
    ]);
    foreach ($enrolledstudents as $stu) {
        $selected = ($stu->id == $targetuser->id) ? ['selected' => 'selected'] : [];
        echo html_writer::tag('option', s(fullname($stu)) . ' (' . s($stu->username) . ')', array_merge(['value' => $stu->id], $selected));
    }
    echo html_writer::end_tag('select');
    echo html_writer::end_tag('form');

    echo html_writer::end_div();
    echo html_writer::end_div();
}

// 3. Telemetry KPI Cards
echo html_writer::start_div('row mb-4');

// Card 1: Verified Score & Band
$scoretext = ($grades['overall_percentage'] !== null) ? $grades['overall_percentage'] . '%' : 'Pending';
$bandbadgeclass = 'badge-secondary';
if ($grades['overall_grade_band'] === 'distinction') {
    $bandbadgeclass = 'badge-success';
} elseif ($grades['overall_grade_band'] === 'merit') {
    $bandbadgeclass = 'badge-info';
} elseif ($grades['overall_grade_band'] === 'pass') {
    $bandbadgeclass = 'badge-warning';
} elseif ($grades['overall_grade_band'] === 'fail') {
    $bandbadgeclass = 'badge-danger';
}

echo html_writer::start_div('col-md-3 col-sm-6 mb-3');
echo html_writer::start_div('card border-0 shadow-sm rounded-lg p-3 bg-white h-100 text-center');
echo html_writer::div('🎯', 'font-size-2rem mb-1');
echo html_writer::div(s($scoretext), 'h3 font-weight-bold mb-1 text-primary');
echo html_writer::tag('span', ucfirst($grades['overall_grade_band']), ['class' => 'badge ' . $bandbadgeclass . ' mb-2']);
echo html_writer::div(get_string('rec_score_label', 'block_cynaris_stats'), 'small text-muted text-uppercase font-weight-bold');
echo html_writer::end_div();
echo html_writer::end_div();

// Card 2: Assessment Ratio
$assessratio = $grades['attempted_items'] . ' / ' . $grades['total_items'];
echo html_writer::start_div('col-md-3 col-sm-6 mb-3');
echo html_writer::start_div('card border-0 shadow-sm rounded-lg p-3 bg-white h-100 text-center');
echo html_writer::div('📝', 'font-size-2rem mb-1');
echo html_writer::div(s($assessratio), 'h3 font-weight-bold mb-1 text-dark');
echo html_writer::div('Graded Submissions', 'badge badge-light border mb-2 text-dark');
echo html_writer::div('Assessments Attempted', 'small text-muted text-uppercase font-weight-bold');
echo html_writer::end_div();
echo html_writer::end_div();

// Card 3: Activity Completion Rate
$comppct = $completion['completion_percentage'] . '%';
echo html_writer::start_div('col-md-3 col-sm-6 mb-3');
echo html_writer::start_div('card border-0 shadow-sm rounded-lg p-3 bg-white h-100 text-center');
echo html_writer::div('✅', 'font-size-2rem mb-1');
echo html_writer::div(s($comppct), 'h3 font-weight-bold mb-1 text-success');
echo html_writer::div($completion['completed_modules'] . ' of ' . ($completion['tracked_modules'] ?: $completion['total_modules']) . ' Items', 'badge badge-light border mb-2 text-dark');
echo html_writer::div(get_string('rec_completion_label', 'block_cynaris_stats'), 'small text-muted text-uppercase font-weight-bold');
echo html_writer::end_div();
echo html_writer::end_div();

// Card 4: Identified Gaps
$gapcount = count(array_filter($weakareas, function ($w) {
    return $w['severity'] === 'critical' || $w['severity'] === 'warning';
}));
$gapclass = ($gapcount > 0) ? 'text-danger' : 'text-success';
$gapbadge = ($gapcount > 0) ? 'badge-danger' : 'badge-success';

echo html_writer::start_div('col-md-3 col-sm-6 mb-3');
echo html_writer::start_div('card border-0 shadow-sm rounded-lg p-3 bg-white h-100 text-center');
echo html_writer::div('🔍', 'font-size-2rem mb-1');
echo html_writer::div(s($gapcount), 'h3 font-weight-bold mb-1 ' . $gapclass);
echo html_writer::tag('span', ($gapcount > 0 ? $gapcount . ' Gaps Detected' : 'All Clear'), ['class' => 'badge ' . $gapbadge . ' mb-2']);
echo html_writer::div('Remediation Gaps', 'small text-muted text-uppercase font-weight-bold');
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div(); // End KPI row

// 4. Strategic AI Study Plan Coaching Card
echo html_writer::start_div('card shadow-sm border-0 mb-4 rounded-lg overflow-hidden');
$aiproviderlabel = ($studyplan['provider'] === 'groq') ? 'Groq Llama 3.3 70B' :
    (($studyplan['provider'] === 'openrouter') ? 'OpenRouter Fallback' : 'Deterministic Rule Engine');
$aiproviderbadge = $studyplan['ai_enriched'] ? 'badge-success' : 'badge-secondary';

echo html_writer::start_div('card-header d-flex justify-content-between align-items-center text-white flex-wrap gap-2', [
    'style' => 'background: linear-gradient(135deg, #1e3a8a, #3b82f6);',
]);
echo html_writer::tag('h3', '🤖 ' . get_string('rec_ai_plan_title', 'block_cynaris_stats'), ['class' => 'h5 font-weight-bold mb-0 text-white']);
echo html_writer::start_div('d-flex gap-2 align-items-center');
if (!empty($studyplan['latency_ms']) && $studyplan['latency_ms'] > 0) {
    echo html_writer::tag('span', round($studyplan['latency_ms']) . ' ms', ['class' => 'badge badge-light small']);
}
echo html_writer::tag('span', s($aiproviderlabel), ['class' => 'badge ' . $aiproviderbadge . ' text-white small p-2']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('card-body p-4 bg-white');
echo html_writer::tag('div', nl2br(s($studyplan['plan_summary'])), ['class' => 'lead text-secondary mb-0', 'style' => 'font-size: 1.05rem; line-height: 1.7;']);
echo html_writer::end_div();
echo html_writer::end_div();

// 5. Prioritized Recommended Actions (Action Cards)
echo html_writer::start_div('mb-4');
echo html_writer::tag('h3', '📌 ' . get_string('rec_actions_title', 'block_cynaris_stats'), ['class' => 'h4 font-weight-bold text-dark mb-3']);

echo html_writer::start_div('row');
foreach ($recommendations as $rec) {
    $cardborder = 'border-secondary';
    if ($rec['priority'] === 'critical') {
        $cardborder = 'border-danger';
    } elseif ($rec['priority'] === 'high') {
        $cardborder = 'border-warning';
    } elseif ($rec['priority'] === 'low') {
        $cardborder = 'border-success';
    }

    echo html_writer::start_div('col-lg-6 col-md-12 mb-3');
    echo html_writer::start_div('card h-100 shadow-sm ' . $cardborder . ' rounded-lg');
    echo html_writer::start_div('card-body d-flex flex-column p-4');

    // Card Header
    echo html_writer::start_div('d-flex justify-content-between align-items-start mb-2 gap-2');
    echo html_writer::tag('h4', s($rec['title']), ['class' => 'h5 font-weight-bold text-dark mb-1']);
    echo html_writer::tag('span', s($rec['badge']), ['class' => 'badge ' . s($rec['badge_class']) . ' p-2 small font-weight-normal']);
    echo html_writer::end_div();

    // Reason
    echo html_writer::tag('p', s($rec['reason']), ['class' => 'text-muted small mb-3']);

    // Steps list
    if (!empty($rec['suggested_steps'])) {
        echo html_writer::start_tag('ul', ['class' => 'list-unstyled small mb-3 flex-grow-1']);
        foreach ($rec['suggested_steps'] as $step) {
            echo html_writer::tag('li', '👉 ' . s($step), ['class' => 'py-1 text-secondary']);
        }
        echo html_writer::end_tag('ul');
    }

    // Action button
    echo html_writer::start_div('mt-auto pt-2 border-top d-flex justify-content-between align-items-center flex-wrap gap-2');
    echo html_writer::link($rec['action_url'], s($rec['action_label']) . ' &rarr;', [
        'class' => 'btn btn-sm btn-primary',
    ]);

    $tutorlink = new moodle_url('/blocks/cynaris_stats/tutor.php', [
        'id' => $course->id,
        'prompt' => 'Can you explain the key concepts for ' . $rec['title'] . ' and give me practice tips?',
    ]);
    echo html_writer::link($tutorlink, 'Ask AI Tutor', ['class' => 'btn btn-sm btn-outline-info']);
    echo html_writer::end_div();

    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div(); // End recs row
echo html_writer::end_div(); // End recs container

// 6. Detailed Competency & Gap Breakdown Table
echo html_writer::start_div('card shadow-sm border-0 rounded-lg mb-4');
echo html_writer::start_div('card-header bg-light py-3 d-flex justify-content-between align-items-center');
echo html_writer::tag('h3', '📊 ' . get_string('rec_weak_areas_title', 'block_cynaris_stats'), ['class' => 'h5 font-weight-bold mb-0 text-dark']);
echo html_writer::tag('span', count($weakareas) . ' Total Monitored Items', ['class' => 'badge badge-secondary']);
echo html_writer::end_div();

echo html_writer::start_div('card-body p-0');
if (empty($weakareas)) {
    echo html_writer::div(
        get_string('rec_no_weak_areas', 'block_cynaris_stats'),
        'p-4 text-center text-success font-weight-bold'
    );
} else {
    echo html_writer::start_tag('div', ['class' => 'table-responsive']);
    echo html_writer::start_tag('table', ['class' => 'table table-hover mb-0']);
    echo html_writer::start_tag('thead', ['class' => 'thead-light']);
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Assessment / Activity Name');
    echo html_writer::tag('th', 'Type');
    echo html_writer::tag('th', 'Score / Status', ['class' => 'text-center']);
    echo html_writer::tag('th', 'Severity', ['class' => 'text-center']);
    echo html_writer::tag('th', 'Diagnosis & Reason');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($weakareas as $wa) {
        $sevbadge = 'badge-secondary';
        if ($wa['severity'] === 'critical') {
            $sevbadge = 'badge-danger';
        } elseif ($wa['severity'] === 'warning') {
            $sevbadge = 'badge-warning';
        } elseif ($wa['severity'] === 'info') {
            $sevbadge = 'badge-info';
        }

        $scorecol = ($wa['score_pct'] !== null) ? $wa['score_pct'] . '%' : '<span class="text-muted">Unattempted</span>';

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', html_writer::tag('strong', s($wa['name'])));
        echo html_writer::tag('td', html_writer::tag('span', s(ucfirst($wa['module'] ?: 'Module')), ['class' => 'badge badge-light border text-dark']));
        echo html_writer::tag('td', $scorecol, ['class' => 'text-center font-weight-bold']);
        echo html_writer::tag('td', html_writer::tag('span', ucfirst($wa['severity']), ['class' => 'badge ' . $sevbadge . ' p-1']), ['class' => 'text-center']);
        echo html_writer::tag('td', s($wa['reason']), ['class' => 'small text-secondary']);
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_tag('div');
}
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div(); // End cynaris-rec-wrapper

echo $OUTPUT->footer();
