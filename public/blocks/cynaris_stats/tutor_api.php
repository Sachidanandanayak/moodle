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
 * Backend AJAX API endpoint for Cynaris AI Course Tutor.
 *
 * Receives student questions, validates authentication, session security (CSRF sesskey),
 * and permissions, and executes queries via the primary (Groq) and fallback (OpenRouter)
 * resilient architecture.
 *
 * API keys remain strictly server-side and are NEVER exposed to the frontend browser.
 *
 * @package    block_cynaris_stats
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/blocks/cynaris_stats/classes/ai_tutor.php');

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

// Check permission: user must be allowed to view the course.
if (!has_capability('moodle/course:view', $context)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'code' => 403,
        'message' => 'You do not have permission to access the AI Tutor in this course.',
    ]);
    exit;
}

// Extract and validate prompt.
$prompt = isset($payload['prompt']) ? (string) $payload['prompt'] : '';
$validation = \block_cynaris_stats\ai_tutor::validate_prompt($prompt);
if (!$validation['valid']) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'code' => 400,
        'message' => $validation['error'],
    ]);
    exit;
}

// Build query options with course context.
$options = [
    'course_name' => format_string($course->fullname),
    'history' => is_array($payload['history'] ?? null) ? $payload['history'] : [],
];

// Optional test simulation parameters (only honored if explicit in payload).
if (!empty($payload['simulate'])) {
    $options['simulate'] = true;
    if (!empty($payload['simulation_scenario'])) {
        $options['simulation_scenario'] = (string) $payload['simulation_scenario'];
    }
}

// Execute AI Tutor engine.
$tutor = new \block_cynaris_stats\ai_tutor();
$result = $tutor->query($validation['prompt'], $options);

if ($result['status'] === 'success') {
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'data' => [
            'response' => $result['response'],
            'provider' => $result['provider'],
            'model' => $result['model'],
            'latency_ms' => $result['latency_ms'] ?? 0,
            'fallback_used' => $result['fallback_used'] ?? false,
            'primary_error' => $result['primary_error'] ?? null,
            'usage' => $result['usage'] ?? null,
        ],
    ]);
} else {
    $httpstatus = ($result['code'] ?? 503) === 400 ? 400 : 503;
    http_response_code($httpstatus);
    echo json_encode([
        'status' => 'error',
        'code' => $httpstatus,
        'message' => $result['message'],
        'fallback_used' => $result['fallback_used'] ?? true,
        'primary_error' => $result['primary_error'] ?? null,
        'fallback_error' => $result['fallback_error'] ?? null,
    ]);
}
