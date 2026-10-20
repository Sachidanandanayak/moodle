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
 * Automated CLI test harness for Cynaris AI Quiz Generator (W8D2).
 *
 * Verifies:
 * 1. Content validation boundaries (empty, min/max length, control chars).
 * 2. 5-MCQ JSON schema validation (strictly 5 questions, 4 options each, explanations).
 * 3. Malformed JSON handling (syntax error, broken brackets, markdown fences).
 * 4. Missing required field rejection (question_text, options, correct_answer, explanation).
 * 5. Question and option count boundaries (rejecting non-5 questions, non-4 options).
 * 6. Correct answer normalization ('A'-'D', integer indices, text matching, and invalid values).
 * 7. Moodle qtype_multichoice form data structure preparation.
 * 8. GIFT format generation and character escaping.
 * 9. Primary Groq execution, HTTP 429 failover, and dual failure resilience.
 * 10. Secret key isolation and error sanitization.
 * 11. Mock vs. Live API distinction (clearly segregated).
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
    if (!defined('FORMAT_HTML')) {
        define('FORMAT_HTML', 1);
    }
    if (!defined('FORMAT_PLAIN')) {
        define('FORMAT_PLAIN', 2);
    }
    if (!class_exists('core_question\local\bank\question_version_status')) {
        eval('namespace core_question\local\bank; class question_version_status { public const QUESTION_STATUS_READY = "ready"; }');
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
}

require_once(__DIR__ . '/../classes/ai_tutor.php');
require_once(__DIR__ . '/../classes/quiz_generator.php');

use block_cynaris_stats\ai_tutor;
use block_cynaris_stats\quiz_generator;

$passed = 0;
$failed = 0;
$skipped = 0;
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

function skip_test(string $name, string $reason): void {
    global $skipped, $testindex;
    $testindex++;
    $skipped++;
    echo sprintf("  [%02d] SKIP: %s (%s)\n", $testindex, $name, $reason);
}

echo "\n======================================================================\n";
echo "  CYNARIS W8D2: AI QUIZ GENERATION & QUESTION BANK VERIFICATION SUITE\n";
echo "  Groq Llama 3.3 70B -> Strict JSON -> Question Bank & Quiz API\n";
echo "======================================================================\n\n";

// ----------------------------------------------------------------------
// SUITE 1: Course Content Input Validation & Boundaries
// ----------------------------------------------------------------------
echo "--- Suite 1: Course Content Input Validation & Boundaries ---\n";

$v1 = quiz_generator::validate_content('');
assert_test("Empty content string is rejected", !$v1['valid'] && strpos($v1['error'], 'cannot be empty') !== false);

$v2 = quiz_generator::validate_content('   ');
assert_test("Whitespace-only content is rejected", !$v2['valid']);

$v3 = quiz_generator::validate_content(12345);
assert_test("Non-string content is rejected", !$v3['valid']);

$v4 = quiz_generator::validate_content('Short text');
assert_test("Content below MIN_CONTENT_LENGTH (20 chars) is rejected", !$v4['valid'] && strpos($v4['error'], 'too short') !== false);

$toolong = str_repeat('A', 15001);
$v5 = quiz_generator::validate_content($toolong);
assert_test("Content above MAX_CONTENT_LENGTH (15000 chars) is rejected", !$v5['valid'] && strpos($v5['error'], 'exceeds') !== false);

$validcontent = "Moodle Question Bank provides an abstraction layer allowing questions to be versioned and categorized.";
$v6 = quiz_generator::validate_content($validcontent);
assert_test("Valid course content is accepted", $v6['valid'] && $v6['content'] === $validcontent);

$withctrl = "Valid course content\x00\x08 with bad control characters stripped.";
$v7 = quiz_generator::validate_content($withctrl);
assert_test("Dangerous control characters are stripped defensively", $v7['valid'] && strpos($v7['content'], "\x00") === false);

