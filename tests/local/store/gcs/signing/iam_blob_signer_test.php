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

namespace tool_objectfs\local\store\gcs\signing;

use PHPUnit\Framework\Attributes\CoversClass;
use tool_objectfs\local\store\gcs\auth\token_provider;

/**
 * IAM Credentials signBlob, through \curl::mock_response().
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(iam_blob_signer::class)]
final class iam_blob_signer_test extends \advanced_testcase {
    /**
     * A signer over a fixed token.
     *
     * @param string $email The account's email.
     * @return iam_blob_signer
     */
    private static function signer(string $email = 'moodle@project.iam.gserviceaccount.com'): iam_blob_signer {
        return new iam_blob_signer(new class ($email) implements token_provider {
            /**
             * Constructor.
             *
             * @param string $email
             */
            public function __construct(
                /** @var string The account's email. */
                private string $email
            ) {
            }

            /**
             * A fixed token.
             *
             * @return string
             */
            public function get_token(): string {
                return 'token';
            }

            /**
             * A fixed account.
             *
             * @return string
             */
            public function get_email(): string {
                return $this->email;
            }
        });
    }

    /**
     * The signature is the decoded signedBlob.
     */
    public function test_sign_returns_the_decoded_blob(): void {
        \curl::mock_response(json_encode(['keyId' => 'key-1', 'signedBlob' => base64_encode("\x01signature\xff")]));

        $this->assertSame("\x01signature\xff", self::signer()->sign('data'));
    }

    /**
     * An error answer fails; Google's message, which names the account, is debug info only.
     */
    public function test_error_answer_throws(): void {
        $error = ['code' => 403, 'message' => 'Permission iam.serviceAccounts.signBlob denied'];
        \curl::mock_response(json_encode(['error' => $error]));

        try {
            self::signer()->sign('data');
            $this->fail('An error answer must fail.');
        } catch (\moodle_exception $e) {
            $this->assertSame('signBlob', $e->a);
            $this->assertSame('Permission iam.serviceAccounts.signBlob denied', $e->debuginfo);
        }
    }

    /**
     * An email that could alter the request path is refused before any request.
     */
    public function test_email_outside_address_characters_is_refused(): void {
        try {
            self::signer('evil/../x@project.iam.gserviceaccount.com')->sign('data');
            $this->fail('The email must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalid service account email', $e->debuginfo);
        }
    }

    /**
     * A blob that is not base64 fails.
     */
    public function test_invalid_blob_throws(): void {
        \curl::mock_response(json_encode(['keyId' => 'key-1', 'signedBlob' => '%%%']));

        $this->expectException(\moodle_exception::class);
        self::signer()->sign('data');
    }
}
