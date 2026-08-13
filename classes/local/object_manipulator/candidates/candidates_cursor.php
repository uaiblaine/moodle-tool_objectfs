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
 * Persisted keyset (seek) cursor for candidate queries.
 *
 * @package   tool_objectfs
 * @copyright Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\local\object_manipulator\candidates;

/**
 * Persisted keyset (seek) cursor for candidate queries.
 *
 * Mirrors the resume pattern already used by the reconcile_filedir task: the
 * cursor is read at the start of a batch, advanced to the last key the
 * manipulator actually reached once the batch outcome is known, and reset to a
 * start sentinel once a full pass completes so newly added rows are
 * rediscovered on the next pass. The value lives in plugin config (no extra
 * table), just like 'reconcile_file_lasthash'.
 */
class candidates_cursor {
    /** @var string Plugin config component used for storage. */
    private const COMPONENT = 'tool_objectfs';

    /** @var string Config key holding this cursor's value. */
    private $configkey;

    /** @var mixed Value representing the start of the table. */
    private $startsentinel;

    /**
     * Constructor.
     *
     * @param string $configkey Config key under the tool_objectfs component.
     * @param mixed $startsentinel Value meaning "start of the table" (e.g. '' or 0).
     */
    public function __construct(string $configkey, $startsentinel) {
        $this->configkey = $configkey;
        $this->startsentinel = $startsentinel;
    }

    /**
     * Returns the current cursor value, or the start sentinel when unset.
     *
     * @return mixed
     */
    public function get() {
        $value = get_config(self::COMPONENT, $this->configkey);
        if ($value === false || $value === null || $value === '') {
            return $this->startsentinel;
        }
        return $value;
    }

    /**
     * Persists the cursor to the last fetched key of the current batch.
     *
     * @param mixed $value
     * @return void
     */
    public function set($value): void {
        set_config($this->configkey, $value, self::COMPONENT);
    }

    /**
     * Resets the cursor to the start sentinel (end of a full pass).
     *
     * @return void
     */
    public function reset(): void {
        set_config($this->configkey, $this->startsentinel, self::COMPONENT);
    }
}