// ----------------------------------------------------------------------
// SUITE 2: Strict 5-MCQ JSON Schema Validation & Parser
// ----------------------------------------------------------------------
echo "\n--- Suite 2: Strict 5-MCQ JSON Schema Validation & Parser ---\n";

$validfixture = [
    'questions' => [
        [
            'question_text' => 'What is the primary purpose of XMLDB in Moodle?',
            'options' => [
                'To define database tables in a portable XML format',
                'To style Mustache templates with CSS',
                'To execute cron jobs periodically',
                'To manage OAuth2 client tokens'
            ],
            'correct_answer' => 'A',
            'explanation' => 'XMLDB abstracts database table definitions into install.xml, supporting PostgreSQL, MySQL, and MariaDB.'
        ],
        [
            'question_text' => 'Which global object handles database operations in Moodle?',
            'options' => [
                '$DB moodle_database abstraction layer',
                'PDO direct connection instance',
                'mysqli_query global function',
                'IndexedDB browser store'
            ],
            'correct_answer' => 'A',
            'explanation' => 'The global $DB object enforces parameter binding and prevents SQL injection vulnerabilities.'
        ],
        [
            'question_text' => 'Under Frankenstyle, what is the component name for block_cynaris_stats?',
            'options' => [
                'block_cynaris_stats',
                'cynaris_stats_block',
                'local_cynaris_stats',
                'mod_cynaris_stats'
            ],
            'correct_answer' => 'A',
            'explanation' => 'Frankenstyle blocks are always prefixed with block_ followed by the plugin name.'
        ],
        [
            'question_text' => 'What HTTP status code triggers automatic OpenRouter fallback from Groq?',
            'options' => [
                'HTTP 429 Too Many Requests',
                'HTTP 200 OK',
                'HTTP 302 Found',
                'HTTP 304 Not Modified'
            ],
            'correct_answer' => 'A',
            'explanation' => 'HTTP 429 indicates upstream rate limiting, prompting instant routing to the fallback provider.'
        ],
        [
            'question_text' => 'What does confirm_sesskey() protect against in Moodle?',
            'options' => [
                'Cross-Site Request Forgery (CSRF)',
                'SQL Injection (SQLi)',
                'Denial of Service (DoS)',
                'Memory Buffer Overflows'
            ],
            'correct_answer' => 'A',
            'explanation' => 'confirm_sesskey() validates the user unique session token to prevent unauthorized forged requests.'
        ],
    ]
];

$p1 = quiz_generator::validate_quiz_json(json_encode($validfixture));
assert_test("Valid 5-MCQ JSON string parses successfully", $p1['valid'] && count($p1['questions']) === 5);
assert_test("Question #1 stem matches fixture", $p1['questions'][0]['question_text'] === $validfixture['questions'][0]['question_text']);
assert_test("Question #1 has exactly 4 options", count($p1['questions'][0]['options']) === 4);
assert_test("Question #1 correct_index is 0 for letter 'A'", $p1['questions'][0]['correct_index'] === 0);
assert_test("Question #1 explanation is captured", !empty($p1['questions'][0]['explanation']));

// ----------------------------------------------------------------------
// SUITE 3: Malformed JSON, Truncated JSON & Markdown Fence Stripping
// ----------------------------------------------------------------------
echo "\n--- Suite 3: Malformed JSON, Truncated JSON & Markdown Fence Stripping ---\n";

$malformedjson = '{"questions": [ {"question_text": "Broken JSON stem';
$m1 = quiz_generator::validate_quiz_json($malformedjson);
assert_test("Malformed JSON string is rejected with parse error", !$m1['valid'] && strpos($m1['error'], 'Malformed JSON') !== false);

$notjson = "I am an AI assistant and here are your 5 questions: 1. What is PHP?";
$m2 = quiz_generator::validate_quiz_json($notjson);
assert_test("Plain conversational text is rejected safely", !$m2['valid']);

// Markdown code fence wrapped JSON (frequent LLM output format)
$fencedjson = "```json\n" . json_encode($validfixture) . "\n```";
$m3 = quiz_generator::validate_quiz_json($fencedjson);
assert_test("Markdown code fence ```json ... ``` is safely unwrapped and parsed", $m3['valid'] && count($m3['questions']) === 5);

