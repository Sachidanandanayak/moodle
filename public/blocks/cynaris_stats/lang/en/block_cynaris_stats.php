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
 * English language strings for block_cynaris_stats.
 *
 * @package    block_cynaris_stats
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Cynaris Course Stats';
$string['cynaris_stats:addinstance'] = 'Add a new Cynaris Course Stats block';
$string['cynaris_stats:myaddinstance'] = 'Add a new Cynaris Course Stats block to Dashboard';
$string['coursename'] = 'Course';
$string['coursecode'] = 'Course Code';
$string['enrolledstudents'] = 'Students';
$string['enrolledteachers'] = 'Teachers';
$string['startdate'] = 'Start Date';
$string['completiontracking'] = 'Completion';
$string['enabled'] = 'Enabled';
$string['disabled'] = 'Disabled';
$string['notset'] = 'Not Set';
$string['viewparticipants'] = 'View Participants';
$string['sitestats'] = 'Site Overview';
$string['totalcourses'] = 'Active Courses';
$string['totalusers'] = 'Registered Users';
$string['guestaccessnotallowed'] = 'Please log in to view course statistics.';
$string['privacy:metadata'] = 'The Cynaris Course Stats block displays summary course metrics and does not store personal data.';
$string['cynaris_stats:viewdashboard'] = 'View Cynaris course progress dashboard';
$string['view_progress_dashboard'] = 'Progress Dashboard';
$string['dashboard_title'] = 'Progress Dashboard';
$string['dashboard_heading'] = 'Grade & Progress Analytics';
$string['back_to_course'] = 'Back to Course';
$string['role_instructor_view'] = 'Instructor / Cohort View';
$string['role_student_view'] = 'Student Progress View';
$string['empty_grade_title'] = 'No Grade Records Available Yet';
$string['empty_grade_desc'] = 'There are currently no graded activities or recorded student submissions in this course.';
$string['instructor_guidance_title'] = 'Instructor Guidance';
$string['instructor_guidance_desc'] = 'To generate progress charts, add graded activities (such as Assignments or Quizzes) and enter student grades via the Moodle Gradebook.';
$string['student_guidance_title'] = 'Student Notice';
$string['student_guidance_desc'] = 'When your instructors publish grades for assignments and quizzes in this course, your progress metrics and Chart.js performance visualisations will appear here automatically.';
$string['graded_activities_count'] = 'Graded Activities';
$string['graded_submissions_count'] = 'Recorded Grades';
$string['graded_students_title'] = 'Graded Students';
$string['cohort_size'] = 'Total Enrolment';
$string['participation_rate'] = 'Graded Ratio';
$string['class_average'] = 'Class Average';
$string['average_percentage'] = 'Average %';
$string['score_range'] = 'Score Range';
$string['highest_lowest'] = 'Lowest to Highest';
$string['students'] = 'Students';
$string['chart_distribution_title'] = 'Cohort Grade Band Distribution';
$string['chart_activities_title'] = 'Activity Average Scores vs Maximum';
$string['chart_student_title'] = 'My Activity Scores vs Maximum';
$string['band_distinction'] = 'Distinction';
$string['band_merit'] = 'Merit';
$string['band_pass'] = 'Pass';
$string['band_fail'] = 'Fail';
$string['band_pending'] = 'Pending Evaluation';
$string['band_none'] = 'No Grades';
$string['activity_breakdown_title'] = 'Course Activity Performance Breakdown';
$string['activity_name'] = 'Activity Name';
$string['activity_type'] = 'Module Type';
$string['max_grade'] = 'Max Points';
$string['my_score'] = 'My Score';
$string['percentage'] = 'Percentage';
$string['status'] = 'Performance';
$string['no_grades_recorded'] = 'Pending Grades';
$string['my_overall_grade'] = 'My Course Score';
$string['total_points_earned'] = 'Total Points';
$string['my_standing'] = 'Academic Standing';
$string['grade_scale_reference'] = 'Distinction >= 85%, Merit >= 70%, Pass >= 50%';
$string['activities_completed'] = 'Completed Items';
$string['my_grades_breakdown'] = 'My Assessment Performance';
$string['result'] = 'Result';
$string['feedback'] = 'Instructor Feedback';
$string['pending'] = 'Pending';

// AI Tutor strings (W8D1).
$string['cynaris_stats:useaitutor'] = 'Interact with Cynaris AI Course Tutor';
$string['ai_tutor_button'] = 'AI Course Tutor';
$string['tutor_title'] = 'AI Course Tutor';
$string['tutor_heading'] = 'Interactive AI Course Tutor';
$string['tutor_desc'] = 'Ask conceptual questions, review code patterns, and receive real-time guidance with automatic Groq-to-OpenRouter fallback.';
$string['ask_tutor'] = 'Ask Question';
$string['send'] = 'Send';
$string['clear_chat'] = 'Clear Conversation';
$string['input_placeholder'] = 'Type your programming or course question here (e.g., How does Moodle Frankenstyle work?)...';
$string['provider_groq'] = 'Groq (Llama 3.3 70B)';
$string['provider_openrouter'] = 'OpenRouter (Fallback)';
$string['fallback_notice'] = 'Primary provider was busy; answer provided seamlessly via OpenRouter fallback.';
$string['error_prompt_empty'] = 'Prompt cannot be empty.';
$string['error_dual_failure'] = 'The AI Tutor service is temporarily unavailable across both primary and fallback providers. Please try again shortly.';

