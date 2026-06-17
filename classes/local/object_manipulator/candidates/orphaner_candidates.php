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
 * Class orphaner_candidates
 * @package tool_objectfs
 * @author Nathan Mares <ngmares@gmail.com>
 * @copyright Catalyst IT
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\local\object_manipulator\candidates;

/**
 * orphaner_candidates
 */
class orphaner_candidates extends manipulator_candidates_base {
    /**
     * queryname
     * @var string
     */
    protected $queryname = 'get_orphan_candidates';

    /**
     * get_candidates_sql
     * @return string
     */
    public function get_candidates_sql() {
        return 'SELECT o.id, o.contenthash, o.location
                  FROM {tool_objectfs_objects} o
             LEFT JOIN {files} f ON o.contenthash = f.contenthash
                 WHERE f.id is null
                   AND o.location != :location
                   AND o.id > :cursor
              ORDER BY o.id ASC';
    }

    /**
     * get_candidates_sql_params
     * @return array
     */
    public function get_candidates_sql_params() {
        return [
          'location' => OBJECT_LOCATION_ORPHANED,
          'cursor' => 0,
        ];
    }

    /**
     * Fetches one keyset-paginated batch and advances the persisted cursor.
     *
     * Seeks {tool_objectfs_objects} by primary key from the stored cursor, so
     * each run is a bounded PK range instead of a full table scan. The cursor
     * resets at the end of a pass so time-capped rows are rediscovered.
     *
     * @return array
     */
    public function get() {
        $cursor = new candidates_cursor('orphaner_lastid', '0');
        $params = $this->get_candidates_sql_params();
        $params['cursor'] = (int) $cursor->get();
        $limit = $this->config->batchsize;

        $records = $this->query($params, $limit);

        if (count($records) < $limit) {
            $cursor->reset();
        } else {
            $last = end($records);
            $cursor->set($last->id);
        }

        return $records;
    }
}