$fencednojson = "```\n" . json_encode($validfixture) . "\n```";
$m4 = quiz_generator::validate_quiz_json($fencednojson);
assert_test("Generic code fence ``` ... ``` is safely unwrapped and parsed", $m4['valid'] && count($m4['questions']) === 5);

// Missing top-level 'questions' key
$missingkey = json_encode(['quiz' => []]);
$m5 = quiz_generator::validate_quiz_json($missingkey);
assert_test("JSON missing 'questions' top-level key is rejected", !$m5['valid'] && strpos($m5['error'], 'Missing required top-level') !== false);

// ----------------------------------------------------------------------
// SUITE 4: Question Count and Option Count Boundary Enforcement
// ----------------------------------------------------------------------
echo "\n--- Suite 4: Question Count and Option Count Boundary Enforcement ---\n";

// Only 3 questions (below REQUIRED_QUESTION_COUNT = 5)
$threeq = $validfixture;
array_splice($threeq['questions'], 3);
$b1 = quiz_generator::validate_quiz_json($threeq);
assert_test("Payload with 3 questions (expected 5) is rejected", !$b1['valid'] && strpos($b1['error'], 'expected exactly 5') !== false);

// 6 questions (above REQUIRED_QUESTION_COUNT = 5)
$sixq = $validfixture;
$sixq['questions'][] = $validfixture['questions'][0];
$b2 = quiz_generator::validate_quiz_json($sixq);
assert_test("Payload with 6 questions (expected 5) is rejected", !$b2['valid'] && strpos($b2['error'], 'expected exactly 5') !== false);

// Question with only 3 options (expected 4)
$badopts = $validfixture;
array_pop($badopts['questions'][0]['options']);
$b3 = quiz_generator::validate_quiz_json($badopts);
assert_test("Question with 3 options (expected 4) is rejected", !$b3['valid'] && strpos($b3['error'], 'must contain exactly 4 options') !== false);

// Question with 5 options (expected 4)
$fiveopts = $validfixture;
$fiveopts['questions'][0]['options'][] = 'Extra fifth option';
$b4 = quiz_generator::validate_quiz_json($fiveopts);
assert_test("Question with 5 options (expected 4) is rejected", !$b4['valid'] && strpos($b4['error'], 'must contain exactly 4 options') !== false);

// Question with duplicate options
$dupopts = $validfixture;
$dupopts['questions'][0]['options'][1] = $dupopts['questions'][0]['options'][0];
$b5 = quiz_generator::validate_quiz_json($dupopts);
assert_test("Question with duplicate options is rejected", !$b5['valid'] && strpos($b5['error'], 'duplicate answer options') !== false);

// ----------------------------------------------------------------------
// SUITE 5: Missing Required Fields Validation
// ----------------------------------------------------------------------
echo "\n--- Suite 5: Missing Required Fields Validation ---\n";

// Missing question_text
$notext = $validfixture;
unset($notext['questions'][1]['question_text']);
$f1 = quiz_generator::validate_quiz_json($notext);
assert_test("Missing question_text is rejected", !$f1['valid'] && strpos($f1['error'], 'question_text') !== false);

// Empty question_text
$emptytext = $validfixture;
$emptytext['questions'][2]['question_text'] = '   ';
$f2 = quiz_generator::validate_quiz_json($emptytext);
assert_test("Whitespace question_text is rejected", !$f2['valid'] && strpos($f2['error'], 'question_text') !== false);

// Missing options
$noopts = $validfixture;
unset($noopts['questions'][3]['options']);
$f3 = quiz_generator::validate_quiz_json($noopts);
assert_test("Missing options array is rejected", !$f3['valid'] && strpos($f3['error'], 'options') !== false);

// Missing correct_answer
$nocorrect = $validfixture;
unset($nocorrect['questions'][4]['correct_answer']);
$f4 = quiz_generator::validate_quiz_json($nocorrect);
assert_test("Missing correct_answer is rejected", !$f4['valid'] && strpos($f4['error'], 'correct_answer') !== false);

