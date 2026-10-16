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
 * Grade Analytics Service for block_cynaris_stats.
 *
 * Provides grade aggregation, grade band classifications, and Chart.js builders
 * using Moodle's native Chart API and Data Manipulation API ($DB).
 *
 * @package    block_cynaris_stats
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_cynaris_stats;

defined('MOODLE_INTERNAL') || die();

use stdClass;
use context_course;
use core\chart_pie;
use core\chart_bar;
use core\chart_series;

/**
 * Grade Analytics Service class.
 */
class analytics {

    /** @var int Course ID. */
    protected int $courseid;

    /** @var int|null Target user ID (optional). */
    protected ?int $userid;

    /**
     * Constructor.
     *
     * @param int $courseid The course ID to analyze.
     * @param int|null $userid Optional user ID for student-specific analytics.
     */
    public function __construct(int $courseid, ?int $userid = null) {
        $this->courseid = $courseid;
        $this->userid = $userid;
    }

    /**
     * Determine whether the course contains any graded items with recorded grades.
     *
     * @return bool True if at least one grade record exists with a non-null finalgrade.
     */
    public function has_grade_data(): bool {
        global $DB;

        $sql = "SELECT COUNT(gg.id)
                  FROM {grade_grades} gg
                  JOIN {grade_items} gi ON gi.id = gg.itemid
                 WHERE gi.courseid = :courseid
                   AND gg.finalgrade IS NOT NULL";

        $count = $DB->count_records_sql($sql, ['courseid' => $this->courseid]);
        return ($count > 0);
    }

    /**
     * Classify a percentage score into a standard pedagogical grade band.
     *
     * Standard grade bands:
     * - distinction: >= 85.0%
     * - merit:       >= 70.0% and < 85.0%
     * - pass:        >= 50.0% and < 70.0%
     * - fail:        < 50.0%
     *
     * @param float $percentage Grade percentage between 0 and 100.
     * @return string One of 'distinction', 'merit', 'pass', or 'fail'.
     */
    public static function calculate_grade_band(float $percentage): string {
        if ($percentage >= 85.0) {
            return 'distinction';
        } else if ($percentage >= 70.0) {
            return 'merit';
        } else if ($percentage >= 50.0) {
            return 'pass';
        } else {
            return 'fail';
        }
    }

    /**
     * Get all gradable activity and manual items for the course.
     *
     * @param bool $includeall When true, includes category and course items.
     * @return array Array of grade item records.
     */
    public function get_grade_items(bool $includeall = false): array {
        global $DB;

        if ($includeall) {
            $sql = "SELECT gi.id, gi.itemname, gi.itemtype, gi.itemmodule, gi.iteminstance,
                           gi.grademin, gi.grademax, gi.gradepass, gi.hidden
                      FROM {grade_items} gi
                     WHERE gi.courseid = :courseid
                  ORDER BY gi.sortorder ASC, gi.id ASC";
        } else {
            $sql = "SELECT gi.id, gi.itemname, gi.itemtype, gi.itemmodule, gi.iteminstance,
                           gi.grademin, gi.grademax, gi.gradepass, gi.hidden
                      FROM {grade_items} gi
                     WHERE gi.courseid = :courseid
                       AND gi.itemtype IN ('mod', 'manual')
                  ORDER BY gi.sortorder ASC, gi.id ASC";
        }

        return $DB->get_records_sql($sql, ['courseid' => $this->courseid]);
    }

