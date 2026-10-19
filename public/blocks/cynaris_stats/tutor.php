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
 * Cynaris AI Course Tutor interactive interface.
 *
 * Provides students and instructors with an intelligent, pedagogically guided chat
 * assistant powered by Groq (Primary) and OpenRouter (Fallback).
 *
 * @package    block_cynaris_stats
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/blocks/cynaris_stats/classes/ai_tutor.php');

$courseid = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// Authenticate and establish course context.
require_login($course);
$context = context_course::instance($course->id);

if (has_capability('block/cynaris_stats:useaitutor', $context)) {
    require_capability('block/cynaris_stats:useaitutor', $context);
} else {
    require_capability('moodle/course:view', $context);
}

// Page setup.
$PAGE->set_course($course);
$PAGE->set_url('/blocks/cynaris_stats/tutor.php', ['id' => $course->id]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('tutor_title', 'block_cynaris_stats') . ' - ' . format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('tutor_title', 'block_cynaris_stats'));

echo $OUTPUT->header();

$sesskey = sesskey();
$tutorapiurl = new moodle_url('/blocks/cynaris_stats/tutor_api.php');
$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
$dashboardurl = new moodle_url('/blocks/cynaris_stats/dashboard.php', ['id' => $course->id]);

// Check configured provider status for badge display.
$tutorservice = new \block_cynaris_stats\ai_tutor();
$hasprimary = $tutorservice->has_primary_key();
$hasfallback = $tutorservice->has_fallback_key();
?>

