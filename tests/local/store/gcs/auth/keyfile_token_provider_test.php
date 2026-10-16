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

namespace tool_objectfs\local\store\gcs\auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Service-account key files, with a key made by the test.
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(keyfile_token_provider::class)]
final class keyfile_token_provider_test extends \advanced_testcase {
    /** @var string Service account of the test key. */
    private const EMAIL = 'moodle@project.iam.gserviceaccount.com';

    /** @var \OpenSSLAsymmetricKey The test key. */
    private \OpenSSLAsymmetricKey $key;

    /**
     * Makes a key and empties the shared token cache.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        \cache::make_from_params(\cache_store::MODE_APPLICATION, 'tool_objectfs', 'gcstoken', [], ['simplekeys' => true])->purge();
    }

    /**
     * Writes a key file.
     *
     * @param array $overrides Fields replacing the valid file's.
     * @return string Path.
     */
    private function key_file(array $overrides = []): string {
        openssl_pkey_export($this->key, $pem);
        $path = make_request_directory() . '/key.json';
        file_put_contents($path, json_encode($overrides + [
            'type' => 'service_account',
            'client_email' => self::EMAIL,
            'private_key' => $pem,
        ]));
        return $path;
    }

    /**
     * Decodes a base64url segment.
     *
     * @param string $segment
     * @return string
     */
    private static function unbase64url(string $segment): string {
        return base64_decode(strtr($segment, '-_', '+/'));
    }

    /**
     * The JWT has the RS256 header, the claims, and a signature by the file's key.
     */
    public function test_jwt(): void {
        $provider = new keyfile_token_provider($this->key_file());
        $claims = ['iss' => self::EMAIL, 'aud' => keyfile_token_provider::TOKEN_URI, 'iat' => 1000, 'exp' => 4600];

        $jwt = $provider->create_jwt($claims);

        [$header, $payload, $signature] = explode('.', $jwt);
        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode(self::unbase64url($header), true));
        $this->assertSame($claims, json_decode(self::unbase64url($payload), true));
        $this->assertStringNotContainsString('=', $jwt);
        $public = openssl_pkey_get_details($this->key)['key'];
        $this->assertSame(1, openssl_verify($header . '.' . $payload, self::unbase64url($signature), $public, OPENSSL_ALGO_SHA256));
    }

    /**
     * The token comes from the token endpoint's answer, and the email from the file.
     */
    public function test_token_and_email(): void {
        \curl::mock_response(json_encode(['access_token' => 'file-token', 'expires_in' => 3599, 'token_type' => 'Bearer']));
        $provider = new keyfile_token_provider($this->key_file());

        $this->assertSame('file-token', $provider->get_token());
        $this->assertSame(self::EMAIL, $provider->get_email());
    }

    /**
     * Relative paths are below the data directory; absolute ones are kept.
     */
    public function test_resolve_path(): void {
        global $CFG;
        $this->assertSame($CFG->dataroot . '/objectfs/key.json', keyfile_token_provider::resolve_path('objectfs/key.json'));
        $this->assertSame('/etc/moodle/key.json', keyfile_token_provider::resolve_path('/etc/moodle/key.json'));
    }

    /**
     * Unusable key files.
     *
     * @return array
     */
    public static function unusable_provider(): array {
        return [
            'not a service account' => [['type' => 'authorized_user']],
            'no email' => [['client_email' => '']],
            'bad key' => [['private_key' => "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----\n"]],
        ];
    }

    /**
     * An unusable file fails on first use, not on construction.
     *
     * @param array $overrides Fields that break the file.
     */
    #[DataProvider('unusable_provider')]
    public function test_unusable_file_fails_on_use(array $overrides): void {
        $provider = new keyfile_token_provider($this->key_file($overrides));

        $this->expectException(\moodle_exception::class);
        $provider->sign('data');
    }

    /**
     * A missing file fails on first use.
     */
    public function test_missing_file_fails_on_use(): void {
        $provider = new keyfile_token_provider(make_request_directory() . '/absent.json');

        $this->expectException(\moodle_exception::class);
        $provider->get_email();
    }
}
