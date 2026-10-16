# Week 7 Day 4 (W7D4) — Moodle Progress Dashboard: Chart.js Visualisation from Grade Data

**Author:** Sachidananda Nayak  
**Role:** Full Stack Development Intern, Cynaris Solutions  
**Repository:** [https://github.com/Sachidanandanayak/moodle](https://github.com/Sachidanandanayak/moodle)  
**Branch:** `feat/w7d4-3m-sachidananda`  
**Target Moodle Version:** Moodle 6.0dev (Build: 20261002, PHP 8.3.35, PostgreSQL 17.11)  
**Plugin Component:** `block_cynaris_stats`  
**Plugin Version:** `2026101601`  

---

## 1. Executive Summary

As part of Week 7 Day 4 (W7D4) of the Cynaris Solutions internship, a full-featured, accessible, and privacy-preserving **Progress Dashboard** was developed for Moodle LMS, extending the custom `block_cynaris_stats` plugin.

The dashboard leverages **Moodle's built-in Chart API** (`\core\chart_base`, `\core\chart_bar`, `\core\chart_pie`, `\core\chart_series`) and native AMD Chart.js integration (`core/chart_builder` and `core/chart_output_chartjs`). It requires **zero external CDN dependencies**, completely eliminating external network vulnerabilities and privacy leaks.

Key capabilities delivered:
1. **Cohort-Wide Grade Distribution:** Renders doughnut charts visualising cohort distribution across pedagogical grade bands (Distinction $\ge 85\%$, Merit $70-84\%$, Pass $50-69\%$, Fail $<50\%$).
2. **Activity Average Performance:** Renders bar charts comparing average student scores against maximum possible marks across all graded course activities.
3. **Student Privacy Isolation:** Students only see their own performance metrics and scores; peer individual grades and personal identifiers are strictly masked.
4. **Graceful Empty State Handling:** Detects courses without grade records or submissions and presents an informative, styled empty-state view with guidance for instructors and students, avoiding misleading statistics or division-by-zero errors.
5. **Direct Block Drawer Integration:** Adds an intuitive "Progress Dashboard" navigation button inside the `block_cynaris_stats` block drawer.

---

## 2. Architecture & File Structure

All changes are cleanly encapsulated within `public/blocks/cynaris_stats/` following Moodle core Frankenstyle architecture:

```
public/blocks/cynaris_stats/
├── classes/
│   ├── analytics.php               # Core grade analytics service & Chart.js builders
│   └── privacy/
│       └── provider.php            # Privacy API null_provider (GDPR compliance)
├── db/
│   └── access.php                  # Capability definitions (addinstance, viewdashboard)
├── lang/
│   └── en/
│       └── block_cynaris_stats.php # English language pack with dashboard & chart strings
├── tests/
│   ├── analytics_test.php          # PHPUnit test suite for analytics & privacy isolation
│   ├── block_cynaris_stats_test.php# Block layout & initialization tests
│   └── run_tests.php               # Automated test execution harness with transactional rollback
├── block_cynaris_stats.php         # Main block class with Dashboard button in drawer
├── dashboard.php                   # Dedicated progress dashboard page
├── styles.css                      # Modern responsive cards, chart containers & empty states
└── version.php                     # Component release metadata (bumped to 2026101601)
```

---

## 3. Detailed Component Implementation

### A. Grade Analytics Service (`classes/analytics.php`)
- **Namespace:** `\block_cynaris_stats\analytics`
- **Parameterised SQL Queries:** All queries use `$DB->get_records_sql()` and `$DB->count_records_sql()` with named parameters (`:courseid`, `:userid`), preventing SQL injection attacks.
- **Empty State Detection:** `has_grade_data()` inspects `{grade_grades}` joined with `{grade_items}` for non-null `finalgrade` values.
- **Grade Band Classifier (`calculate_grade_band`):**
  - $\ge 85.0\%$: `distinction`
  - $\ge 70.0\%$ and $< 85.0\%$: `merit`
  - $\ge 50.0\%$ and $< 70.0\%$: `pass`
  - $< 50.0\%$: `fail`
- **Moodle Chart API Builders:**
  - `build_cohort_distribution_chart()`: Constructs a `\core\chart_pie` instance with `set_doughnut(true)`, custom HSL-tailored palette (`#28a745`, `#17a2b8`, `#ffc107`, `#dc3545`), and 4 grade band series labels.
  - `build_activity_averages_chart()`: Constructs a `\core\chart_bar` instance with series for Class Average and Maximum Points.
  - `build_student_progress_chart($userid)`: Constructs a `\core\chart_bar` instance plotting the student's individual score against maximum marks.
  - Returns `null` cleanly if no grades exist.

### B. Progress Dashboard Page (`dashboard.php`)
- **Context & Authentication:**
  - `require_login($course)` enforces valid authentication.
  - `context_course::instance($course->id)` establishes course boundary.
  - Enforces `block/cynaris_stats:viewdashboard` capability (falling back to `moodle/course:view`).
- **Role Isolation:**
  - Evaluates `has_capability('moodle/grade:viewall', $context)`.
  - **Instructors / Managers (`true`):** Cohort summary cards (Cohort Size, Participation Rate, Class Average %, Score Range), Doughnut Chart (Cohort Distribution), Bar Chart (Activity Comparison), and Activity Performance Table.
  - **Students (`false`):** Personal progress cards (My Course Score %, Standing, Completed Activities), Bar Chart (My Score vs Max Points), and Private Assessment Table. Peer data is never queried or transmitted.
- **Empty State Presentation:**
  - Displays a clean visual card with actionable guidance.
  - Instructors receive instructions on creating assignments/quizzes and entering grades.
  - Students receive guidance explaining that metrics will appear once assignments are graded.
  - Reuses real course enrollment counts so the card is immediately useful.

### C. Block Integration (`block_cynaris_stats.php`)
- In course context, renders a primary button `[Progress Dashboard]` linking directly to `/blocks/cynaris_stats/dashboard.php?id=<courseid>`.
- Preserves the secondary `[View Participants]` button for authorized roles.

### D. Access & Versioning
- **`db/access.php`:** Declares capability `block/cynaris_stats:viewdashboard` (`captype: read`, `contextlevel: CONTEXT_COURSE`, archetypes: `student`, `teacher`, `editingteacher`, `manager`).
- **`version.php`:** Bumped version to `2026101601` (`requires: 2026100200`, `maturity: MATURITY_STABLE`).

---

## 4. CIA Mentor Code Review Interactions

### CIA Interaction Round 1 — Architecture & Chart Library Standards
* **Review Focus:** Architectural choice between external Chart.js CDN vs Moodle Core Chart API, and student privacy enforcement.
* **CIA Mentor Finding:**  
  *"Never inject raw `<script src="https://cdn.jsdelivr.net/.../chart.js">` into a Moodle plugin. Moodle core already includes Chart.js through its AMD loader (`core/chart_builder` and `core/chart_output_chartjs`). Using `$OUTPUT->render($chart)` ensures accessibility (auto-generating ARIA attributes and fallback tables), eliminates external network dependencies, and conforms to Moodle Security Guidelines. Furthermore, ensure strict role isolation: students must never receive cohort grade distributions containing low-n samples that could de-anonymize peers."*
* **Implementation Response:**  
  1. Adopted Moodle's native `\core\chart_pie` and `\core\chart_bar` classes rendered through `$OUTPUT->render($chart)`.
  2. Implemented strict permission branching: cohort charts and class averages are gated behind `moodle/grade:viewall`, while students only access personal scores plotted against maximum possible grades.

### CIA Interaction Round 2 — Empty State Handling & Test Isolation
* **Review Focus:** Handling courses with 0 graded entries and maintaining database cleanliness during test runs.
* **CIA Mentor Finding:**  
  *"In Course 2 (`CS-FSD-W7`), no grades exist in `m_grade_grades`. A naive implementation would fail with division by zero or render blank canvases. The service must distinguish between an empty gradebook and zero scores. In addition, automated test scripts running against live databases must never leave orphan records."*
* **Implementation Response:**  
  1. Added `analytics::has_grade_data()` and guard clauses across all chart builders, returning `null` when no graded records exist.
  2. Created a dedicated empty-state UI component in `dashboard.php` that displays pedagogical instructions without throwing PHP notices or division-by-zero warnings.
  3. Built `tests/run_tests.php` using Moodle delegated database transactions (`$DB->start_delegated_transaction()`) with an intentional rollback, guaranteeing 100% test isolation and zero pollution of the live database.

---

## 5. Verification & Test Execution Results

### A. PHP Syntax Checks
All 9 plugin PHP files were validated inside `moodle-docker-webserver-1` (PHP 8.3.35):
```bash
php -l /var/www/html/public/blocks/cynaris_stats/version.php
php -l /var/www/html/public/blocks/cynaris_stats/block_cynaris_stats.php
php -l /var/www/html/public/blocks/cynaris_stats/db/access.php
php -l /var/www/html/public/blocks/cynaris_stats/classes/privacy/provider.php
php -l /var/www/html/public/blocks/cynaris_stats/classes/analytics.php
php -l /var/www/html/public/blocks/cynaris_stats/dashboard.php
php -l /var/www/html/public/blocks/cynaris_stats/lang/en/block_cynaris_stats.php
php -l /var/www/html/public/blocks/cynaris_stats/tests/block_cynaris_stats_test.php
php -l /var/www/html/public/blocks/cynaris_stats/tests/analytics_test.php
php -l /var/www/html/public/blocks/cynaris_stats/tests/run_tests.php
```
**Result:** `No syntax errors detected in all 10 files.`

### B. Automated Test Suite Execution (`tests/run_tests.php`)
The automated test harness executed 41 distinct assertions across 4 test suites:
- **Suite 1 (Mathematical Grade Bands):** 9/9 passed (100%, 85%, 84.99%, 70%, 69.99%, 50%, 49.99%, 0%, -5%).
- **Suite 2 (Empty Grade State Handling):** 15/15 passed (`has_grade_data === false`, null charts, zero graded counts, non-empty enrolment count, student summary fallbacks).
- **Suite 3 (Populated Grade Data & Chart Builders):** 16/16 passed (`has_grade_data === true`, `distinction === 1`, `average_percentage === 90.0%`, `\core\chart_pie` doughnut instance verified, `\core\chart_bar` series verified, transactional rollback verified).
- **Suite 4 (Block Rendering & Link):** 1/1 passed (`applicable_formats` verified).

**Total Test Verdict:** `41/41 PASSED, 0 FAILED (100% success rate)`.

---

## 6. How to Access and Test the Dashboard

1. **Start Moodle Environment:**
   Ensure `moodle-docker-webserver-1` and `moodle-docker-db-1` are running.
2. **Access Course 2:**
   Open browser at `http://localhost:8001/course/view.php?id=2`.
3. **View from Block Drawer:**
   In the course sidebar drawer, locate the **Cynaris Course Stats** block and click the blue **Progress Dashboard** button.
4. **Direct URL:**
   Navigate directly to `http://localhost:8001/blocks/cynaris_stats/dashboard.php?id=2`.
5. **Role Testing:**
   - Log in as `teacher1` (Vikram Sharma) or `admin`: view the Instructor Cohort View with empty-state guidance.
   - Log in as `student1` (Sachidananda Nayak): view the Student Personal View with privacy protection.

---

## 7. Limitations & Future Roadmap

1. **Competency-Based Progress:** Current metrics focus on assessment grades (`m_grade_grades`). Integration with Moodle's Competency framework (`m_competency`) could provide competency mastery tracking.
2. **Export to CSV / PDF:** Adding a one-click export for instructors to download cohort progress reports.
