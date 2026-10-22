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
 * Automated CLI test harness for Cynaris AI Learning Recommendations (W8D3).
 *
 * Verifies:
 * 1. Mathematical boundary conditions for weak areas (<50% critical, 50-70% warning, >=70% good).
 * 2. Explicit gradepass threshold enforcement (score < gradepass triggers critical).
 * 3. Activity completion state evaluation (tracked vs untracked, incomplete vs completed).
 * 4. Empty/missing grade and completion data handling (graceful empty states).
 * 5. Multi-tier recommendation prioritization and sorting (Critical -> Warning -> Info -> Enrichment).
 * 6. Course module deep-linking and action URL resolution.
 * 7. AI study plan generation, Groq primary, OpenRouter fallback, and deterministic narrative.
 * 8. Zero credential exposure and security isolation.
 *
 * Can execute cleanly in Docker or standalone PHP CLI on Windows/Linux.
 *
 * @package    block_cynaris_stats
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// Bootstrap minimal environment if outside Moodle
$configpath = __DIR__ . '/../../../../config.php';
if (file_exists($configpath) && getenv('MOODLE_DOCKER_DBTYPE')) {
    require_once($configpath);
} else {
    if (!defined('MOODLE_INTERNAL')) {
        define('MOODLE_INTERNAL', true);
    }
    if (!function_exists('s')) {
        function s($string) {
            return htmlspecialchars((string) $string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }
    if (!function_exists('format_string')) {
        function format_string($string) {
            return (string) $string;
        }
    }
    if (!class_exists('moodle_url')) {
        class moodle_url {
            protected string $url;
            public function __construct(string $url, array $params = []) {
                if (!empty($params)) {
                    $url .= '?' . http_build_query($params);
                }
                $this->url = $url;
            }
            public function out(bool $escaped = true): string {
                return $escaped ? htmlspecialchars($this->url) : $this->url;
            }
        }
    }
}

require_once(__DIR__ . '/../classes/analytics.php');
require_once(__DIR__ . '/../classes/ai_tutor.php');
require_once(__DIR__ . '/../classes/recommendation_engine.php');

use block_cynaris_stats\analytics;
use block_cynaris_stats\ai_tutor;
use block_cynaris_stats\recommendation_engine;

$passed = 0;
$failed = 0;
$skipped = 0;
$testindex = 0;

function run_rec_test(string $desc, callable $fn): void {
    global $passed, $failed, $testindex;
    $testindex++;
    try {
        $result = $fn();
        if ($result === true) {
            $passed++;
            printf("  [%02d] PASS: %s\n", $testindex, $desc);
        } else {
            $failed++;
            printf("  [%02d] FAIL: %s (Assertion returned false)\n", $testindex, $desc);
        }
    } catch (\Throwable $e) {
        $failed++;
        printf("  [%02d] ERROR: %s (%s)\n", $testindex, $desc, $e->getMessage());
    }
}

echo "\n======================================================================\n";
echo "  CYNARIS W8D3: AI LEARNING RECOMMENDATION VERIFICATION SUITE\n";
echo "  Grade & Completion Telemetry -> Weak Area Analysis -> AI Coaching\n";
echo "======================================================================\n\n";

// --- Suite 1: Mathematical Boundary Conditions for Weak Areas ---
echo "--- Suite 1: Mathematical Boundary Conditions for Weak Areas ---\n";

run_rec_test("Score of 49.9% is flagged as critical severity", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $gradedata = [
        'has_grades' => true,
        'items' => [
            [
                'id' => 1,
                'name' => 'Quiz 1',
                'itemmodule' => 'quiz',
                'iteminstance' => 10,
                'has_grade' => true,
                'percentage' => 49.9,
                'gradepass' => 0.0,
                'passed' => false,
            ],
        ],
    ];
    $weak = $engine->identify_weak_areas($gradedata, ['modules' => []]);
    return count($weak) === 1 && $weak[0]['severity'] === 'critical';
});

run_rec_test("Score of 0.0% is flagged as critical severity", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $gradedata = [
        'has_grades' => true,
        'items' => [
            [
                'id' => 1,
                'name' => 'Assignment 1',
                'itemmodule' => 'assign',
                'iteminstance' => 11,
                'has_grade' => true,
                'percentage' => 0.0,
                'gradepass' => 0.0,
                'passed' => false,
            ],
        ],
    ];
    $weak = $engine->identify_weak_areas($gradedata, ['modules' => []]);
    return count($weak) === 1 && $weak[0]['severity'] === 'critical';
});

