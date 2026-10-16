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
 * Regression and validation tests for gradereport_user UI templates and rendering.
 *
 * Validates:
 * 1. user_report_category_content quotes data-categoryid attribute properly.
 * 2. zero_state resolves ARIA accessibility conflict (alt="" with aria-hidden="true").
 * 3. zero_state adheres to heading hierarchy with <h2 class="h3">.
 * 4. user_navigation example context JSON has no duplicate keys.
 *
 * @package    gradereport_user
 * @copyright  2026 Sachidananda Nayak (Cynaris Solutions)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$passed = 0;
$failed = 0;

function assert_check(string $title, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] $title\n";
    } else {
        $failed++;
        echo "  [FAIL] $title" . ($details ? ": $details" : "") . "\n";
    }
}

echo "\n=======================================================\n";
echo "  CYNARIS W7D5 GRADEREPORT_USER UI REGRESSION TEST SUITE\n";
echo "=======================================================\n\n";

global $OUTPUT, $PAGE;
$PAGE->set_context(\context_system::instance());

// -------------------------------------------------------------
// Test 1: Category Toggle Content Template Rendering & Quoting
// -------------------------------------------------------------
echo "--- Suite 1: Category Content Template Attribute Quoting ---\n";
$catcontext = [
    'categoryid' => '42',
    'categoryname' => 'Assignments & Quizzes',
    'arrow' => 'right'
];
$renderedcat = $OUTPUT->render_from_template('gradereport_user/user_report_category_content', $catcontext);

// Check that data-categoryid is strictly quoted with double quotes
$hasquotedcategoryid = (strpos($renderedcat, 'data-categoryid="42"') !== false);
$hasunquotedcategoryid = (preg_match('/data-categoryid=42(\s|>)/', $renderedcat) === 1);

assert_check("data-categoryid is quoted with double quotes", $hasquotedcategoryid, "Found unquoted or missing attribute in: " . trim($renderedcat));
assert_check("No unquoted data-categoryid attribute exists", !$hasunquotedcategoryid, "Unquoted attribute pattern matched in output");
assert_check("Category name rendered correctly", strpos($renderedcat, 'Assignments &amp; Quizzes') !== false || strpos($renderedcat, 'Assignments & Quizzes') !== false);
assert_check("Toggle button has toggle-category class", strpos($renderedcat, 'class="btn btn-icon me-1 toggle-category"') !== false);
assert_check("Toggle button has aria-expanded attribute", strpos($renderedcat, 'aria-expanded="true"') !== false);

// -------------------------------------------------------------
// Test 2: Zero State Accessibility and Heading Hierarchy
// -------------------------------------------------------------
echo "\n--- Suite 2: Zero State ARIA Conformance & Heading Hierarchy ---\n";
$zerocontext = [
    'imglink' => 'http://localhost:8001/theme/image.php/boost/gradereport_user/123/zero_state'
];
$renderedzero = $OUTPUT->render_from_template('gradereport_user/zero_state', $zerocontext);

// An aria-hidden="true" image must have alt="" (empty) to avoid conflicting accessible name
$hasproperdecorativealt = (strpos($renderedzero, 'alt="" aria-hidden="true"') !== false || strpos($renderedzero, 'aria-hidden="true" alt=""') !== false);
$hasconflictingalt = (preg_match('/<img[^>]+aria-hidden="true"[^>]+alt="[^\"]+"/', $renderedzero) === 1 ||
                      preg_match('/<img[^>]+alt="[^\"]+"[^>]+aria-hidden="true"/', $renderedzero) === 1);

assert_check("Zero state image has alt=\"\" when aria-hidden=\"true\"", $hasproperdecorativealt, "Image missing empty alt or has conflicting alt text");
assert_check("No conflicting non-empty alt with aria-hidden=\"true\"", !$hasconflictingalt, "Found conflicting non-empty alt on decorative image");

// Heading must use <h2 class="h3"> for proper WCAG heading hierarchy
$hash2heading = (strpos($renderedzero, '<h2 class="h3">') !== false);
$hasrawh3heading = (strpos($renderedzero, '<h3>') !== false);

assert_check("Zero state uses <h2 class=\"h3\"> for WCAG heading hierarchy", $hash2heading, "Heading is not <h2 class=\"h3\">");
assert_check("Zero state does not skip directly to raw <h3>", !$hasrawh3heading, "Found raw <h3> heading skipping document outline level");

// -------------------------------------------------------------
// Test 3: user_navigation.mustache Example Context JSON Validation
// -------------------------------------------------------------
echo "\n--- Suite 3: Template Documentation Example Context Syntax ---\n";
$navtemplatepath = $CFG->dirroot . '/grade/report/user/templates/user_navigation.mustache';
$navcontent = file_get_contents($navtemplatepath);

// Extract the example context JSON between Example context (json): and }}
preg_match('/Example context \(json\):\s*(\{.*?\})\s*\}\}/s', $navcontent, $matches);
$jsonstr = $matches[1] ?? '';

// Check if raw JSON string has duplicate previoususer keys
$duplicateprevious = (substr_count($jsonstr, '"previoususer"') > 1);
assert_check("user_navigation example JSON has no duplicate 'previoususer' key", !$duplicateprevious, "Found multiple 'previoususer' keys in example JSON");

$decoded = json_decode($jsonstr, true);
assert_check("user_navigation example JSON decodes without syntax error", json_last_error() === JSON_ERROR_NONE, json_last_error_msg());
if ($decoded) {
    assert_check("user_navigation example context defines previoususer", isset($decoded['previoususer']));
    assert_check("user_navigation example context defines nextuser", isset($decoded['nextuser']), "Missing 'nextuser' in example context");
}

// -------------------------------------------------------------
// Summary
// -------------------------------------------------------------
echo "\n=======================================================\n";
echo "  TEST SUMMARY: Total: " . ($passed + $failed) . " | Passed: $passed | Failed: $failed\n";
echo "=======================================================\n\n";

exit($failed === 0 ? 0 : 1);
