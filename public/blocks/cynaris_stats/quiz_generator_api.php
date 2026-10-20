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
 * Backend AJAX API endpoint for Cynaris AI Quiz Generator.
 *
 * Secure server-side controller for generating MCQs via Groq / OpenRouter,
 * validating JSON schemas, importing questions into Moodle's Question Bank,
 * and creating linked Quiz activities.
 *
 * Security Guarantees:
 * 1. CSRF Sesskey Enforcement: Rejects any request with missing or invalid sesskey.
 * 2. Strict Role Guard: Only instructors and course managers with activity management
 *    capabilities can generate or publish quizzes.
 * 3. Secret Isolation: API keys never leave server environment memory; zero exposure to browser.
 * 4. Sanitized Responses: Stack traces and sensitive credentials are automatically redacted.
 *
 * @package    block_cynaris_stats
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/blocks/cynaris_stats/classes/quiz_generator.php');

use block_cynaris_stats\ai_tutor;
use block_cynaris_stats\quiz_generator;

header('Content-Type: application/json; charset=utf-8');

// Ensure HTTP POST method is used for state-changing queries.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'code' => 405,
        'message' => 'Method Not Allowed. Only HTTP POST requests are supported.',
    ]);
    exit;
}

// Parse request payload (supports JSON body or form-data).
$rawinput = file_get_contents('php://input');
$payload = json_decode($rawinput, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$courseid = isset($payload['courseid']) ? (int) $payload['courseid'] : 0;
if ($courseid <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'code' => 400,
        'message' => 'Invalid or missing course ID.',
    ]);
    exit;
}

// Verify course existence.
$course = $DB->get_record('course', ['id' => $courseid]);
if (!$course) {
    http_response_code(404);
    echo json_encode([
        'status' => 'error',
        'code' => 404,
        'message' => 'Course not found.',
    ]);
    exit;
}

// Authenticate user and establish course context.
try {
    require_login($course, false, null, false, true);
} catch (\Throwable $e) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'code' => 401,
        'message' => 'Authentication required. Please log into Moodle.',
    ]);
    exit;
}

$context = \context_course::instance($course->id);

// Check CSRF protection token (sesskey).
$sesskey = $payload['sesskey'] ?? $_SERVER['HTTP_X_MOODLE_SESSKEY'] ?? '';
if (empty($sesskey) || !confirm_sesskey($sesskey)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'code' => 403,
        'message' => 'Invalid session security token (CSRF sesskey). Please refresh the page.',
    ]);
    exit;
}

// Check permission: user must have quiz generation or course activity editing capability.
$canedit = has_capability('block/cynaris_stats:generatequiz', $context) ||
           has_capability('moodle/course:manageactivities', $context) ||
           has_capability('moodle/question:add', $context);

if (!$canedit) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'code' => 403,
        'message' => 'Access denied. You do not have permission to generate quizzes or manage activities in this course.',
    ]);
    exit;
}

$action = isset($payload['action']) ? trim((string) $payload['action']) : 'generate';

$generator = new quiz_generator();

try {
    switch ($action) {
        case 'generate':
            // Generate 5 MCQs from content.
            $rawcontent = $payload['content'] ?? '';
            $validation = quiz_generator::validate_content($rawcontent);
            if (!$validation['valid']) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'code' => 400,
                    'message' => $validation['error'],
                ]);
                exit;
            }

            $result = $generator->generate_mcqs($validation['content'], [
                'course_name' => $course->fullname,
                'simulate' => !empty($payload['simulate']),
                'simulation_scenario' => $payload['simulation_scenario'] ?? 'primary_success',
            ]);

            if ($result['status'] === 'success') {
                http_response_code(200);
                echo json_encode([
                    'status' => 'success',
                    'data' => [
                        'questions' => $result['questions'],
                        'provider' => $result['provider'],
                        'model' => $result['model'],
                        'latency_ms' => $result['latency_ms'],
                        'fallback_used' => $result['fallback_used'],
                        'primary_error' => $result['primary_error'] ?? null,
                    ],
                ]);
            } else {
                http_response_code($result['code'] ?? 500);
                echo json_encode([
                    'status' => 'error',
                    'code' => $result['code'] ?? 500,
                    'message' => $result['message'],
                    'provider' => $result['provider'] ?? null,
                    'fallback_used' => $result['fallback_used'] ?? false,
                    'primary_error' => $result['primary_error'] ?? null,
                    'fallback_error' => $result['fallback_error'] ?? null,
                ]);
            }
            break;

        case 'publish':
            // Import validated MCQs into Question Bank and create Quiz activity.
            $quizname = !empty($payload['quizname']) ? trim($payload['quizname']) : ('AI Quiz: ' . format_string($course->shortname) . ' (' . date('M j, Y') . ')');
            $section = isset($payload['section']) ? (int) $payload['section'] : 1;
            $rawquestions = $payload['questions'] ?? null;

            if (empty($rawquestions)) {
                // If questions not supplied, generate on the fly from content.
                $rawcontent = $payload['content'] ?? '';
                $genresult = $generator->generate_mcqs($rawcontent, ['course_name' => $course->fullname]);
                if ($genresult['status'] !== 'success') {
                    http_response_code(400);
                    echo json_encode([
                        'status' => 'error',
                        'code' => 400,
                        'message' => 'Failed to generate questions: ' . $genresult['message'],
                    ]);
                    exit;
                }
                $questions = $genresult['questions'];
            } else {
                $validation = quiz_generator::validate_quiz_json(['questions' => $rawquestions]);
                if (!$validation['valid']) {
                    http_response_code(400);
                    echo json_encode([
                        'status' => 'error',
                        'code' => 400,
                        'message' => 'Questions validation error: ' . $validation['error'],
                    ]);
                    exit;
                }
                $questions = $validation['questions'];
            }

            // 1. Import to Moodle Question Bank via official Question APIs.
            $createdquestions = quiz_generator::import_to_question_bank($questions, $course->id);
            $questionids = array_map(static fn($q) => $q->id, $createdquestions);

            // 2. Create Moodle Quiz activity and attach the questions.
            $quizresult = quiz_generator::create_moodle_quiz($course->id, $quizname, $questionids, [
                'section' => $section,
            ]);

            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'quizid' => $quizresult->quizid,
                    'cmid' => $quizresult->cmid,
                    'quizname' => $quizresult->quizname,
                    'question_count' => count($questionids),
                    'question_ids' => $questionids,
                    'quiz_url' => $quizresult->url->out(false),
                    'course_url' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
                ],
            ]);
            break;

        case 'export_gift':
            // Generate standard Moodle GIFT format for the supplied questions.
            $rawquestions = $payload['questions'] ?? [];
            $validation = quiz_generator::validate_quiz_json(['questions' => $rawquestions]);
            if (!$validation['valid']) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'code' => 400,
                    'message' => 'GIFT export failed: ' . $validation['error'],
                ]);
                exit;
            }

            $gift = quiz_generator::convert_to_gift($validation['questions'], format_string($course->fullname));
            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'gift' => $gift,
                    'filename' => 'quiz_' . clean_filename($course->shortname) . '_' . date('Ymd_His') . '.txt',
                ],
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'code' => 400,
                'message' => "Unknown action '{$action}'. Supported actions: 'generate', 'publish', 'export_gift'.",
            ]);
            break;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'code' => 500,
        'message' => 'An unexpected internal error occurred during quiz processing.',
        'details' => ai_tutor::sanitize_error($e->getMessage()),
    ]);
}