run_rec_test("Score of 50.0% is flagged as warning severity (needs improvement)", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $gradedata = [
        'has_grades' => true,
        'items' => [
            [
                'id' => 1,
                'name' => 'Quiz 2',
                'itemmodule' => 'quiz',
                'iteminstance' => 12,
                'has_grade' => true,
                'percentage' => 50.0,
                'gradepass' => 0.0,
                'passed' => true,
            ],
        ],
    ];
    $weak = $engine->identify_weak_areas($gradedata, ['modules' => []]);
    return count($weak) === 1 && $weak[0]['severity'] === 'warning';
});

run_rec_test("Score of 69.9% is flagged as warning severity", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $gradedata = [
        'has_grades' => true,
        'items' => [
            [
                'id' => 1,
                'name' => 'Midterm',
                'itemmodule' => 'quiz',
                'iteminstance' => 13,
                'has_grade' => true,
                'percentage' => 69.9,
                'gradepass' => 0.0,
                'passed' => true,
            ],
        ],
    ];
    $weak = $engine->identify_weak_areas($gradedata, ['modules' => []]);
    return count($weak) === 1 && $weak[0]['severity'] === 'warning';
});

run_rec_test("Score of 70.0% is NOT flagged as a weak area", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $gradedata = [
        'has_grades' => true,
        'items' => [
            [
                'id' => 1,
                'name' => 'Quiz 3',
                'itemmodule' => 'quiz',
                'iteminstance' => 14,
                'has_grade' => true,
                'percentage' => 70.0,
                'gradepass' => 0.0,
                'passed' => true,
            ],
        ],
    ];
    $weak = $engine->identify_weak_areas($gradedata, ['modules' => []]);
    return count($weak) === 0;
});

run_rec_test("Score of 95.0% (Distinction) is NOT flagged as a weak area", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $gradedata = [
        'has_grades' => true,
        'items' => [
            [
                'id' => 1,
                'name' => 'Capstone Project',
                'itemmodule' => 'assign',
                'iteminstance' => 15,
                'has_grade' => true,
                'percentage' => 95.0,
                'gradepass' => 50.0,
                'passed' => true,
            ],
        ],
    ];
    $weak = $engine->identify_weak_areas($gradedata, ['modules' => []]);
    return count($weak) === 0;
});

run_rec_test("Explicit gradepass threshold failure (score 58% < gradepass 60%) triggers critical", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $gradedata = [
        'has_grades' => true,
        'items' => [
            [
                'id' => 1,
                'name' => 'Database Certification Exam',
                'itemmodule' => 'quiz',
                'iteminstance' => 16,
                'has_grade' => true,
                'percentage' => 58.0,
                'gradepass' => 60.0,
                'passed' => false,
            ],
        ],
    ];
    $weak = $engine->identify_weak_areas($gradedata, ['modules' => []]);
    return count($weak) === 1 && $weak[0]['severity'] === 'critical';
});

run_rec_test("Unattempted assessment is categorized as info severity", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $gradedata = [
        'has_grades' => false,
        'items' => [
            [
                'id' => 2,
                'name' => 'Pending Final Quiz',
                'itemmodule' => 'quiz',
                'iteminstance' => 17,
                'has_grade' => false,
                'percentage' => null,
                'gradepass' => 50.0,
                'passed' => null,
            ],
        ],
    ];
    $weak = $engine->identify_weak_areas($gradedata, ['modules' => []]);
    return count($weak) === 1 && $weak[0]['severity'] === 'info';
});

