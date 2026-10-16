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
use tool_objectfs\tests\test_gcs_integration_client;

/**
 * Google Cloud Storage client against a real bucket (docs/gcp/DESIGN.md, test plan 6.2).
 *
 * Skipped unless config.php sets, for a bucket with uniform bucket-level access and an HMAC key whose service
 * account has Storage Object Admin on it:
 *
 *     $CFG->phpunit_objectfs_gcs_integration_test_credentials = [
 *         'gcs_bucket' => '...', 'gcs_hmac_key' => '...', 'gcs_hmac_secret' => '...',
 *     ];
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(client::class)]
final class integration_test extends \advanced_testcase {
    /** @var test_gcs_integration_client|null The client, null when skipped. */
    private ?test_gcs_integration_client $client = null;

    /** @var string[] Content hashes uploaded by the current test. */
    private array $uploaded = [];

    /**
     * Builds the client from the integration credentials, or skips.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        if (empty($CFG->phpunit_objectfs_gcs_integration_test_credentials)) {
            $this->markTestSkipped('No $CFG->phpunit_objectfs_gcs_integration_test_credentials.');
        }
        $config = (object)($CFG->phpunit_objectfs_gcs_integration_test_credentials + [
            'key_prefix' => '',
            'expirationtime' => HOURSECS,
            'presignedminfilesize' => 0,
            'enablepresignedurls' => 1,
            'proxyrangerequests' => 1,
            'useproxy' => 0,
        ]);
        $this->client = new test_gcs_integration_client($config);
    }

    /**
     * Removes the objects the test uploaded.
     */
    protected function tearDown(): void {
        foreach ($this->uploaded as $contenthash) {
            $this->client->delete_test_object($contenthash);
        }
        $this->client = null;
        parent::tearDown();
    }

    /**
     * Uploads content and remembers it for tear-down.
     *
     * @param string $content
     * @return array Content hash and local path.
     */
    private function upload(string $content): array {
        $path = make_request_directory() . '/content';
        file_put_contents($path, $content);
        $contenthash = sha1($content);
        $this->client->upload_to_s3($path, $contenthash, 'application/octet-stream');
        $this->uploaded[] = $contenthash;
        return [$contenthash, $path];
    }

    /**
     * Credentials and bucket pass the connection test.
     */
    public function test_connection(): void {
        $result = $this->client->test_connection();
        $this->assertTrue($result->success, $result->details);
    }

    /**
     * A single upload verifies by digest, and a different local file does not.
     */
    public function test_single_upload_verifies_by_digest(): void {
        [$contenthash, $path] = $this->upload('objectfs gcs ' . random_string(32));

        $this->assertTrue($this->client->verify_object($contenthash, $path));

        file_put_contents($path, 'other content');
        $this->expectOutputRegex('/does not match the local file/');
        $this->assertFalse($this->client->verify_object($contenthash, $path));
    }

    /**
     * A multipart upload (two parts) succeeds without checksum headers and verifies by CRC32C.
     */
    public function test_multipart_upload_verifies_by_crc32c(): void {
        $this->client->multipartthreshold = 5 * 1024 * 1024;
        [$contenthash, $path] = $this->upload(random_bytes(6 * 1024 * 1024));

        $this->assertTrue($this->client->verify_object($contenthash, $path));
    }

    /**
     * A range is read as partial content.
     */
    public function test_range_read(): void {
        $content = random_bytes(4096);
        [$contenthash] = $this->upload($content);

        $result = $this->client->get_object_range($contenthash, (object)['rangefrom' => 100, 'rangeto' => 199]);

        $this->assertSame(substr($content, 100, 100), (string)$result['Body']);
    }

    /**
     * A signed URL serves the object with the requested filename.
     */
    public function test_signed_url_serves_the_object_with_its_filename(): void {
        $content = 'signed ' . random_string(32);
        [$contenthash] = $this->upload($content);

        $signedurl = $this->client->generate_presigned_url($contenthash, [
            'Expires' => time() + HOURSECS,
            'Content-Disposition' => 'attachment; filename="report.txt"',
            'Content-Type' => 'text/plain',
        ]);
        $curl = new \curl();
        $body = $curl->get($signedurl->url->out(false));

        $this->assertSame(200, $curl->get_info()['http_code'], $body);
        $this->assertSame($content, $body);
        $this->assertStringContainsString('report.txt', \tool_objectfs\local\manager::get_header(
            $curl->getResponse(),
            'Content-Disposition'
        ));
    }
}
