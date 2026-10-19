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
 * Automated CLI test harness for Cynaris AI Course Tutor (W8D1).
 *
 * Verifies:
 * 1. Input validation (empty, whitespace, length bounds, control chars).
 * 2. Primary provider (Groq Llama 3.3 70B) execution and token tracking.
 * 3. Rate-limit (HTTP 429) detection and automatic OpenRouter fallback.
 * 4. Timeout detection and automatic OpenRouter fallback.
 * 5. Safe dual-provider failure handling (HTTP 503, user-safe message).
 * 6. Secret key isolation and error sanitization (no credential leakage).
 * 7. Pedagogical system prompt generation and course context injection.
 * 8. Latency metrics and usage accounting.
 *
 * Can execute cleanly in Docker or standalone PHP CLI on Windows/Linux.
 *
 * @package    block_cynaris_stats
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// Safe bootstrap: load Moodle if available; otherwise provide minimal environment.
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
}

require_once(__DIR__ . '/../classes/ai_tutor.php');

use block_cynaris_stats\ai_tutor;

$passed = 0;
$failed = 0;
$testindex = 0;

function assert_test(string $name, bool $condition, string $details = ''): void {
    global $passed, $failed, $testindex;
    $testindex++;
    if ($condition) {
        $passed++;
        echo sprintf("  [%02d] PASS: %s\n", $testindex, $name);
    } else {
        $failed++;
        echo sprintf("  [%02d] FAIL: %s%s\n", $testindex, $name, ($details ? " -> {$details}" : ''));
    }
}

echo "\n======================================================================\n";
echo "  CYNARIS W8D1: AI TUTOR INTEGRATION (GROQ PRIMARY / OPENROUTER FALLBACK)\n";
echo "  Automated Verification & Resilience Test Suite\n";
echo "======================================================================\n\n";

// ----------------------------------------------------------------------
// SUITE 1: Input Validation & Sanitization Boundaries
// ----------------------------------------------------------------------
echo "--- Suite 1: Input Validation & Sanitization ---\n";

$v1 = ai_tutor::validate_prompt('');
assert_test("Empty string is rejected", !$v1['valid'] && str_contains($v1['error'], 'empty'));

$v2 = ai_tutor::validate_prompt('     ');
assert_test("Whitespace-only prompt is rejected", !$v2['valid'] && str_contains($v2['error'], 'empty'));

$v3 = ai_tutor::validate_prompt('?');
assert_test("1-character prompt is rejected (below MIN_PROMPT_LENGTH)", !$v3['valid'] && str_contains($v3['error'], 'short'));

$v4 = ai_tutor::validate_prompt(str_repeat('A', 3001));
assert_test("Prompt > 3000 chars is rejected (above MAX_PROMPT_LENGTH)", !$v4['valid'] && str_contains($v4['error'], 'exceeds'));

$v5 = ai_tutor::validate_prompt("How does Frankenstyle work in Moodle?");
assert_test("Valid prompt is accepted", $v5['valid'] && $v5['prompt'] === "How does Frankenstyle work in Moodle?");

$v6 = ai_tutor::validate_prompt("Explain \x00null byte and \x07bell char sanitization");
assert_test("Control characters are stripped defensively", $v6['valid'] && !str_contains($v6['prompt'], "\x00") && !str_contains($v6['prompt'], "\x07"));


// ----------------------------------------------------------------------
// SUITE 2: Primary Provider (Groq) Success Path
// ----------------------------------------------------------------------
echo "\n--- Suite 2: Primary Provider (Groq Llama 3.3 70B) Success Path ---\n";

$tutor = new ai_tutor('test_groq_key_placeholder', 'test_openrouter_key_placeholder', [
    'simulate' => true,
    'simulation_scenario' => 'primary_success',
]);

$res1 = $tutor->query('Explain Moodle Frankenstyle plugin naming convention', [
    'course_name' => 'Full Stack Web Development',
]);

