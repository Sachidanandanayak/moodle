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
 * Cynaris AI Quiz & Question Bank Generator portal.
 *
 * Dedicated instructor portal for synthesizing 5 MCQs from course content
 * via Groq (Llama 3.3 70B), validating JSON, previewing items, exporting GIFT,
 * and publishing directly into Moodle Question Bank and Quiz activities.
 *
 * @package    block_cynaris_stats
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/blocks/cynaris_stats/classes/quiz_generator.php');

$courseid = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// Authenticate and establish course context.
require_login($course);
$context = context_course::instance($course->id);

// Enforce instructor/editing capability.
$canmanage = has_capability('block/cynaris_stats:generatequiz', $context) ||
             has_capability('moodle/course:manageactivities', $context) ||
             has_capability('moodle/question:add', $context);

if (!$canmanage) {
    throw new \moodle_exception('nopermissions', 'error', '', get_string('cynaris_stats:generatequiz', 'block_cynaris_stats'));
}

// Page setup.
$PAGE->set_course($course);
$PAGE->set_url('/blocks/cynaris_stats/quiz_generator.php', ['id' => $course->id]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('quiz_gen_title', 'block_cynaris_stats') . ' - ' . format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('quiz_gen_title', 'block_cynaris_stats'));

echo $OUTPUT->header();

$sesskey = sesskey();
$quizapiurl = new moodle_url('/blocks/cynaris_stats/quiz_generator_api.php');
$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
$dashboardurl = new moodle_url('/blocks/cynaris_stats/dashboard.php', ['id' => $course->id]);

// Retrieve course sections for placement selection.
$modinfo = get_fast_modinfo($course);
$sections = $modinfo->get_section_info_all();

// Check configured provider status for badge display.
$generator = new \block_cynaris_stats\quiz_generator();
$hasprimary = $generator->has_primary_key();
$hasfallback = $generator->has_fallback_key();
?>

