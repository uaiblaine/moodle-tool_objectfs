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
 * Upgrade helpers of the fork (FORK.md).
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the location-bit columns and start their migration where upstream's 2026102100 step never ran.
 *
 * The fork's version passed 2026102100 before upstream's step reached it, so a site upgraded from the
 * fork's 2026111000 skips that step. This repeats it, idempotently: columns and index only when missing,
 * and the migration state and task only when a column was added or no migration was ever started, so a
 * site where upstream's step ran (or a fresh install, whose install.php marks the migration complete)
 * is left as it is. {@see xmldb_tool_objectfs_upgrade()} step 2026102100 is the code this must stay in
 * step with.
 *
 * @return void
 */
function tool_objectfs_upgrade_add_location_bits(): void {
    global $DB;

    $dbman = $DB->get_manager();
    $table = new xmldb_table('tool_objectfs_objects');

    $added = false;
    $fields = [
        new xmldb_field('in_filedir', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'tagslastpushed'),
        new xmldb_field('in_mdl_files', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'in_filedir'),
        new xmldb_field('in_remote', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'in_mdl_files'),
    ];
    foreach ($fields as $field) {
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            $added = true;
        }
    }

    $index = new xmldb_index(
        'ix_location_bits',
        XMLDB_INDEX_NOTUNIQUE,
        ['in_filedir', 'in_mdl_files', 'in_remote', 'filesize']
    );
    if (!$dbman->index_exists($table, $index)) {
        $dbman->add_index($table, $index);
    }

    if (!$added && get_config('tool_objectfs', 'locationbitsmigrationcomplete') !== false) {
        return;
    }

    set_config('locationbitsmigrationcomplete', 0, 'tool_objectfs');
    set_config('locationbitsmigrationlastid', 0, 'tool_objectfs');
    set_config('locationbitsmigrationtotal', $DB->count_records('tool_objectfs_objects'), 'tool_objectfs');
    set_config('locationbitsmigrationdonesofar', 0, 'tool_objectfs');
    \core\task\manager::queue_adhoc_task(new \tool_objectfs\task\migrate_object_locations(), true);
}
