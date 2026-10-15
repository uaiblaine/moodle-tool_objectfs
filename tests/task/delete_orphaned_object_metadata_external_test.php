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

namespace tool_objectfs\task;

use tool_objectfs\tests\testcase;

/**
 * Tests for the external branch of the orphan removal: which deleteexternal modes remove the remote object.
 *
 * @covers \tool_objectfs\task\delete_orphaned_object_metadata
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class delete_orphaned_object_metadata_external_test extends testcase {
    /**
     * Create a remote object, drop its files rows and mark it orphaned at the given time.
     *
     * @param string $content File content.
     * @param int $orphanedat Value of timeduplicated, the orphaning time.
     * @return string Content hash.
     */
    private function create_orphaned_remote_object(string $content, int $orphanedat): string {
        global $DB;
        $object = $this->create_remote_object($content);
        $DB->delete_records('files', ['contenthash' => $object->contenthash]);
        $DB->set_field('tool_objectfs_objects', 'location', OBJECT_LOCATION_ORPHANED, ['id' => $object->id]);
        $DB->set_field('tool_objectfs_objects', 'timeduplicated', $orphanedat, ['id' => $object->id]);
        return $object->contenthash;
    }

    /**
     * Run the task with a 30 day grace period and the given deleteexternal mode.
     *
     * @param int $mode One of the TOOL_OBJECTFS_DELETE_EXTERNAL_* constants.
     */
    private function run_task(int $mode): void {
        global $CFG;
        // The base class forces deleteexternal off, and a forced setting wins over set_config().
        $CFG->forced_plugin_settings['tool_objectfs']['deleteexternal'] = $mode;
        set_config('maxorphanedage', 30 * DAYSECS, 'tool_objectfs');
        set_config('filesystem', '\\tool_objectfs\\tests\\test_file_system', 'tool_objectfs');
        ob_start();
        (new delete_orphaned_object_metadata())->execute();
        ob_end_clean();
    }

    /**
     * In full deletion mode an orphan past the grace period loses its remote object too (upstream #693);
     * an orphan inside the grace period keeps both (control).
     */
    public function test_full_mode_removes_the_remote_object_of_an_expired_orphan(): void {
        global $DB;
        $expired = $this->create_orphaned_remote_object('expired orphan', time() - (31 * DAYSECS));
        $recent = $this->create_orphaned_remote_object('recent orphan', time() - DAYSECS);
        $this->assertTrue($this->is_externally_readable_by_hash($expired));

        $this->run_task(TOOL_OBJECTFS_DELETE_EXTERNAL_FULL);

        $this->assertFalse($this->is_externally_readable_by_hash($expired));
        $this->assertFalse($DB->record_exists('tool_objectfs_objects', ['contenthash' => $expired]));
        $this->assertTrue($this->is_externally_readable_by_hash($recent));
        $this->assertTrue($DB->record_exists('tool_objectfs_objects', ['contenthash' => $recent]));
    }

    /**
     * With external deletion off the remote object stays, only the metadata goes.
     */
    public function test_no_external_deletion_keeps_the_remote_object(): void {
        global $DB;
        $expired = $this->create_orphaned_remote_object('expired orphan kept remotely', time() - (31 * DAYSECS));

        $this->run_task(TOOL_OBJECTFS_DELETE_EXTERNAL_NO);

        $this->assertTrue($this->is_externally_readable_by_hash($expired));
        $this->assertFalse($DB->record_exists('tool_objectfs_objects', ['contenthash' => $expired]));
    }
}
