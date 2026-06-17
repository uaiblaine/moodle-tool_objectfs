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

/**
 * Tests for the plugin database schema.
 *
 * @coversNothing
 * @package   tool_objectfs
 * @copyright Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class db_test extends \advanced_testcase {
    public function test_objects_location_index_exists(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('tool_objectfs_objects');
        $index = new \xmldb_index('toolobjeobje_locfiltim_ix', XMLDB_INDEX_NOTUNIQUE, ['location', 'filesize', 'timeduplicated']);
        $this->assertTrue($dbman->index_exists($table, $index));
    }
}
