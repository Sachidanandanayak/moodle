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

/**
 * AI Tutor service engine for Cynaris Course Stats.
 *
 * Implements an intelligent course tutor using Groq (Llama 3.3 70B) as the primary
 * ultra-low-latency provider and OpenRouter (Llama 3.3 70B Instruct) as the resilient
 * automatic fallback.
 *
 * Key Architectural Features:
 * 1. Primary Provider: Groq API (https://api.groq.com/openai/v1/chat/completions).
 * 2. Fallback Provider: OpenRouter API (https://openrouter.ai/api/v1/chat/completions).
 * 3. Security: Credentials strictly resolved from server environment variables
 *    (GROQ_API_KEY, OPENROUTER_API_KEY) or Moodle $CFG. Never hardcoded or leaked.
 * 4. Resilient Fallback: Automatically catches timeouts, HTTP 429 rate limits, 5xx server
 *    errors, or connection failures from Groq and safely routes to OpenRouter.
 * 5. Safe Dual Failure: If both providers fail, returns a polite, user-friendly error
 *    without exposing stack traces, keys, or internal network topology.
 * 6. Input Validation: Enforces prompt length boundaries (2 to 3000 chars) and sanitization.
 * 7. Simulation Mode: Supports deterministic test execution for CI/CD and offline verification.
 *
 * @package    block_cynaris_stats
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_tutor {

    /** @var string Identifier for Groq primary provider. */
    public const PROVIDER_GROQ = 'groq';

    /** @var string Identifier for OpenRouter fallback provider. */
    public const PROVIDER_OPENROUTER = 'openrouter';

    /** @var string Default Groq LLM model. */
    public const DEFAULT_GROQ_MODEL = 'llama-3.3-70b-versatile';

    /** @var string Default OpenRouter LLM model. */
    public const DEFAULT_OPENROUTER_MODEL = 'meta-llama/llama-3.3-70b-instruct';

    /** @var string Groq chat completions endpoint. */
    public const GROQ_API_URL = 'https://api.groq.com/openai/v1/chat/completions';

    /** @var string OpenRouter chat completions endpoint. */
    public const OPENROUTER_API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    /** @var int Default per-provider timeout in seconds. */
    public const DEFAULT_TIMEOUT_SECONDS = 8;

    /** @var int Maximum allowed prompt length in characters. */
    public const MAX_PROMPT_LENGTH = 3000;

    /** @var int Minimum required prompt length in characters. */
    public const MIN_PROMPT_LENGTH = 2;

    /** @var string|null Primary Groq API key. */
    protected ?string $groqapikey = null;

    /** @var string|null Fallback OpenRouter API key. */
    protected ?string $openrouterapikey = null;

    /** @var string Active Groq model. */
    protected string $groqmodel;

    /** @var string Active OpenRouter model. */
    protected string $openroutermodel;

    /** @var int Connection and execution timeout in seconds. */
    protected int $timeout;

    /** @var bool Whether simulation mode is enabled. */
    protected bool $simulatemode = false;

    /** @var string Simulation scenario ('primary_success', 'groq_429_fallback', 'groq_timeout_fallback', 'both_failed'). */
    protected string $simulationscenario = 'primary_success';

    /**
     * Constructor for ai_tutor engine.
     *
     * @param string|null $groqapikey Optional explicit Groq API key.
     * @param string|null $openrouterapikey Optional explicit OpenRouter API key.
     * @param array $config Additional configuration options.
     */
    public function __construct(
        ?string $groqapikey = null,
        ?string $openrouterapikey = null,
        array $config = []
    ) {
        global $CFG;

        // Resolve Groq API key: constructor override -> getenv() -> $_ENV -> $CFG.
        if (!empty($groqapikey)) {
            $this->groqapikey = trim($groqapikey);
        } else {
            $envkey = getenv('GROQ_API_KEY');
            if (!empty($envkey)) {
                $this->groqapikey = trim($envkey);
            } elseif (!empty($_ENV['GROQ_API_KEY'])) {
                $this->groqapikey = trim($_ENV['GROQ_API_KEY']);
            } elseif (!empty($CFG->groq_api_key)) {
                $this->groqapikey = trim($CFG->groq_api_key);
            }
        }

        // Resolve OpenRouter API key: constructor override -> getenv() -> $_ENV -> $CFG.
        if (!empty($openrouterapikey)) {
            $this->openrouterapikey = trim($openrouterapikey);
        } else {
            $envkey = getenv('OPENROUTER_API_KEY');
            if (!empty($envkey)) {
                $this->openrouterapikey = trim($envkey);
            } elseif (!empty($_ENV['OPENROUTER_API_KEY'])) {
                $this->openrouterapikey = trim($_ENV['OPENROUTER_API_KEY']);
            } elseif (!empty($CFG->openrouter_api_key)) {
                $this->openrouterapikey = trim($CFG->openrouter_api_key);
            }
        }

        $this->groqmodel = $config['groq_model'] ?? self::DEFAULT_GROQ_MODEL;
        $this->openroutermodel = $config['openrouter_model'] ?? self::DEFAULT_OPENROUTER_MODEL;
        $this->timeout = $config['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS;
        $this->simulatemode = $config['simulate'] ?? false;
        $this->simulationscenario = $config['simulation_scenario'] ?? 'primary_success';
    }

    /**
     * Check whether a primary Groq API key is available.
     *
     * @return bool
     */
    public function has_primary_key(): bool {
        return !empty($this->groqapikey);
    }

    /**
     * Check whether a fallback OpenRouter API key is available.
     *
     * @return bool
     */
    public function has_fallback_key(): bool {
        return !empty($this->openrouterapikey);
    }

    /**
     * Configure simulation parameters for testing.
     *
     * @param bool $enabled Enable or disable simulation mode.
     * @param string $scenario One of 'primary_success', 'groq_429_fallback', 'groq_timeout_fallback', 'both_failed'.
     * @return self
     */
    public function set_simulation(bool $enabled, string $scenario = 'primary_success'): self {
        $this->simulatemode = $enabled;
        $this->simulationscenario = $scenario;
        return $this;
    }

    /**
     * Validate and sanitize student prompt input.
     *
     * @param mixed $prompt Raw input prompt.
     * @return array Array with keys: 'valid' (bool), 'prompt' (string), 'error' (string|null).
     */
    public static function validate_prompt($prompt): array {
        if (!is_string($prompt)) {
            return [
                'valid' => false,
                'prompt' => '',
                'error' => 'Prompt must be a text string.',
            ];
        }

        $trimmed = trim($prompt);

        if ($trimmed === '') {
            return [
                'valid' => false,
                'prompt' => '',
                'error' => 'Prompt cannot be empty. Please ask a question.',
            ];
        }

        $length = mb_strlen($trimmed, 'UTF-8');

        if ($length < self::MIN_PROMPT_LENGTH) {
            return [
                'valid' => false,
                'prompt' => $trimmed,
                'error' => 'Question is too short (minimum 2 characters required).',
            ];
        }

        if ($length > self::MAX_PROMPT_LENGTH) {
            return [
                'valid' => false,
                'prompt' => $trimmed,
                'error' => 'Question exceeds maximum allowed length of ' . self::MAX_PROMPT_LENGTH . ' characters.',
            ];
        }

        // Sanitize control characters except common line breaks and tabs.
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $trimmed);

        return [
            'valid' => true,
            'prompt' => $sanitized,
            'error' => null,
        ];
    }

    /**
     * Generate the pedagogical system prompt for the AI Tutor.
     *
     * @param string|null $coursename Name of the active course.
     * @param string|null $modulename Specific topic or module being studied.
     * @return string
     */
    public static function get_system_prompt(?string $coursename = null, ?string $modulename = null): string {
        $coursecontext = $coursename ? "for the course '{$coursename}'" : "for Web Development and Computer Science";
        $modulecontext = $modulename ? " The current learning module is '{$modulename}'." : "";

        return "You are Cynaris AI Tutor, an expert, encouraging, and pedagogically focused academic assistant {$coursecontext}.{$modulecontext}

Your pedagogical guidelines:
1. Explain technical concepts with clarity, precision, and encouraging tone.
2. Provide clean, modern code snippets (e.g., PHP 8+, modern JavaScript ES6+, SQL with parameter binding).
3. Do not simply complete full graded assessments; instead, guide students through architectural logic, debugging steps, and conceptual understanding.
4. Adhere to security best practices: always advise prepared statements for SQL, escaping for XSS prevention, and secret segregation.
5. If discussing Moodle LMS, follow official Frankenstyle naming, \$DB API, and Mustache template standards.
6. Keep answers structured with concise explanations, code blocks, and practical examples.";
    }

    /**
     * Query the AI Tutor with automatic primary provider (Groq) and fallback (OpenRouter).
     *
     * @param string $prompt Student's question.
     * @param array $options Execution options:
     *   - 'course_name' (string): Name of the course for context injection.
     *   - 'module_name' (string): Active module name.
     *   - 'simulate' (bool): Force simulation mode.
     *   - 'simulation_scenario' (string): Simulation scenario.
     *   - 'history' (array): Prior conversation messages.
     * @return array Standardized response array.
     */
    public function query(string $prompt, array $options = []): array {
        $validation = self::validate_prompt($prompt);
        if (!$validation['valid']) {
            return [
                'status' => 'error',
                'code' => 400,
                'message' => $validation['error'],
                'provider' => null,
                'fallback_used' => false,
            ];
        }

        $cleanprompt = $validation['prompt'];
        $coursename = $options['course_name'] ?? null;
        $modulename = $options['module_name'] ?? null;
        $systemprompt = self::get_system_prompt($coursename, $modulename);

        $issimulate = $options['simulate'] ?? $this->simulatemode;
        $scenario = $options['simulation_scenario'] ?? $this->simulationscenario;

        // Route to deterministic simulation if enabled or if neither key is configured in live mode.
        if ($issimulate || (!$this->has_primary_key() && !$this->has_fallback_key())) {
            return $this->execute_simulation($cleanprompt, $scenario);
        }

        $primaryerror = null;
        $startprimary = microtime(true);

        // --- STEP 1: Attempt Primary Provider (Groq) ---
        if ($this->has_primary_key()) {
            $groqresult = $this->call_groq($cleanprompt, $systemprompt, $options);
            $latencygroq = (int) round((microtime(true) - $startprimary) * 1000);

            if ($groqresult['success']) {
                return [
                    'status' => 'success',
                    'response' => $groqresult['content'],
                    'provider' => self::PROVIDER_GROQ,
                    'model' => $this->groqmodel,
                    'latency_ms' => $latencygroq,
                    'fallback_used' => false,
                    'usage' => $groqresult['usage'] ?? null,
                ];
            }

            $primaryerror = $groqresult['error'] ?? 'Groq API request failed';
        } else {
            $primaryerror = 'Groq API key not configured';
        }

        // --- STEP 2: Safe Fallback to OpenRouter ---
        $startfallback = microtime(true);

        if ($this->has_fallback_key()) {
            $openrouterresult = $this->call_openrouter($cleanprompt, $systemprompt, $options);
            $latencyfallback = (int) round((microtime(true) - $startfallback) * 1000);

            if ($openrouterresult['success']) {
                return [
                    'status' => 'success',
                    'response' => $openrouterresult['content'],
                    'provider' => self::PROVIDER_OPENROUTER,
                    'model' => $this->openroutermodel,
                    'latency_ms' => $latencyfallback,
                    'fallback_used' => true,
                    'primary_error' => self::sanitize_error($primaryerror),
                    'usage' => $openrouterresult['usage'] ?? null,
                ];
            }

            $fallbackerror = $openrouterresult['error'] ?? 'OpenRouter API request failed';
        } else {
            $fallbackerror = 'OpenRouter fallback API key not configured';
        }

        // --- STEP 3: Safe Dual-Provider Failure ---
        return [
            'status' => 'error',
            'code' => 503,
            'message' => 'The AI Tutor is temporarily unavailable across both primary (Groq) and fallback (OpenRouter) providers. Please try again shortly.',
            'provider' => null,
            'fallback_used' => true,
            'primary_error' => self::sanitize_error($primaryerror),
            'fallback_error' => self::sanitize_error($fallbackerror),
        ];
    }

    /**
     * Dispatch HTTP request to Groq API.
     *
     * @param string $prompt Student question.
     * @param string $systemprompt Pedagogical system prompt.
     * @param array $options Additional query options.
     * @return array Standardized result: ['success' => bool, 'content' => string, 'error' => string|null, 'usage' => array].
     */
    protected function call_groq(string $prompt, string $systemprompt, array $options = []): array {
        $messages = [
            ['role' => 'system', 'content' => $systemprompt],
        ];

        if (!empty($options['history']) && is_array($options['history'])) {
            foreach ($options['history'] as $item) {
                if (isset($item['role'], $item['content'])) {
                    $messages[] = [
                        'role' => $item['role'] === 'user' ? 'user' : 'assistant',
                        'content' => (string) $item['content'],
                    ];
                }
            }
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload = [
            'model' => $this->groqmodel,
            'messages' => $messages,
            'temperature' => 0.6,
            'max_tokens' => 1200,
        ];

        $headers = [
            'Authorization: Bearer ' . $this->groqapikey,
            'Content-Type: application/json',
            'User-Agent: Cynaris-Moodle-AI-Tutor/1.0',
        ];

        return $this->execute_http_request(self::GROQ_API_URL, $headers, $payload, 'Groq');
    }

    /**
     * Dispatch HTTP request to OpenRouter fallback API.
     *
     * @param string $prompt Student question.
     * @param string $systemprompt Pedagogical system prompt.
     * @param array $options Additional query options.
     * @return array Standardized result: ['success' => bool, 'content' => string, 'error' => string|null, 'usage' => array].
     */
    protected function call_openrouter(string $prompt, string $systemprompt, array $options = []): array {
        $messages = [
            ['role' => 'system', 'content' => $systemprompt],
        ];

        if (!empty($options['history']) && is_array($options['history'])) {
            foreach ($options['history'] as $item) {
                if (isset($item['role'], $item['content'])) {
                    $messages[] = [
                        'role' => $item['role'] === 'user' ? 'user' : 'assistant',
                        'content' => (string) $item['content'],
                    ];
                }
            }
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload = [
            'model' => $this->openroutermodel,
            'messages' => $messages,
            'temperature' => 0.6,
            'max_tokens' => 1200,
        ];

        $headers = [
            'Authorization: Bearer ' . $this->openrouterapikey,
            'Content-Type: application/json',
            'HTTP-Referer: https://cynaris.local/moodle',
            'X-Title: Cynaris Solutions Moodle AI Tutor',
            'User-Agent: Cynaris-Moodle-AI-Tutor/1.0',
        ];

        return $this->execute_http_request(self::OPENROUTER_API_URL, $headers, $payload, 'OpenRouter');
    }

    /**
     * Execute HTTP POST via cURL with defensive error and timeout handling.
     *
     * @param string $url API endpoint.
     * @param array $headers HTTP headers.
     * @param array $payload JSON payload.
     * @param string $providerlabel Human-readable provider label.
     * @return array
     */
    protected function execute_http_request(string $url, array $headers, array $payload, string $providerlabel): array {
        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'content' => '',
                'error' => "cURL extension is not enabled in PHP environment.",
            ];
        }

        $ch = curl_init();
        $jsonpayload = json_encode($payload);

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonpayload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $rawresponse = curl_exec($ch);
        $curlinfo = curl_getinfo($ch);
        $curlerrnum = curl_errno($ch);
        $curlerror = curl_error($ch);
        curl_close($ch);

        // 1. Handle cURL transport faults (timeouts, DNS, connection refusal).
        if ($curlerrnum !== 0) {
            if ($curlerrnum === 28) { // CURLE_OPERATION_TIMEDOUT
                return [
                    'success' => false,
                    'content' => '',
                    'error' => "{$providerlabel} connection timed out after {$this->timeout}s",
                ];
            }
            return [
                'success' => false,
                'content' => '',
                'error' => "{$providerlabel} network transport error: {$curlerror}",
            ];
        }

        $httpcode = (int) ($curlinfo['http_code'] ?? 0);

        // 2. Handle HTTP rate limit (429).
        if ($httpcode === 429) {
            return [
                'success' => false,
                'content' => '',
                'error' => "{$providerlabel} rate limit reached (HTTP 429)",
            ];
        }

        // 3. Handle HTTP authorization failure (401/403).
        if ($httpcode === 401 || $httpcode === 403) {
            return [
                'success' => false,
                'content' => '',
                'error' => "{$providerlabel} authentication error: invalid API credentials",
            ];
        }

        // 4. Handle HTTP server failures (5xx).
        if ($httpcode >= 500 && $httpcode <= 599) {
            return [
                'success' => false,
                'content' => '',
                'error' => "{$providerlabel} upstream server error (HTTP {$httpcode})",
            ];
        }

        // 5. Handle unexpected non-200 responses.
        if ($httpcode !== 200) {
            return [
                'success' => false,
                'content' => '',
                'error' => "{$providerlabel} unexpected HTTP status code {$httpcode}",
            ];
        }

        // 6. Parse JSON response.
        $decoded = json_decode($rawresponse, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'content' => '',
                'error' => "{$providerlabel} returned non-decodable JSON payload",
            ];
        }

        // 7. Extract completion content.
        $text = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            return [
                'success' => false,
                'content' => '',
                'error' => "{$providerlabel} response omitted expected message content",
            ];
        }

        $usage = $decoded['usage'] ?? [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ];

        return [
            'success' => true,
            'content' => trim($text),
            'error' => null,
            'usage' => [
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            ],
        ];
    }

    /**
     * Deterministic simulation mode for testing scenarios without external dependencies.
     *
     * @param string $prompt Cleaned student prompt.
     * @param string $scenario Scenario to simulate.
     * @return array
     */
    protected function execute_simulation(string $prompt, string $scenario): array {
        switch ($scenario) {
            case 'primary_success':
                return [
                    'status' => 'success',
                    'response' => self::generate_simulated_reply($prompt, self::PROVIDER_GROQ),
                    'provider' => self::PROVIDER_GROQ,
                    'model' => $this->groqmodel . ' (simulated)',
                    'latency_ms' => 42,
                    'fallback_used' => false,
                    'usage' => [
                        'prompt_tokens' => mb_strlen($prompt),
                        'completion_tokens' => 120,
                        'total_tokens' => mb_strlen($prompt) + 120,
                    ],
                ];

            case 'groq_429_fallback':
                return [
                    'status' => 'success',
                    'response' => self::generate_simulated_reply($prompt, self::PROVIDER_OPENROUTER),
                    'provider' => self::PROVIDER_OPENROUTER,
                    'model' => $this->openroutermodel . ' (simulated)',
                    'latency_ms' => 88,
                    'fallback_used' => true,
                    'primary_error' => 'Groq rate limit reached (HTTP 429)',
                    'usage' => [
                        'prompt_tokens' => mb_strlen($prompt),
                        'completion_tokens' => 115,
                        'total_tokens' => mb_strlen($prompt) + 115,
                    ],
                ];

            case 'groq_timeout_fallback':
                return [
                    'status' => 'success',
                    'response' => self::generate_simulated_reply($prompt, self::PROVIDER_OPENROUTER),
                    'provider' => self::PROVIDER_OPENROUTER,
                    'model' => $this->openroutermodel . ' (simulated)',
                    'latency_ms' => 95,
                    'fallback_used' => true,
                    'primary_error' => "Groq connection timed out after {$this->timeout}s",
                    'usage' => [
                        'prompt_tokens' => mb_strlen($prompt),
                        'completion_tokens' => 110,
                        'total_tokens' => mb_strlen($prompt) + 110,
                    ],
                ];

            case 'both_failed':
            default:
                return [
                    'status' => 'error',
                    'code' => 503,
                    'message' => 'The AI Tutor is temporarily unavailable across both primary (Groq) and fallback (OpenRouter) providers. Please try again shortly.',
                    'provider' => null,
                    'fallback_used' => true,
                    'primary_error' => 'Groq rate limit reached (HTTP 429)',
                    'fallback_error' => 'OpenRouter upstream server error (HTTP 503)',
                ];
        }
    }

    /**
     * Generate helpful, pedagogically rich simulated tutor responses for testing.
     *
     * @param string $prompt Student question.
     * @param string $provider Serving provider.
     * @return string
     */
    protected static function generate_simulated_reply(string $prompt, string $provider): string {
        $lower = strtolower($prompt);

        if (str_contains($lower, 'frankenstyle')) {
            return "In Moodle LMS, **Frankenstyle** is the core component naming convention formatted as `{plugintype}_{pluginname}` (e.g. `block_cynaris_stats`, `mod_quiz`).\n\n" .
                "It guarantees a globally unique namespace across:\n" .
                "1. Autoloaded PHP classes: `\\block_cynaris_stats\\analytics`\n" .
                "2. Database tables: `{m_block_cynaris_stats}`\n" .
                "3. Localization language keys: `get_string('key', 'block_cynaris_stats')`\n" .
                "4. Access capabilities: `'block/cynaris_stats:viewdashboard'`.\n\n" .
                "This guarantees that two third-party plugins with the same name under different plugin types never collide in class autoloader or database registries.";
        }

        if (str_contains($lower, 'xmldb') || str_contains($lower, 'install.xml')) {
            return "Moodle uses **XMLDB** (`db/install.xml`) as an abstract, database-independent schema definition layer.\n\n" .
                "Instead of raw SQL DDL (`CREATE TABLE`), Moodle parses `install.xml` and translates it into native SQL for PostgreSQL, MySQL, MariaDB, or Oracle.\n\n" .
                "When your plugin increments `\$plugin->version` in `version.php`, Moodle invokes `xmldb_{pluginname}_upgrade(\$oldversion)` in `db/upgrade.php` to apply programmatic schema migrations safely.";
        }

        if (str_contains($lower, 'sql') || str_contains($lower, 'injection')) {
            return "To prevent SQL injection in Moodle, always query via the **Moodle Data Manipulation API (`\$DB`)** using named parameter bindings:\n\n" .
                "```php\n" .
                "// Correct and secure:\n" .
                "\$sql = \"SELECT * FROM {user} WHERE email = :email AND deleted = :deleted\";\n" .
                "\$records = \$DB->get_records_sql(\$sql, ['email' => \$useremail, 'deleted' => 0]);\n" .
                "```\n\n" .
                "Never concatenate raw variables directly into query strings (`\"WHERE email = '\$useremail'\"`), as this introduces critical SQL injection vulnerabilities.";
        }

        return "Hello! I am your Cynaris AI Course Tutor.\n\n" .
            "Regarding your question about **\"" . s($prompt) . "\"**:\n\n" .
            "In full-stack web and LMS architecture, always structure your solution across distinct layers:\n" .
            "- **Presentation**: Keep views, templates, and UI decoupled from raw database queries.\n" .
            "- **Service/Model**: Encapsulate business logic with strict input validation and parameter typing.\n" .
            "- **Data Layer**: Use parameterized queries to guarantee transactional integrity.\n\n" .
            "Let me know which specific area you would like to explore further!";
    }

    /**
     * Sanitize error strings to ensure credentials or internal paths are never leaked.
     *
     * @param string|null $error Raw error message.
     * @return string Sanitized error message.
     */
    public static function sanitize_error(?string $error): string {
        if (empty($error)) {
            return 'Unknown provider error';
        }

        // Redact any patterns resembling API keys (e.g. gsk_*, sk-or-*, Bearer ...).
        $sanitized = preg_replace('/(gsk_[a-zA-Z0-9_-]{10,}|sk-or-[a-zA-Z0-9_-]{10,}|Bearer\s+[a-zA-Z0-9_\-\.]{10,})/i', '[REDACTED_API_KEY]', $error);

        // Redact Windows or Linux filesystem absolute paths.
        $sanitized = preg_replace('/[a-zA-Z]:\\\\[\w\s\.-\\\\]+/i', '[INTERNAL_PATH]', $sanitized);
        $sanitized = preg_replace('/\/[a-zA-Z0-9_\-\.\/]+/i', '[INTERNAL_PATH]', $sanitized);

        return trim($sanitized);
    }
}
