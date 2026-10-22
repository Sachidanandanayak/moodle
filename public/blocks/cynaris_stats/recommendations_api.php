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
 * RESTful AJAX endpoint for Cynaris AI Learning Recommendations (W8D3).
 *
 * Provides JSON payloads of student weak areas, prioritized remediation actions,
 * and AI-synthesized coaching strategies.
 *
 * @package    block_cynaris_stats
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/gradelib.php');

header('Content-Type: application/json; charset=utf-8');

try {
    $courseid = required_param('courseid', PARAM_INT);
    $targetuserid = optional_param('userid', 0, PARAM_INT);

    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

    // Enforce authentication & session
    require_login($course);
    $context = context_course::instance($course->id);

    // Capability check
    if (has_capability('block/cynaris_stats:viewrecommendations', $context)) {
        require_capability('block/cynaris_stats:viewrecommendations', $context);
    } else {
        require_capability('moodle/course:view', $context);
    }

    $canviewall = has_capability('moodle/grade:viewall', $context);

    // Students may only view their own telemetry
    if (!$canviewall || $targetuserid <= 0) {
        $targetuserid = (int)$USER->id;
    }

    $targetuser = $DB->get_record('user', ['id' => $targetuserid], '*', MUST_EXIST);

    // Initialize recommendation engine
    $aitutor = new \block_cynaris_stats\ai_tutor();
    $engine = new \block_cynaris_stats\recommendation_engine($course->id, $targetuser->id, $aitutor, true);
    $report = $engine->get_full_report(format_string($course->fullname));

    echo json_encode([
        'status' => 'success',
        'data' => $report,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (\moodle_exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'errorcode' => $e->errorcode ?? 'generalexception',
        'message' => $e->getMessage(),
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'errorcode' => 'server_error',
        'message' => 'An error occurred while generating recommendations.',
    ]);
}
