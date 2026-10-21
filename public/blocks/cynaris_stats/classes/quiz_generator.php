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

require_once(__DIR__ . '/ai_tutor.php');

/**
 * AI Quiz and Question Bank Generator for Cynaris Course Stats.
 *
 * Accepts course or lesson content and automatically synthesizes exactly five
 * pedagogically grounded multiple-choice questions (MCQs) using the Groq API
 * (Llama 3.3 70B Versatile) as the primary provider with automatic OpenRouter
 * fallback.
 *
 * Core Capabilities:
 * 1. Groq Primary + OpenRouter Fallback: Reuses secure ai_tutor provider logic and secret isolation.
 * 2. Strict JSON Schema Validation: Enforces exactly 5 questions, 4 options per question,
 *    1 valid correct answer, and an in-depth pedagogical explanation.
 * 3. Moodle Question Bank Integration: Uses official Moodle Question APIs
 *    (question_bank::get_qtype('multichoice')->save_question) and Moodle 6.0 question versioning;
 *    never writes directly to database tables.
 * 4. Quiz Activity Creation: Automatically provisions a mod_quiz instance, attaches questions,
 *    and recalculates grade totals via grade_calculator.
 * 5. GIFT Format Portability: Generates standard Moodle GIFT format for instant export/backup.
 * 6. Defends Secrets: Resolves API credentials exclusively on the server side with zero client leaks.
 *
 * @package    block_cynaris_stats
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_generator extends ai_tutor {

    /** @var int Exactly five MCQs are required per specification. */
    public const REQUIRED_QUESTION_COUNT = 5;

    /** @var int Exactly four options per MCQ (A, B, C, D). */
    public const REQUIRED_OPTION_COUNT = 4;

    /** @var int Minimum required characters for input content. */
    public const MIN_CONTENT_LENGTH = 20;

    /** @var int Maximum allowed characters for input content. */
    public const MAX_CONTENT_LENGTH = 15000;

    /**
     * Validate course or lesson content input.
     *
     * @param mixed $content Raw text input.
     * @return array Array with keys: 'valid' (bool), 'content' (string), 'error' (string|null).
     */
    public static function validate_content($content): array {
        if (!is_string($content)) {
            return [
                'valid' => false,
                'content' => '',
                'error' => 'Course content must be provided as text.',
            ];
        }

        $trimmed = trim($content);

        if ($trimmed === '') {
            return [
                'valid' => false,
                'content' => '',
                'error' => 'Course content cannot be empty. Please provide lesson or lecture notes.',
            ];
        }

        $length = mb_strlen($trimmed, 'UTF-8');

        if ($length < self::MIN_CONTENT_LENGTH) {
            return [
                'valid' => false,
                'content' => $trimmed,
                'error' => 'Content is too short (minimum ' . self::MIN_CONTENT_LENGTH . ' characters required for quiz generation).',
            ];
        }

        if ($length > self::MAX_CONTENT_LENGTH) {
            return [
                'valid' => false,
                'content' => $trimmed,
                'error' => 'Content exceeds maximum allowed length of ' . self::MAX_CONTENT_LENGTH . ' characters.',
            ];
        }

        // Clean control characters except newlines, carriage returns, and tabs.
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $trimmed);

        return [
            'valid' => true,
            'content' => $sanitized,
            'error' => null,
        ];
    }

    /**
     * Build the pedagogical prompt instructing the LLM to generate exactly 5 MCQs in strict JSON format.
     *
     * @param string $content Sanitized lesson/course content.
     * @param string|null $coursename Name of the course for pedagogical context.
     * @return array Array with 'system' and 'user' prompts.
     */
    public static function get_quiz_prompts(string $content, ?string $coursename = null): array {
        $coursecontext = $coursename ? "for the course '{$coursename}'" : "for Web Development / Computer Science";

        $systemprompt = "You are Cynaris AI Quiz Architect, an expert academic assessment designer {$coursecontext}.
Your job is to read course or lesson content and generate EXACTLY 5 high-quality, conceptual, multiple-choice questions (MCQs).

CRITICAL ASSESSMENT RULES:
1. Generate EXACTLY 5 questions. No more, no less.
2. Each question MUST test conceptual understanding, architectural reasoning, or practical application from the supplied material.
3. Each question MUST provide EXACTLY 4 answer options (labeled A, B, C, D).
4. EXACTLY ONE option must be completely correct.
5. The other 3 options must be plausible distractors based on common misconceptions or related topics.
6. Provide a thorough, pedagogically clear explanation of why the correct option is right and why the distractors are wrong.
7. OUTPUT FORMAT MUST BE STRICT VALID JSON. Do not include introductory text, conversational remarks, or markdown text outside the JSON object.

JSON SCHEMA:
{
  \"questions\": [
    {
      \"question_text\": \"Clear, specific question stem ending with a question mark?\",
      \"options\": [
        \"First option text\",
        \"Second option text\",
        \"Third option text\",
        \"Fourth option text\"
      ],
      \"correct_answer\": \"A\",
      \"explanation\": \"Detailed pedagogical explanation of the concept and why option A is correct.\"
    }
  ]
}";

        $userprompt = "Please analyze the following course content and generate exactly 5 multiple choice questions following the strict JSON schema:\n\n--- COURSE CONTENT ---\n" . $content . "\n--- END CONTENT ---";

        return [
            'system' => $systemprompt,
            'user' => $userprompt,
        ];
    }

    /**
     * Validate and normalize a quiz JSON response.
     *
     * Safely handles:
     * - JSON string or array input.
     * - Stripping surrounding markdown code blocks (```json ... ```).
     * - Validating top-level 'questions' array.
     * - Ensuring exactly 5 questions.
     * - Ensuring each question has question_text, exactly 4 options, valid correct_answer, and explanation.
     * - Normalizing correct_answer to a 0-indexed integer (0..3) and letter ('A'..'D').
     *
     * @param mixed $input Raw JSON string or parsed array.
     * @return array Array with keys: 'valid' (bool), 'questions' (array), 'error' (string|null), 'raw_json' (string).
     */
    public static function validate_quiz_json($input): array {
        $rawjson = '';

        if (is_string($input)) {
            $rawjson = trim($input);

            // Strip markdown code fences if present.
            if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/i', $rawjson, $matches)) {
                $rawjson = trim($matches[1]);
            }

            $data = json_decode($rawjson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'valid' => false,
                    'questions' => [],
                    'error' => 'Malformed JSON response from AI provider: ' . json_last_error_msg(),
                    'raw_json' => $rawjson,
                ];
            }
        } elseif (is_array($input)) {
            $data = $input;
            $rawjson = json_encode($input, JSON_PRETTY_PRINT);
        } else {
            return [
                'valid' => false,
                'questions' => [],
                'error' => 'Quiz response must be a JSON string or associative array.',
                'raw_json' => '',
            ];
        }

        if (!is_array($data) || !isset($data['questions']) || !is_array($data['questions'])) {
            return [
                'valid' => false,
                'questions' => [],
                'error' => "Missing required top-level 'questions' array in JSON output.",
                'raw_json' => $rawjson,
            ];
        }

        $questions = $data['questions'];
        $count = count($questions);

        if ($count !== self::REQUIRED_QUESTION_COUNT) {
            return [
                'valid' => false,
                'questions' => [],
                'error' => "Invalid question count: expected exactly " . self::REQUIRED_QUESTION_COUNT . " questions, received {$count}.",
                'raw_json' => $rawjson,
            ];
        }

        $validatedquestions = [];
        $lettermap = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3];
        $indexletters = ['A', 'B', 'C', 'D'];

        foreach ($questions as $idx => $q) {
            $qnum = $idx + 1;

            if (!is_array($q)) {
                return [
                    'valid' => false,
                    'questions' => [],
                    'error' => "Question #{$qnum} is not a valid object.",
                    'raw_json' => $rawjson,
                ];
            }

            // 1. Question text validation.
            if (empty($q['question_text']) || !is_string($q['question_text']) || trim($q['question_text']) === '') {
                return [
                    'valid' => false,
                    'questions' => [],
                    'error' => "Question #{$qnum} is missing required 'question_text'.",
                    'raw_json' => $rawjson,
                ];
            }
            $questiontext = trim($q['question_text']);

            // 2. Options array validation.
            if (!isset($q['options']) || !is_array($q['options'])) {
                return [
                    'valid' => false,
                    'questions' => [],
                    'error' => "Question #{$qnum} is missing 'options' array.",
                    'raw_json' => $rawjson,
                ];
            }

            $rawoptions = $q['options'];
            if (count($rawoptions) !== self::REQUIRED_OPTION_COUNT) {
                return [
                    'valid' => false,
                    'questions' => [],
                    'error' => "Question #{$qnum} must contain exactly " . self::REQUIRED_OPTION_COUNT . " options, received " . count($rawoptions) . ".",
                    'raw_json' => $rawjson,
                ];
            }

            $cleanoptions = [];
            foreach ($rawoptions as $optidx => $opt) {
                if (!is_string($opt) && !is_numeric($opt)) {
                    return [
                        'valid' => false,
                        'questions' => [],
                        'error' => "Question #{$qnum} option " . ($optidx + 1) . " must be a string.",
                        'raw_json' => $rawjson,
                    ];
                }
                $cleanopt = trim((string) $opt);
                // Strip redundant leading "A) ", "B. ", etc., if the LLM prefixed the option text.
                $cleanopt = preg_replace('/^[A-Da-d][\)\.\:\-]\s*/', '', $cleanopt);
                if ($cleanopt === '') {
                    return [
                        'valid' => false,
                        'questions' => [],
                        'error' => "Question #{$qnum} option " . ($optidx + 1) . " cannot be empty.",
                        'raw_json' => $rawjson,
                    ];
                }
                $cleanoptions[] = $cleanopt;
            }

            // Check for distinct options.
            if (count(array_unique($cleanoptions)) < self::REQUIRED_OPTION_COUNT) {
                return [
                    'valid' => false,
                    'questions' => [],
                    'error' => "Question #{$qnum} contains duplicate answer options.",
                    'raw_json' => $rawjson,
                ];
            }

            // 3. Correct answer validation.
            // Check for correct answer across canonical and alternate keys.
            $correctraw = null;
            if (isset($q['correct_answer']) && $q['correct_answer'] !== '') {
                $correctraw = $q['correct_answer'];
            } elseif (isset($q['correct_letter']) && $q['correct_letter'] !== '') {
                $correctraw = $q['correct_letter'];
            } elseif (isset($q['correct_index']) && $q['correct_index'] !== '') {
                $correctraw = $q['correct_index'];
            } elseif (isset($q['correct_option']) && $q['correct_option'] !== '') {
                $correctraw = $q['correct_option'];
            } elseif (isset($q['correctAnswer']) && $q['correctAnswer'] !== '') {
                $correctraw = $q['correctAnswer'];
            } elseif (isset($q['correct_answer_text']) && $q['correct_answer_text'] !== '') {
                $correctraw = $q['correct_answer_text'];
            }

            if ($correctraw === null || (is_string($correctraw) && trim($correctraw) === '')) {
                return [
                    'valid' => false,
                    'questions' => [],
                    'error' => "Question #{$qnum} is missing 'correct_answer'.",
                    'raw_json' => $rawjson,
                ];
            }

            $correctraw = trim((string) $correctraw);
            $correctindex = null;

            // Option 3a: Direct letter match ('A', 'B', 'C', 'D').
            $upperletter = strtoupper($correctraw);
            if (isset($lettermap[$upperletter])) {
                $correctindex = $lettermap[$upperletter];
            } elseif (is_numeric($correctraw) && ((int) $correctraw >= 0 && (int) $correctraw < 4)) {
                // Option 3b: 0-indexed integer (0, 1, 2, 3).
                $correctindex = (int) $correctraw;
            } elseif (is_numeric($correctraw) && ((int) $correctraw >= 1 && (int) $correctraw <= 4)) {
                // Option 3c: 1-indexed integer (1, 2, 3, 4).
                $correctindex = ((int) $correctraw) - 1;
            } else {
                // Option 3d: Exact match to one of the option strings.
                $strippedcorrect = preg_replace('/^[A-Da-d][\)\.\:\-]\s*/', '', $correctraw);
                foreach ($cleanoptions as $optidx => $copt) {
                    if (strcasecmp($copt, $correctraw) === 0 || strcasecmp($copt, $strippedcorrect) === 0) {
                        $correctindex = $optidx;
                        break;
                    }
                }
            }

            if ($correctindex === null || $correctindex < 0 || $correctindex >= self::REQUIRED_OPTION_COUNT) {
                return [
                    'valid' => false,
                    'questions' => [],
                    'error' => "Question #{$qnum} has invalid correct_answer: '{$correctraw}'. Must map to one of the 4 options (A, B, C, or D).",
                    'raw_json' => $rawjson,
                ];
            }

            // 4. Explanation validation.
            if (empty($q['explanation']) || !is_string($q['explanation']) || trim($q['explanation']) === '') {
                return [
                    'valid' => false,
                    'questions' => [],
                    'error' => "Question #{$qnum} is missing a clear pedagogical 'explanation'.",
                    'raw_json' => $rawjson,
                ];
            }
            $explanation = trim($q['explanation']);

            $validatedquestions[] = [
                'index' => $idx,
                'number' => $qnum,
                'question_text' => $questiontext,
                'options' => $cleanoptions,
                'correct_answer' => $indexletters[$correctindex],
                'correct_index' => $correctindex,
                'correct_letter' => $indexletters[$correctindex],
                'correct_answer_text' => $cleanoptions[$correctindex],
                'explanation' => $explanation,
            ];
        }

        return [
            'valid' => true,
            'questions' => $validatedquestions,
            'error' => null,
            'raw_json' => $rawjson,
        ];
    }

    /**
     * Generate exactly 5 MCQs from content using Groq primary with OpenRouter fallback.
     *
     * @param string $content Course or lesson content.
     * @param array $options Execution options:
     *   - 'course_name' (string|null): Context course name.
     *   - 'simulate' (bool): Force simulation mode.
     *   - 'simulation_scenario' (string): Simulation scenario ('primary_success', 'groq_429_fallback', 'both_failed', 'malformed_json').
     * @return array Standardized result array.
     */
    public function generate_mcqs(string $content, array $options = []): array {
        $validation = self::validate_content($content);
        if (!$validation['valid']) {
            return [
                'status' => 'error',
                'code' => 400,
                'message' => $validation['error'],
                'questions' => [],
                'provider' => null,
                'fallback_used' => false,
            ];
        }

        $cleancontent = $validation['content'];
        $coursename = $options['course_name'] ?? null;
        $prompts = self::get_quiz_prompts($cleancontent, $coursename);

        $issimulate = $options['simulate'] ?? $this->simulatemode;
        $scenario = $options['simulation_scenario'] ?? $this->simulationscenario;

        // Route to deterministic simulation if enabled or if neither API key is configured.
        if ($issimulate || (!$this->has_primary_key() && !$this->has_fallback_key())) {
            return $this->execute_quiz_simulation($cleancontent, $scenario);
        }

        $primaryerror = null;
        $startprimary = microtime(true);

        // --- STEP 1: Attempt Primary Provider (Groq Llama 3.3 70B with JSON Mode) ---
        if ($this->has_primary_key()) {
            $groqresult = $this->call_groq_quiz($prompts['user'], $prompts['system']);
            $latencygroq = (int) round((microtime(true) - $startprimary) * 1000);

            if ($groqresult['success']) {
                $parsed = self::validate_quiz_json($groqresult['content']);
                if ($parsed['valid']) {
                    return [
                        'status' => 'success',
                        'questions' => $parsed['questions'],
                        'raw_json' => $parsed['raw_json'],
                        'provider' => self::PROVIDER_GROQ,
                        'model' => $this->groqmodel,
                        'latency_ms' => $latencygroq,
                        'fallback_used' => false,
                        'usage' => $groqresult['usage'] ?? null,
                    ];
                }
                $primaryerror = 'Groq output failed JSON schema validation: ' . $parsed['error'];
            } else {
                $primaryerror = $groqresult['error'] ?? 'Groq API request failed';
            }
        } else {
            $primaryerror = 'Groq API key not configured';
        }

        // --- STEP 2: Automatic Fallback to OpenRouter ---
        $startfallback = microtime(true);

        if ($this->has_fallback_key()) {
            $openrouterresult = $this->call_openrouter_quiz($prompts['user'], $prompts['system']);
            $latencyfallback = (int) round((microtime(true) - $startfallback) * 1000);

            if ($openrouterresult['success']) {
                $parsed = self::validate_quiz_json($openrouterresult['content']);
                if ($parsed['valid']) {
                    return [
                        'status' => 'success',
                        'questions' => $parsed['questions'],
                        'raw_json' => $parsed['raw_json'],
                        'provider' => self::PROVIDER_OPENROUTER,
                        'model' => $this->openroutermodel,
                        'latency_ms' => $latencyfallback,
                        'fallback_used' => true,
                        'primary_error' => self::sanitize_error($primaryerror),
                        'usage' => $openrouterresult['usage'] ?? null,
                    ];
                }
                $fallbackerror = 'OpenRouter output failed JSON schema validation: ' . $parsed['error'];
            } else {
                $fallbackerror = $openrouterresult['error'] ?? 'OpenRouter fallback API request failed';
            }
        } else {
            $fallbackerror = 'OpenRouter fallback API key not configured';
        }

        // --- STEP 3: Dual Provider Failure ---
        return [
            'status' => 'error',
            'code' => 503,
            'message' => 'AI Quiz Generation is temporarily unavailable across primary (Groq) and fallback (OpenRouter) providers. Please try again shortly.',
            'questions' => [],
            'provider' => null,
            'fallback_used' => true,
            'primary_error' => self::sanitize_error($primaryerror),
            'fallback_error' => self::sanitize_error($fallbackerror),
        ];
    }

    /**
     * Dispatch JSON-enforced chat completion to Groq API.
     *
     * @param string $userprompt User prompt containing content.
     * @param string $systemprompt Pedagogical system instructions.
     * @return array Standardized result: ['success' => bool, 'content' => string, 'error' => string|null, 'usage' => array].
     */
    protected function call_groq_quiz(string $userprompt, string $systemprompt): array {
        $messages = [
            ['role' => 'system', 'content' => $systemprompt],
            ['role' => 'user', 'content' => $userprompt],
        ];

        $payload = [
            'model' => $this->groqmodel,
            'messages' => $messages,
            'temperature' => 0.2, // Low temperature for high schema determinism
            'max_tokens' => 2000,
            'response_format' => ['type' => 'json_object'],
        ];

        $headers = [
            'Authorization: Bearer ' . $this->groqapikey,
            'Content-Type: application/json',
            'User-Agent: Cynaris-Moodle-Quiz-Generator/1.0',
        ];

        return $this->execute_http_request(self::GROQ_API_URL, $headers, $payload, 'Groq');
    }

    /**
     * Dispatch JSON-enforced chat completion to OpenRouter fallback API.
     *
     * @param string $userprompt User prompt.
     * @param string $systemprompt Pedagogical system instructions.
     * @return array Standardized result.
     */
    protected function call_openrouter_quiz(string $userprompt, string $systemprompt): array {
        $messages = [
            ['role' => 'system', 'content' => $systemprompt],
            ['role' => 'user', 'content' => $userprompt],
        ];

        $payload = [
            'model' => $this->openroutermodel,
            'messages' => $messages,
            'temperature' => 0.2,
            'max_tokens' => 2000,
            'response_format' => ['type' => 'json_object'],
        ];

        $headers = [
            'Authorization: Bearer ' . $this->openrouterapikey,
            'Content-Type: application/json',
            'HTTP-Referer: https://cynaris.local/moodle',
            'X-Title: Cynaris Solutions Moodle Quiz Generator',
            'User-Agent: Cynaris-Moodle-Quiz-Generator/1.0',
        ];

        return $this->execute_http_request(self::OPENROUTER_API_URL, $headers, $payload, 'OpenRouter');
    }

    /**
     * Convert an array of 5 validated MCQs into Moodle standard GIFT format.
     *
     * Enables instructors to export, backup, or import questions across courses.
     *
     * @param array $questions Array of validated questions.
     * @param string|null $categoryname Optional GIFT category header.
     * @return string Valid GIFT format text.
     */
    public static function convert_to_gift(array $questions, ?string $categoryname = null): string {
        $gift = "// Cynaris AI Quiz Generator (W8D2) - Automated GIFT Export\n";
        $gift .= "// Generated: " . date('Y-m-d H:i:s') . "\n";
        $gift .= "// Total MCQs: " . count($questions) . "\n\n";

        if ($categoryname) {
            $escapedcat = str_replace('$', '\$', $categoryname);
            $gift .= "\$CATEGORY: {$escapedcat}\n\n";
        }

        foreach ($questions as $q) {
            $qnum = $q['number'] ?? 1;
            $stem = self::escape_gift_text($q['question_text']);
            $explanation = self::escape_gift_text($q['explanation']);
            $title = "Q{$qnum}: " . mb_substr(strip_tags($q['question_text']), 0, 40);
            $title = preg_replace('/[[:cntrl:]:]/', ' ', $title);

            $gift .= "::{$title}:: {$stem} {\n";

            $correctindex = null;
            if (isset($q['correct_index']) && is_numeric($q['correct_index'])) {
                $correctindex = (int) $q['correct_index'];
            } elseif (!empty($q['correct_letter'])) {
                $lettermap = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3];
                $correctindex = $lettermap[strtoupper(trim($q['correct_letter']))] ?? null;
            } elseif (!empty($q['correct_answer'])) {
                $lettermap = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3];
                $correctindex = $lettermap[strtoupper(trim($q['correct_answer']))] ?? null;
            }
            if ($correctindex === null || $correctindex < 0 || $correctindex >= count($q['options'])) {
                $correctindex = 0;
            }
            foreach ($q['options'] as $idx => $opt) {
                $escapedopt = self::escape_gift_text($opt);
                if ($idx === $correctindex) {
                    $gift .= "    ={$escapedopt} #Correct! {$explanation}\n";
                } else {
                    $gift .= "    ~{$escapedopt} #Incorrect distractor.\n";
                }
            }

            $gift .= "}\n\n";
        }

        return $gift;
    }

    /**
     * Escape special characters in GIFT strings (~, =, #, {, }, :, \).
     *
     * @param string $text Raw text.
     * @return string Escaped GIFT text.
     */
    public static function escape_gift_text(string $text): string {
        $search = ['\\', '~', '=', '#', '{', '}', ':'];
        $replace = ['\\\\', '\~', '\=', '\#', '\{', '\}', '\:'];
        return str_replace($search, $replace, trim($text));
    }

    /**
     * Prepare Moodle Question Type (qtype_multichoice) form data object.
     *
     * Compatible with Moodle 4.x, 5.x, and 6.0dev question bank APIs.
     *
     * @param array $mcq Single validated question array.
     * @param int $categoryid Question category ID.
     * @param int $contextid Context ID.
     * @return \stdClass Moodle form-compatible question data object.
     */
    public static function prepare_moodle_question_form_data(array $mcq, int $categoryid, int $contextid): \stdClass {
        $qdata = new \stdClass();

        $title = mb_substr(strip_tags($mcq['question_text']), 0, 80);
        $title = trim($title);
        if ($title === '') {
            $title = 'AI MCQ ' . ($mcq['number'] ?? 1);
        }

        $qdata->category = "{$categoryid},{$contextid}";
        $qdata->name = $title;
        $qdata->questiontext = [
            'text' => '<p>' . htmlspecialchars($mcq['question_text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>',
            'format' => FORMAT_HTML,
        ];
        $qdata->generalfeedback = [
            'text' => '<p><strong>Explanation:</strong> ' . htmlspecialchars($mcq['explanation'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>',
            'format' => FORMAT_HTML,
        ];
        $qdata->defaultmark = 1.0;
        $qdata->penalty = 0.3333333;
        $qdata->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
        $qdata->single = '1'; // Single-choice MCQ.
        $qdata->shuffleanswers = 1;
        $qdata->answernumbering = 'abc';
        $qdata->showstandardinstruction = 0;
        $qdata->noanswers = self::REQUIRED_OPTION_COUNT;
        $qdata->correctfeedback = [
            'text' => '',
            'format' => FORMAT_HTML,
        ];
        $qdata->partiallycorrectfeedback = [
            'text' => '',
            'format' => FORMAT_HTML,
        ];
        $qdata->incorrectfeedback = [
            'text' => '',
            'format' => FORMAT_HTML,
        ];
        $qdata->shownumcorrect = 0;

        $qdata->answer = [];
        $qdata->fraction = [];
        $qdata->feedback = [];

        $correctidx = null;
        if (isset($mcq['correct_index']) && is_numeric($mcq['correct_index'])) {
            $correctidx = (int) $mcq['correct_index'];
        } elseif (!empty($mcq['correct_letter'])) {
            $lettermap = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3];
            $correctidx = $lettermap[strtoupper(trim($mcq['correct_letter']))] ?? null;
        } elseif (!empty($mcq['correct_answer'])) {
            $lettermap = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3];
            $correctidx = $lettermap[strtoupper(trim($mcq['correct_answer']))] ?? null;
        }
        if ($correctidx === null || $correctidx < 0 || $correctidx >= count($mcq['options'])) {
            $correctidx = 0;
        }

        foreach ($mcq['options'] as $idx => $opttext) {
            $qdata->answer[$idx] = [
                'text' => $opttext,
                'format' => FORMAT_PLAIN,
            ];

            if ($idx === $correctidx) {
                $qdata->fraction[$idx] = '1.0';
                $qdata->feedback[$idx] = [
                    'text' => '<p>Correct! ' . htmlspecialchars($mcq['explanation'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>',
                    'format' => FORMAT_HTML,
                ];
            } else {
                $qdata->fraction[$idx] = '0.0';
                $qdata->feedback[$idx] = [
                    'text' => '<p>Incorrect.</p>',
                    'format' => FORMAT_HTML,
                ];
            }
        }

        return $qdata;
    }

    /**
     * Import validated MCQs into Moodle's Question Bank using official Question APIs.
     *
     * Adheres to requirement: "do not write directly to question-bank database tables."
     * Uses question_bank::get_qtype('multichoice')->save_question().
     *
     * @param array $questions Validated 5-MCQ array.
     * @param int $courseid Target course ID.
     * @param int|null $targetcategoryid Optional target question category ID.
     * @return array Array of created question objects/IDs.
     * @throws \moodle_exception
     */
    public static function import_to_question_bank(array $questions, int $courseid, ?int $targetcategoryid = null): array {
        global $DB, $USER, $CFG;

        require_once(__DIR__ . '/../../../../config.php');
        require_once($CFG->dirroot . '/question/engine/bank.php');
        require_once($CFG->dirroot . '/question/type/questiontypebase.php');
        require_once($CFG->dirroot . '/lib/questionlib.php');

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);

        // Security check: Verify question adding capability.
        require_capability('moodle/question:add', $context);

        // Resolve or create target question category.
        $category = null;
        if ($targetcategoryid) {
            $category = $DB->get_record('question_categories', ['id' => $targetcategoryid]);
        }

        if (!$category) {
            // Find or provision the system question bank instance for this course.
            $qbank = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($course, true);
            $bankcontext = \context_module::instance($qbank->id);
            $category = question_get_default_category($bankcontext->id, true);

            if (!$category) {
                // Fallback to course context default category.
                $defaultcats = $DB->get_records_select('question_categories', 'contextid = ? AND parent <> 0', [$context->id], 'id', '*', 0, 1);
                $category = reset($defaultcats);
                if (!$category) {
                    $topcat = question_get_top_category($context->id, true);
                    $newcat = new \stdClass();
                    $newcat->name = 'Cynaris AI Quizzes - ' . format_string($course->shortname);
                    $newcat->info = 'Automated AI Generated MCQs';
                    $newcat->contextid = $context->id;
                    $newcat->parent = $topcat->id;
                    $newcat->sortorder = 999;
                    $newcat->stamp = make_unique_id_code();
                    $newcat->id = $DB->insert_record('question_categories', $newcat);
                    $category = $newcat;
                }
            }
        }

        $targetcontextid = $category->contextid;
        $qtype = \question_bank::get_qtype('multichoice');
        $createdquestions = [];

        foreach ($questions as $mcq) {
            $formdata = self::prepare_moodle_question_form_data($mcq, $category->id, $targetcontextid);
            $basequestion = new \stdClass();
            $basequestion->qtype = 'multichoice';

            // Official Moodle Question Type API invocation.
            $savedquestion = $qtype->save_question($basequestion, $formdata);
            $createdquestions[] = $savedquestion;
        }

        return $createdquestions;
    }

    /**
     * Create a Moodle Quiz activity and attach the generated questions.
     *
     * Uses official Moodle Quiz APIs:
     * - add_course_module()
     * - quiz_add_instance()
     * - course_add_cm_to_section()
     * - quiz_add_quiz_question()
     * - grade_calculator::recompute_quiz_sumgrades()
     *
     * @param int $courseid Course ID.
     * @param string $quizname Name/Title of the new Quiz.
     * @param array $questionids Array of question IDs to attach.
     * @param array $options Configuration options (section, timeopen, timeclose, etc.).
     * @return \stdClass Result object containing quiz ID, cmid, and course URL.
     * @throws \moodle_exception
     */
    public static function create_moodle_quiz(int $courseid, string $quizname, array $questionids, array $options = []): \stdClass {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);

        // Security check: Must have activity management capability.
        require_capability('moodle/course:manageactivities', $context);

        $sectionnum = $options['section'] ?? 1;

        // 1. Prepare quiz record.
        $quiz = new \stdClass();
        $quiz->course = $course->id;
        $quiz->name = trim($quizname);
        $quiz->intro = '<p><strong>AI-Generated Knowledge Check</strong> created with Cynaris AI Quiz Generator.</p>';
        $quiz->introformat = FORMAT_HTML;
        $quiz->timeopen = $options['timeopen'] ?? 0;
        $quiz->timeclose = $options['timeclose'] ?? 0;
        $quiz->preferredbehaviour = 'deferredfeedback';
        $quiz->attempts = 0; // Unlimited attempts for learning reinforcement.
        $quiz->attemptonlast = 0;
        $quiz->grademethod = QUIZ_GRADEHIGHEST;
        $quiz->decimalpoints = 2;
        $quiz->questiondecimalpoints = -1;
        $quiz->questionsperpage = 1; // 1 question per page.
        $quiz->shuffleanswers = 1;
        $quiz->sumgrades = 0;
        $quiz->grade = 100;
        $quiz->timecreated = time();
        $quiz->timemodified = time();
        $quiz->timelimit = 0;
        $quiz->overduehandling = 'autosubmit';
        $quiz->graceperiod = 86400;
        $quiz->quizpassword = '';
        $quiz->subnet = '';
        $quiz->browsersecurity = '';
        $quiz->navmethod = QUIZ_NAVMETHOD_FREE;

        // 2. Add course module record.
        $module = $DB->get_record('modules', ['name' => 'quiz'], '*', MUST_EXIST);
        $cm = new \stdClass();
        $cm->course = $course->id;
        $cm->module = $module->id;
        $cm->section = $sectionnum;
        $cm->visible = 1;
        $cm->visibleold = 1;
        $cm->groupmode = 0;
        $cm->groupingid = 0;
        $cmid = add_course_module($cm);

        $quiz->coursemodule = $cmid;
        $quizid = quiz_add_instance($quiz);

        // Link course module to instance.
        $DB->set_field('course_modules', 'instance', $quizid, ['id' => $cmid]);
        course_add_cm_to_section($course->id, $cmid, $sectionnum);

        $quiz->id = $quizid;
        $quiz->cmid = $cmid;

        // 3. Attach questions to the quiz slots.
        $page = 1;
        foreach ($questionids as $qid) {
            quiz_add_quiz_question($qid, $quiz, $page, 1.0);
            $page++;
        }

        // 4. Recompute quiz sumgrades using Moodle's grade_calculator.
        $quizsettings = \mod_quiz\quiz_settings::create($quizid);
        $quizsettings->get_grade_calculator()->recompute_quiz_sumgrades();

        // 5. Rebuild course modinfo cache so quiz appears immediately in course view.
        rebuild_course_cache($course->id, true);

        $result = new \stdClass();
        $result->quizid = $quizid;
        $result->cmid = $cmid;
        $result->courseid = $course->id;
        $result->quizname = $quiz->name;
        $result->questioncount = count($questionids);
        $result->url = new \moodle_url('/mod/quiz/view.php', ['id' => $cmid]);

        return $result;
    }

    /**
     * Execute deterministic simulation mode for testing and offline verification.
     *
     * @param string $content Course content.
     * @param string $scenario Simulation scenario.
     * @return array Standardized result.
     */
    protected function execute_quiz_simulation(string $content, string $scenario): array {
        if ($scenario === 'both_failed') {
            return [
                'status' => 'error',
                'code' => 503,
                'message' => 'The AI Quiz Generator is temporarily unavailable across primary (Groq) and fallback (OpenRouter) providers. Please try again shortly.',
                'questions' => [],
                'provider' => null,
                'fallback_used' => true,
                'primary_error' => 'Groq API connection timeout (Simulated)',
                'fallback_error' => 'OpenRouter upstream 503 Service Unavailable (Simulated)',
            ];
        }

        if ($scenario === 'malformed_json') {
            return [
                'status' => 'error',
                'code' => 502,
                'message' => 'Malformed JSON response from AI provider: Syntax error (Simulated)',
                'questions' => [],
                'provider' => self::PROVIDER_GROQ,
                'fallback_used' => false,
            ];
        }

        // High quality simulated questions grounded in Full Stack / Moodle concepts.
        $simulatedquestions = [
            [
                'question_text' => 'What is the primary role of Moodle\'s XMLDB subsystem in plugin development?',
                'options' => [
                    'To define database tables, fields, and indexes in a portable, database-independent XML format',
                    'To render Mustache frontend templates into browser DOM elements',
                    'To authenticate OAuth2 single-sign-on client applications',
                    'To compress CSS stylesheets and JavaScript assets for production'
                ],
                'correct_answer' => 'A',
                'explanation' => 'XMLDB provides an abstraction layer that defines database schema in portable XML (install.xml), ensuring seamless compatibility across PostgreSQL, MariaDB, MySQL, and Oracle without vendor-specific DDL scripts.'
            ],
            [
                'question_text' => 'Which Moodle API class should be used to interact safely with the database using parameterized queries?',
                'options' => [
                    '$DB global database abstraction object using parameter arrays',
                    'Direct PHP PDO instances with raw string concatenation',
                    'mysql_query() global procedural calls',
                    'Client-side IndexedDB browser storage'
                ],
                'correct_answer' => 'A',
                'explanation' => 'Moodle requires all SQL operations to route through the global $DB object (moodle_database) with bound placeholders (:param or ?) to prevent SQL injection vulnerabilities.'
            ],
            [
                'question_text' => 'Under Moodle\'s Frankenstyle naming convention, what is the correct namespace for a block plugin named "cynaris_stats"?',
                'options' => [
                    'block_cynaris_stats',
                    'cynaris_stats\\block',
                    'moodle\\plugin\\stats',
                    'local_cynaris_stats'
                ],
                'correct_answer' => 'A',
                'explanation' => 'Frankenstyle specifies that block plugins use the prefix "block_" followed by the unique component name ("cynaris_stats"), producing "block_cynaris_stats".'
            ],
            [
                'question_text' => 'Which HTTP status code triggers an immediate automatic failover from the Groq primary provider to OpenRouter in Cynaris architecture?',
                'options' => [
                    'HTTP 429 Too Many Requests (Rate Limit)',
                    'HTTP 200 OK',
                    'HTTP 301 Moved Permanently',
                    'HTTP 304 Not Modified'
                ],
                'correct_answer' => 'A',
                'explanation' => 'HTTP 429 indicates that rate limits have been reached on the primary provider. The resilience engine catches this status and routes the request to the fallback provider immediately.'
            ],
            [
                'question_text' => 'What is the purpose of Moodle\'s sesskey token in web forms and AJAX API endpoints?',
                'options' => [
                    'To protect against Cross-Site Request Forgery (CSRF) attacks',
                    'To encrypt user passwords using bcrypt',
                    'To compress AJAX payloads for low-bandwidth devices',
                    'To track student grading weights across grade items'
                ],
                'correct_answer' => 'A',
                'explanation' => 'The sesskey token is a unique session identifier required by confirm_sesskey() and require_sesskey() to verify that state-changing requests originate from the authentic user session.'
            ],
        ];

        $validated = self::validate_quiz_json(['questions' => $simulatedquestions]);

        $isfallback = ($scenario === 'groq_429_fallback' || $scenario === 'groq_timeout_fallback');
        $provider = $isfallback ? self::PROVIDER_OPENROUTER : self::PROVIDER_GROQ;
        $model = $isfallback ? $this->openroutermodel : $this->groqmodel;

        return [
            'status' => 'success',
            'questions' => $validated['questions'],
            'raw_json' => $validated['raw_json'],
            'provider' => $provider,
            'model' => $model,
            'latency_ms' => $isfallback ? 340 : 185,
            'fallback_used' => $isfallback,
            'primary_error' => $isfallback ? 'Groq HTTP 429 Rate Limit (Simulated)' : null,
            'usage' => [
                'prompt_tokens' => 450,
                'completion_tokens' => 520,
                'total_tokens' => 970,
            ],
        ];
    }
}
