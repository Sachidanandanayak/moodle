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
require_once($CFG->dirroot . '/blocks/cynaris_stats/classes/recommendation_engine.php');
require_once($CFG->dirroot . '/blocks/cynaris_stats/classes/analytics.php');
require_once($CFG->dirroot . '/blocks/cynaris_stats/classes/ai_tutor.php');

/**
 * PHPUnit test case for Cynaris AI Learning Recommendations (W8D3).
 *
 * @package    block_cynaris_stats
 * @category   test
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recommendation_test extends \advanced_testcase {

    /**
     * Test empty database telemetry fallback.
     */
    public function test_empty_database_telemetry(): void {
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Test Recommendation Course', 'shortname' => 'TRC01']);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $engine = new recommendation_engine($course->id, $student->id, null, false);
        $report = $engine->get_full_report($course->fullname);

        $this->assertEquals($course->id, $report['courseid']);
        $this->assertEquals($student->id, $report['userid']);
        $this->assertFalse($report['grade_summary']['has_grades']);
        $this->assertCount(1, $report['recommendations']);
        $this->assertEquals('onboarding', $report['recommendations'][0]['category']);
    }

    /**
     * Test weak area classification boundaries.
     */
    public function test_weak_area_boundaries(): void {
        $engine = new recommendation_engine(1, 1, null, false);

        $gradedata = [
            'has_grades' => true,
            'items' => [
                [
                    'id' => 1,
                    'name' => 'Critical Quiz',
                    'itemmodule' => 'quiz',
                    'iteminstance' => 1,
                    'has_grade' => true,
                    'percentage' => 45.0,
                    'gradepass' => 0.0,
                    'passed' => false,
                ],
                [
                    'id' => 2,
                    'name' => 'Warning Quiz',
                    'itemmodule' => 'quiz',
                    'iteminstance' => 2,
                    'has_grade' => true,
                    'percentage' => 65.0,
                    'gradepass' => 0.0,
                    'passed' => true,
                ],
                [
                    'id' => 3,
                    'name' => 'Distinction Quiz',
                    'itemmodule' => 'quiz',
                    'iteminstance' => 3,
                    'has_grade' => true,
                    'percentage' => 90.0,
                    'gradepass' => 0.0,
                    'passed' => true,
                ],
            ],
        ];

        $weak = $engine->identify_weak_areas($gradedata, ['modules' => []]);
        $this->assertCount(2, $weak);
        $this->assertEquals('critical', $weak[0]['severity']);
        $this->assertEquals('warning', $weak[1]['severity']);
    }

    /**
     * Test prioritization ordering.
     */
    public function test_recommendation_prioritization(): void {
        $engine = new recommendation_engine(1, 1, null, false);

        $weak = [
            [
                'id' => 'grade_2',
                'source' => 'grade',
                'name' => 'Moderate Item',
                'module' => 'quiz',
                'instance' => 2,
                'severity' => 'warning',
                'reason' => 'Score 60%.',
            ],
            [
                'id' => 'grade_1',
                'source' => 'grade',
                'name' => 'Failed Item',
                'module' => 'quiz',
                'instance' => 1,
                'severity' => 'critical',
                'reason' => 'Score 40%.',
            ],
        ];

        $recs = $engine->generate_recommendations($weak, ['has_grades' => true], ['modules' => []]);
        $this->assertCount(2, $recs);
        $this->assertEquals('critical', $recs[0]['priority']);
        $this->assertEquals('high', $recs[1]['priority']);
    }
}