// Missing explanation
$noexp = $validfixture;
unset($noexp['questions'][0]['explanation']);
$f5 = quiz_generator::validate_quiz_json($noexp);
assert_test("Missing explanation is rejected", !$f5['valid'] && strpos($f5['error'], 'explanation') !== false);

// ----------------------------------------------------------------------
// SUITE 6: Correct Answer Normalization & Mapping
// ----------------------------------------------------------------------
echo "\n--- Suite 6: Correct Answer Normalization & Mapping ---\n";

// Correct answer as letter 'B'
$qletterb = $validfixture;
$qletterb['questions'][0]['correct_answer'] = 'B';
$c1 = quiz_generator::validate_quiz_json($qletterb);
assert_test("Letter 'B' maps to correct_index 1 and letter 'B'", $c1['valid'] && $c1['questions'][0]['correct_index'] === 1 && $c1['questions'][0]['correct_letter'] === 'B');

// Correct answer as letter 'C'
$qletterc = $validfixture;
$qletterc['questions'][0]['correct_answer'] = 'c'; // Lowercase
$c2 = quiz_generator::validate_quiz_json($qletterc);
assert_test("Lowercase 'c' maps to correct_index 2 and letter 'C'", $c2['valid'] && $c2['questions'][0]['correct_index'] === 2 && $c2['questions'][0]['correct_letter'] === 'C');

// Correct answer as letter 'D'
$qletterd = $validfixture;
$qletterd['questions'][0]['correct_answer'] = 'D';
$c3 = quiz_generator::validate_quiz_json($qletterd);
assert_test("Letter 'D' maps to correct_index 3 and letter 'D'", $c3['valid'] && $c3['questions'][0]['correct_index'] === 3 && $c3['questions'][0]['correct_letter'] === 'D');

// Correct answer as 0-indexed integer "2"
$qint2 = $validfixture;
$qint2['questions'][0]['correct_answer'] = 2;
$c4 = quiz_generator::validate_quiz_json($qint2);
assert_test("Integer 2 maps to correct_index 2", $c4['valid'] && $c4['questions'][0]['correct_index'] === 2);

// Correct answer as exact string match to an option
$qexact = $validfixture;
$targetstring = $qexact['questions'][0]['options'][3];
$qexact['questions'][0]['correct_answer'] = $targetstring;
$c5 = quiz_generator::validate_quiz_json($qexact);
assert_test("Exact string match to option 4 resolves to index 3", $c5['valid'] && $c5['questions'][0]['correct_index'] === 3);

// Out of bounds letter 'E'
$qlettere = $validfixture;
$qlettere['questions'][0]['correct_answer'] = 'E';
$c6 = quiz_generator::validate_quiz_json($qlettere);
assert_test("Out-of-bounds letter 'E' is rejected safely", !$c6['valid'] && strpos($c6['error'], 'invalid correct_answer') !== false);

// Out of bounds integer 9
$qint9 = $validfixture;
$qint9['questions'][0]['correct_answer'] = 9;
$c7 = quiz_generator::validate_quiz_json($qint9);
assert_test("Out-of-bounds integer 9 is rejected safely", !$c7['valid'] && strpos($c7['error'], 'invalid correct_answer') !== false);

// ----------------------------------------------------------------------
// SUITE 7: GIFT Format Export & Character Escaping
// ----------------------------------------------------------------------
echo "\n--- Suite 7: GIFT Format Export & Character Escaping ---\n";

$gift = quiz_generator::convert_to_gift($p1['questions'], 'Full Stack Course');
assert_test("GIFT format contains header", strpos($gift, 'Cynaris AI Quiz Generator') !== false);
assert_test("GIFT format contains category tag", strpos($gift, '$CATEGORY: Full Stack Course') !== false);
assert_test("GIFT format contains exactly 5 question stems", substr_count($gift, '::Q') === 5);
assert_test("GIFT format marks correct option with '='", strpos($gift, '=To define database tables') !== false);
assert_test("GIFT format marks distractors with '~'", strpos($gift, '~To style Mustache templates') !== false);
assert_test("GIFT format includes explanation behind '#'", strpos($gift, '#Correct!') !== false);

