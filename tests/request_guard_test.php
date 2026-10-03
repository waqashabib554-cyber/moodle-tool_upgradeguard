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
 * Tests for the request level guards.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard;

use basic_testcase;
use moodle_exception;
use tool_upgradeguard\local\request_guard;

/**
 * Tests that state changing actions cannot be reached with a GET request.
 *
 * This covers the delete action of the dashboard: core's data_submitted() answers
 * false (never null) when $_POST is empty, so the guard has to be compared
 * against false. A "=== null" comparison silently never fires, which would let a
 * link delete a stored scan.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_upgradeguard\local\request_guard
 */
final class request_guard_test extends basic_testcase {
    /** @var array The $_POST superglobal as it was before the test. */
    private array $savedpost = [];

    /** @var array The $_GET superglobal as it was before the test. */
    private array $savedget = [];

    /**
     * Remember the request superglobals.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->savedpost = $_POST;
        $this->savedget = $_GET;
    }

    /**
     * Restore the request superglobals.
     *
     * @return void
     */
    protected function tearDown(): void {
        $_POST = $this->savedpost;
        $_GET = $this->savedget;
        parent::tearDown();
    }

    /**
     * A GET request cannot delete anything, even with a valid sesskey.
     *
     * @covers \tool_upgradeguard\local\request_guard::require_post
     */
    public function test_get_request_is_rejected(): void {
        $_POST = [];
        $_GET = ['action' => 'deletescan', 'scanid' => 1, 'sesskey' => 'whatever'];

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_requirepost', 'tool_upgradeguard'));

        request_guard::require_post();
    }

    /**
     * A POST request is accepted.
     *
     * @covers \tool_upgradeguard\local\request_guard::require_post
     */
    public function test_post_request_is_allowed(): void {
        $_POST = ['action' => 'deletescan', 'scanid' => 1, 'sesskey' => 'whatever'];
        $_GET = [];

        $this->expectNotToPerformAssertions();

        request_guard::require_post();
    }

    /**
     * A POST body without fields is not a usable submission either.
     *
     * @covers \tool_upgradeguard\local\request_guard::require_post
     */
    public function test_empty_post_is_rejected(): void {
        $_POST = [];
        $_GET = [];

        $this->expectException(moodle_exception::class);

        request_guard::require_post();
    }
}
