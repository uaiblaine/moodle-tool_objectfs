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

namespace tool_objectfs\local\object_manipulator\candidates;

/**
 * Tests for the keyset candidates cursor.
 *
 * @covers \tool_objectfs\local\object_manipulator\candidates\candidates_cursor
 * @package   tool_objectfs
 * @copyright Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class candidates_cursor_test extends \advanced_testcase {
    public function test_get_returns_sentinel_when_unset(): void {
        $this->resetAfterTest();
        $this->assertSame('', (new candidates_cursor('cursor_unset_str', ''))->get());
        $this->assertSame('0', (new candidates_cursor('cursor_unset_int', '0'))->get());
    }

    public function test_set_and_get_round_trip(): void {
        $this->resetAfterTest();
        $cursor = new candidates_cursor('cursor_round_trip', '');
        $cursor->set('abc123');
        $this->assertSame('abc123', $cursor->get());
    }

    public function test_reset_returns_to_sentinel(): void {
        $this->resetAfterTest();
        $cursor = new candidates_cursor('cursor_reset', '0');
        $cursor->set('999');
        $this->assertSame('999', $cursor->get());
        $cursor->reset();
        $this->assertSame('0', $cursor->get());
    }

    public function test_value_is_stored_under_tool_objectfs_component(): void {
        $this->resetAfterTest();
        (new candidates_cursor('cursor_component', ''))->set('xyz');
        $this->assertSame('xyz', get_config('tool_objectfs', 'cursor_component'));
    }
}