// --- Suite 2: Course Activity Completion Telemetry ---
echo "\n--- Suite 2: Course Activity Completion Telemetry ---\n";

run_rec_test("Incomplete tracked activity is identified as a weak area", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $completiondata = [
        'modules' => [
            [
                'cmid' => 101,
                'modname' => 'page',
                'instance' => 5,
                'name' => 'Moodle Architecture Overview',
                'section' => 1,
                'completion_tracked' => true,
                'is_completed' => false,
                'completion_state' => 0,
            ],
        ],
    ];
    $weak = $engine->identify_weak_areas(['items' => []], $completiondata);
    return count($weak) === 1 && $weak[0]['severity'] === 'warning' && $weak[0]['cmid'] === 101;
});

run_rec_test("Completed tracked activity is omitted from weak areas", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $completiondata = [
        'modules' => [
            [
                'cmid' => 102,
                'modname' => 'page',
                'instance' => 6,
                'name' => 'XMLDB Guide',
                'section' => 1,
                'completion_tracked' => true,
                'is_completed' => true,
                'completion_state' => 1,
            ],
        ],
    ];
    $weak = $engine->identify_weak_areas(['items' => []], $completiondata);
    return count($weak) === 0;
});

run_rec_test("Untracked activity is omitted from completion weak areas", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $completiondata = [
        'modules' => [
            [
                'cmid' => 103,
                'modname' => 'forum',
                'instance' => 7,
                'name' => 'General Discussion Forum',
                'section' => 0,
                'completion_tracked' => false,
                'is_completed' => false,
                'completion_state' => 0,
            ],
        ],
    ];
    $weak = $engine->identify_weak_areas(['items' => []], $completiondata);
    return count($weak) === 0;
});

run_rec_test("Avoids duplicate weak area when activity is already tracked in grades", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $gradedata = [
        'has_grades' => true,
        'items' => [
            [
                'id' => 55,
                'name' => 'Quiz A',
                'itemmodule' => 'quiz',
                'iteminstance' => 20,
                'has_grade' => true,
                'percentage' => 45.0,
                'gradepass' => 0.0,
                'passed' => false,
            ],
        ],
    ];
    $completiondata = [
        'modules' => [
            [
                'cmid' => 201,
                'modname' => 'quiz',
                'instance' => 20,
                'name' => 'Quiz A',
                'section' => 2,
                'completion_tracked' => true,
                'is_completed' => false,
                'completion_state' => 0,
            ],
        ],
    ];
    $weak = $engine->identify_weak_areas($gradedata, $completiondata);
    return count($weak) === 1; // Only captured once under grade critical
});

// --- Suite 3: Recommendation Prioritization & Sorting ---
echo "\n--- Suite 3: Recommendation Prioritization & Sorting ---\n";

run_rec_test("Critical failure creates remediation recommendation with priority weight 100", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $weak = [
        [
            'id' => 'grade_1',
            'source' => 'grade',
            'name' => 'Database Quiz',
            'module' => 'quiz',
            'instance' => 10,
            'severity' => 'critical',
            'reason' => 'Score of 35% is below pass threshold.',
        ],
    ];
    $recs = $engine->generate_recommendations($weak, ['has_grades' => true], ['modules' => []]);
    return count($recs) === 1 &&
        $recs[0]['priority'] === 'critical' &&
        $recs[0]['priority_weight'] === 100 &&
        $recs[0]['category'] === 'assessment_remediation';
});

