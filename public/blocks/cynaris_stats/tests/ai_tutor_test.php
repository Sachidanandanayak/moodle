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
 * Unit test case for AI Tutor primary and fallback provider resilience.
 *
 * @package    block_cynaris_stats
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_tutor_test extends \advanced_testcase {

    /**
     * Test prompt validation bounds and sanitization.
     */
    public function test_prompt_validation(): void {
        $empty = ai_tutor::validate_prompt('');
        $this->assertFalse($empty['valid']);

        $tooShort = ai_tutor::validate_prompt('?');
        $this->assertFalse($tooShort['valid']);

        $tooLong = ai_tutor::validate_prompt(str_repeat('a', 3001));
        $this->assertFalse($tooLong['valid']);

        $valid = ai_tutor::validate_prompt('Explain Frankenstyle in Moodle');
        $this->assertTrue($valid['valid']);
    }

    /**
     * Test primary Groq provider execution path.
     */
    public function test_primary_groq_success(): void {
        $tutor = new ai_tutor('mock_key', 'mock_key', [
            'simulate' => true,
            'simulation_scenario' => 'primary_success',
        ]);

        $res = $tutor->query('Explain Moodle Frankenstyle plugin naming convention');
        $this->assertEquals('success', $res['status']);
        $this->assertEquals(ai_tutor::PROVIDER_GROQ, $res['provider']);
        $this->assertFalse($res['fallback_used']);
    }

    /**
     * Test Groq rate limit triggering OpenRouter fallback.
     */
    public function test_groq_429_triggers_openrouter_fallback(): void {
        $tutor = new ai_tutor('mock_key', 'mock_key', [
            'simulate' => true,
            'simulation_scenario' => 'groq_429_fallback',
        ]);

        $res = $tutor->query('Explain XMLDB');
        $this->assertEquals('success', $res['status']);
        $this->assertEquals(ai_tutor::PROVIDER_OPENROUTER, $res['provider']);
        $this->assertTrue($res['fallback_used']);
        $this->assertStringContainsString('HTTP 429', $res['primary_error']);
    }

    /**
     * Test Groq timeout triggering OpenRouter fallback.
     */
    public function test_groq_timeout_triggers_openrouter_fallback(): void {
        $tutor = new ai_tutor('mock_key', 'mock_key', [
            'simulate' => true,
            'simulation_scenario' => 'groq_timeout_fallback',
        ]);

        $res = $tutor->query('Explain SQL safety in Moodle');
        $this->assertEquals('success', $res['status']);
        $this->assertEquals(ai_tutor::PROVIDER_OPENROUTER, $res['provider']);
        $this->assertTrue($res['fallback_used']);
        $this->assertStringContainsString('timed out', $res['primary_error']);
    }

    /**
     * Test safe error returned when both providers fail.
     */
    public function test_both_providers_failed(): void {
        $tutor = new ai_tutor('mock_key', 'mock_key', [
            'simulate' => true,
            'simulation_scenario' => 'both_failed',
        ]);

        $res = $tutor->query('Explain course context');
        $this->assertEquals('error', $res['status']);
        $this->assertEquals(503, $res['code']);
        $this->assertNull($res['provider']);
        $this->assertStringContainsString('temporarily unavailable', $res['message']);
    }

    /**
     * Test that secret API keys are never exposed in serialized responses.
     */
    public function test_secret_key_isolation(): void {
        $tutor = new ai_tutor('gsk_secret_token_12345', 'sk-or-v1-secret_token_67890', [
            'simulate' => true,
            'simulation_scenario' => 'primary_success',
        ]);

        $res = $tutor->query('Test security prompt');
        $serialized = json_encode($res);
        $this->assertStringNotContainsString('gsk_secret_token_12345', $serialized);
        $this->assertStringNotContainsString('sk-or-v1-secret_token_67890', $serialized);
    }
}