assert_test("Query returns status 'success'", $res1['status'] === 'success');
assert_test("Serving provider is identified as Groq", $res1['provider'] === ai_tutor::PROVIDER_GROQ);
assert_test("fallback_used flag is false on primary success", $res1['fallback_used'] === false);
assert_test("Model identifies Groq Llama 3.3", str_contains($res1['model'], 'llama-3.3-70b'));
assert_test("Response contains pedagogical explanation of Frankenstyle", str_contains($res1['response'], 'Frankenstyle'));
assert_test("Latency in milliseconds is recorded (> 0)", isset($res1['latency_ms']) && $res1['latency_ms'] > 0);
assert_test("Token usage metrics are tracked", isset($res1['usage']['total_tokens']) && $res1['usage']['total_tokens'] > 0);


// ----------------------------------------------------------------------
// SUITE 3: Groq Rate-Limit (HTTP 429) Triggers OpenRouter Fallback
// ----------------------------------------------------------------------
echo "\n--- Suite 3: Groq Rate-Limit (HTTP 429) -> OpenRouter Fallback ---\n";

$tutor->set_simulation(true, 'groq_429_fallback');

$res2 = $tutor->query('How does XMLDB schema definition work?', [
    'course_name' => 'Full Stack Web Development',
]);

assert_test("Status is 'success' via automatic fallback", $res2['status'] === 'success');
assert_test("Serving provider switched to OpenRouter", $res2['provider'] === ai_tutor::PROVIDER_OPENROUTER);
assert_test("fallback_used flag is true", $res2['fallback_used'] === true);
assert_test("Primary error reason is captured (HTTP 429 Rate Limit)", str_contains($res2['primary_error'], 'HTTP 429'));
assert_test("Response provides XMLDB schema explanation", str_contains($res2['response'], 'XMLDB') || str_contains($res2['response'], 'install.xml'));


// ----------------------------------------------------------------------
// SUITE 4: Groq Connection Timeout Triggers OpenRouter Fallback
// ----------------------------------------------------------------------
echo "\n--- Suite 4: Groq Connection Timeout -> OpenRouter Fallback ---\n";

$tutor->set_simulation(true, 'groq_timeout_fallback');

$res3 = $tutor->query('How to prevent SQL injection in Moodle?', [
    'course_name' => 'Full Stack Web Development',
]);

assert_test("Status is 'success' despite primary timeout", $res3['status'] === 'success');
assert_test("Serving provider is OpenRouter", $res3['provider'] === ai_tutor::PROVIDER_OPENROUTER);
assert_test("fallback_used is true", $res3['fallback_used'] === true);
assert_test("Primary error captures timeout description", str_contains($res3['primary_error'], 'timed out'));
assert_test("Response emphasizes Moodle \$DB parameter binding", str_contains($res3['response'], '$DB'));


// ----------------------------------------------------------------------
// SUITE 5: Safe Dual-Provider Failure Handling
// ----------------------------------------------------------------------
echo "\n--- Suite 5: Dual Provider Failure (Both Groq and OpenRouter Fail) ---\n";

$tutor->set_simulation(true, 'both_failed');

$res4 = $tutor->query('What is the role archetype hierarchy in Moodle?');

assert_test("Dual failure returns status 'error'", $res4['status'] === 'error');
assert_test("Dual failure returns HTTP status code 503", ($res4['code'] ?? 0) === 503);
assert_test("Provider is null on dual failure", $res4['provider'] === null);
assert_test("User-facing message is polite and clear", str_contains($res4['message'], 'temporarily unavailable'));
assert_test("Primary and fallback error details are recorded", !empty($res4['primary_error']) && !empty($res4['fallback_error']));


// ----------------------------------------------------------------------
// SUITE 6: Secret Key Isolation & Error Redaction
// ----------------------------------------------------------------------
echo "\n--- Suite 6: Secret Key Isolation & Credential Redaction ---\n";

$leakyerror = "Groq API error with Authorization Bearer gsk_live_secret_key_1234567890abcdef at /var/www/html/secret.php";
$cleanerror = ai_tutor::sanitize_error($leakyerror);

