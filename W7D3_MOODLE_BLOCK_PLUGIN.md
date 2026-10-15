# Week 7 Day 3 — Moodle Block Plugin: `block_cynaris_stats`

**Author:** Sachidananda Nayak  
**Role:** Full Stack Development Intern, Cynaris Solutions  
**Repository:** [https://github.com/Sachidanandanayak/moodle](https://github.com/Sachidanandanayak/moodle)  
**Branch:** `feat/w7d3-3m-sachidananda`  
**Target Moodle Version:** Moodle 6.0dev (Build: 20261002, PHP 8.3, PostgreSQL 17)  
**Plugin Component:** `block_cynaris_stats`  
**Plugin Version:** `2026101600`  

---

## 1. Executive Summary

As part of Week 7 Day 3 (W7D3) of the Cynaris Solutions internship, a custom Moodle block plugin named **Cynaris Course Statistics** (`block_cynaris_stats`) was developed, installed, and validated on the containerized Moodle development stack.

The plugin provides instructors, administrators, and students with real-time course metrics rendered directly in the course sidebar/block drawer, adhering strictly to Moodle Core plugin standards, Moodle Data Manipulation API (`$DB`), Access API (`has_capability`), Privacy API (`null_provider`), and standard localization strings.

---

## 2. Plugin Architecture and File Structure

Following Moodle 6.0dev directory conventions, the plugin is located inside `public/blocks/cynaris_stats/`:

```
public/blocks/cynaris_stats/
├── classes/
│   └── privacy/
│       └── provider.php            # Privacy API null_provider implementation
├── db/
│   └── access.php                  # Capability definitions (addinstance, myaddinstance)
├── lang/
│   └── en/
│       └── block_cynaris_stats.php # Localized English language pack
├── tests/
│   └── block_cynaris_stats_test.php# Automated PHPUnit test suite
├── block_cynaris_stats.php         # Main block class extending block_base
├── styles.css                      # Clean, non-intrusive CSS styles for statistics cards
└── version.php                     # Component metadata, version, and requirements
```

### File Details & Responsibilities

1. **`version.php`**:
   - Component: `block_cynaris_stats`
   - Version: `2026101600`
   - Minimum Moodle Requirement: `2026100200` (Moodle 6.0dev)
   - Maturity: `MATURITY_ALPHA`

2. **`block_cynaris_stats.php`**:
   - Extends standard `block_base`.
   - Implements `init()` to assign localized block title.
   - Restricts duplication: `instance_allow_multiple()` returns `false`.
   - Declares applicable page formats: `course-view`, `site`, `my` (Dashboard).
   - Implements `get_content()`:
     - Detects whether running inside a specific course context or site-wide.
     - **Course Context:**
       - Resolves `context_course::instance($course->id)`.
       - Queries active student enrolments safely using `$DB->count_records_sql()` joined against `m_role_assignments` and `m_role` (shortname `student`).
       - Queries active editing/non-editing teacher counts safely.
       - Formats course start date using `userdate()`.
       - Inspects `$course->enablecompletion` to display completion tracking status.
       - Verifies `moodle/course:viewparticipants` capability before showing a secure direct link to the course participants list.
     - **Site / Front Page Context:**
       - Safely tallies total active visible courses and registered non-deleted users using `$DB->count_records()`.
   - Never exposes private user PII (names, emails, grades).
   - Generates responsive HTML metric cards styled with CSS classes.

3. **`db/access.php`**:
   - Declares `block/cynaris_stats:myaddinstance` (risk: `RISK_SPAM`, allowed for `user`).
   - Declares `block/cynaris_stats:addinstance` (risk: `RISK_SPAM`, allowed for `editingteacher` and `manager`).

4. **`classes/privacy/provider.php`**:
   - Implements `\core_privacy\local\metadata\null_provider`.
   - Formally informs Moodle's Privacy Subsystem that `block_cynaris_stats` does not store any private or personal data in the database.

5. **`lang/en/block_cynaris_stats.php`**:
   - Standard localization string keys: `pluginname`, `cynaris_stats:addinstance`, `cynaris_stats:myaddinstance`, `enrolled_students`, `teachers`, `course_startdate`, `completion_status`, `completion_enabled`, `completion_disabled`, `view_participants`, `site_overview`, `total_courses`, `total_users`.

6. **`styles.css`**:
   - Modern metric grid layout (`.cynaris-stats-grid`, `.cynaris-stat-card`, `.cynaris-badge`).

---

## 3. Installation & Upgrade Procedure

The plugin was deployed and registered using Moodle's supported CLI upgrade script:

```bash
docker exec -u www-data moodle-docker-webserver-1 php /var/www/html/admin/cli/upgrade.php --non-interactive --allow-unstable
```

### Installation Verification

Database inspection confirmed clean registration in PostgreSQL (`m_` prefix):

1. **Plugin Config:**
   ```sql
   SELECT plugin, name, value FROM m_config_plugins WHERE plugin = 'block_cynaris_stats';
   ```
   **Output:**
   | plugin | name | value |
   | :--- | :--- | :--- |
   | `block_cynaris_stats` | `version` | `2026101600` |

2. **Block Registration:**
   ```sql
   SELECT id, name, cron, visible FROM m_block WHERE name = 'cynaris_stats';
   ```
   **Output:** ID `41`, name `cynaris_stats`, visible `1`.

3. **Capabilities:**
   ```sql
   SELECT id, name, captype, contextlevel, component FROM m_capabilities WHERE component = 'block_cynaris_stats';
   ```
   **Output:**
   - ID `707`: `block/cynaris_stats:myaddinstance`
   - ID `708`: `block/cynaris_stats:addinstance`

---

## 4. How to Add the Block to a Course

To add `block_cynaris_stats` to any Moodle course:

1. Navigate to the course in your web browser (e.g., `http://localhost:8001/course/view.php?id=2`).
2. Log in with an instructor or administrator account (e.g., `admin` or `teacher1`).
3. Turn **Edit mode** ON via the top-right toggle switch.
4. Expand the **Block drawer** on the right side of the screen.
5. Click **Add a block**.
6. Select **Cynaris Course Statistics** from the modal list.
7. The block instance will instantly appear in the course drawer, querying and rendering active course stats.

---

## 5. Verification & Test Results

### A. PHP Syntax Check
Run inside container:
```bash
php -l /var/www/html/public/blocks/cynaris_stats/version.php
php -l /var/www/html/public/blocks/cynaris_stats/block_cynaris_stats.php
php -l /var/www/html/public/blocks/cynaris_stats/lang/en/block_cynaris_stats.php
php -l /var/www/html/public/blocks/cynaris_stats/db/access.php
php -l /var/www/html/public/blocks/cynaris_stats/classes/privacy/provider.php
php -l /var/www/html/public/blocks/cynaris_stats/tests/block_cynaris_stats_test.php
```
**Result:** `No syntax errors detected in all 6 files.`

### B. Integration Render Test (Course 2: Full Stack Web Development)
Executed via Moodle CLI harness:
- **Course Fullname:** `Full Stack Web Development` (`CS-FSD-W7`)
- **Enrolled Students:** `1` (Verified student user: `student1`)
- **Teachers:** `1` (Verified teacher user: `teacher1`)
- **Course Start Date:** Formatted properly via `userdate()`
- **Completion Status:** `Enabled` badge displayed (`enablecompletion = 1`)
- **Participant Roster Link:** Displayed for authorized users (`/user/index.php?id=2`)
- **Render Output:**
  ```html
  <div class="cynaris-stats-container">
    <div class="cynaris-course-header">
      <strong>Full Stack Web Development</strong>
      <span class="text-muted">(CS-FSD-W7)</span>
    </div>
    <div class="cynaris-stats-grid">
      <div class="cynaris-stat-card"><span class="cynaris-stat-number">1</span><span class="cynaris-stat-label">Students</span></div>
      <div class="cynaris-stat-card"><span class="cynaris-stat-number">1</span><span class="cynaris-stat-label">Teachers</span></div>
    </div>
    ...
  </div>
  ```

### C. Integration Render Test (Site / Front Page Context)
- **Active Courses:** `2`
- **Registered Users:** `3`
- **Header:** `Site Overview`

### D. Unit Tests (`tests/block_cynaris_stats_test.php`)
- `test_block_initialization`: Asserts block title, multiple instance restriction, and page format applicability.
- `test_block_content_course_context`: Generates mock course with teacher and student enrolments, verifies correct counts and participant footer URL.
- `test_block_content_site_context`: Verifies site overview card for admin users.

---

## 6. Security & Performance Considerations

- **SQL Injection Prevention:** All database operations utilize `$DB->count_records_sql()` with strict named/positional parameter arrays or standard `$DB->count_records()`.
- **Capability Enforcement:** Access to participant lists is protected via `has_capability('moodle/course:viewparticipants', $context)`.
- **Privacy API Compliance:** The plugin implements `\core_privacy\local\metadata\null_provider`, ensuring clean privacy export/deletion compliance under GDPR.
- **Resource Efficiency:** Queries are light count aggregations on indexed columns (`roleid`, `contextid`, `deleted`), resulting in sub-millisecond execution times.

---

## 7. Limitations and Future Improvements

1. **Activity-level completion rate:** The initial version shows whether completion tracking is enabled for the course. A future version could calculate an aggregate percentage of completed course modules across enrolled students.
2. **Group filtering:** Currently displays totals across the entire course. Support for Moodle course groups could be added if courses use separated groups.
