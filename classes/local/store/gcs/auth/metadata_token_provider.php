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
 * Tokens from the metadata server of Compute Engine, GKE and Cloud Run.
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\local\store\gcs\auth;

/**
 * Tokens of the service account attached to the VM, GKE workload or Cloud Run service: no key is stored.
 */
class metadata_token_provider extends cached_token_provider {
    /** @var string Metadata server path of the default service account. */
    const BASE_URL = 'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/';

    /** @var string|null The service account email, once known. */
    private ?string $email;

    /**
     * Constructor.
     *
     * @param string $email The service account email when configured, '' to ask the metadata server.
     */
    public function __construct(string $email = '') {
        $this->email = $email === '' ? null : $email;
    }

    /**
     * The default service account's cache identity.
     *
     * @return string
     */
    protected function get_identity(): string {
        return 'metadata';
    }

    /**
     * Fetches a token from the metadata server.
     *
     * @return array
     * @throws \moodle_exception
     */
    protected function fetch_token(): array {
        return self::parse_token_response($this->request('token'));
    }

    /**
     * The attached service account's email.
     *
     * @return string
     * @throws \moodle_exception When the metadata server does not give a valid address.
     */
    public function get_email(): string {
        if ($this->email === null) {
            $email = trim($this->request('email'));
            if (!validate_email($email)) {
                $debuginfo = 'no service account email';
                throw new \moodle_exception('settings:gcs:autherror', 'tool_objectfs', '', 'metadata server', $debuginfo);
            }
            $this->email = $email;
        }
        return $this->email;
    }

    /**
     * GET on the metadata server.
     *
     * The server is link-local, which core's curl security helper blocks and no proxy can reach; the URL is a
     * class constant plus a fixed path, so nothing an admin or a user sets reaches this request.
     *
     * @param string $path Path below BASE_URL.
     * @return string Response body.
     * @throws \moodle_exception When the server cannot be reached.
     */
    protected function request(string $path): string {
        global $CFG;
        // Cron tasks reach this without get_file_storage(), so filelib is not loaded.
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl(['ignoresecurity' => true, 'proxy' => false]);
        $curl->setHeader(['Metadata-Flavor: Google']);
        $curl->setopt(['CURLOPT_CONNECTTIMEOUT' => 2, 'CURLOPT_TIMEOUT' => 5]);
        $body = $curl->get(self::BASE_URL . $path);
        if ($curl->get_errno()) {
            throw new \moodle_exception('settings:gcs:autherror', 'tool_objectfs', '', 'metadata server', $curl->error);
        }
        return (string)$body;
    }
}
