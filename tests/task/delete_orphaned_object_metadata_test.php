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

use tool_objectfs\local\manager;

/**
 * Tests for the orphan grace period: its start and the metadata-only removal branch.
 *
 * @covers \tool_objectfs\task\delete_orphaned_object_metadata
 * @covers \tool_objectfs\local\manager::upsert_object
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class delete_orphaned_object_metadata_test extends \advanced_testcase {
    /**
     * Insert an objects row.
     *
     * @param string $contenthash Content hash.
     * @param int $location Object location.
     * @param int $timeduplicated Value of timeduplicated.
     * @return int Row id.
     */
    private function insert_object(string $contenthash, int $location, int $timeduplicated): int {
        global $DB;
        return $DB->insert_record('tool_objectfs_objects', (object)[
            'contenthash' => $contenthash,
            'location' => $location,
            'timeduplicated' => $timeduplicated,
            'filesize' => 10,
        ]);
    }

    /**
     * Run the task with metadata-only removal and a 30 day grace period.
     */
    private function run_task(): void {
        set_config('maxorphanedage', 30 * DAYSECS, 'tool_objectfs');
        set_config('deleteexternal', TOOL_OBJECTFS_DELETE_EXTERNAL_NO, 'tool_objectfs');
        ob_start();
        (new delete_orphaned_object_metadata())->execute();
        ob_end_clean();
    }

    /**
     * A row duplicated long ago and orphaned now keeps its metadata; the age counts from the orphaning.
     */
    public function test_orphaning_restamps_timeduplicated_and_grace_counts_from_it(): void {
        global $DB;
        $this->resetAfterTest();

        $hash = sha1('duplicated long ago');
        $this->insert_object($hash, OBJECT_LOCATION_DUPLICATED, time() - (90 * DAYSECS));

        manager::update_object_by_hash($hash, OBJECT_LOCATION_ORPHANED);

        $row = $DB->get_record('tool_objectfs_objects', ['contenthash' => $hash], '*', MUST_EXIST);
        $this->assertEquals(OBJECT_LOCATION_ORPHANED, $row->location);
        $this->assertGreaterThan(time() - 60, (int)$row->timeduplicated);

        $this->run_task();
        $this->assertTrue($DB->record_exists('tool_objectfs_objects', ['contenthash' => $hash]));
    }

    /**
     * Upserting an already orphaned row keeps the original orphaning time.
     */
    public function test_reorphaning_keeps_the_original_orphaning_time(): void {
        global $DB;
        $this->resetAfterTest();

        $hash = sha1('already orphaned');
        $orphanedat = time() - (40 * DAYSECS);
        $this->insert_object($hash, OBJECT_LOCATION_ORPHANED, $orphanedat);

        $row = $DB->get_record('tool_objectfs_objects', ['contenthash' => $hash], '*', MUST_EXIST);
        manager::upsert_object($row, OBJECT_LOCATION_ORPHANED);

        $this->assertEquals($orphanedat, $DB->get_field('tool_objectfs_objects', 'timeduplicated', ['contenthash' => $hash]));
    }

    /**
     * Control: a row orphaned more than the grace period ago is removed.
     */
    public function test_row_orphaned_beyond_the_grace_period_is_removed(): void {
        global $DB;
        $this->resetAfterTest();

        $hash = sha1('orphaned long ago');
        $this->insert_object($hash, OBJECT_LOCATION_ORPHANED, time() - (31 * DAYSECS));

        $this->run_task();
        $this->assertFalse($DB->record_exists('tool_objectfs_objects', ['contenthash' => $hash]));
    }

    /**
     * The metadata-only branch spares a row whose content hash is referenced by a files row.
     */
    public function test_metadata_branch_spares_a_row_with_a_files_row(): void {
        global $DB;
        $this->resetAfterTest();

        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'tool_objectfs',
            'filearea' => 'unittest',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'kept.txt',
        ], 'content still referenced');
        $hash = $file->get_contenthash();
        $DB->delete_records('tool_objectfs_objects', ['contenthash' => $hash]);
        $this->insert_object($hash, OBJECT_LOCATION_ORPHANED, time() - (90 * DAYSECS));
        $orphanhash = sha1('no files row');
        $this->insert_object($orphanhash, OBJECT_LOCATION_ORPHANED, time() - (90 * DAYSECS));

        $this->run_task();

        $this->assertTrue($DB->record_exists('tool_objectfs_objects', ['contenthash' => $hash]));
        $this->assertFalse($DB->record_exists('tool_objectfs_objects', ['contenthash' => $orphanhash]));
    }
}