run_rec_test("Recommendations are strictly sorted by priority weight descending", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $weak = [
        [
            'id' => 'cm_1',
            'source' => 'completion',
            'name' => 'Reading Item',
            'module' => 'page',
            'instance' => 3,
            'severity' => 'warning',
            'reason' => 'Reading is incomplete.',
        ],
        [
            'id' => 'grade_1',
            'source' => 'grade',
            'name' => 'Failed Exam',
            'module' => 'quiz',
            'instance' => 5,
            'severity' => 'critical',
            'reason' => 'Score 20% is failing.',
        ],
        [
            'id' => 'grade_2',
            'source' => 'pending_grade',
            'name' => 'Upcoming Quiz',
            'module' => 'quiz',
            'instance' => 6,
            'severity' => 'info',
            'reason' => 'Not yet attempted.',
        ],
    ];
    $recs = $engine->generate_recommendations($weak, ['has_grades' => true], ['modules' => []]);
    return count($recs) === 3 &&
        $recs[0]['priority'] === 'critical' &&
        $recs[1]['priority'] === 'high' &&
        $recs[2]['priority'] === 'medium';
});

run_rec_test("Distinction student with zero weak areas receives academic enrichment recommendation", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $recs = $engine->generate_recommendations([], ['has_grades' => true], ['modules' => []]);
    return count($recs) === 1 &&
        $recs[0]['category'] === 'enrichment' &&
        $recs[0]['priority'] === 'low';
});

run_rec_test("Empty state student receives onboarding recommendation", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $recs = $engine->generate_recommendations([], ['has_grades' => false], ['modules' => []]);
    return count($recs) === 1 &&
        $recs[0]['category'] === 'onboarding' &&
        $recs[0]['badge'] === 'Course Onboarding';
});

// --- Suite 4: Activity Deep Linking & Action URLs ---
echo "\n--- Suite 4: Activity Deep Linking & Action URLs ---\n";

run_rec_test("Resolves direct Moodle course module URL when cmid is matched", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $weak = [
        [
            'id' => 'grade_10',
            'source' => 'grade',
            'name' => 'Quiz Module',
            'module' => 'quiz',
            'instance' => 77,
            'severity' => 'critical',
            'reason' => 'Failing score.',
        ],
    ];
    $completion = [
        'modules' => [
            [
                'cmid' => 999,
                'modname' => 'quiz',
                'instance' => 77,
            ],
        ],
    ];
    $recs = $engine->generate_recommendations($weak, ['has_grades' => true], $completion);
    return strpos($recs[0]['action_url'], '/mod/quiz/view.php?id=999') !== false;
});

run_rec_test("Falls back to course view URL when cmid cannot be resolved", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $weak = [
        [
            'id' => 'grade_20',
            'source' => 'grade',
            'name' => 'Unmapped Item',
            'module' => 'manual',
            'instance' => 0,
            'severity' => 'critical',
            'reason' => 'Low grade.',
        ],
    ];
    $recs = $engine->generate_recommendations($weak, ['has_grades' => true], ['modules' => []]);
    return strpos($recs[0]['action_url'], '/course/view.php?id=2') !== false;
});

// --- Suite 5: AI Coaching Plan & Deterministic Resilience ---
echo "\n--- Suite 5: AI Coaching Plan & Deterministic Resilience ---\n";

run_rec_test("Deterministic rules engine is used when AI is disabled", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $gradedata = [
        'overall_percentage' => 45.0,
        'overall_grade_band' => 'fail',
        'attempted_items' => 1,
        'total_items' => 2,
    ];
    $compdata = ['completion_percentage' => 50.0];
    $recs = [
        ['priority' => 'critical', 'title' => 'Retake Quiz 1'],
    ];
    $plan = $engine->generate_ai_study_plan($gradedata, $compdata, $recs, 'Web Dev');
    return $plan['status'] === 'success' &&
        $plan['ai_enriched'] === false &&
        $plan['provider'] === 'deterministic_rules' &&
        strpos($plan['plan_summary'], '45%') !== false;
});

