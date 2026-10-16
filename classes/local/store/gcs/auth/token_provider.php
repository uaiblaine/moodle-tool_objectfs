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
 * Source of OAuth 2.0 access tokens for Cloud Storage.
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\local\store\gcs\auth;

/**
 * Source of OAuth 2.0 access tokens for one service account.
 */
interface token_provider {
    /**
     * A valid access token.
     *
     * @return string
     * @throws \moodle_exception When no token can be obtained.
     */
    public function get_token(): string;

    /**
     * The service account's email address.
     *
     * @return string
     * @throws \moodle_exception When it cannot be determined.
     */
    public function get_email(): string;
}
