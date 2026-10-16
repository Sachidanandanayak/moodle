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
 * Cynaris Course Stats block definition.
 *
 * Displays course statistics and enrollment summaries using official Moodle APIs.
 *
 * @package    block_cynaris_stats
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class block_cynaris_stats extends block_base {

    /**
     * Initialize block title and properties.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_cynaris_stats');
    }

    /**
     * Defines where this block can be placed across the LMS.
     *
     * @return array
     */
    public function applicable_formats() {
        return [
            'course-view' => true,
            'site'        => true,
            'my'          => true,
        ];
    }

    /**
     * Prevents multiple instances on the same page.
     *
     * @return bool
     */
    public function instance_allow_multiple() {
        return false;
    }

    /**
     * Block has no global admin configuration page.
     *
     * @return bool
     */
    public function has_config() {
        return false;
    }

    /**
     * Generate content to display in the block.
     *
     * @return stdClass
     */
    public function get_content() {
        global $COURSE, $DB;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        // If guest or unauthenticated user, show a graceful notice.
        if (!isloggedin() || isguestuser()) {
            $this->content->text = html_writer::div(
                get_string('guestaccessnotallowed', 'block_cynaris_stats'),
                'cynaris-stats-notice text-muted small p-2'
            );
            return $this->content;
        }

        $course = $this->page->course;
        if (empty($course) || empty($course->id)) {
            $course = $COURSE;
        }

        $html = '';

        // Case 1: Active Course Context (Course ID > 1)
        if ($course->id > SITEID) {
            $context = context_course::instance($course->id, IGNORE_MISSING);

            $fullname = format_string($course->fullname, true, ['context' => $context]);
            $shortname = format_string($course->shortname, true, ['context' => $context]);

            // Retrieve enrolled student and teacher counts safely using standard Moodle APIs
            $studentcount = 0;
            $teachercount = 0;

            if ($context) {
                // Find student role count
                $studentrole = $DB->get_record('role', ['shortname' => 'student']);
                if ($studentrole) {
                    $studentcount = count_role_users($studentrole->id, $context);
                } else {
                    $studentcount = count_enrolled_users($context);
                }

                // Find editing teacher role count
                $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher']);
                if ($teacherrole) {
                    $teachercount = count_role_users($teacherrole->id, $context);
                }
            }

            // Start date formatting
            $startdatestr = !empty($course->startdate)
                ? userdate($course->startdate, get_string('strftimedatetime', 'langconfig'))
                : get_string('notset', 'block_cynaris_stats');

            // Course completion tracking status
            $completionstr = !empty($course->enablecompletion)
                ? get_string('enabled', 'block_cynaris_stats')
                : get_string('disabled', 'block_cynaris_stats');
            $completionbadgeclass = !empty($course->enablecompletion)
                ? 'badge badge-success'
                : 'badge badge-secondary';

            // Render stats container
            $html .= html_writer::start_div('cynaris-stats-container');

            // Course Title & Code
            $html .= html_writer::div(
                html_writer::tag('span', s($fullname), ['class' => 'cynaris-course-title font-weight-bold d-block text-truncate']) .
                html_writer::tag('span', s($shortname), ['class' => 'badge badge-info mt-1']),
                'cynaris-header mb-2'
            );

            // Metrics grid
            $html .= html_writer::start_div('cynaris-metrics-grid d-flex gap-2 my-2');

            // Metric 1: Students
            $html .= html_writer::div(
                html_writer::div(s($studentcount), 'cynaris-metric-value text-primary font-weight-bold h4 mb-0') .
                html_writer::div(get_string('enrolledstudents', 'block_cynaris_stats'), 'cynaris-metric-label small text-muted'),
                'cynaris-metric-card flex-fill p-2 text-center border rounded bg-light'
            );

            // Metric 2: Teachers
            $html .= html_writer::div(
                html_writer::div(s($teachercount), 'cynaris-metric-value text-success font-weight-bold h4 mb-0') .
                html_writer::div(get_string('enrolledteachers', 'block_cynaris_stats'), 'cynaris-metric-label small text-muted'),
                'cynaris-metric-card flex-fill p-2 text-center border rounded bg-light'
            );

            $html .= html_writer::end_div(); // End cynaris-metrics-grid

            // Details list
            $html .= html_writer::start_tag('ul', ['class' => 'list-unstyled cynaris-details-list small mt-2 mb-2']);

            $html .= html_writer::tag('li',
                html_writer::tag('strong', get_string('startdate', 'block_cynaris_stats') . ': ') . s($startdatestr),
                ['class' => 'py-1 border-bottom']
            );

            $html .= html_writer::tag('li',
                html_writer::tag('strong', get_string('completiontracking', 'block_cynaris_stats') . ': ') .
                html_writer::tag('span', s($completionstr), ['class' => $completionbadgeclass . ' ml-1']),
                ['class' => 'py-1']
            );

            $html .= html_writer::end_tag('ul');

            $html .= html_writer::end_div(); // End cynaris-stats-container

            // Footer actions: View Dashboard and Participants
            $footerlinks = [];
            $dashboardurl = new moodle_url('/blocks/cynaris_stats/dashboard.php', ['id' => $course->id]);
            $footerlinks[] = html_writer::link(
                $dashboardurl,
                get_string('view_progress_dashboard', 'block_cynaris_stats'),
                ['class' => 'btn btn-sm btn-primary btn-block w-100 mb-1']
            );

            if ($context && has_capability('moodle/course:viewparticipants', $context)) {
                $url = new moodle_url('/user/index.php', ['id' => $course->id]);
                $footerlinks[] = html_writer::link(
                    $url,
                    get_string('viewparticipants', 'block_cynaris_stats'),
                    ['class' => 'btn btn-sm btn-outline-secondary btn-block w-100']
                );
            }
            $this->content->footer = implode('', $footerlinks);
        } else {
            // Case 2: Front Page or Dashboard Context (Site-Wide Overview)
            $totalcourses = $DB->count_records_select('course', 'id > 1 AND visible = 1');
            $totalusers = $DB->count_records_select('user', 'deleted = 0 AND id > 1');

            $html .= html_writer::start_div('cynaris-stats-container site-level');
            $html .= html_writer::div(
                html_writer::tag('h6', get_string('sitestats', 'block_cynaris_stats'), ['class' => 'text-muted mb-2 font-weight-bold'])
            );

            $html .= html_writer::start_div('cynaris-metrics-grid d-flex gap-2 my-2');

            // Metric 1: Courses
            $html .= html_writer::div(
                html_writer::div(s($totalcourses), 'cynaris-metric-value text-primary font-weight-bold h4 mb-0') .
                html_writer::div(get_string('totalcourses', 'block_cynaris_stats'), 'cynaris-metric-label small text-muted'),
                'cynaris-metric-card flex-fill p-2 text-center border rounded bg-light'
            );

            // Metric 2: Users
            $html .= html_writer::div(
                html_writer::div(s($totalusers), 'cynaris-metric-value text-success font-weight-bold h4 mb-0') .
                html_writer::div(get_string('totalusers', 'block_cynaris_stats'), 'cynaris-metric-label small text-muted'),
                'cynaris-metric-card flex-fill p-2 text-center border rounded bg-light'
            );

            $html .= html_writer::end_div();
            $html .= html_writer::end_div();

            // Footer link to course catalog
            $this->content->footer = html_writer::link(
                new moodle_url('/course/index.php'),
                get_string('courses'),
                ['class' => 'btn btn-sm btn-outline-secondary btn-block w-100']
            );
        }

        $this->content->text = $html;
        return $this->content;
    }
}
