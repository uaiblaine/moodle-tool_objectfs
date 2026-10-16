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
 * Write-and-verify probe of the Cloud Storage bucket.
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\check;

use core\check\check;
use core\check\result;
use tool_objectfs\local\manager;
use tool_objectfs\local\store\gcs\client;

/**
 * Writes a probe object and checks the digest Cloud Storage reports for it.
 *
 * The connection check proves that the credentials can read. This one proves what the deleter relies on before it
 * removes a local copy: that an upload without an ACL succeeds and that the stored object reports a digest equal
 * to its content.
 */
class gcs_bucket extends check {
    /** @var client|null Client to probe, null for the configured one. */
    private ?client $client;

    /**
     * Constructor.
     *
     * @param client|null $client Client to probe, null for the configured one.
     */
    public function __construct(?client $client = null) {
        $this->client = $client;
    }

    /**
     * Name shown in the status report.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('settings:gcs:bucketcheck', 'tool_objectfs');
    }

    /**
     * Link to the plugin settings.
     *
     * @return \action_link|null
     */
    public function get_action_link(): ?\action_link {
        $url = new \moodle_url('/admin/settings.php', ['section' => 'tool_objectfs_settings']);
        return new \action_link($url, get_string('pluginname', 'tool_objectfs'));
    }

    /**
     * The probe's verdict.
     *
     * @return result
     */
    public function get_result(): result {
        $client = $this->client;
        if ($client === null) {
            $config = manager::get_objectfs_config();
            if (($config->filesystem ?? '') !== '\tool_objectfs\gcs_file_system') {
                return new result(result::NA, get_string('settings:gcs:bucketcheck_na', 'tool_objectfs'));
            }
        }

        // The status report runs every check on one page: a failure here must stay this check's result.
        try {
            $probe = ($client ?? new client($config))->run_probe();
        } catch (\Throwable $e) {
            $probe = (object)['success' => false, 'details' => $e->getMessage()];
        }
        if ($probe->success) {
            return new result(result::OK, get_string('settings:gcs:bucketcheck_ok', 'tool_objectfs'));
        }
        // The details carry Cloud Storage's and the SDK's text into an HTML summary.
        return new result(result::ERROR, get_string('settings:gcs:bucketcheck_error', 'tool_objectfs', s($probe->details)));
    }

    /**
     * The status checks of the Cloud Storage store, empty when another store is selected (FORK.md P19).
     *
     * @return check[]
     */
    public static function get_store_checks(): array {
        $config = manager::get_objectfs_config();
        if (($config->filesystem ?? '') !== '\tool_objectfs\gcs_file_system') {
            return [];
        }
        return [new self(), new gcp_load_balancer()];
    }
}
