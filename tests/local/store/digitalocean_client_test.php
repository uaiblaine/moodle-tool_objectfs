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

namespace tool_objectfs\local\store;

use tool_objectfs\local\manager;
use tool_objectfs\local\store\digitalocean\client;

/**
 * The DigitalOcean Spaces client takes the shared S3 settings from the configuration (FORK.md P24).
 *
 * @covers \tool_objectfs\local\store\digitalocean\client
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class digitalocean_client_test extends \advanced_testcase {
    /**
     * A configured Spaces client.
     *
     * @return client
     */
    private function make_client(): client {
        $config = manager::get_objectfs_config();
        $config->do_key = 'key';
        $config->do_secret = 'secret';
        $config->do_space = 'space';
        $config->do_region = 'sfo2';
        $config->expirationtime = 2 * HOURSECS;
        $config->presignedminfilesize = 1024;
        $config->enablepresignedurls = 1;
        return new client($config);
    }

    /**
     * Spaces accepts multipart uploads up to 5 TB, as S3 does.
     */
    public function test_maximum_upload_size_is_set(): void {
        $this->assertSame(OBJECTFS_BYTES_IN_TERABYTE * 5, $this->make_client()->get_maximum_upload_size());
    }

    /**
     * Pre-signed URLs last the configured time, not the two-minute floor.
     */
    public function test_presigned_url_expiry_follows_the_setting(): void {
        $now = time();
        $expires = $this->make_client()->get_expiration_time($now, 0);
        $this->assertGreaterThanOrEqual($now + 2 * HOURSECS, $expires);
    }

    /**
     * The pre-signed URL switches reach the client.
     */
    public function test_presigned_url_settings_are_set(): void {
        $client = $this->make_client();
        $this->assertEquals(1024, $client->presignedminfilesize);
        $this->assertEquals(1, $client->enablepresignedurls);
    }
}
