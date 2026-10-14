# Week 7 – Day 1: Moodle Setup, Course Management & Plugin Architecture Overview

**Program:** Cynaris Solutions Full Stack Development Internship  
**Author:** Sachidananda Nayak  
**Email:** `23BTCE160@gcu.edu.in`  
**Milestone:** W7D1 – LMS Setup, Course Creation, User Management, Plugin Architecture & Viva  
**Feature Branch:** `feat/w7d1-3m-sachidananda`  
**Fork Repository:** `https://github.com/Sachidanandanayak/moodle.git`  
**Upstream Repository:** `https://github.com/moodle/moodle.git`  
**Environment:** Containerized Moodle 6.0dev Stack (`moodle-docker-webserver-1`, `moodle-docker-db-1`, `moodle-docker-mailpit-1`)  
**Base URL:** [http://localhost:8001](http://localhost:8001)  
**Safety Isolation:** Port 8000 (`cynaris_app`) strictly preserved and unmodified.  

---

## 📌 1. Practical Tasks Accomplished

### 1.1 Course Creation
* **Course Name:** Full Stack Web Development
* **Short Name:** `CS-FSD-W7`
* **Course ID:** `2`
* **Category:** Category 1 (Default Category ID `1`)
* **Format:** Topics format with 4 active learning modules.
* **Summary:** Cynaris Solutions Full Stack Development Training Course – Week 7 Moodle Module.

### 1.2 User Account Management
* **Teacher Account:**
  * **Username:** `teacher1`
  * **Name:** Vikram Sharma
  * **Email:** `teacher1@cynaris.local`
  * **Assigned Role:** `editingteacher` (Role ID `3`)
* **Student Account:**
  * **Username:** `student1`
  * **Name:** Sachidananda Nayak
  * **Email:** `student1@cynaris.local`
  * **Assigned Role:** `student` (Role ID `5`)

### 1.3 Course Enrolment
* **Enrolment Method:** Internal Manual Enrolment (`enrol_manual`)
* **Status:** Active / Enabled (`status = 0`)
* Both `student1` and `teacher1` are actively enrolled in course `CS-FSD-W7` with respective permissions and context access.

---

## 🏛️ 2. Moodle Plugin Architecture Overview (Three Key Concepts)

Moodle is constructed around a decoupled, highly extensible modular framework where the core platform provides base APIs, event dispatcher loops, and persistence abstractions, while all educational and administrative features exist as specialized plugins.

### Concept 1: Plugin Types & The Frankenstyle Naming Convention
Moodle categorizes all extensions into over 30 distinct plugin types (e.g., `mod` for learning activities, `block` for sidebar/dashboard widgets, `auth` for authentication, `enrol` for course enrolments, `filter` for text processing, `theme` for presentations, and `local` for custom organizational extensions).
* Every plugin is strictly referenced by its **Frankenstyle** component name: `{plugintype}_{pluginname}` (e.g., `mod_assign`, `block_timeline`, `local_cynaris`).
* Frankenstyle guarantees globally unique identifiers across:
  * Class autoloading namespaces: `\{plugintype}_{pluginname}\...` (e.g., `\mod_quiz\event\...`).
  * Database table prefixes: `{prefix}_{plugintype}_{pluginname}` (e.g., `m_mod_forum_posts`).
  * String translation keys and capability identifiers (`{plugintype}/{pluginname}:{capability}`).

### Concept 2: Standard Component Lifecycle & XMLDB Schema Management
Every Moodle plugin adheres to a standardized structural contract:
* **`version.php`**: Declares component metadata, including `$plugin->version` (integer in `YYYYMMDDXX` format), `$plugin->requires` (minimum core version), `$plugin->component` (Frankenstyle name), and maturity level.
* **Declarative XMLDB (`db/install.xml`)**: Moodle uses an abstract, database-independent XML schema rather than raw SQL migrations. When a plugin is installed, Moodle translates `install.xml` into native PostgreSQL, MySQL, MariaDB, or Oracle DDL statements.
* **Incremental Upgrades (`db/upgrade.php`)**: When `$plugin->version` increases, Moodle's upgrade engine invokes `xmldb_{pluginname}_upgrade($oldversion)` to execute targeted database migrations programmatically.

### Concept 3: Hook & Callback Subsystem (Hooks API & Event Observers)
Plugins inject custom behavior into Moodle core without modifying core source code using two key mechanisms:
* **Modern Hooks API**: Allows core and other plugins to dispatch strongly-typed hook objects. Plugins register callbacks to intercept, inspect, or modify execution flows (e.g., altering course navigation menus or augmenting page footers).
* **Event Dispatcher (`db/events.php`)**: Enables non-blocking event observation. When domain events occur (such as `\core\event\course_viewed` or `\core\event\user_enrolment_created`), registered observers in plugins receive payloads to trigger audit logging, webhook dispatches, or notification workflows.

---

## 💬 3. W7D1 Viva Technical Questions & Concise Answers

### Q1: What is the purpose of Moodle's Frankenstyle naming convention, and how does it prevent collisions?
**Answer:**  
Frankenstyle combines the plugin type and the specific plugin name into a single identifier formatted as `{type}_{name}` (e.g., `mod_quiz`, `block_course_overview`). It provides a deterministic, global namespace across the entire LMS. This guarantees that class autoloaders, database table prefixes, capability definitions, and language string files never collide, even when third-party developers build plugins with identical base names under different plugin types.

### Q2: Explain the role of `version.php` and the XMLDB schema in Moodle plugin installation and upgrades.
**Answer:**  
`version.php` defines the plugin's metadata, release name, minimum required core Moodle version, and its current integer schema version (`$plugin->version`). Moodle compares this integer against the value stored in the `mdl_config_plugins` database table. If a new version is detected, Moodle runs incremental migrations defined in `db/upgrade.php` using the XMLDB abstraction layer (`db/install.xml`), ensuring database schema changes deploy consistently across PostgreSQL, MySQL, and MariaDB without manual SQL scripts.

### Q3: How does Moodle handle user roles and access control, specifically the difference between Role, Context, and Capability?
**Answer:**  
Moodle implements Context-Based Access Control composed of three layers:
1. **Capability:** A discrete permission to perform a specific action (e.g., `moodle/course:update`, `mod/quiz:attempt`).
2. **Role:** A collection of capabilities configured as Allow, Prevent, or Prohibit (e.g., `Student`, `Editing Teacher`, `Manager`).
3. **Context:** The hierarchical scope where a role is assigned to a user (`System` ➔ `Category` ➔ `Course` ➔ `Activity/Module` ➔ `Block`).  
This hierarchy allows a user to hold different permissions across different courses (e.g., `Teacher` in Course A, but only `Student` in Course B).

---

## 🔍 4. Verification Evidence & Database Inspection

### 4.1 Course Record
```sql
SELECT id, fullname, shortname, category FROM m_course WHERE id = 2;
```
* **ID:** `2` | **Fullname:** `Full Stack Web Development` | **Shortname:** `CS-FSD-W7` | **Category:** `1`

### 4.2 User Accounts
```sql
SELECT id, username, firstname, lastname, email FROM m_user WHERE id IN (3, 4);
```
* **Teacher:** `ID 3` | `teacher1` | `Vikram Sharma` | `teacher1@cynaris.local`
* **Student:** `ID 4` | `student1` | `Sachidananda Nayak` | `student1@cynaris.local`

### 4.3 Active Course Enrolment
```sql
SELECT e.id AS enrolid, e.courseid, e.enrol, ue.userid, u.username, ue.status 
FROM m_enrol e 
JOIN m_user_enrolments ue ON ue.enrolid = e.id 
JOIN m_user u ON u.id = ue.userid 
WHERE e.courseid = 2;
```
* **Enrolment Method:** `manual`
* **Enrolled Users:** `teacher1` (`Editing Teacher`), `student1` (`Student`)
* **Status:** `0` (Active)

---

## 📸 5. Dashboard Screenshot Guide

To capture the required Moodle dashboard evidence for your submission portal:
1. Open your browser and navigate to: **[http://localhost:8001/login/index.php](http://localhost:8001/login/index.php)**
2. Log in using the admin account:
   * **Username:** `admin`
   * **Password:** `test`
3. In the top navigation bar, click **"My courses"** or **"Site administration" ➔ "Courses" ➔ "Manage courses and categories"**.
4. You will see **Full Stack Web Development (`CS-FSD-W7`)**. Click on the course and select **"Participants"** to display `Vikram Sharma (teacher1)` and `Sachidananda Nayak (student1)`.
5. Capture a full-screen screenshot and save it to `screenshots/moodle_w7d1_dashboard.png`.