<div class="cynaris-quizgen-wrapper my-3">
    <!-- Header Card -->
    <div class="card shadow-sm border-0 mb-4 cynaris-quizgen-header-card" style="border-radius: 12px; overflow: hidden; background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%); color: #fff;">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                        <span class="badge px-3 py-2 font-weight-bold" style="background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); font-size: 0.85rem; letter-spacing: 0.5px;">
                            <i class="fa fa-magic mr-1"></i> CYNARIS AI QUIZ ARCHITECT
                        </span>
                        <span class="badge badge-success px-2 py-1">
                            <i class="fa fa-bolt mr-1"></i> Primary: Groq Llama 3.3 70B
                        </span>
                        <span class="badge badge-secondary px-2 py-1">
                            <i class="fa fa-shield mr-1"></i> Fallback: OpenRouter
                        </span>
                        <span class="badge badge-info px-2 py-1">
                            <i class="fa fa-database mr-1"></i> Moodle 6.0 Question Bank
                        </span>
                    </div>
                    <h2 class="h3 font-weight-bold mb-1 text-white">
                        AI Quiz & Question Bank Generator
                    </h2>
                    <p class="mb-0 text-light opacity-80 small">
                        Synthesize 5 pedagogically calibrated MCQs from lesson content, review distractors & explanations, export GIFT format, and publish directly to Moodle.
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?php echo $dashboardurl; ?>" class="btn btn-sm btn-light font-weight-bold">
                        <i class="fa fa-line-chart mr-1"></i> Dashboard
                    </a>
                    <a href="<?php echo $courseurl; ?>" class="btn btn-sm btn-outline-light">
                        <i class="fa fa-arrow-left mr-1"></i> Course
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Generation & Configuration Grid -->
    <div class="row">
        <!-- Left Column: Input Form -->
        <div class="col-lg-5 mb-4">
            <div class="card shadow-sm border-0" style="border-radius: 12px;">
                <div class="card-header bg-white font-weight-bold border-bottom py-3">
                    <i class="fa fa-pencil-square-o text-primary mr-2"></i> 1. Course Content & Assessment Details
                </div>
                <div class="card-body p-4">
                    <form id="cynaris-quizgen-form">
                        <div class="form-group mb-3">
                            <label for="quiz-name" class="font-weight-bold small text-dark">
                                Quiz Title <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="quiz-name" class="form-control"
                                   placeholder="e.g. Week 8 Checkpoint: Full Stack Concepts"
                                   value="Week 8 Checkpoint: Full Stack Architecture (AI Generated)" required>
                        </div>

                        <div class="form-group mb-3">
                            <label for="course-section" class="font-weight-bold small text-dark">
                                Target Course Section
                            </label>
                            <select id="course-section" class="form-control custom-select">
                                <?php foreach ($sections as $snum => $sinfo): ?>
                                    <?php
                                    $sectiontitle = $sinfo->name ? format_string($sinfo->name) : ('Section ' . $snum);
                                    if ($snum === 0) {
                                        $sectiontitle = 'General / Announcements';
                                    }
                                    ?>
                                    <option value="<?php echo $snum; ?>" <?php echo $snum === 1 ? 'selected' : ''; ?>>
                                        <?php echo s($sectiontitle); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label for="course-content" class="font-weight-bold small text-dark mb-0">
                                    Lesson / Lecture Content <span class="text-danger">*</span>
                                </label>
                                <div class="dropdown">
                                    <button class="btn btn-link btn-sm p-0 small dropdown-toggle text-primary" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        <i class="fa fa-folder-open-o mr-1"></i> Load Sample Topic
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-right">
                                        <a class="dropdown-item small" href="#" id="load-sample-moodle">Moodle Architecture & XMLDB</a>
                                        <a class="dropdown-item small" href="#" id="load-sample-webdev">Modern Full Stack (REST, React, SQL)</a>
                                        <a class="dropdown-item small" href="#" id="load-sample-resilience">Cloud Resilience & Fallback</a>
                                    </div>
                                </div>
                            </div>
                            <textarea id="course-content" class="form-control font-monospace" rows="10"
                                      placeholder="Paste lecture notes, module summaries, or technical documentation here (minimum 20 characters)..."
                                      style="font-size: 0.88rem; line-height: 1.45;" required></textarea>
                            <small class="form-text text-muted d-flex justify-content-between">
                                <span>Minimum 20 characters, maximum 15,000 characters.</span>
                                <span id="content-char-count">0 chars</span>
                            </small>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" id="btn-generate-quiz" class="btn btn-primary btn-block py-2 font-weight-bold" style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); border: none;">
                                <i class="fa fa-magic mr-2"></i> Generate 5 MCQs with AI
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Right Column: Live Preview & Publication Center -->
        <div class="col-lg-7 mb-4">
            <div class="card shadow-sm border-0" style="border-radius: 12px; min-height: 520px;">
                <div class="card-header bg-white d-flex justify-content-between align-items-center border-bottom py-3">
                    <span class="font-weight-bold">
                        <i class="fa fa-list-ol text-success mr-2"></i> 2. Assessment Preview & Publication
                    </span>
                    <div id="preview-badges" class="d-none">
                        <span id="badge-provider" class="badge badge-success px-2 py-1 mr-1"></span>
                        <span id="badge-latency" class="badge badge-light border text-muted px-2 py-1"></span>
                    </div>
                </div>

                <div class="card-body p-4" id="quiz-preview-container">
                    <!-- Empty State Placeholder -->
                    <div id="quiz-empty-placeholder" class="text-center py-5">
                        <div class="mb-3">
                            <i class="fa fa-lightbulb-o text-muted" style="font-size: 3.5rem; opacity: 0.4;"></i>
                        </div>
                        <h4 class="h5 font-weight-bold text-dark">No Quiz Generated Yet</h4>
                        <p class="text-muted small max-w-600 mx-auto mb-3">
                            Paste lecture notes or pick a sample topic on the left, then click <strong>"Generate 5 MCQs with AI"</strong>.
                            Groq Llama 3.3 70B will synthesize exactly 5 multiple choice questions with explanations in verified JSON format.
                        </p>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="btn-empty-load-sample">
                            <i class="fa fa-bolt mr-1"></i> Try Sample Topic
                        </button>
                    </div>

                    <!-- Loading Indicator -->
                    <div id="quiz-loading-state" class="d-none text-center py-5">
                        <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
                            <span class="sr-only">Loading...</span>
                        </div>
                        <h5 class="h6 font-weight-bold text-dark mb-1">Synthesizing 5 Pedagogical MCQs...</h5>
                        <p class="text-muted small mb-0" id="loading-status-text">
                            Querying Groq Llama 3.3 70B Versatile with JSON schema enforcement...
                        </p>
                    </div>

                    <!-- Generated Questions Accordion / List -->
                    <div id="quiz-questions-list" class="d-none">
                        <div class="alert alert-info py-2 px-3 small d-flex justify-content-between align-items-center mb-3">
                            <span><i class="fa fa-check-circle mr-1"></i> Exactly 5 MCQs validated against Moodle schema.</span>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-light border btn-sm" id="btn-export-gift">
                                    <i class="fa fa-download mr-1"></i> Export GIFT
                                </button>
                            </div>
                        </div>

                        <div id="questions-accordion">
                            <!-- Injected by JavaScript -->
                        </div>

                        <!-- Publication Controls -->
                        <div class="mt-4 pt-3 border-top">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-regenerate">
                                    <i class="fa fa-refresh mr-1"></i> Regenerate
                                </button>
                                <button type="button" class="btn btn-success font-weight-bold px-4" id="btn-publish-quiz" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border: none;">
                                    <i class="fa fa-cloud-upload mr-1"></i> Import to Question Bank & Create Quiz
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Success Confirmation Banner -->
                    <div id="quiz-success-state" class="d-none text-center py-4">
                        <div class="alert alert-success p-4 border-0 shadow-sm text-left" style="border-radius: 10px; background: #ecfdf5; border-left: 4px solid #10b981 !important;">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fa fa-check-circle text-success mr-2" style="font-size: 1.5rem;"></i>
                                <h5 class="mb-0 font-weight-bold text-success">Quiz Published Successfully!</h5>
                            </div>
                            <p class="mb-2 text-dark small" id="success-quiz-title"></p>
                            <div class="small text-muted mb-3">
                                <strong>5 questions</strong> were successfully imported into Moodle's Question Bank under the system category and attached to the newly created Quiz activity.
                            </div>
                            <div class="d-flex gap-2 flex-wrap">
                                <a href="#" id="link-open-quiz" class="btn btn-success btn-sm font-weight-bold" target="_blank">
                                    <i class="fa fa-external-link mr-1"></i> Open Created Quiz
                                </a>
                                <a href="<?php echo $courseurl; ?>" class="btn btn-outline-secondary btn-sm">
                                    <i class="fa fa-arrow-left mr-1"></i> Return to Course
                                </a>
                                <button type="button" class="btn btn-light border btn-sm" id="btn-create-another">
                                    <i class="fa fa-plus mr-1"></i> Generate Another Quiz
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sesskey = '<?php echo $sesskey; ?>';
    const courseid = <?php echo $course->id; ?>;
    const apiurl = '<?php echo $quizapiurl->out(false); ?>';

    const form = document.getElementById('cynaris-quizgen-form');
    const contentInput = document.getElementById('course-content');
    const quizNameInput = document.getElementById('quiz-name');
    const sectionInput = document.getElementById('course-section');
    const charCounter = document.getElementById('content-char-count');

    const emptyPlaceholder = document.getElementById('quiz-empty-placeholder');
    const loadingState = document.getElementById('quiz-loading-state');
    const questionsList = document.getElementById('quiz-questions-list');
    const successState = document.getElementById('quiz-success-state');
    const accordion = document.getElementById('questions-accordion');
    const badgesContainer = document.getElementById('preview-badges');
    const providerBadge = document.getElementById('badge-provider');
    const latencyBadge = document.getElementById('badge-latency');

    const btnPublish = document.getElementById('btn-publish-quiz');
    const btnExportGift = document.getElementById('btn-export-gift');
    const btnRegenerate = document.getElementById('btn-regenerate');
    const btnCreateAnother = document.getElementById('btn-create-another');

    let currentQuestions = [];

    // Character counter
    contentInput.addEventListener('input', function() {
        const len = this.value.trim().length;
        charCounter.textContent = len + ' chars';
    });

    // Sample topics
    const samples = {
        moodle: `Moodle Architecture and Question Subsystem Overview:
Moodle LMS is architected around modular Frankenstyle components, database independence, and robust capability-based security.
The Question Bank subsystem abstracts assessments from specific activities, allowing questions to be authored, tagged, versioned, and shared across multiple quizzes and courses.
Since Moodle 4.0 and continuing through Moodle 6.0dev, question versioning is handled through question_bank_entries and question_versions tables, ensuring that updates to questions do not disrupt historical student attempts.
The XMLDB database abstraction layer uses portable XML schema files (install.xml) to generate DDL statements across PostgreSQL, MariaDB, MySQL, and Oracle without vendor lock-in.
All database queries must route through the global $DB object using parameterized placeholders (:param or ?) to prevent SQL injection vulnerabilities.
State-changing web forms and AJAX endpoints enforce Cross-Site Request Forgery (CSRF) defenses using confirm_sesskey().`,

        webdev: `Full Stack Web Application Development:
Modern full-stack web applications separate client presentation from backend persistence using RESTful JSON APIs and token-based authentication.
On the frontend, reactive single-page applications manage declarative UI state, rendering component trees efficiently through virtual DOM reconciliation.
On the backend, asynchronous controllers validate incoming HTTP request payloads, enforce role-based access control (RBAC), and coordinate transactions against relational databases.
Relational database management systems guarantee ACID properties (Atomicity, Consistency, Isolation, Durability) through write-ahead logging and multi-version concurrency control (MVCC).
Security best practices require passwords to be hashed using adaptive cryptographic functions such as bcrypt or Argon2id with unique salts, and sensitive API secrets must be segregated into server-side environment variables.`,

        resilience: `Cloud Resilience and LLM Provider Fallback Architecture:
Educational platforms requiring continuous availability cannot rely on a single third-party Large Language Model provider due to aggressive rate limits (HTTP 429), timeouts, and upstream server outages.
A dual-provider fault-tolerant architecture uses an ultra-fast primary provider (such as Groq Llama 3.3 70B) for sub-second latency and an automatic fallback provider (such as OpenRouter Llama 3.3 70B Instruct).
Requests are bounded by strict defensive timeouts (e.g., 8 seconds execution and 4 seconds connection timeout).
When Groq returns HTTP 429 Too Many Requests or times out, the failover engine catches the exception immediately and seamlessly dispatches the query to OpenRouter.
All API credentials remain strictly confined to the server backend. Any errors returned to the user have sensitive bearer tokens, filesystem paths, and stack traces thoroughly sanitized.`
    };

    document.getElementById('load-sample-moodle')?.addEventListener('click', (e) => {
        e.preventDefault();
        contentInput.value = samples.moodle;
        quizNameInput.value = 'Moodle Architecture & Question Bank Checkpoint';
        charCounter.textContent = samples.moodle.trim().length + ' chars';
    });

    document.getElementById('load-sample-webdev')?.addEventListener('click', (e) => {
        e.preventDefault();
        contentInput.value = samples.webdev;
        quizNameInput.value = 'Full Stack Development Knowledge Check';
        charCounter.textContent = samples.webdev.trim().length + ' chars';
    });

    document.getElementById('load-sample-resilience')?.addEventListener('click', (e) => {
        e.preventDefault();
        contentInput.value = samples.resilience;
        quizNameInput.value = 'Cloud Resilience & LLM Failover Checkpoint';
        charCounter.textContent = samples.resilience.trim().length + ' chars';
    });

    document.getElementById('btn-empty-load-sample')?.addEventListener('click', () => {
        contentInput.value = samples.moodle;
        quizNameInput.value = 'Moodle Architecture & Question Bank Checkpoint';
        charCounter.textContent = samples.moodle.trim().length + ' chars';
        form.dispatchEvent(new Event('submit'));
    });

    // Form submit -> Generate MCQs
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const content = contentInput.value.trim();
        if (content.length < 20) {
            alert('Please enter at least 20 characters of course content.');
            return;
        }

        // Show loading
        emptyPlaceholder.classList.add('d-none');
        questionsList.classList.add('d-none');
        successState.classList.add('d-none');
        loadingState.classList.remove('d-none');
        badgesContainer.classList.add('d-none');

        try {
            const resp = await fetch(apiurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Moodle-Sesskey': sesskey
                },
                body: JSON.stringify({
                    action: 'generate',
                    courseid: courseid,
                    sesskey: sesskey,
                    content: content
                })
            });

            const data = await resp.json();

            if (!resp.ok || data.status !== 'success') {
                throw new Error(data.message || 'Failed to generate MCQs from AI provider.');
            }

            currentQuestions = data.data.questions;
            renderQuestions(currentQuestions);

            // Update badges
            providerBadge.textContent = data.data.provider === 'groq' ? 'Groq Llama 3.3 (Primary)' : 'OpenRouter (Fallback)';
            providerBadge.className = 'badge ' + (data.data.fallback_used ? 'badge-warning' : 'badge-success') + ' px-2 py-1 mr-1';
            latencyBadge.textContent = data.data.latency_ms + ' ms';
            badgesContainer.classList.remove('d-none');

            loadingState.classList.add('d-none');
            questionsList.classList.remove('d-none');

        } catch (err) {
            loadingState.classList.add('d-none');
            emptyPlaceholder.classList.remove('d-none');
            alert('AI Generation Error: ' + err.message);
        }
    });

    function renderQuestions(questions) {
        accordion.innerHTML = '';
        const letters = ['A', 'B', 'C', 'D'];

        questions.forEach((q, idx) => {
            const card = document.createElement('div');
            card.className = 'card border mb-3';
            card.style.borderRadius = '8px';

            let optionsHtml = '<div class="list-group list-group-flush">';
            q.options.forEach((opt, optidx) => {
                const isCorrect = (optidx === q.correct_index);
                const pillClass = isCorrect ? 'list-group-item-success font-weight-bold' : '';
                const badge = isCorrect ? '<span class="badge badge-success ml-2"><i class="fa fa-check"></i> Correct</span>' : '';
                optionsHtml += `
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3 ${pillClass}">
                        <span><strong class="text-muted mr-2">${letters[optidx]})</strong> ${escapeHtml(opt)}</span>
                        ${badge}
                    </div>
                `;
            });
            optionsHtml += '</div>';

            const isFirst = (idx === 0);
            card.innerHTML = `
                <div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center cynaris-accordion-header ${isFirst ? '' : 'collapsed'}"
                     style="cursor: pointer;"
                     data-toggle="collapse"
                     data-target="#qcollapse-${idx}"
                     data-bs-toggle="collapse"
                     data-bs-target="#qcollapse-${idx}"
                     aria-expanded="${isFirst ? 'true' : 'false'}"
                     aria-controls="qcollapse-${idx}">
                    <span class="font-weight-bold text-dark small">
                        <span class="badge badge-primary mr-2">Q${idx + 1}</span>
                        ${escapeHtml(q.question_text)}
                    </span>
                    <i class="fa ${isFirst ? 'fa-chevron-up' : 'fa-chevron-down'} text-muted small cynaris-accordion-icon"></i>
                </div>
                <div id="qcollapse-${idx}" class="collapse ${isFirst ? 'show' : ''}">
                    <div class="card-body p-0">
                        ${optionsHtml}
                        <div class="p-3 bg-light border-top small">
                            <strong class="text-primary"><i class="fa fa-info-circle mr-1"></i> Explanation:</strong>
                            <span class="text-muted">${escapeHtml(q.explanation)}</span>
                        </div>
                    </div>
                </div>
            `;
            accordion.appendChild(card);
        });
    }

    // Synchronize chevron icons and ARIA state with Bootstrap collapse events
    accordion.addEventListener('show.bs.collapse', function(e) {
        const card = e.target.closest('.card');
        if (!card) return;
        const header = card.querySelector('.card-header');
        if (header) {
            header.setAttribute('aria-expanded', 'true');
            header.classList.remove('collapsed');
            const icon = header.querySelector('.cynaris-accordion-icon');
            if (icon) {
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            }
        }
    });

    accordion.addEventListener('hide.bs.collapse', function(e) {
        const card = e.target.closest('.card');
        if (!card) return;
        const header = card.querySelector('.card-header');
        if (header) {
            header.setAttribute('aria-expanded', 'false');
            header.classList.add('collapsed');
            const icon = header.querySelector('.cynaris-accordion-icon');
            if (icon) {
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            }
        }
    });

    // Fallback toggle in case Bootstrap data-api is not active in the current theme
    accordion.addEventListener('click', function(e) {
        const header = e.target.closest('.card-header');
        if (!header || !accordion.contains(header)) return;

        const targetSelector = header.getAttribute('data-bs-target') || header.getAttribute('data-target');
        const targetEl = targetSelector ? accordion.querySelector(targetSelector) : header.nextElementSibling;
        if (!targetEl) return;

        setTimeout(function() {
            // If Bootstrap did not initiate a collapse transition ('collapsing')
            if (!targetEl.classList.contains('collapsing')) {
                const isShown = targetEl.classList.contains('show');
                const ariaState = (header.getAttribute('aria-expanded') === 'true');
                if (isShown === ariaState) {
                    // Bootstrap data-api did not toggle this element; toggle manually
                    if (isShown) {
                        targetEl.classList.remove('show');
                        header.setAttribute('aria-expanded', 'false');
                        header.classList.add('collapsed');
                        const icon = header.querySelector('.cynaris-accordion-icon');
                        if (icon) {
                            icon.classList.remove('fa-chevron-up');
                            icon.classList.add('fa-chevron-down');
                        }
                    } else {
                        targetEl.classList.add('show');
                        header.setAttribute('aria-expanded', 'true');
                        header.classList.remove('collapsed');
                        const icon = header.querySelector('.cynaris-accordion-icon');
                        if (icon) {
                            icon.classList.remove('fa-chevron-down');
                            icon.classList.add('fa-chevron-up');
                        }
                    }
                }
            }
        }, 50);
    });

    // Publish to Question Bank and Create Quiz
    btnPublish.addEventListener('click', async function() {
        if (!currentQuestions || currentQuestions.length !== 5) {
            alert('Cannot publish: Exactly 5 validated MCQs are required.');
            return;
        }

        const quizname = quizNameInput.value.trim() || 'AI Assessment Quiz';
        const section = parseInt(sectionInput.value, 10) || 1;

        btnPublish.disabled = true;
        btnPublish.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span> Publishing to Moodle...';

        const publishQuestions = currentQuestions.map(q => {
            const letter = q.correct_answer || q.correct_letter || (typeof q.correct_index === 'number' && ['A', 'B', 'C', 'D'][q.correct_index]) || '';
            return {
                ...q,
                correct_answer: letter,
                correct_letter: letter
            };
        });

        try {
            const resp = await fetch(apiurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Moodle-Sesskey': sesskey
                },
                body: JSON.stringify({
                    action: 'publish',
                    courseid: courseid,
                    sesskey: sesskey,
                    quizname: quizname,
                    section: section,
                    questions: publishQuestions
                })
            });

            const data = await resp.json();

            if (!resp.ok || data.status !== 'success') {
                throw new Error(data.message || 'Failed to publish quiz to Moodle.');
            }

            // Success state
            questionsList.classList.add('d-none');
            successState.classList.remove('d-none');

            document.getElementById('success-quiz-title').textContent = data.data.quizname;
            const quizLink = document.getElementById('link-open-quiz');
            quizLink.href = data.data.quiz_url;

        } catch (err) {
            alert('Publication Error: ' + err.message);
        } finally {
            btnPublish.disabled = false;
            btnPublish.innerHTML = '<i class="fa fa-cloud-upload mr-1"></i> Import to Question Bank & Create Quiz';
        }
    });

    // Export GIFT
    btnExportGift.addEventListener('click', async function() {
        if (!currentQuestions || currentQuestions.length === 0) {
            alert('No questions to export.');
            return;
        }

        const giftQuestions = currentQuestions.map(q => {
            const letter = q.correct_answer || q.correct_letter || (typeof q.correct_index === 'number' && ['A', 'B', 'C', 'D'][q.correct_index]) || '';
            return {
                ...q,
                correct_answer: letter,
                correct_letter: letter
            };
        });

        try {
            const resp = await fetch(apiurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Moodle-Sesskey': sesskey
                },
                body: JSON.stringify({
                    action: 'export_gift',
                    courseid: courseid,
                    sesskey: sesskey,
                    questions: giftQuestions
                })
            });

            const data = await resp.json();
            if (!resp.ok || data.status !== 'success') {
                throw new Error(data.message || 'GIFT export failed.');
            }

            // Download as text file
            const blob = new Blob([data.data.gift], { type: 'text/plain;charset=utf-8' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = data.data.filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

        } catch (err) {
            alert('Export Error: ' + err.message);
        }
    });

    // Regenerate
    btnRegenerate?.addEventListener('click', () => {
        form.dispatchEvent(new Event('submit'));
    });

    // Create another
    btnCreateAnother?.addEventListener('click', () => {
        successState.classList.add('d-none');
        emptyPlaceholder.classList.remove('d-none');
        contentInput.value = '';
        charCounter.textContent = '0 chars';
    });

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
});
</script>

<?php
echo $OUTPUT->footer();
