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

namespace tool_objectfs;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/admin/tool/objectfs/lib.php');

/**
 * Tests for lib.php functions.
 *
 * @covers ::tool_objectfs_status_checks
 * @package   tool_objectfs
 * @copyright Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lib_test extends \advanced_testcase {
    /**
     * Returns the class names of the checks returned by tool_objectfs_status_checks().
     *
     * @return array
     */
    private function get_status_check_classes(): array {
        return array_map('get_class', tool_objectfs_status_checks());
    }

    public function test_status_checks_without_tagging(): void {
        $this->resetAfterTest();
        set_config('taggingenabled', '0', 'tool_objectfs');

        $classes = $this->get_status_check_classes();
        $this->assertContains(check\token_expiry::class, $classes);
        $this->assertContains(check\connection::class, $classes);
        $this->assertNotContains(check\tagging_status::class, $classes);
        $this->assertNotContains(check\tagging_sync_status::class, $classes);
        $this->assertNotContains(check\tagging_migration_status::class, $classes);
    }

    public function test_status_checks_with_tagging_enabled_registers_all_tagging_checks(): void {
        $this->resetAfterTest();
        set_config('taggingenabled', '1', 'tool_objectfs');

        $classes = $this->get_status_check_classes();
        $this->assertContains(check\token_expiry::class, $classes);
        $this->assertContains(check\connection::class, $classes);
        $this->assertContains(check\tagging_status::class, $classes);
        $this->assertContains(check\tagging_sync_status::class, $classes);
        $this->assertContains(check\tagging_migration_status::class, $classes);
    }

    public function test_status_checks_with_proxy_range_requests(): void {
        $this->resetAfterTest();
        set_config('proxyrangerequests', '1', 'tool_objectfs');
        $this->assertContains(check\proxy_range_request::class, $this->get_status_check_classes());

        set_config('proxyrangerequests', '0', 'tool_objectfs');
        $this->assertNotContains(check\proxy_range_request::class, $this->get_status_check_classes());
    }
}
