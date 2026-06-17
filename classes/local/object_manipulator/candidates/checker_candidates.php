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
 * Class checker_candidates
 * @package tool_objectfs
 * @author Gleimer Mora <gleimermora@catalyst-au.net>
 * @copyright Catalyst IT
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\local\object_manipulator\candidates;

/**
 * chcker_candiates
 */
class checker_candidates extends manipulator_candidates_base {
    /**
     * queryname
     * @var string
     */
    protected $queryname = 'get_check_candidates';

    /**
     * get_candiates_sql
     * @return string
     */
    public function get_candidates_sql() {
        return 'SELECT f.contenthash
                  FROM {files} f
             LEFT JOIN {tool_objectfs_objects} o ON f.contenthash = o.contenthash
                 WHERE f.filesize > 0
                   AND o.location is NULL
                   AND f.contenthash > :cursor
              GROUP BY f.contenthash
              ORDER BY f.contenthash ASC';
    }

    /**
     * get_candidates_sql_params
     * @return array
     */
    public function get_candidates_sql_params() {
        return ['cursor' => ''];
    }

    /**
     * Fetches one keyset-paginated batch and advances the persisted cursor.
     *
     * Scans {files} in contenthash order from the stored cursor, so each run is a
     * bounded index range instead of a full table scan. When the batch is short
     * (end of the table), the cursor resets so newly added or time-capped files
     * are rediscovered on the next pass.
     *
     * @return array
     */
    public function get() {
        $cursor = new candidates_cursor('checker_lasthash', '');
        $params = $this->get_candidates_sql_params();
        $params['cursor'] = $cursor->get();
        $limit = $this->config->batchsize;

        $records = $this->query($params, $limit);

        if (count($records) < $limit) {
            $cursor->reset();
        } else {
            $last = end($records);
            $cursor->set($last->contenthash);
        }

        return $records;
    }
}