<div class="cynaris-tutor-wrapper my-3">
    <!-- Header Card -->
    <div class="card shadow-sm border-0 mb-3 cynaris-tutor-header-card">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge badge-primary px-3 py-2 text-uppercase font-weight-bold">
                            <i class="fa fa-graduation-cap mr-1"></i> Cynaris AI Tutor
                        </span>
                        <span class="badge badge-success px-2 py-1">
                            <i class="fa fa-bolt mr-1"></i> Primary: Groq Llama 3.3 70B
                        </span>
                        <span class="badge badge-secondary px-2 py-1">
                            <i class="fa fa-shield mr-1"></i> Fallback: OpenRouter
                        </span>
                    </div>
                    <h2 class="h3 font-weight-bold mb-1 text-dark">
                        <?php echo format_string($course->fullname); ?> — Academic AI Assistant
                    </h2>
                    <p class="text-muted mb-0 small">
                        Ask conceptual questions, request code reviews, or get debugging guidance. Powered by resilient dual-provider failover.
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?php echo $dashboardurl; ?>" class="btn btn-outline-primary btn-sm">
                        <i class="fa fa-line-chart mr-1"></i> <?php echo get_string('view_progress_dashboard', 'block_cynaris_stats'); ?>
                    </a>
                    <a href="<?php echo $courseurl; ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="fa fa-arrow-left mr-1"></i> <?php echo get_string('back_to_course', 'block_cynaris_stats'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Chat Workspace Card -->
    <div class="card shadow-sm border-0 cynaris-chat-card">
        <!-- Quick Topic Suggestion Chips -->
        <div class="card-header bg-white border-bottom py-3">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="fa fa-lightbulb-o text-warning"></i>
                <span class="font-weight-bold small text-uppercase text-muted">Suggested Course Topics:</span>
            </div>
            <div class="cynaris-chips-container d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-sm btn-outline-info cynaris-chip" data-prompt="Explain Moodle Frankenstyle plugin naming convention and how it prevents namespace collisions.">
                    🧩 Frankenstyle Convention
                </button>
                <button type="button" class="btn btn-sm btn-outline-info cynaris-chip" data-prompt="How does XMLDB schema definition (install.xml) work in Moodle plugin development?">
                    🗄️ XMLDB & Migrations
                </button>
                <button type="button" class="btn btn-sm btn-outline-info cynaris-chip" data-prompt="Explain the difference between Context, Role, and Capability in Moodle access control.">
                    🔐 Context, Role & Capability
                </button>
                <button type="button" class="btn btn-sm btn-outline-info cynaris-chip" data-prompt="How do I prevent SQL injection attacks when using Moodle's $DB API?">
                    🛡️ $DB SQL Injection Defense
                </button>
                <button type="button" class="btn btn-sm btn-outline-info cynaris-chip" data-prompt="Explain PHP 8.3 constructor property promotion and typed class constants.">
                    🐘 Modern PHP 8.3 Features
                </button>
            </div>
        </div>

        <!-- Chat Conversation Messages Window -->
        <div class="card-body p-4 cynaris-messages-window" id="cynaris-messages-window" style="min-height: 420px; max-height: 560px; overflow-y: auto;">
            <!-- Welcome Initial Message from Tutor -->
            <div class="cynaris-message-wrapper tutor-message mb-3">
                <div class="d-flex gap-2 align-items-start">
                    <div class="cynaris-avatar tutor-avatar bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px; min-width: 38px;">
                        <i class="fa fa-graduation-cap"></i>
                    </div>
                    <div class="cynaris-bubble tutor-bubble p-3 rounded-lg bg-light border" style="max-width: 85%;">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="font-weight-bold text-primary small">Cynaris AI Tutor</span>
                            <span class="badge badge-light text-muted border small">Ready</span>
                        </div>
                        <div class="cynaris-bubble-content text-dark">
                            <p class="mb-1">Hello! I am your <strong>Cynaris AI Course Tutor</strong> for <em><?php echo format_string($course->fullname); ?></em>.</p>
                            <p class="mb-1">I am configured with a resilient dual-provider architecture: querying <strong>Groq (Llama 3.3 70B)</strong> as primary for ultra-fast generation, with automatic failover to <strong>OpenRouter</strong> if rate limits or network issues arise.</p>
                            <p class="mb-0">Click any topic chip above or type your programming or architectural question below to begin!</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dynamic Typing / Thinking Indicator (Hidden by default) -->
        <div id="cynaris-typing-indicator" class="px-4 py-2 bg-light border-top d-none">
            <div class="d-flex align-items-center gap-2 text-muted small">
                <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                <span id="cynaris-typing-text">AI Tutor is thinking...</span>
            </div>
        </div>

        <!-- Input Area Footer -->
        <div class="card-footer bg-white border-top p-3">
            <form id="cynaris-tutor-form">
                <div class="input-group">
                    <textarea id="cynaris-prompt-input"
                              class="form-control"
                              rows="2"
                              maxlength="3000"
                              placeholder="<?php echo get_string('input_placeholder', 'block_cynaris_stats'); ?>"
                              required></textarea>
                    <div class="input-group-append">
                        <button type="submit" id="cynaris-send-btn" class="btn btn-primary px-4">
                            <i class="fa fa-paper-plane mr-1"></i> <?php echo get_string('send', 'block_cynaris_stats'); ?>
                        </button>
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2 small text-muted">
                    <span>
                        <button type="button" id="cynaris-clear-btn" class="btn btn-link btn-sm text-muted p-0">
                            <i class="fa fa-trash-o mr-1"></i> <?php echo get_string('clear_chat', 'block_cynaris_stats'); ?>
                        </button>
                    </span>
                    <span id="cynaris-char-counter">0 / 3000</span>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const courseId = <?php echo (int) $course->id; ?>;
    const sesskey = '<?php echo $sesskey; ?>';
    const apiUrl = '<?php echo $tutorapiurl->out(false); ?>';

    const form = document.getElementById('cynaris-tutor-form');
    const input = document.getElementById('cynaris-prompt-input');
    const sendBtn = document.getElementById('cynaris-send-btn');
    const clearBtn = document.getElementById('cynaris-clear-btn');
    const messagesWindow = document.getElementById('cynaris-messages-window');
    const typingIndicator = document.getElementById('cynaris-typing-indicator');
    const typingText = document.getElementById('cynaris-typing-text');
    const charCounter = document.getElementById('cynaris-char-counter');
    const chips = document.querySelectorAll('.cynaris-chip');

    let conversationHistory = [];

    // Character counter updates
    input.addEventListener('input', function() {
        const len = input.value.length;
        charCounter.textContent = `${len} / 3000`;
    });

    // Enter to submit (Shift+Enter for newline)
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.dispatchEvent(new Event('submit'));
        }
    });

    // Topic suggestion chips click
    chips.forEach(chip => {
        chip.addEventListener('click', function() {
            input.value = this.getAttribute('data-prompt');
            input.focus();
            input.dispatchEvent(new Event('input'));
        });
    });

    // Clear chat
    clearBtn.addEventListener('click', function() {
        if (confirm('Clear chat history?')) {
            conversationHistory = [];
            messagesWindow.innerHTML = `
                <div class="cynaris-message-wrapper tutor-message mb-3">
                    <div class="d-flex gap-2 align-items-start">
                        <div class="cynaris-avatar tutor-avatar bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px; min-width: 38px;">
                            <i class="fa fa-graduation-cap"></i>
                        </div>
                        <div class="cynaris-bubble tutor-bubble p-3 rounded-lg bg-light border" style="max-width: 85%;">
                            <span class="font-weight-bold text-primary small d-block mb-1">Cynaris AI Tutor</span>
                            <div class="cynaris-bubble-content text-dark">
                                <p class="mb-0">Chat history cleared. How can I assist you with your course studies?</p>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
    });

    // Form submission
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const promptText = input.value.trim();
        if (!promptText) return;

        // Append user bubble to chat
        appendUserMessage(promptText);
        input.value = '';
        input.dispatchEvent(new Event('input'));
        input.disabled = true;
        sendBtn.disabled = true;

        // Show typing indicator
        typingIndicator.classList.remove('d-none');
        typingText.textContent = 'Contacting AI Tutor (Groq primary)...';
        scrollToBottom();

        try {
            const payload = {
                courseid: courseId,
                sesskey: sesskey,
                prompt: promptText,
                history: conversationHistory.slice(-6)
            };

            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Moodle-Sesskey': sesskey
                },
                body: JSON.stringify(payload)
            });

            const data = await response.json();

            if (response.ok && data.status === 'success') {
                const resData = data.data;
                appendTutorMessage(resData.response, resData.provider, resData.fallback_used, resData.latency_ms, resData.primary_error);
                conversationHistory.push({ role: 'user', content: promptText });
                conversationHistory.push({ role: 'assistant', content: resData.response });
            } else {
                const errorMsg = data.message || 'An error occurred while communicating with the AI Tutor.';
                appendErrorMessage(errorMsg, data.primary_error, data.fallback_error);
            }
        } catch (err) {
            appendErrorMessage('Network or client communication failure. Please check your connection and retry.');
        } finally {
            typingIndicator.classList.add('d-none');
            input.disabled = false;
            sendBtn.disabled = false;
            input.focus();
            scrollToBottom();
        }
    });

    function appendUserMessage(text) {
        const div = document.createElement('div');
        div.className = 'cynaris-message-wrapper user-message mb-3 d-flex justify-content-end';
        div.innerHTML = `
            <div class="d-flex gap-2 align-items-start flex-row-reverse" style="max-width: 85%;">
                <div class="cynaris-avatar user-avatar bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px; min-width: 38px;">
                    <i class="fa fa-user"></i>
                </div>
                <div class="cynaris-bubble user-bubble p-3 rounded-lg bg-primary text-white">
                    <span class="font-weight-bold small d-block mb-1 text-white-50">You</span>
                    <div class="cynaris-bubble-content" style="white-space: pre-wrap;">${escapeHtml(text)}</div>
                </div>
            </div>
        `;
        messagesWindow.appendChild(div);
        scrollToBottom();
    }

    function appendTutorMessage(text, provider, fallbackUsed, latencyMs, primaryError) {
        const div = document.createElement('div');
        div.className = 'cynaris-message-wrapper tutor-message mb-3';

        let badgeHtml = '';
        if (fallbackUsed) {
            badgeHtml = `<span class="badge badge-warning text-dark border small" title="${escapeHtml(primaryError || '')}"><i class="fa fa-shield"></i> OpenRouter (Fallback)</span>`;
        } else {
            badgeHtml = `<span class="badge badge-success small"><i class="fa fa-bolt"></i> Groq Llama 3.3</span>`;
        }

        const latencyHtml = latencyMs ? `<span class="badge badge-light border text-muted small ml-1">${latencyMs}ms</span>` : '';

        div.innerHTML = `
            <div class="d-flex gap-2 align-items-start">
                <div class="cynaris-avatar tutor-avatar bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px; min-width: 38px;">
                    <i class="fa fa-graduation-cap"></i>
                </div>
                <div class="cynaris-bubble tutor-bubble p-3 rounded-lg bg-light border" style="max-width: 85%;">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="font-weight-bold text-primary small">Cynaris AI Tutor</span>
                        <div>${badgeHtml}${latencyHtml}</div>
                    </div>
                    <div class="cynaris-bubble-content text-dark" style="white-space: pre-wrap;">${formatTutorContent(text)}</div>
                </div>
            </div>
        `;
        messagesWindow.appendChild(div);
        scrollToBottom();
    }

    function appendErrorMessage(message, primaryError, fallbackError) {
        const div = document.createElement('div');
        div.className = 'cynaris-message-wrapper error-message mb-3';

        let details = '';
        if (primaryError || fallbackError) {
            details = `<div class="small text-muted mt-1">
                ${primaryError ? `<div><strong>Primary (Groq):</strong> ${escapeHtml(primaryError)}</div>` : ''}
                ${fallbackError ? `<div><strong>Fallback (OpenRouter):</strong> ${escapeHtml(fallbackError)}</div>` : ''}
            </div>`;
        }

        div.innerHTML = `
            <div class="d-flex gap-2 align-items-start">
                <div class="cynaris-avatar error-avatar bg-danger text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px; min-width: 38px;">
                    <i class="fa fa-exclamation-triangle"></i>
                </div>
                <div class="cynaris-bubble error-bubble p-3 rounded-lg bg-danger-light border border-danger text-danger" style="max-width: 85%; background-color: #fff3f3;">
                    <span class="font-weight-bold small d-block mb-1">AI Tutor Service Notice</span>
                    <div class="cynaris-bubble-content">${escapeHtml(message)}</div>
                    ${details}
                </div>
            </div>
        `;
        messagesWindow.appendChild(div);
        scrollToBottom();
    }

    function scrollToBottom() {
        messagesWindow.scrollTop = messagesWindow.scrollHeight;
    }

    function escapeHtml(string) {
        const entityMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
        return String(string).replace(/[&<>"']/g, s => entityMap[s]);
    }

    function formatTutorContent(text) {
        // Safe formatting: escape HTML first, then format bold and inline code
        let safe = escapeHtml(text);
        // Code blocks: ```code```
        safe = safe.replace(/```([a-zA-Z0-9]*)\n?([\s\S]*?)```/g, '<pre class="bg-dark text-light p-2 rounded small mt-1 mb-1"><code>$2</code></pre>');
        // Inline code: `code`
        safe = safe.replace(/`([^`]+)`/g, '<code class="bg-white border px-1 py-0 rounded text-dark">$1</code>');
        // Bold: **text**
        safe = safe.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        return safe;
    }
});
</script>

<?php
echo $OUTPUT->footer();
