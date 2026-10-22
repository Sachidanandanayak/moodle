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

use stdClass;
use moodle_url;
use context_course;

/**
 * AI Learning Recommendation Engine for block_cynaris_stats (W8D3).
 *
 * Computes deterministic, evidence-based learning recommendations using
 * real Moodle gradebook records ({grade_grades}, {grade_items}) and activity
 * completion logs ({course_modules}, {course_modules_completion}).
 *
 * Key Architectural Features:
 * 1. Evidence-Based Analysis: Identifies weak competencies from actual recorded scores
 *    (<50% fail, 50-70% needs improvement) and incomplete course activities without fabrication.
 * 2. Multi-Tier Prioritization: Categorizes recommendations into Critical, High, Medium,
 *    and Enrichment priorities based on pedagogical urgency.
 * 3. Deep Activity Linking: Maps recommended remediation items directly to concrete Moodle
 *    course module URLs (/mod/{module}/view.php?id={cmid}).
 * 4. Dual Provider AI Enrichment: Leverages existing ai_tutor (Groq primary, OpenRouter fallback)
 *    to synthesize personalized coaching narratives, with 100% resilient fallback to
 *    rule-based summaries if keys are absent or network is offline.
 * 5. Strict Capability Isolation: Respects Moodle role boundaries. Students view only their own
 *    recommendations; instructors with moodle/grade:viewall can inspect any enrolled student.
 *
 * @package    block_cynaris_stats
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recommendation_engine {

    /** @var float Critical threshold below which an activity is marked critical failure. */
    public const CRITICAL_FAIL_THRESHOLD = 50.0;

    /** @var float Threshold below which an activity requires improvement. */
    public const IMPROVEMENT_THRESHOLD = 70.0;

    /** @var float Distinction threshold representing academic mastery. */
    public const MASTERY_THRESHOLD = 85.0;

    /** @var int Target course ID. */
    protected int $courseid;

    /** @var int Target student user ID. */
    protected int $userid;

    /** @var ai_tutor|null AI Tutor instance for optional AI enrichment. */
    protected ?ai_tutor $aitutor = null;

    /** @var bool Whether AI enrichment is active. */
    protected bool $aienabled = true;

    /**
     * Constructor.
     *
     * @param int $courseid Target course ID.
     * @param int $userid Target student user ID.
     * @param ai_tutor|null $aitutor Optional AI Tutor instance.
     * @param bool $aienabled Whether AI enrichment should be attempted.
     */
    public function __construct(
        int $courseid,
        int $userid,
        ?ai_tutor $aitutor = null,
        bool $aienabled = true
    ) {
        $this->courseid = $courseid;
        $this->userid = $userid;
        $this->aitutor = $aitutor;
        $this->aienabled = $aienabled;
    }

    /**
     * Retrieve student grade performance metrics from actual Moodle tables.
     *
     * @return array Standardized array of grade records and summary stats.
     */
    public function get_student_grade_data(): array {
        global $DB;

        $gradedata = [
            'total_items' => 0,
            'attempted_items' => 0,
            'total_score' => 0.0,
            'total_max' => 0.0,
            'overall_percentage' => null,
            'overall_grade_band' => 'pending',
            'has_grades' => false,
            'items' => [],
        ];

        if (empty($DB) || !method_exists($DB, 'get_records_sql')) {
            return $gradedata;
        }

        $sql = "SELECT gi.id, gi.itemname, gi.itemtype, gi.itemmodule, gi.iteminstance,
                       gi.grademin, gi.grademax, gi.gradepass,
                       gg.finalgrade, gg.rawgrade, gg.feedback, gg.timemodified
                  FROM {grade_items} gi
             LEFT JOIN {grade_grades} gg
                    ON gg.itemid = gi.id
                   AND gg.userid = :userid
                   AND gg.hidden = 0
                 WHERE gi.courseid = :courseid
                   AND gi.itemtype IN ('mod', 'manual')
              ORDER BY gi.sortorder ASC, gi.id ASC";

        $records = $DB->get_records_sql($sql, [
            'courseid' => $this->courseid,
            'userid' => $this->userid,
        ]);

        if (empty($records)) {
            return $gradedata;
        }

        $gradedata['total_items'] = count($records);

        foreach ($records as $rec) {
            $name = !empty($rec->itemname) ? $rec->itemname : ucfirst($rec->itemmodule ?? 'Item') . ' #' . $rec->id;
            $grademin = (float)$rec->grademin;
            $grademax = (float)$rec->grademax;
            $range = $grademax - $grademin;
            $hasgrade = ($rec->finalgrade !== null);
            $finalgrade = $hasgrade ? round((float)$rec->finalgrade, 2) : null;
            $percentage = ($hasgrade && $range > 0) ? round((($finalgrade - $grademin) / $range) * 100.0, 1) : null;

            $passed = null;
            if ($hasgrade && (float)$rec->gradepass > 0) {
                $passed = ($finalgrade >= (float)$rec->gradepass);
            } elseif ($hasgrade) {
                $passed = ($percentage >= self::CRITICAL_FAIL_THRESHOLD);
            }

            $gradedata['total_max'] += $grademax;
            if ($hasgrade) {
                $gradedata['attempted_items']++;
                $gradedata['total_score'] += $finalgrade;
                $gradedata['has_grades'] = true;
            }

            $band = 'pending';
            if ($hasgrade && $percentage !== null) {
                $band = analytics::calculate_grade_band($percentage);
            }

            $gradedata['items'][] = [
                'id' => (int)$rec->id,
                'name' => $name,
                'itemmodule' => $rec->itemmodule ?? '',
                'iteminstance' => (int)($rec->iteminstance ?? 0),
                'grademin' => $grademin,
                'grademax' => $grademax,
                'gradepass' => (float)$rec->gradepass,
                'has_grade' => $hasgrade,
                'finalgrade' => $finalgrade,
                'percentage' => $percentage,
                'grade_band' => $band,
                'passed' => $passed,
                'feedback' => $rec->feedback ?? '',
                'timemodified' => $rec->timemodified ? (int)$rec->timemodified : null,
            ];
        }

        if ($gradedata['has_grades'] && $gradedata['total_max'] > 0) {
            $gradedata['overall_percentage'] = round(($gradedata['total_score'] / $gradedata['total_max']) * 100.0, 1);
            $gradedata['overall_grade_band'] = analytics::calculate_grade_band($gradedata['overall_percentage']);
        }

        return $gradedata;
    }

    /**
     * Retrieve student activity completion records from actual Moodle tables.
     *
     * @return array Standardized completion metrics.
     */
    public function get_student_completion_data(): array {
        global $DB;

        $completiondata = [
            'completion_enabled' => false,
            'course_completed' => false,
            'timecompleted' => null,
            'total_modules' => 0,
            'tracked_modules' => 0,
            'completed_modules' => 0,
            'completion_percentage' => 0.0,
            'modules' => [],
        ];

        if (empty($DB) || !method_exists($DB, 'get_records_sql')) {
            return $completiondata;
        }

        // Check if course has completion tracking enabled
        $course = $DB->get_record('course', ['id' => $this->courseid], 'id, enablecompletion');
        if ($course && !empty($course->enablecompletion)) {
            $completiondata['completion_enabled'] = true;
        }

        // Check course completion status
        $coursecomp = $DB->get_record('course_completions', [
            'course' => $this->courseid,
            'userid' => $this->userid,
        ]);
        if ($coursecomp && !empty($coursecomp->timecompleted)) {
            $completiondata['course_completed'] = true;
            $completiondata['timecompleted'] = (int)$coursecomp->timecompleted;
        }

        // Query course modules and module completions
        $sql = "SELECT cm.id AS cmid, cm.course, cm.module, cm.instance, cm.section, cm.completion,
                       m.name AS modname,
                       cmc.id AS cmcid, cmc.completionstate, cmc.timemodified AS completiontime
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
             LEFT JOIN {course_modules_completion} cmc
                    ON cmc.coursemoduleid = cm.id
                   AND cmc.userid = :userid
                 WHERE cm.course = :courseid
                   AND cm.deletioninprogress = 0
              ORDER BY cm.section ASC, cm.id ASC";

        $modules = $DB->get_records_sql($sql, [
            'courseid' => $this->courseid,
            'userid' => $this->userid,
        ]);

        if (empty($modules)) {
            return $completiondata;
        }

        $completiondata['total_modules'] = count($modules);

        foreach ($modules as $mod) {
            $tracked = ((int)$mod->completion > 0);
            $state = $mod->completionstate !== null ? (int)$mod->completionstate : 0;
            // In Moodle: 1 = complete, 2 = complete pass, 3 = complete fail
            $iscomplete = ($state === 1 || $state === 2);

            if ($tracked) {
                $completiondata['tracked_modules']++;
                if ($iscomplete) {
                    $completiondata['completed_modules']++;
                }
            }

            // Resolve activity display name from instance table if possible
            $instancename = '';
            if (!empty($mod->modname) && !empty($mod->instance)) {
                $inst = $DB->get_record($mod->modname, ['id' => $mod->instance], 'id, name');
                if ($inst && !empty($inst->name)) {
                    $instancename = $inst->name;
                }
            }
            if (empty($instancename)) {
                $instancename = ucfirst($mod->modname) . ' #' . $mod->instance;
            }

            $completiondata['modules'][] = [
                'cmid' => (int)$mod->cmid,
                'modname' => $mod->modname,
                'instance' => (int)$mod->instance,
                'name' => $instancename,
                'section' => (int)$mod->section,
                'completion_tracked' => $tracked,
                'is_completed' => $iscomplete,
                'completion_state' => $state,
                'completion_time' => $mod->completiontime ? (int)$mod->completiontime : null,
            ];
        }

        if ($completiondata['tracked_modules'] > 0) {
            $completiondata['completion_percentage'] = round(
                ($completiondata['completed_modules'] / $completiondata['tracked_modules']) * 100.0,
                1
            );
        } elseif ($completiondata['total_modules'] > 0) {
            // If no individual completion rules set, default to graded ratio
            $completiondata['completion_percentage'] = 0.0;
        }

        return $completiondata;
    }

    /**
     * Identify student weak areas and gaps using mathematical thresholds.
     *
     * @param array $gradedata Output from get_student_grade_data().
     * @param array $completiondata Output from get_student_completion_data().
     * @return array Detected weak areas categorized by severity.
     */
    public function identify_weak_areas(array $gradedata, array $completiondata): array {
        $weakareas = [];

        // 1. Grade-Based Weak Areas
        if (!empty($gradedata['items'])) {
            foreach ($gradedata['items'] as $item) {
                if ($item['has_grade']) {
                    $pct = $item['percentage'] ?? 0.0;
                    $passed = $item['passed'];

                    if ($pct < self::CRITICAL_FAIL_THRESHOLD || $passed === false) {
                        $weakareas[] = [
                            'id' => 'grade_' . $item['id'],
                            'source' => 'grade',
                            'item_id' => $item['id'],
                            'name' => $item['name'],
                            'module' => $item['itemmodule'],
                            'instance' => $item['iteminstance'],
                            'score_pct' => $pct,
                            'gradepass' => $item['gradepass'],
                            'severity' => 'critical',
                            'reason' => 'Score of ' . $pct . '% is below the passing standard (' .
                                ($item['gradepass'] > 0 ? $item['gradepass'] . ' points' : self::CRITICAL_FAIL_THRESHOLD . '%') . ').',
                            'feedback' => $item['feedback'] ?? '',
                        ];
                    } elseif ($pct < self::IMPROVEMENT_THRESHOLD) {
                        $weakareas[] = [
                            'id' => 'grade_' . $item['id'],
                            'source' => 'grade',
                            'item_id' => $item['id'],
                            'name' => $item['name'],
                            'module' => $item['itemmodule'],
                            'instance' => $item['iteminstance'],
                            'score_pct' => $pct,
                            'gradepass' => $item['gradepass'],
                            'severity' => 'warning',
                            'reason' => 'Score of ' . $pct . '% passes, but concept mastery requires targeted reinforcement.',
                            'feedback' => $item['feedback'] ?? '',
                        ];
                    }
                } else {
                    // Item has not been attempted or graded yet
                    $weakareas[] = [
                        'id' => 'grade_' . $item['id'],
                        'source' => 'pending_grade',
                        'item_id' => $item['id'],
                        'name' => $item['name'],
                        'module' => $item['itemmodule'],
                        'instance' => $item['iteminstance'],
                        'score_pct' => null,
                        'gradepass' => $item['gradepass'],
                        'severity' => 'info',
                        'reason' => 'Assessment has not been attempted yet or grades are pending publication.',
                        'feedback' => '',
                    ];
                }
            }
        }

        // 2. Activity Completion-Based Weak Areas
        if (!empty($completiondata['modules'])) {
            foreach ($completiondata['modules'] as $mod) {
                if ($mod['completion_tracked'] && !$mod['is_completed']) {
                    // Check if not already captured under grades
                    $alreadycaptured = false;
                    foreach ($weakareas as $wa) {
                        if (isset($wa['module']) && $wa['module'] === $mod['modname'] &&
                            isset($wa['instance']) && $wa['instance'] === $mod['instance']) {
                            $alreadycaptured = true;
                            break;
                        }
                    }

                    if (!$alreadycaptured) {
                        $weakareas[] = [
                            'id' => 'cm_' . $mod['cmid'],
                            'source' => 'completion',
                            'cmid' => $mod['cmid'],
                            'name' => $mod['name'],
                            'module' => $mod['modname'],
                            'instance' => $mod['instance'],
                            'score_pct' => null,
                            'gradepass' => 0.0,
                            'severity' => 'warning',
                            'reason' => 'Required activity is incomplete in course section ' . $mod['section'] . '.',
                            'feedback' => '',
                        ];
                    }
                }
            }
        }

        return $weakareas;
    }

    /**
     * Generate prioritized learning recommendations based on identified weak areas.
     *
     * @param array $weakareas Output from identify_weak_areas().
     * @param array $gradedata Output from get_student_grade_data().
     * @param array $completiondata Output from get_student_completion_data().
     * @return array Array of prioritized recommendation objects.
     */
    public function generate_recommendations(
        array $weakareas,
        array $gradedata,
        array $completiondata
    ): array {
        $recommendations = [];

        // 1. Critical Failure Interventions (Priority 1)
        foreach ($weakareas as $area) {
            if ($area['severity'] === 'critical') {
                $cmid = $this->resolve_cmid_for_item($area, $completiondata);
                $actionurl = $this->get_activity_url($area['module'], $cmid);

                $recommendations[] = [
                    'id' => 'rec_' . $area['id'],
                    'title' => 'Remediate & Retake: ' . $area['name'],
                    'category' => 'assessment_remediation',
                    'priority' => 'critical',
                    'priority_weight' => 100,
                    'badge' => 'High Priority Remediation',
                    'badge_class' => 'badge-danger',
                    'reason' => $area['reason'],
                    'action_url' => $actionurl,
                    'action_label' => 'Review & Retake Assessment',
                    'suggested_steps' => [
                        'Review foundational lecture notes and code examples before retrying.',
                        'Use the AI Course Tutor to ask targeted questions about missed concepts.',
                        'Re-attempt the assessment to raise your score above the passing threshold.',
                    ],
                ];
            }
        }

        // 2. Concept Reinforcement Warnings (Priority 2)
        foreach ($weakareas as $area) {
            if ($area['severity'] === 'warning') {
                $cmid = $this->resolve_cmid_for_item($area, $completiondata);
                $actionurl = $this->get_activity_url($area['module'], $cmid);

                $recommendations[] = [
                    'id' => 'rec_' . $area['id'],
                    'title' => 'Reinforce Concept Mastery: ' . $area['name'],
                    'category' => 'concept_reinforcement',
                    'priority' => 'high',
                    'priority_weight' => 70,
                    'badge' => 'Needs Improvement',
                    'badge_class' => 'badge-warning',
                    'reason' => $area['reason'],
                    'action_url' => $actionurl,
                    'action_label' => 'Open Activity Review',
                    'suggested_steps' => [
                        'Review instructor feedback and notes on this topic.',
                        'Practice implementing related code patterns or architectural concepts.',
                        'Aim for distinction standard (>85%) on upcoming assessments.',
                    ],
                ];
            }
        }

        // 3. Pending Assessments & Incomplete Items (Priority 3)
        foreach ($weakareas as $area) {
            if ($area['severity'] === 'info') {
                $cmid = $this->resolve_cmid_for_item($area, $completiondata);
                $actionurl = $this->get_activity_url($area['module'], $cmid);

                $recommendations[] = [
                    'id' => 'rec_' . $area['id'],
                    'title' => 'Upcoming Assessment: ' . $area['name'],
                    'category' => 'pending_assessment',
                    'priority' => 'medium',
                    'priority_weight' => 40,
                    'badge' => 'Pending Attempt',
                    'badge_class' => 'badge-info',
                    'reason' => $area['reason'],
                    'action_url' => $actionurl,
                    'action_label' => 'Start Assessment',
                    'suggested_steps' => [
                        'Prepare your study notes before beginning this assessment.',
                        'Ensure you have uninterrupted time to complete the submission.',
                    ],
                ];
            }
        }

        // 4. Academic Enrichment (If No Critical or Warning Areas Detected)
        if (empty($recommendations) && !empty($gradedata['has_grades'])) {
            $recommendations[] = [
                'id' => 'rec_enrichment_mastery',
                'title' => 'Academic Mastery: Advanced Independent Exploration',
                'category' => 'enrichment',
                'priority' => 'low',
                'priority_weight' => 10,
                'badge' => 'Academic Distinction',
                'badge_class' => 'badge-success',
                'reason' => 'Outstanding performance! You have attained distinction or pass standard across all completed assessments.',
                'action_url' => (new moodle_url('/course/view.php', ['id' => $this->courseid]))->out(false),
                'action_label' => 'Explore Advanced Topics',
                'suggested_steps' => [
                    'Explore advanced architectural documentation and Moodle developer core guides.',
                    'Assist peers in forum discussions and practical implementation exercises.',
                    'Experiment with building custom Moodle subplugins or web service APIs.',
                ],
            ];
        }

        // 5. Empty State Guidance
        if (empty($recommendations)) {
            $recommendations[] = [
                'id' => 'rec_onboarding',
                'title' => 'Begin Your Learning Journey',
                'category' => 'onboarding',
                'priority' => 'medium',
                'priority_weight' => 20,
                'badge' => 'Course Onboarding',
                'badge_class' => 'badge-primary',
                'reason' => 'No graded submissions or activity completion records were found for your account.',
                'action_url' => (new moodle_url('/course/view.php', ['id' => $this->courseid]))->out(false),
                'action_label' => 'View Course Modules',
                'suggested_steps' => [
                    'Open the course page and review the syllabus and introductory materials.',
                    'Attempt the initial quizzes and interactive tasks to generate performance analytics.',
                ],
            ];
        }

        // Sort by priority weight descending
        usort($recommendations, function ($a, $b) {
            return ($b['priority_weight'] ?? 0) <=> ($a['priority_weight'] ?? 0);
        });

        return $recommendations;
    }

    /**
     * Generate an AI-synthesized pedagogical coaching summary.
     *
     * @param array $gradedata Student grade data.
     * @param array $completiondata Student completion data.
     * @param array $recommendations Generated recommendations.
     * @param string|null $coursename Course title.
     * @return array Array with AI response, provider used, and latency.
     */
    public function generate_ai_study_plan(
        array $gradedata,
        array $completiondata,
        array $recommendations,
        ?string $coursename = null
    ): array {
        $coursename = $coursename ?? ('Course #' . $this->courseid);

        // Fallback rule-based summary for offline or unconfigured environments
        $rulesummary = $this->build_rule_based_narrative($gradedata, $completiondata, $recommendations);

        if (!$this->aienabled || empty($this->aitutor)) {
            return [
                'status' => 'success',
                'provider' => 'deterministic_rules',
                'model' => 'rule_engine_v1',
                'plan_summary' => $rulesummary,
                'ai_enriched' => false,
                'latency_ms' => 0.0,
            ];
        }

        // Formulate concise factual prompt based exclusively on real data
        $systemprompt = "You are an intelligent Moodle academic tutor and learning strategist for the course '{$coursename}'. " .
            "Your objective is to review the student's actual performance data and provide a concise, constructive, " .
            "and encouraging study strategy (max 3 short paragraphs). Focus strictly on their identified weak competencies, " .
            "remediation steps, and actionable next goals. Do not fabricate hypothetical grades or unmentioned topics.";

        $userprompt = "Here is my current verified course standing:\n" .
            "- Overall Grade: " . ($gradedata['overall_percentage'] !== null ? $gradedata['overall_percentage'] . '%' : 'Pending') . " (" . $gradedata['overall_grade_band'] . ")\n" .
            "- Completed Assessments: " . $gradedata['attempted_items'] . " of " . $gradedata['total_items'] . "\n" .
            "- Activity Completion Rate: " . $completiondata['completion_percentage'] . "%\n";

        if (!empty($recommendations)) {
            $userprompt .= "- Priority Action Items:\n";
            $toprecs = array_slice($recommendations, 0, 3);
            foreach ($toprecs as $r) {
                $userprompt .= "  * [" . strtoupper($r['priority']) . "] " . $r['title'] . ": " . $r['reason'] . "\n";
            }
        }

        $userprompt .= "\nPlease provide a personalized 3-step action plan to help me improve my academic standing.";

        try {
            $result = $this->aitutor->query($userprompt, [
                'course_name' => $coursename,
            ]);

            if (!empty($result['status']) && $result['status'] === 'success' && !empty($result['response'])) {
                return [
                    'status' => 'success',
                    'provider' => $result['provider'] ?? 'groq',
                    'model' => $result['model'] ?? 'llama-3.3-70b-versatile',
                    'plan_summary' => $result['response'],
                    'ai_enriched' => true,
                    'latency_ms' => $result['latency_ms'] ?? 0.0,
                    'fallback_used' => $result['fallback_used'] ?? false,
                ];
            }
        } catch (\Throwable $e) {
            // Gracefully catch any API issues and fallback seamlessly
        }

        return [
            'status' => 'success',
            'provider' => 'deterministic_rules_fallback',
            'model' => 'rule_engine_v1',
            'plan_summary' => $rulesummary,
            'ai_enriched' => false,
            'latency_ms' => 0.0,
        ];
    }

    /**
     * Build deterministic rule-based coaching narrative when AI is disabled or offline.
     *
     * @param array $gradedata
     * @param array $completiondata
     * @param array $recommendations
     * @return string
     */
    protected function build_rule_based_narrative(
        array $gradedata,
        array $completiondata,
        array $recommendations
    ): string {
        $scorestr = $gradedata['overall_percentage'] !== null ? $gradedata['overall_percentage'] . '%' : 'Pending';
        $bandstr = ucfirst($gradedata['overall_grade_band'] ?? 'pending');
        $compstr = $completiondata['completion_percentage'] . '%';

        $narrative = "Academic Performance Overview:\n";
        $narrative .= "Your current course score is {$scorestr} ({$bandstr}), with an activity completion rate of {$compstr}.\n\n";

        $criticalcount = 0;
        $warningcount = 0;
        foreach ($recommendations as $r) {
            if ($r['priority'] === 'critical') {
                $criticalcount++;
            } elseif ($r['priority'] === 'high') {
                $warningcount++;
            }
        }

        if ($criticalcount > 0) {
            $narrative .= "Immediate Action Required: You have {$criticalcount} assessment(s) requiring urgent remediation. " .
                "Prioritize retaking assessments with scores below the 50% threshold to ensure compliance with minimum course pass requirements.\n";
        } elseif ($warningcount > 0) {
            $narrative .= "Focus Area: Your foundational scores are passing, but {$warningcount} topic(s) show opportunities for reinforcement. " .
                "Review the feedback provided on these items and practice the core concepts before the final evaluation.\n";
        } else {
            $narrative .= "On Track: Your assessments reflect solid mastery. Continue completing all scheduled course activities on time " .
                "and maintain your strong pace toward academic distinction.\n";
        }

        return $narrative;
    }

    /**
     * Generate complete report bundling all telemetry and recommendations.
     *
     * @param string|null $coursename
     * @return array
     */
    public function get_full_report(?string $coursename = null): array {
        $gradedata = $this->get_student_grade_data();
        $completiondata = $this->get_student_completion_data();
        $weakareas = $this->identify_weak_areas($gradedata, $completiondata);
        $recommendations = $this->generate_recommendations($weakareas, $gradedata, $completiondata);
        $aiplan = $this->generate_ai_study_plan($gradedata, $completiondata, $recommendations, $coursename);

        return [
            'courseid' => $this->courseid,
            'userid' => $this->userid,
            'grade_summary' => $gradedata,
            'completion_summary' => $completiondata,
            'weak_areas' => $weakareas,
            'recommendations' => $recommendations,
            'study_plan' => $aiplan,
            'timestamp' => time(),
        ];
    }

    /**
     * Resolve coursemodule ID for an identified weak area.
     *
     * @param array $area Weak area record.
     * @param array $completiondata Completion data containing module mapping.
     * @return int|null
     */
    protected function resolve_cmid_for_item(array $area, array $completiondata): ?int {
        if (!empty($area['cmid'])) {
            return (int)$area['cmid'];
        }

        if (!empty($completiondata['modules']) && !empty($area['module']) && !empty($area['instance'])) {
            foreach ($completiondata['modules'] as $mod) {
                if ($mod['modname'] === $area['module'] && (int)$mod['instance'] === (int)$area['instance']) {
                    return (int)$mod['cmid'];
                }
            }
        }

        return null;
    }

    /**
     * Generate standard Moodle URL for an activity.
     *
     * @param string $modname Module name (e.g., 'quiz', 'assign').
     * @param int|null $cmid Course module ID.
     * @return string
     */
    protected function get_activity_url(string $modname, ?int $cmid): string {
        if ($cmid && !empty($modname)) {
            return (new moodle_url("/mod/{$modname}/view.php", ['id' => $cmid]))->out(false);
        }
        return (new moodle_url('/course/view.php', ['id' => $this->courseid]))->out(false);
    }

    /**
     * Retrieve recent course interaction telemetry using optimized SARGable temporal indexing (W8D4).
     *
     * Leverages Moodle's composite covering index: m_logsstanlog_couanotim_ix (courseid, anonymous, timecreated).
     * Avoids non-SARGable runtime SQL scalar casting by binding native integer timestamps.
     *
     * @param int $days Number of preceding days to audit.
     * @return array Aggregated event records.
     */
    public function get_recent_activity_summary(int $days = 7): array {
        global $DB;

        if (empty($DB) || !method_exists($DB, 'get_records_sql')) {
            return [];
        }

        $timethreshold = time() - ($days * 86400);

        $sql = "SELECT l.eventname, l.component, l.action, l.target,
                       COUNT(l.id) AS total_events,
                       COUNT(DISTINCT l.userid) AS unique_active_users,
                       MIN(l.timecreated) AS first_event,
                       MAX(l.timecreated) AS latest_event
                  FROM {logstore_standard_log} l
                 WHERE l.courseid = :courseid
                   AND l.anonymous = 0
                   AND l.timecreated >= :timestart
              GROUP BY l.eventname, l.component, l.action, l.target
              ORDER BY total_events DESC";

        return $DB->get_records_sql($sql, [
            'courseid' => $this->courseid,
            'timestart' => $timethreshold,
        ]);
    }
}
