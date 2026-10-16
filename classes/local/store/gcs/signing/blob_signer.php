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
 * Signer of raw bytes with a service account's key.
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\local\store\gcs\signing;

/**
 * Signs bytes as RSASSA-PKCS1-v1_5 with SHA-256 under a service account's key.
 */
interface blob_signer {
    /**
     * Raw signature of the data.
     *
     * @param string $data
     * @return string Raw signature bytes.
     * @throws \moodle_exception When signing fails.
     */
    public function sign(string $data): string;

    /**
     * The email of the service account whose key signs.
     *
     * @return string
     * @throws \moodle_exception When it cannot be determined.
     */
    public function get_email(): string;
}