assert_test("Groq API key pattern (gsk_*) is redacted", !str_contains($cleanerror, 'gsk_live_secret_key_1234567890abcdef') && str_contains($cleanerror, '[REDACTED_API_KEY]'));
assert_test("Internal filesystem paths are redacted", !str_contains($cleanerror, '/var/www/html/secret.php') && str_contains($cleanerror, '[INTERNAL_PATH]'));

$leakyopenrouter = "OpenRouter fault using sk-or-v1-abcdef0123456789abcdef in C:\\Users\\sachin\\app\\config.php";
$cleanopenrouter = ai_tutor::sanitize_error($leakyopenrouter);
assert_test("OpenRouter API key pattern (sk-or-*) is redacted", !str_contains($cleanopenrouter, 'sk-or-v1-abcdef0123456789abcdef') && str_contains($cleanopenrouter, '[REDACTED_API_KEY]'));
assert_test("Windows filesystem paths are redacted", !str_contains($cleanopenrouter, 'C:\\Users\\sachin') && str_contains($cleanopenrouter, '[INTERNAL_PATH]'));

// Verify keys never appear in public query responses
$tutorreal = new ai_tutor('gsk_secret_sample_key', 'sk-or-v1-secret_sample_key', ['simulate' => true]);
$ressecret = $tutorreal->query('Hello world');
$serialized = json_encode($ressecret);
assert_test("API keys are never exposed in serialized JSON responses", !str_contains($serialized, 'gsk_secret_sample_key') && !str_contains($serialized, 'sk-or-v1-secret_sample_key'));


// ----------------------------------------------------------------------
// SUITE 7: Pedagogical System Prompt Engineering
// ----------------------------------------------------------------------
echo "\n--- Suite 7: Pedagogical System Prompt Engineering ---\n";

$prompt1 = ai_tutor::get_system_prompt('Full Stack Web Development', 'W8D1 AI Tutor');

assert_test("System prompt includes course name", str_contains($prompt1, 'Full Stack Web Development'));
assert_test("System prompt includes learning module", str_contains($prompt1, 'W8D1 AI Tutor'));
assert_test("System prompt specifies pedagogical guidelines", str_contains($prompt1, 'pedagogical guidelines'));
assert_test("System prompt instructs clean modern code (PHP 8+, ES6+)", str_contains($prompt1, 'PHP 8+') && str_contains($prompt1, 'ES6+'));
assert_test("System prompt enforces SQL prepared statements", str_contains($prompt1, 'prepared statements'));


// ----------------------------------------------------------------------
// SUITE 8: Provider Status & Configuration Inspection
// ----------------------------------------------------------------------
echo "\n--- Suite 8: Configuration Inspection ---\n";

$tutorunconfigured = new ai_tutor('', '');
assert_test("Unconfigured tutor reports has_primary_key() false", !$tutorunconfigured->has_primary_key());
assert_test("Unconfigured tutor reports has_fallback_key() false", !$tutorunconfigured->has_fallback_key());

$tutorconfigured = new ai_tutor('gsk_valid_mock_key', 'sk-or-valid_mock_key');
assert_test("Configured tutor reports has_primary_key() true", $tutorconfigured->has_primary_key());
assert_test("Configured tutor reports has_fallback_key() true", $tutorconfigured->has_fallback_key());


// ----------------------------------------------------------------------
// Final Test Results Summary
// ----------------------------------------------------------------------
echo "\n======================================================================\n";
echo sprintf("  TEST EXECUTION SUMMARY: %d PASSED, %d FAILED (TOTAL %d)\n", $passed, $failed, $passed + $failed);
if ($failed === 0) {
    echo "  STATUS: ALL TESTS PASSED SUCCESSFULLY (100% SUCCESS RATE)\n";
} else {
    echo "  STATUS: TEST FAILURES DETECTED\n";
}
echo "======================================================================\n\n";

exit($failed > 0 ? 1 : 0);
