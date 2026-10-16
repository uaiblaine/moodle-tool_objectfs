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
 * Google Cloud Storage client for integration tests against a real bucket.
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\tests;

use tool_objectfs\local\store\gcs\client;

/**
 * Keeps every object of a run under test/<run id>/, and can lower the multipart threshold.
 */
class test_gcs_integration_client extends client {
    /** @var string Identifies this run's objects. */
    private $runidentifier;

    /** @var int|null Multipart threshold in bytes, null for the SDK default. */
    public $multipartthreshold = null;

    /**
     * Constructor.
     *
     * @param \stdClass $config Plugin config.
     */
    public function __construct($config) {
        $this->runidentifier = md5(microtime());
        parent::__construct($config);
    }

    /**
     * Object key below the run's prefix.
     *
     * @param string $contenthash
     * @return string
     */
    protected function get_filepath_from_hash($contenthash) {
        return 'test/' . $this->runidentifier . '/' . parent::get_filepath_from_hash($contenthash);
    }

    /**
     * Uploader options with the test's multipart threshold.
     *
     * @param string $mimetype
     * @return array
     */
    protected function get_uploader_options(string $mimetype): array {
        $options = parent::get_uploader_options($mimetype);
        if ($this->multipartthreshold !== null) {
            $options['mup_threshold'] = $this->multipartthreshold;
        }
        return $options;
    }

    /**
     * Deletes one of the run's objects.
     *
     * @param string $contenthash
     * @return void
     */
    public function delete_test_object(string $contenthash): void {
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $this->bucketkeyprefix . $this->get_filepath_from_hash($contenthash),
        ]);
    }
}
