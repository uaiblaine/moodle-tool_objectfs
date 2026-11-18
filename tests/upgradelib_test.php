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
require_once($CFG->dirroot . '/admin/tool/objectfs/db/upgradelib.php');

/**
 * The fork's catch-up for upstream's location-bit step (FORK.md P25).
 *
 * The branch that adds the columns is not exercised here: dropping columns of a plugin table inside
 * PHPUnit would leave the schema of every later test in doubt. These tests pin the decision that
 * guards a site where upstream's step already ran.
 *
 * @covers ::tool_objectfs_upgrade_add_location_bits
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upgradelib_test extends \advanced_testcase {
    /**
     * Number of queued location-bit migration tasks.
     *
     * @return int
     */
    private function queued_migrations(): int {
        return count(\core\task\manager::get_adhoc_tasks(\tool_objectfs\task\migrate_object_locations::class));
    }

    /**
     * A site where the migration state exists is left alone: no new task, no reset progress.
     */
    public function test_existing_migration_state_is_kept(): void {
        $this->resetAfterTest();
        set_config('locationbitsmigrationcomplete', 1, 'tool_objectfs');
        set_config('locationbitsmigrationdonesofar', 42, 'tool_objectfs');

        tool_objectfs_upgrade_add_location_bits();

        $this->assertSame(0, $this->queued_migrations());
        $this->assertEquals(1, get_config('tool_objectfs', 'locationbitsmigrationcomplete'));
        $this->assertEquals(42, get_config('tool_objectfs', 'locationbitsmigrationdonesofar'));
    }

    /**
     * A site that never started the migration gets its state and the task.
     */
    public function test_missing_migration_state_starts_the_migration(): void {
        global $DB;
        $this->resetAfterTest();
        unset_config('locationbitsmigrationcomplete', 'tool_objectfs');
        $DB->insert_record('tool_objectfs_objects', (object)[
            'contenthash' => sha1('upgradelib'),
            'location' => OBJECT_LOCATION_LOCAL,
            'filesize' => 10,
            'timeduplicated' => time(),
        ]);

        tool_objectfs_upgrade_add_location_bits();

        $this->assertSame(1, $this->queued_migrations());
        $this->assertEquals(0, get_config('tool_objectfs', 'locationbitsmigrationcomplete'));
        $this->assertEquals(
            $DB->count_records('tool_objectfs_objects'),
            get_config('tool_objectfs', 'locationbitsmigrationtotal')
        );
    }
}