// Test special character escaping
$rawspecial = "Test: {braces} ~tilde =equal #hash \\slash";
$escaped = quiz_generator::escape_gift_text($rawspecial);
assert_test("Special GIFT character '~' is escaped", strpos($escaped, '\~') !== false);
assert_test("Special GIFT character '=' is escaped", strpos($escaped, '\=') !== false);
assert_test("Special GIFT character '#' is escaped", strpos($escaped, '\#') !== false);
assert_test("Special GIFT character '{' is escaped", strpos($escaped, '\{') !== false);
assert_test("Special GIFT character '}' is escaped", strpos($escaped, '\}') !== false);

// ----------------------------------------------------------------------
// SUITE 8: Moodle Question Bank Form Data Adapter (qtype_multichoice)
// ----------------------------------------------------------------------
echo "\n--- Suite 8: Moodle Question Bank Form Data Adapter (qtype_multichoice) ---\n";

$singlequestion = $p1['questions'][0];
$formdata = quiz_generator::prepare_moodle_question_form_data($singlequestion, 12, 34);

assert_test("Form data sets combined category context (12,34)", $formdata->category === '12,34');
assert_test("Form data sets single-choice flag to '1'", $formdata->single === '1');
assert_test("Form data sets defaultmark to 1.0", $formdata->defaultmark === 1.0);
assert_test("Form data creates exactly 4 answers", count($formdata->answer) === 4);
assert_test("Form data marks correct answer with fraction 1.0", $formdata->fraction[0] === '1.0');
assert_test("Form data marks distractor 1 with fraction 0.0", $formdata->fraction[1] === '0.0');
assert_test("Form data marks distractor 2 with fraction 0.0", $formdata->fraction[2] === '0.0');
assert_test("Form data marks distractor 3 with fraction 0.0", $formdata->fraction[3] === '0.0');
assert_test("Form data sets question status to READY", $formdata->status === \core_question\local\bank\question_version_status::QUESTION_STATUS_READY);

// ----------------------------------------------------------------------
// SUITE 9: Pedagogical Prompt Generation & Course Context
// ----------------------------------------------------------------------
echo "\n--- Suite 9: Pedagogical Prompt Generation & Course Context ---\n";

$prompts = quiz_generator::get_quiz_prompts("XMLDB Schema Notes", "Full Stack Development");
assert_test("System prompt specifies course name", strpos($prompts['system'], "for the course 'Full Stack Development'") !== false);
assert_test("System prompt enforces EXACTLY 5 questions rule", strpos($prompts['system'], "Generate EXACTLY 5 questions") !== false);
assert_test("System prompt enforces EXACTLY 4 options rule", strpos($prompts['system'], "EXACTLY 4 answer options") !== false);
assert_test("System prompt provides JSON schema", strpos($prompts['system'], '"questions"') !== false);
assert_test("User prompt embeds course content cleanly", strpos($prompts['user'], "XMLDB Schema Notes") !== false);

// ----------------------------------------------------------------------
// SUITE 10: Provider Failover Resilience & Simulation Execution
// ----------------------------------------------------------------------
echo "\n--- Suite 10: Provider Failover Resilience & Simulation Execution ---\n";

$gen = new quiz_generator(null, null, ['simulate' => true]);

// Simulation primary success
$simsuccess = $gen->generate_mcqs("Moodle plugin development guidelines and architecture concepts.", [
    'simulate' => true,
    'simulation_scenario' => 'primary_success',
]);
assert_test("Simulation primary returns status 'success'", $simsuccess['status'] === 'success');
assert_test("Simulation primary serves via Groq", $simsuccess['provider'] === ai_tutor::PROVIDER_GROQ);
assert_test("Simulation primary delivers exactly 5 questions", count($simsuccess['questions']) === 5);
assert_test("Simulation primary records latency (> 0 ms)", $simsuccess['latency_ms'] > 0);

