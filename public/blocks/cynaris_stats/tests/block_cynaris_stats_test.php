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
require_once($CFG->dirroot . '/blocks/moodleblock.class.php');
require_once($CFG->dirroot . '/blocks/cynaris_stats/block_cynaris_stats.php');

/**
 * Unit tests for block_cynaris_stats.
 *
 * @package    block_cynaris_stats
 * @category   test
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class block_cynaris_stats_test extends \advanced_testcase {

    /**
     * Test block initialization and metadata properties.
     */
    public function test_block_initialization(): void {
        $block = new \block_cynaris_stats();
        $block->init();

        $this->assertEquals(get_string('pluginname', 'block_cynaris_stats'), $block->title);
        $this->assertFalse($block->instance_allow_multiple());
        $this->assertFalse($block->has_config());

        $formats = $block->applicable_formats();
        $this->assertArrayHasKey('course-view', $formats);
        $this->assertTrue($formats['course-view']);
        $this->assertArrayHasKey('site', $formats);
        $this->assertTrue($formats['site']);
        $this->assertArrayHasKey('my', $formats);
        $this->assertTrue($formats['my']);
    }

    /**
     * Test block rendering within a course context.
     */
    public function test_block_content_course_context(): void {
        global $PAGE, $DB;

        $this->resetAfterTest(true);

        // Generate sample course with teacher and student
        $generator = $this->getDataGenerator();
        $course = $generator->create_course([
            'fullname' => 'Test Statistics Course',
            'shortname' => 'TSC-01',
            'enablecompletion' => 1,
        ]);
        $teacher = $generator->create_user();
        $student = $generator->create_user();

        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        // Set active user and page context
        $this->setUser($teacher);
        $PAGE->set_course($course);

        $block = new \block_cynaris_stats();
        $block->init();
        $content = $block->get_content();

        $this->assertNotEmpty($content);
        $this->assertNotEmpty($content->text);
        $this->assertStringContainsString('Test Statistics Course', $content->text);
        $this->assertStringContainsString('TSC-01', $content->text);
        $this->assertStringContainsString('Students', $content->text);
        $this->assertStringContainsString('Teachers', $content->text);
        $this->assertNotEmpty($content->footer);
        $this->assertStringContainsString('user/index.php', $content->footer);
    }

    /**
     * Test block rendering in site context.
     */
    public function test_block_content_site_context(): void {
        global $PAGE, $SITE;

        $this->resetAfterTest(true);

        $admin = get_admin();
        $this->setUser($admin);
        $PAGE->set_course($SITE);

        $block = new \block_cynaris_stats();
        $block->init();
        $content = $block->get_content();

        $this->assertNotEmpty($content);
        $this->assertNotEmpty($content->text);
        $this->assertStringContainsString('Site Overview', $content->text);
        $this->assertStringContainsString('Active Courses', $content->text);
        $this->assertStringContainsString('Registered Users', $content->text);
    }
}