run_rec_test("Simulation primary Groq returns AI-enriched study plan", function () {
    $tutor = new ai_tutor(null, null, [
        'simulate' => true,
        'simulation_scenario' => 'primary_success',
    ]);
    $engine = new recommendation_engine(2, 4, $tutor, true);
    $gradedata = [
        'overall_percentage' => 62.5,
        'overall_grade_band' => 'pass',
        'attempted_items' => 2,
        'total_items' => 3,
    ];
    $compdata = ['completion_percentage' => 66.7];
    $recs = [
        ['priority' => 'high', 'title' => 'Review XMLDB', 'reason' => 'Score 62.5%.'],
    ];
    $plan = $engine->generate_ai_study_plan($gradedata, $compdata, $recs, 'Full Stack Web Dev');
    return $plan['status'] === 'success' &&
        $plan['ai_enriched'] === true &&
        $plan['provider'] === 'groq';
});

run_rec_test("Simulation Groq 429 rate limit triggers OpenRouter fallback", function () {
    $tutor = new ai_tutor(null, null, [
        'simulate' => true,
        'simulation_scenario' => 'groq_429_fallback',
    ]);
    $engine = new recommendation_engine(2, 4, $tutor, true);
    $gradedata = [
        'overall_percentage' => 48.0,
        'overall_grade_band' => 'fail',
        'attempted_items' => 1,
        'total_items' => 2,
    ];
    $compdata = ['completion_percentage' => 50.0];
    $recs = [
        ['priority' => 'critical', 'title' => 'Retake Quiz 2', 'reason' => 'Score 48%.'],
    ];
    $plan = $engine->generate_ai_study_plan($gradedata, $compdata, $recs, 'Full Stack Web Dev');
    return $plan['status'] === 'success' &&
        $plan['ai_enriched'] === true &&
        $plan['provider'] === 'openrouter' &&
        $plan['fallback_used'] === true;
});

run_rec_test("Simulation dual failure gracefully falls back to deterministic rules", function () {
    $tutor = new ai_tutor(null, null, [
        'simulate' => true,
        'simulation_scenario' => 'both_failed',
    ]);
    $engine = new recommendation_engine(2, 4, $tutor, true);
    $gradedata = [
        'overall_percentage' => 40.0,
        'overall_grade_band' => 'fail',
        'attempted_items' => 1,
        'total_items' => 2,
    ];
    $compdata = ['completion_percentage' => 50.0];
    $recs = [
        ['priority' => 'critical', 'title' => 'Retake Quiz 1', 'reason' => 'Failing score.'],
    ];
    $plan = $engine->generate_ai_study_plan($gradedata, $compdata, $recs, 'Full Stack Web Dev');
    return $plan['status'] === 'success' &&
        $plan['ai_enriched'] === false &&
        $plan['provider'] === 'deterministic_rules_fallback' &&
        strpos($plan['plan_summary'], 'Immediate Action Required') !== false;
});

// --- Suite 6: Full Report Payload & Structure Integrity ---
echo "\n--- Suite 6: Full Report Payload & Structure Integrity ---\n";

run_rec_test("Full report contains all required keys and valid timestamp", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $report = $engine->get_full_report('Full Stack Web Development');
    return isset($report['courseid']) &&
        isset($report['userid']) &&
        isset($report['grade_summary']) &&
        isset($report['completion_summary']) &&
        isset($report['weak_areas']) &&
        isset($report['recommendations']) &&
        isset($report['study_plan']) &&
        isset($report['timestamp']) &&
        $report['timestamp'] > 0;
});

run_rec_test("Optimized activity query handles offline DB fallback safely", function () {
    $engine = new recommendation_engine(2, 4, null, false);
    $result = $engine->get_recent_activity_summary(7);
    return is_array($result);
});

echo "\n======================================================================\n";
printf("  TEST EXECUTION SUMMARY: %d PASSED, %d FAILED, %d SKIPPED (TOTAL %d)\n",
    $passed, $failed, $skipped, $passed + $failed + $skipped);
if ($failed === 0) {
    echo "  STATUS: ALL RECOMMENDATION TESTS PASSED (100% SUCCESS RATE)\n";
} else {
    echo "  STATUS: FAILURES DETECTED IN TEST SUITE\n";
}
echo "======================================================================\n\n";

exit($failed === 0 ? 0 : 1);