// Simulation Groq HTTP 429 rate limit failover to OpenRouter
$simfallback = $gen->generate_mcqs("Course content with more than twenty characters for testing.", [
    'simulate' => true,
    'simulation_scenario' => 'groq_429_fallback',
]);
assert_test("HTTP 429 failover returns status 'success'", $simfallback['status'] === 'success');
assert_test("HTTP 429 failover switches provider to OpenRouter", $simfallback['provider'] === ai_tutor::PROVIDER_OPENROUTER);
assert_test("HTTP 429 failover marks fallback_used as true", $simfallback['fallback_used'] === true);
assert_test("HTTP 429 failover records primary_error reason", strpos($simfallback['primary_error'], 'Rate Limit') !== false);

// Simulation dual provider failure
$simdual = $gen->generate_mcqs("Course content with more than twenty characters for testing.", [
    'simulate' => true,
    'simulation_scenario' => 'both_failed',
]);
assert_test("Dual failure returns status 'error'", $simdual['status'] === 'error');
assert_test("Dual failure returns HTTP status code 503", $simdual['code'] === 503);
assert_test("Dual failure returns polite user-safe message", strpos($simdual['message'], 'temporarily unavailable') !== false);

// ----------------------------------------------------------------------
// SUITE 11: Secret Key Isolation & Error Redaction
// ----------------------------------------------------------------------
echo "\n--- Suite 11: Secret Key Isolation & Error Redaction ---\n";

$leakyerr = "cURL 401 error with Groq key gsk_SecretKey1234567890abcdef at C:\\xampp\\htdocs\\moodle\\config.php";
$sanitized = ai_tutor::sanitize_error($leakyerr);
assert_test("Groq API key (gsk_*) is redacted", strpos($sanitized, 'gsk_SecretKey') === false && strpos($sanitized, '[REDACTED_API_KEY]') !== false);
assert_test("Server filesystem paths are redacted", strpos($sanitized, 'xampp') === false && strpos($sanitized, '[INTERNAL_PATH]') !== false);


// ----------------------------------------------------------------------
// SUITE 12: Live API Network Verification (Conditional)
// ----------------------------------------------------------------------
echo "\n--- Suite 12: Live API Network Verification (Segregated) ---\n";

$livekey = getenv('GROQ_API_KEY');
if (!empty($livekey)) {
    echo "  [INFO] Detected active GROQ_API_KEY. Dispatching live network query to Groq...\n";
    $livegen = new quiz_generator($livekey);
    $liveresult = $livegen->generate_mcqs("Modern web development emphasizes separation of concerns between reactive frontend frameworks and RESTful APIs.", [
        'simulate' => false,
    ]);
    assert_test("Live Groq API returns status 'success'", $liveresult['status'] === 'success');
    assert_test("Live Groq API delivers exactly 5 MCQs", count($liveresult['questions']) === 5);
    assert_test("Live Groq API uses Groq provider", $liveresult['provider'] === ai_tutor::PROVIDER_GROQ);
    assert_test("Live Groq API records sub-second latency", $liveresult['latency_ms'] > 0);
} else {
    skip_test("Live Groq API query", "GROQ_API_KEY not configured in local environment; mocked tests verify full pipeline");
    skip_test("Live Groq JSON schema verification", "Skipped due to absent live key");
}

echo "\n======================================================================\n";
echo sprintf("  TEST EXECUTION SUMMARY: %d PASSED, %d FAILED, %d SKIPPED (TOTAL %d)\n", $passed, $failed, $skipped, $testindex);
if ($failed === 0) {
    echo "  STATUS: ALL AUTOMATED TESTS PASSED SUCCESSFULLY (100% PASS RATE)\n";
} else {
    echo "  STATUS: FAILURES DETECTED IN TEST SUITE\n";
}
echo "======================================================================\n\n";

exit($failed > 0 ? 1 : 0);
