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

namespace tool_objectfs\local\store\gcs;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The signing-method select on the settings page offers the store's own methods (FORK.md P18).
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(client::class)]
final class settings_test extends \advanced_testcase {
    /**
     * Choices of the signingmethod setting with a given file system configured.
     *
     * @param string $filesystem File system class.
     * @return array Choice keys.
     */
    private function signing_choices(string $filesystem): array {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');

        set_config('filesystem', $filesystem, 'tool_objectfs');
        foreach (['gcs_bucket' => 'b', 'gcs_hmac_key' => 'k', 'gcs_hmac_secret' => 's', 's3_bucket' => 'b'] as $name => $value) {
            set_config($name, $value, 'tool_objectfs');
        }
        set_config('s3_key', 'k', 'tool_objectfs');
        set_config('s3_secret', 's', 'tool_objectfs');

        // A settings page keys plugin settings by plugin and name run together.
        $page = admin_get_root(true, true)->locate('tool_objectfs_settings');
        return array_keys($page->settings->tool_objectfssigningmethod->choices);
    }

    /**
     * The Cloud Storage store names its methods; the S3 store keeps upstream's.
     */
    public function test_signing_choices_come_from_the_store(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertSame(['s3', client::SIGNING_CDN], $this->signing_choices('\tool_objectfs\gcs_file_system'));
        $this->assertSame(['s3', 'cf'], $this->signing_choices('\tool_objectfs\s3_file_system'));
    }
}
