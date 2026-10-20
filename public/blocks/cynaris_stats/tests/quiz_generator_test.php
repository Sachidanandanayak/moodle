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

require_once(__DIR__ . '/../classes/ai_tutor.php');
require_once(__DIR__ . '/../classes/quiz_generator.php');

/**
 * Unit test case for AI Quiz Generator, JSON schema validation, and Question Bank adapters.
 *
 * @package    block_cynaris_stats
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_generator_test extends \advanced_testcase {

    /**
     * Test course content validation boundaries.
     */
    public function test_content_validation(): void {
        $empty = quiz_generator::validate_content('');
        $this->assertFalse($empty['valid']);

        $tooShort = quiz_generator::validate_content('Too short');
        $this->assertFalse($tooShort['valid']);

        $tooLong = quiz_generator::validate_content(str_repeat('a', 15001));
        $this->assertFalse($tooLong['valid']);

        $valid = quiz_generator::validate_content('Moodle XMLDB abstracts relational database tables in portable XML format.');
        $this->assertTrue($valid['valid']);
    }

    /**
     * Test valid 5-MCQ JSON parsing and field normalization.
     */
    public function test_valid_quiz_json(): void {
        $fixture = [
            'questions' => [
                [
                    'question_text' => 'What is XMLDB?',
                    'options' => ['Option A', 'Option B', 'Option C', 'Option D'],
                    'correct_answer' => 'A',
                    'explanation' => 'XMLDB provides database abstraction.'
                ],
                [
                    'question_text' => 'What is $DB?',
                    'options' => ['Option A', 'Option B', 'Option C', 'Option D'],
                    'correct_answer' => 'B',
                    'explanation' => '$DB is the database API.'
                ],
                [
                    'question_text' => 'What is sesskey?',
                    'options' => ['Option A', 'Option B', 'Option C', 'Option D'],
                    'correct_answer' => 'C',
                    'explanation' => 'sesskey defends against CSRF.'
                ],
                [
                    'question_text' => 'What is Frankenstyle?',
                    'options' => ['Option A', 'Option B', 'Option C', 'Option D'],
                    'correct_answer' => 'D',
                    'explanation' => 'Frankenstyle provides unique plugin naming.'
                ],
                [
                    'question_text' => 'What does HTTP 429 indicate?',
                    'options' => ['Option A', 'Option B', 'Option C', 'Option D'],
                    'correct_answer' => 'A',
                    'explanation' => 'HTTP 429 indicates rate limiting.'
                ],
            ]
        ];

        $parsed = quiz_generator::validate_quiz_json($fixture);
        $this->assertTrue($parsed['valid']);
        $this->assertCount(5, $parsed['questions']);
        $this->assertEquals(0, $parsed['questions'][0]['correct_index']);
        $this->assertEquals(1, $parsed['questions'][1]['correct_index']);
        $this->assertEquals(2, $parsed['questions'][2]['correct_index']);
        $this->assertEquals(3, $parsed['questions'][3]['correct_index']);
    }

    /**
     * Test malformed JSON and count rejection.
     */
    public function test_malformed_and_count_rejection(): void {
        // Broken JSON
        $broken = quiz_generator::validate_quiz_json('{"questions": broken');
        $this->assertFalse($broken['valid']);

        // 3 questions instead of 5
        $threeq = [
            'questions' => [
                ['question_text' => 'Q1', 'options' => ['A', 'B', 'C', 'D'], 'correct_answer' => 'A', 'explanation' => 'E1'],
                ['question_text' => 'Q2', 'options' => ['A', 'B', 'C', 'D'], 'correct_answer' => 'A', 'explanation' => 'E2'],
                ['question_text' => 'Q3', 'options' => ['A', 'B', 'C', 'D'], 'correct_answer' => 'A', 'explanation' => 'E3'],
            ]
        ];
        $resCount = quiz_generator::validate_quiz_json($threeq);
        $this->assertFalse($resCount['valid']);
        $this->assertStringContainsString('expected exactly 5', $resCount['error']);
    }

    /**
     * Test GIFT format generation.
     */
    public function test_gift_format_conversion(): void {
        $mcqs = [
            [
                'number' => 1,
                'question_text' => 'What does XMLDB manage?',
                'options' => ['Database tables', 'CSS styles', 'Cron tasks', 'Emails'],
                'correct_index' => 0,
                'explanation' => 'XMLDB abstracts database table definitions.'
            ]
        ];

        $gift = quiz_generator::convert_to_gift($mcqs, 'Test Course');
        $this->assertStringContainsString('$CATEGORY: Test Course', $gift);
        $this->assertStringContainsString('=Database tables #Correct!', $gift);
        $this->assertStringContainsString('~CSS styles #Incorrect', $gift);
    }

    /**
     * Test Moodle question form data preparation.
     */
    public function test_prepare_moodle_question_form_data(): void {
        $mcq = [
            'number' => 1,
            'question_text' => 'What is Frankenstyle?',
            'options' => ['Moodle naming convention', 'CSS framework', 'JS bundler', 'Docker engine'],
            'correct_index' => 0,
            'explanation' => 'Frankenstyle provides unique plugin namespaces.'
        ];

        $formdata = quiz_generator::prepare_moodle_question_form_data($mcq, 10, 20);
        $this->assertEquals('10,20', $formdata->category);
        $this->assertEquals('1', $formdata->single);
        $this->assertEquals('1.0', $formdata->fraction[0]);
        $this->assertEquals('0.0', $formdata->fraction[1]);
        $this->assertCount(4, $formdata->answer);
    }

    /**
     * Test simulation execution and failover.
     */
    public function test_simulation_generation(): void {
        $gen = new quiz_generator('mock_key', 'mock_key', ['simulate' => true]);

        $res = $gen->generate_mcqs('Moodle architecture and question bank APIs lesson content.', [
            'simulate' => true,
            'simulation_scenario' => 'primary_success',
        ]);
        $this->assertEquals('success', $res['status']);
        $this->assertEquals(ai_tutor::PROVIDER_GROQ, $res['provider']);
        $this->assertCount(5, $res['questions']);

        // Fallback simulation
        $fallbackRes = $gen->generate_mcqs('Moodle architecture and question bank APIs lesson content.', [
            'simulate' => true,
            'simulation_scenario' => 'groq_429_fallback',
        ]);
        $this->assertEquals('success', $fallbackRes['status']);
        $this->assertEquals(ai_tutor::PROVIDER_OPENROUTER, $fallbackRes['provider']);
        $this->assertTrue($fallbackRes['fallback_used']);
    }
}
