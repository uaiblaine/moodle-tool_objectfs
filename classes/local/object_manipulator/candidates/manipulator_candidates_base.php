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
 * Class candidates_factory
 * @package tool_objectfs
 * @author Gleimer Mora <gleimermora@catalyst-au.net>
 * @copyright Catalyst IT
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\local\object_manipulator\candidates;

use coding_exception;
use dml_exception;
use stdClass;

/**
 * manipulator_candidates_base
 */
abstract class manipulator_candidates_base implements manipulator_candidates {
    /** @var stdClass $config */
    protected $config;

    /** @var candidates_cursor|null Keyset cursor bound by the last get() call, when paginated. */
    protected $cursor = null;

    /** @var array|null Records returned by the last keyset-paginated get() call. */
    protected $lastbatch = null;

    /** @var int Limit used by the last keyset-paginated get() call. */
    protected $lastlimit = 0;

    /**
     * manipulator_candidates_base constructor.
     * @param stdClass $config
     */
    public function __construct(stdClass $config) {
        $this->config = $config;
    }

    /**
     * get_query_name
     * @return string
     */
    public function get_query_name() {
        return $this->queryname;
    }

    /**
     * get
     * @return array
     * @throws dml_exception
     */
    public function get() {
        return $this->query($this->get_candidates_sql_params(), $this->config->batchsize);
    }

    /**
     * Runs the candidate SQL with an offset-0 limit.
     *
     * Shared by get() and by keyset-paginated subclasses so the single DB read
     * site stays in one place.
     *
     * @param array $params Query parameters.
     * @param int $limit Maximum number of rows to fetch.
     * @return array
     * @throws dml_exception
     */
    protected function query(array $params, int $limit) {
        global $DB;
        return $DB->get_records_sql($this->get_candidates_sql(), $params, 0, $limit);
    }

    /**
     * Persists the keyset cursor once the fate of the last fetched batch is known.
     *
     * The cursor only moves past candidates the manipulator actually reached,
     * so a batch aborted by a storage outage or the task time cap is retried
     * on the next run instead of being skipped until the pass resets:
     * - whole batch reached and full: advance to the batch's last key;
     * - whole batch reached but short (or unlimited): pass completed, reset;
     * - batch partially reached: advance to the last reached key;
     * - nothing reached: leave the cursor unchanged.
     *
     * @param int $processedcount Number of records the manipulator reached.
     * @return void
     */
    public function commit_cursor(int $processedcount): void {
        if ($this->cursor === null || $this->lastbatch === null) {
            return;
        }
        $records = array_values($this->lastbatch);
        $count = count($records);
        $this->lastbatch = null;

        if ($processedcount >= $count) {
            if ($this->lastlimit > 0 && $count >= $this->lastlimit) {
                $this->cursor->set($this->cursor_key(end($records)));
            } else {
                $this->cursor->reset();
            }
        } else if ($processedcount > 0) {
            $this->cursor->set($this->cursor_key($records[$processedcount - 1]));
        }
    }

    /**
     * Returns the keyset cursor key of a candidate record.
     *
     * Only called for finders that bind a cursor in get().
     *
     * @param stdClass $record Candidate record.
     * @return mixed
     * @throws coding_exception When the finder declares no cursor key.
     */
    protected function cursor_key(stdClass $record) {
        throw new coding_exception('cursor_key() must be overridden by keyset-paginated candidate finders.');
    }
}