    /**
     * Compute cohort-wide metrics including grade band distribution and course averages.
     *
     * @return array Associative array of summary metrics.
     */
    public function get_cohort_summary(): array {
        global $DB;

        $summary = [
            'has_data' => false,
            'enrolled_students' => 0,
            'graded_students' => 0,
            'average_score' => null,
            'average_percentage' => null,
            'highest_percentage' => null,
            'lowest_percentage' => null,
            'grade_bands' => [
                'distinction' => 0,
                'merit' => 0,
                'pass' => 0,
                'fail' => 0,
            ],
            'total_items' => 0,
        ];

        // 1. Get student enrollment count
        $context = context_course::instance($this->courseid, IGNORE_MISSING);
        if ($context) {
            $studentrole = $DB->get_record('role', ['shortname' => 'student']);
            if ($studentrole) {
                $summary['enrolled_students'] = count_role_users($studentrole->id, $context);
            } else {
                $summary['enrolled_students'] = count_enrolled_users($context);
            }
        }

        // 2. Count grade items
        $gradeitems = $this->get_grade_items(false);
        $summary['total_items'] = count($gradeitems);

        if (!$this->has_grade_data()) {
            return $summary;
        }

        // 3. Check for course total grade item first
        $courseitem = $DB->get_record('grade_items', [
            'courseid' => $this->courseid,
            'itemtype' => 'course',
        ]);

        $studentpercentages = [];

        if ($courseitem && $courseitem->grademax > $courseitem->grademin) {
            $sql = "SELECT gg.userid, gg.finalgrade
                      FROM {grade_grades} gg
                     WHERE gg.itemid = :itemid
                       AND gg.finalgrade IS NOT NULL";
            $grades = $DB->get_records_sql($sql, ['itemid' => $courseitem->id]);

            $range = $courseitem->grademax - $courseitem->grademin;
            foreach ($grades as $grade) {
                $pct = (($grade->finalgrade - $courseitem->grademin) / $range) * 100.0;
                $pct = max(0.0, min(100.0, (float)$pct));
                $studentpercentages[$grade->userid] = $pct;
            }
        }

        // 4. If no course item grades exist yet, aggregate across activity items
        if (empty($studentpercentages) && !empty($gradeitems)) {
            $sql = "SELECT gg.userid,
                           SUM(gg.finalgrade) AS total_score,
                           SUM(gi.grademax) AS total_max,
                           SUM(gi.grademin) AS total_min
                      FROM {grade_grades} gg
                      JOIN {grade_items} gi ON gi.id = gg.itemid
                     WHERE gi.courseid = :courseid
                       AND gi.itemtype IN ('mod', 'manual')
                       AND gg.finalgrade IS NOT NULL
                  GROUP BY gg.userid";
            $grades = $DB->get_records_sql($sql, ['courseid' => $this->courseid]);

            foreach ($grades as $grade) {
                $range = $grade->total_max - $grade->total_min;
                if ($range > 0) {
                    $pct = (($grade->total_score - $grade->total_min) / $range) * 100.0;
                    $pct = max(0.0, min(100.0, (float)$pct));
                    $studentpercentages[$grade->userid] = $pct;
                }
            }
        }

        if (!empty($studentpercentages)) {
            $summary['has_data'] = true;
            $summary['graded_students'] = count($studentpercentages);
            $totalpct = 0.0;
            $highest = -1.0;
            $lowest = 101.0;

            foreach ($studentpercentages as $pct) {
                $totalpct += $pct;
                if ($pct > $highest) {
                    $highest = $pct;
                }
                if ($pct < $lowest) {
                    $lowest = $pct;
                }

                $band = self::calculate_grade_band($pct);
                $summary['grade_bands'][$band]++;
            }

            $count = count($studentpercentages);
            $summary['average_percentage'] = round($totalpct / $count, 1);
            $summary['highest_percentage'] = round($highest, 1);
            $summary['lowest_percentage'] = round($lowest, 1);
        }

        return $summary;
    }

