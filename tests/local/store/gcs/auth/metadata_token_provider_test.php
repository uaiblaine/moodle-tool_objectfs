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

/**
 * Metadata-server tokens, through \curl::mock_response() (which serves the last queued answer first).
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(metadata_token_provider::class)]
#[CoversClass(cached_token_provider::class)]
final class metadata_token_provider_test extends \advanced_testcase {
    /**
     * Empties the shared token cache: every metadata provider shares one entry.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        \cache::make_from_params(\cache_store::MODE_APPLICATION, 'tool_objectfs', 'gcstoken', [], ['simplekeys' => true])->purge();
    }

    /**
     * A token answer.
     *
     * @param string $token
     * @param int $expiresin
     * @return string
     */
    private static function answer(string $token, int $expiresin): string {
        return json_encode(['access_token' => $token, 'expires_in' => $expiresin, 'token_type' => 'Bearer']);
    }

    /**
     * One fetch serves later calls, of this instance and of a new one on another request.
     */
    public function test_token_is_fetched_once_and_shared(): void {
        \curl::mock_response(self::answer('token-1', 3599));

        $this->assertSame('token-1', (new metadata_token_provider())->get_token());
        // Nothing is queued any more: a second fetch would try the real metadata server and fail.
        $this->assertSame('token-1', (new metadata_token_provider())->get_token());
    }

    /**
     * A token within the refresh margin of its expiry is replaced.
     */
    public function test_token_near_expiry_is_refetched(): void {
        \curl::mock_response(self::answer('token-2', 3599));
        \curl::mock_response(self::answer('token-1', cached_token_provider::REFRESH_MARGIN));
        $provider = new metadata_token_provider();

        $this->assertSame('token-1', $provider->get_token());
        $this->assertSame('token-2', $provider->get_token());
    }

    /**
     * An answer without a token fails; the server's explanation is debug info, never the shown message.
     */
    public function test_error_answer_throws(): void {
        \curl::mock_response(json_encode(['error' => 'invalid_scope', 'error_description' => 'scope not allowed']));

        try {
            (new metadata_token_provider())->get_token();
            $this->fail('An answer without a token must fail.');
        } catch (\moodle_exception $e) {
            $this->assertSame('token endpoint', $e->a);
            $this->assertSame('scope not allowed', $e->debuginfo);
        }
    }

    /**
     * A configured email is used as given; otherwise the server's answer is trimmed and validated.
     */
    public function test_email(): void {
        $configured = new metadata_token_provider('sa@project.iam.gserviceaccount.com');
        $this->assertSame('sa@project.iam.gserviceaccount.com', $configured->get_email());

        \curl::mock_response("moodle@project.iam.gserviceaccount.com\n");
        $this->assertSame('moodle@project.iam.gserviceaccount.com', (new metadata_token_provider())->get_email());

        \curl::mock_response('<html>not found</html>');
        $this->expectException(\moodle_exception::class);
        (new metadata_token_provider())->get_email();
    }

    /**
     * Requests go to the fixed metadata paths.
     */
    public function test_requests_use_the_fixed_metadata_paths(): void {
        $provider = new class () extends metadata_token_provider {
            /** @var string[] Paths requested. */
            public array $paths = [];

            /**
             * Records the path and answers.
             *
             * @param string $path
             * @return string
             */
            protected function request(string $path): string {
                $this->paths[] = $path;
                if ($path === 'token') {
                    return json_encode(['access_token' => 't', 'expires_in' => 3599]);
                }
                return 'a@b.iam.gserviceaccount.com';
            }
        };

        $provider->get_token();
        $provider->get_email();

        $this->assertSame(['token', 'email'], $provider->paths);
        $this->assertSame(
            'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/',
            metadata_token_provider::BASE_URL
        );
    }
}
