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
     * Fetches one keyset-paginated batch; commit_cursor() persists the cursor
     * once the batch outcome is known.
     *
     * Seeks {tool_objectfs_objects} by primary key from the stored cursor. The
     * seek only bounds the scan while at least a full batch of candidates
     * remains ahead of the cursor; when candidates are sparse the anti-join
     * still scans to the end of the table before returning a short batch,
     * which completes the pass so new orphans are rediscovered on the next one.
     *
     * @return array
     */
    public function get() {
        $this->cursor = new candidates_cursor('orphaner_lastid', '0');
        $params = $this->get_candidates_sql_params();
        $params['cursor'] = (int) $this->cursor->get();
        $this->lastlimit = (int) $this->config->batchsize;
        $this->lastbatch = $this->query($params, $this->lastlimit);

        return $this->lastbatch;
    }

    /**
     * Returns the keyset cursor key of a candidate record.
     *
     * @param \stdClass $record Candidate record.
     * @return int
     */
    protected function cursor_key(\stdClass $record) {
        return $record->id;
    }
}