    /**
     * Compute activity-level averages and statistics across the cohort.
     *
     * @return array Array of activity analysis records.
     */
    public function get_activity_averages(): array {
        global $DB;

        $sql = "SELECT gi.id, gi.itemname, gi.itemtype, gi.itemmodule, gi.iteminstance,
                       gi.grademin, gi.grademax, gi.gradepass,
                       COUNT(gg.id) AS graded_count,
                       AVG(gg.finalgrade) AS avg_grade,
                       MIN(gg.finalgrade) AS min_grade,
                       MAX(gg.finalgrade) AS max_grade
                  FROM {grade_items} gi
             LEFT JOIN {grade_grades} gg
                    ON gg.itemid = gi.id
                   AND gg.finalgrade IS NOT NULL
                   AND gg.hidden = 0
                 WHERE gi.courseid = :courseid
                   AND gi.itemtype IN ('mod', 'manual')
              GROUP BY gi.id, gi.itemname, gi.itemtype, gi.itemmodule, gi.iteminstance,
                       gi.grademin, gi.grademax, gi.gradepass, gi.sortorder
              ORDER BY gi.sortorder ASC, gi.id ASC";

        $records = $DB->get_records_sql($sql, ['courseid' => $this->courseid]);
        $activities = [];

        foreach ($records as $rec) {
            $name = !empty($rec->itemname) ? $rec->itemname : ucfirst($rec->itemmodule ?? 'Item') . ' #' . $rec->id;
            $grademin = (float)$rec->grademin;
            $grademax = (float)$rec->grademax;
            $range = $grademax - $grademin;
            $hasgrades = ((int)$rec->graded_count > 0);
            $avggrade = $hasgrades ? round((float)$rec->avg_grade, 2) : 0.0;
            $percentage = ($hasgrades && $range > 0) ? round((($avggrade - $grademin) / $range) * 100.0, 1) : 0.0;

            $activities[] = [
                'id' => (int)$rec->id,
                'name' => $name,
                'type' => $rec->itemtype,
                'module' => $rec->itemmodule ?? '',
                'grademin' => $grademin,
                'grademax' => $grademax,
                'gradepass' => (float)$rec->gradepass,
                'graded_count' => (int)$rec->graded_count,
                'has_grades' => $hasgrades,
                'avg_grade' => $avggrade,
                'min_grade' => $hasgrades ? round((float)$rec->min_grade, 2) : 0.0,
                'max_grade' => $hasgrades ? round((float)$rec->max_grade, 2) : 0.0,
                'percentage' => $percentage,
                'grade_band' => $hasgrades ? self::calculate_grade_band($percentage) : 'none',
            ];
        }

        return $activities;
    }

    /**
     * Retrieve grades for a specific student, preserving student privacy.
     *
     * @param int $userid The student's user ID.
     * @return array Array of item grades for this student.
     */
    public function get_student_grades(int $userid): array {
        global $DB;

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
            'userid' => $userid,
        ]);

        $grades = [];
        foreach ($records as $rec) {
            $name = !empty($rec->itemname) ? $rec->itemname : ucfirst($rec->itemmodule ?? 'Item') . ' #' . $rec->id;
            $grademin = (float)$rec->grademin;
            $grademax = (float)$rec->grademax;
            $range = $grademax - $grademin;
            $hasgrade = ($rec->finalgrade !== null);
            $finalgrade = $hasgrade ? round((float)$rec->finalgrade, 2) : null;
            $percentage = ($hasgrade && $range > 0) ? round((($finalgrade - $grademin) / $range) * 100.0, 1) : null;

            $passed = null;
            if ($hasgrade && $rec->gradepass > 0) {
                $passed = ($finalgrade >= (float)$rec->gradepass);
            }

            $grades[] = [
                'id' => (int)$rec->id,
                'name' => $name,
                'module' => $rec->itemmodule ?? '',
                'grademin' => $grademin,
                'grademax' => $grademax,
                'gradepass' => (float)$rec->gradepass,
                'has_grade' => $hasgrade,
                'finalgrade' => $finalgrade,
                'percentage' => $percentage,
                'grade_band' => $hasgrade ? self::calculate_grade_band($percentage) : 'pending',
                'passed' => $passed,
                'feedback' => $rec->feedback ?? '',
                'timemodified' => $rec->timemodified ? (int)$rec->timemodified : null,
            ];
        }

        return $grades;
    }

    /**
     * Compute a student's personal progress overview.
     *
     * @param int $userid The student's user ID.
     * @return array Summary of student points and completion.
     */
    public function get_student_summary(int $userid): array {
        $grades = $this->get_student_grades($userid);

        $summary = [
            'total_items' => count($grades),
            'completed_items' => 0,
            'total_score' => 0.0,
            'total_max' => 0.0,
            'overall_percentage' => null,
            'overall_grade_band' => 'pending',
            'has_grades' => false,
        ];

        foreach ($grades as $g) {
            $summary['total_max'] += $g['grademax'];
            if ($g['has_grade']) {
                $summary['completed_items']++;
                $summary['total_score'] += $g['finalgrade'];
                $summary['has_grades'] = true;
            }
        }

        if ($summary['has_grades'] && $summary['total_max'] > 0) {
            $summary['overall_percentage'] = round(($summary['total_score'] / $summary['total_max']) * 100.0, 1);
            $summary['overall_grade_band'] = self::calculate_grade_band($summary['overall_percentage']);
        }

        return $summary;
    }

    /**
     * Build Moodle Chart API pie/doughnut chart for cohort grade distribution.
     *
     * @return chart_pie|null Moodle chart instance or null if no data exists.
     */
    public function build_cohort_distribution_chart(): ?chart_pie {
        $cohort = $this->get_cohort_summary();
        if (!$cohort['has_data'] || $cohort['graded_students'] === 0) {
            return null;
        }

        $bands = $cohort['grade_bands'];

        $chart = new chart_pie();
        $chart->set_doughnut(true);
        $chart->set_title(get_string('chart_distribution_title', 'block_cynaris_stats'));

        $labels = [
            get_string('band_distinction', 'block_cynaris_stats') . ' (>=85%)',
            get_string('band_merit', 'block_cynaris_stats') . ' (70-84%)',
            get_string('band_pass', 'block_cynaris_stats') . ' (50-69%)',
            get_string('band_fail', 'block_cynaris_stats') . ' (<50%)',
        ];

        $values = [
            $bands['distinction'],
            $bands['merit'],
            $bands['pass'],
            $bands['fail'],
        ];

        $series = new chart_series(get_string('students', 'block_cynaris_stats'), $values);
        $series->set_colors(['#28a745', '#17a2b8', '#ffc107', '#dc3545']);
        $chart->add_series($series);
        $chart->set_labels($labels);

        return $chart;
    }

    /**
     * Build Moodle Chart API bar chart comparing activity average scores against max points.
     *
     * @return chart_bar|null Moodle chart instance or null if no data exists.
     */
    public function build_activity_averages_chart(): ?chart_bar {
        $activities = $this->get_activity_averages();
        if (empty($activities)) {
            return null;
        }

        $hasanygrades = false;
        $labels = [];
        $averages = [];
        $maxes = [];

        foreach ($activities as $act) {
            $labels[] = $act['name'];
            $averages[] = $act['avg_grade'];
            $maxes[] = $act['grademax'];
            if ($act['has_grades']) {
                $hasanygrades = true;
            }
        }

        if (!$hasanygrades) {
            return null;
        }

        $chart = new chart_bar();
        $chart->set_title(get_string('chart_activities_title', 'block_cynaris_stats'));
        $chart->set_labels($labels);

        $seriesavg = new chart_series(get_string('class_average', 'block_cynaris_stats'), $averages);
        $seriesavg->set_color('#17a2b8');

        $seriesmax = new chart_series(get_string('max_grade', 'block_cynaris_stats'), $maxes);
        $seriesmax->set_color('#6c757d');

        $chart->add_series($seriesavg);
        $chart->add_series($seriesmax);

        return $chart;
    }

    /**
     * Build Moodle Chart API bar chart of a student's personal score vs max points.
     *
     * @param int $userid The student's user ID.
     * @return chart_bar|null Moodle chart instance or null if no data exists.
     */
    public function build_student_progress_chart(int $userid): ?chart_bar {
        $grades = $this->get_student_grades($userid);
        if (empty($grades)) {
            return null;
        }

        $hasanygrades = false;
        $labels = [];
        $myscores = [];
        $maxes = [];

        foreach ($grades as $g) {
            $labels[] = $g['name'];
            $myscores[] = $g['has_grade'] ? $g['finalgrade'] : 0.0;
            $maxes[] = $g['grademax'];
            if ($g['has_grade']) {
                $hasanygrades = true;
            }
        }

        if (!$hasanygrades) {
            return null;
        }

        $chart = new chart_bar();
        $chart->set_title(get_string('chart_student_title', 'block_cynaris_stats'));
        $chart->set_labels($labels);

        $seriesscore = new chart_series(get_string('my_score', 'block_cynaris_stats'), $myscores);
        $seriesscore->set_color('#007bff');

        $seriesmax = new chart_series(get_string('max_grade', 'block_cynaris_stats'), $maxes);
        $seriesmax->set_color('#6c757d');

        $chart->add_series($seriesscore);
        $chart->add_series($seriesmax);

        return $chart;
    }
}
