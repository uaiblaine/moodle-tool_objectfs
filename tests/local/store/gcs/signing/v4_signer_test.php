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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * V4 signed URLs against Google's conformance vectors.
 *
 * The vectors are cases of storage/v1/v4_signatures.json in github.com/googleapis/conformance-tests (Apache
 * License 2.0, Copyright Google LLC). Only the canonical request, the string to sign and the unsigned URL are
 * compared: those carry every encoding and ordering rule. The RSA step is checked with a key made by the
 * test, so no private key is committed.
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(v4_signer::class)]
final class v4_signer_test extends \advanced_testcase {
    /** @var string Service account of the conformance vectors. */
    private const EMAIL = 'test-iam-credentials@dummy-project-id.iam.gserviceaccount.com';

    /**
     * Google conformance vectors: bucket, object, expiration, timestamp, extra query, canonical request, string
     * to sign, and the expected URL up to its signature.
     *
     * @return array
     */
    public static function conformance_provider(): array {
        return [
            'Simple GET' => [
                'test-bucket',
                'test-object',
                10,
                '2019-02-01T09:00:00Z',
                [],
                implode("\n", [
                    'GET',
                    '/test-bucket/test-object',
                    'X-Goog-Algorithm=GOOG4-RSA-SHA256&X-Goog-Credential=test-iam-credentials%40dummy' .
                        '-project-id.iam.gserviceaccount.com%2F20190201%2Fauto%2Fstorage%2Fgoog4_request&' .
                        'X-Goog-Date=20190201T090000Z&X-Goog-Expires=10&X-Goog-SignedHeaders=host',
                    'host:storage.googleapis.com',
                    '',
                    'host',
                    'UNSIGNED-PAYLOAD',
                ]),
                implode("\n", [
                    'GOOG4-RSA-SHA256',
                    '20190201T090000Z',
                    '20190201/auto/storage/goog4_request',
                    '00e2fb794ea93d7adb703edaebdd509821fcc7d4f1a79ac5c8d2b394df109320',
                ]),
                'https://storage.googleapis.com/test-bucket/test-object?X-Goog-Algorithm=GOOG4-RS' .
                    'A-SHA256&X-Goog-Credential=test-iam-credentials%40dummy-project-id.iam.gservicea' .
                    'ccount.com%2F20190201%2Fauto%2Fstorage%2Fgoog4_request&X-Goog-Date=20190201T0900' .
                    '00Z&X-Goog-Expires=10&X-Goog-SignedHeaders=host',
            ],
            'Vary expiration and timestamp' => [
                'test-bucket',
                'test-object',
                20,
                '2019-03-01T09:00:00Z',
                [],
                implode("\n", [
                    'GET',
                    '/test-bucket/test-object',
                    'X-Goog-Algorithm=GOOG4-RSA-SHA256&X-Goog-Credential=test-iam-credentials%40dummy' .
                        '-project-id.iam.gserviceaccount.com%2F20190301%2Fauto%2Fstorage%2Fgoog4_request&' .
                        'X-Goog-Date=20190301T090000Z&X-Goog-Expires=20&X-Goog-SignedHeaders=host',
                    'host:storage.googleapis.com',
                    '',
                    'host',
                    'UNSIGNED-PAYLOAD',
                ]),
                implode("\n", [
                    'GOOG4-RSA-SHA256',
                    '20190301T090000Z',
                    '20190301/auto/storage/goog4_request',
                    '779f19fdb6fd381390e2d5af04947cf21750277ee3c20e0c97b7e46a1dff8907',
                ]),
                'https://storage.googleapis.com/test-bucket/test-object?X-Goog-Algorithm=GOOG4-RS' .
                    'A-SHA256&X-Goog-Credential=test-iam-credentials%40dummy-project-id.iam.gservicea' .
                    'ccount.com%2F20190301%2Fauto%2Fstorage%2Fgoog4_request&X-Goog-Date=20190301T0900' .
                    '00Z&X-Goog-Expires=20&X-Goog-SignedHeaders=host',
            ],
            'Vary bucket and object' => [
                'test-bucket2',
                'test-object2',
                10,
                '2019-02-01T09:00:00Z',
                [],
                implode("\n", [
                    'GET',
                    '/test-bucket2/test-object2',
                    'X-Goog-Algorithm=GOOG4-RSA-SHA256&X-Goog-Credential=test-iam-credentials%40dummy' .
                        '-project-id.iam.gserviceaccount.com%2F20190201%2Fauto%2Fstorage%2Fgoog4_request&' .
                        'X-Goog-Date=20190201T090000Z&X-Goog-Expires=10&X-Goog-SignedHeaders=host',
                    'host:storage.googleapis.com',
                    '',
                    'host',
                    'UNSIGNED-PAYLOAD',
                ]),
                implode("\n", [
                    'GOOG4-RSA-SHA256',
                    '20190201T090000Z',
                    '20190201/auto/storage/goog4_request',
                    'a139afbf35ac30e9864f63197f79609731ab1b0ca166e2a456dba156fcd3f9ce',
                ]),
                'https://storage.googleapis.com/test-bucket2/test-object2?X-Goog-Algorithm=GOOG4-' .
                    'RSA-SHA256&X-Goog-Credential=test-iam-credentials%40dummy-project-id.iam.gservic' .
                    'eaccount.com%2F20190201%2Fauto%2Fstorage%2Fgoog4_request&X-Goog-Date=20190201T09' .
                    '0000Z&X-Goog-Expires=10&X-Goog-SignedHeaders=host',
            ],
            'Query Parameter Encoding' => [
                'test-bucket',
                'test-object',
                10,
                '2019-02-01T09:00:00Z',
                ['aA0é/=%-_.~' => '~ ._-%=/é0Aa'],
                implode("\n", [
                    'GET',
                    '/test-bucket/test-object',
                    'X-Goog-Algorithm=GOOG4-RSA-SHA256&X-Goog-Credential=test-iam-credentials%40dummy' .
                        '-project-id.iam.gserviceaccount.com%2F20190201%2Fauto%2Fstorage%2Fgoog4_request&' .
                        'X-Goog-Date=20190201T090000Z&X-Goog-Expires=10&X-Goog-SignedHeaders=host&aA0%C3%' .
                        'A9%2F%3D%25-_.~=~%20._-%25%3D%2F%C3%A90Aa',
                    'host:storage.googleapis.com',
                    '',
                    'host',
                    'UNSIGNED-PAYLOAD',
                ]),
                implode("\n", [
                    'GOOG4-RSA-SHA256',
                    '20190201T090000Z',
                    '20190201/auto/storage/goog4_request',
                    '448f96c23dafa8210900554e138b2b5fd55bc53ef53b8637cecc3edec45a8fcf',
                ]),
                'https://storage.googleapis.com/test-bucket/test-object?X-Goog-Algorithm=GOOG4-RS' .
                    'A-SHA256&X-Goog-Credential=test-iam-credentials%40dummy-project-id.iam.gservicea' .
                    'ccount.com%2F20190201%2Fauto%2Fstorage%2Fgoog4_request&X-Goog-Date=20190201T0900' .
                    '00Z&X-Goog-Expires=10&X-Goog-SignedHeaders=host&aA0%C3%A9%2F%3D%25-_.~=~%20._-%2' .
                    '5%3D%2F%C3%A90Aa',
            ],
            'Query Parameter Ordering' => [
                'test-bucket',
                'test-object',
                10,
                '2019-02-01T09:00:00Z',
                ['prefix' => '/foo', 'X-Goog-Meta-Foo' => 'bar'],
                implode("\n", [
                    'GET',
                    '/test-bucket/test-object',
                    'X-Goog-Algorithm=GOOG4-RSA-SHA256&X-Goog-Credential=test-iam-credentials%40dummy' .
                        '-project-id.iam.gserviceaccount.com%2F20190201%2Fauto%2Fstorage%2Fgoog4_request&' .
                        'X-Goog-Date=20190201T090000Z&X-Goog-Expires=10&X-Goog-Meta-Foo=bar&X-Goog-Signed' .
                        'Headers=host&prefix=%2Ffoo',
                    'host:storage.googleapis.com',
                    '',
                    'host',
                    'UNSIGNED-PAYLOAD',
                ]),
                implode("\n", [
                    'GOOG4-RSA-SHA256',
                    '20190201T090000Z',
                    '20190201/auto/storage/goog4_request',
                    '4dafe74ad142f32b7c25fc4e6b38fd3b8a6339d7f112247573fb0066f637db6c',
                ]),
                'https://storage.googleapis.com/test-bucket/test-object?X-Goog-Algorithm=GOOG4-RS' .
                    'A-SHA256&X-Goog-Credential=test-iam-credentials%40dummy-project-id.iam.gservicea' .
                    'ccount.com%2F20190201%2Fauto%2Fstorage%2Fgoog4_request&X-Goog-Date=20190201T0900' .
                    '00Z&X-Goog-Expires=10&X-Goog-Meta-Foo=bar&X-Goog-SignedHeaders=host&prefix=%2Ffo' .
                    'o',
            ],
        ];
    }

    /**
     * The canonical request, string to sign and URL match Google's.
     *
     * @param string $bucket
     * @param string $object
     * @param int $expiration
     * @param string $timestamp
     * @param array $query
     * @param string $canonicalrequest
     * @param string $stringtosign
     * @param string $url
     */
    #[DataProvider('conformance_provider')]
    public function test_prepare_matches_the_conformance_vectors(
        string $bucket,
        string $object,
        int $expiration,
        string $timestamp,
        array $query,
        string $canonicalrequest,
        string $stringtosign,
        string $url
    ): void {
        $prepared = v4_signer::prepare(self::EMAIL, $bucket, $object, $expiration, $query, strtotime($timestamp));

        $this->assertSame($canonicalrequest, $prepared['canonicalrequest']);
        $this->assertSame($stringtosign, $prepared['stringtosign']);
        $this->assertSame($url, $prepared['url']);
    }

    /**
     * Slashes stay path separators and every segment is encoded on its own.
     */
    public function test_object_name_segments_are_encoded_separately(): void {
        $prepared = v4_signer::prepare(self::EMAIL, 'test-bucket', 'path/with/slashes/under_score/amper&sand/file.ext', 10, [], 0);

        $this->assertStringStartsWith(
            "GET\n/test-bucket/path/with/slashes/under_score/amper%26sand/file.ext\n",
            $prepared['canonicalrequest']
        );
    }

    /**
     * The signature is the hex RSA-SHA256 signature of the string to sign, by the signer's key.
     */
    public function test_sign_url_appends_the_signature_of_the_string_to_sign(): void {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $signer = new class ($key) implements blob_signer {
            /**
             * Constructor.
             *
             * @param \OpenSSLAsymmetricKey $key
             */
            public function __construct(
                /** @var \OpenSSLAsymmetricKey Private key. */
                private \OpenSSLAsymmetricKey $key
            ) {
            }

            /**
             * Signs with the test key.
             *
             * @param string $data
             * @return string
             */
            public function sign(string $data): string {
                openssl_sign($data, $signature, $this->key, OPENSSL_ALGO_SHA256);
                return $signature;
            }

            /**
             * The vectors' account.
             *
             * @return string
             */
            public function get_email(): string {
                return 'test-iam-credentials@dummy-project-id.iam.gserviceaccount.com';
            }
        };
        $now = strtotime('2019-02-01T09:00:00Z');

        $url = (new v4_signer($signer))->sign_url('test-bucket', 'test-object', 10, [], $now);

        $prepared = v4_signer::prepare(self::EMAIL, 'test-bucket', 'test-object', 10, [], $now);
        $this->assertStringStartsWith($prepared['url'] . '&X-Goog-Signature=', $url);
        $signature = hex2bin(substr($url, strlen($prepared['url'] . '&X-Goog-Signature=')));
        $public = openssl_pkey_get_details($key)['key'];
        $this->assertSame(1, openssl_verify($prepared['stringtosign'], $signature, $public, OPENSSL_ALGO_SHA256));
    }

    /**
     * Validity outside 1 s to 7 days is refused before anything is signed.
     */
    public function test_validity_out_of_range_is_refused(): void {
        v4_signer::prepare(self::EMAIL, 'b', 'o', v4_signer::MAX_EXPIRES, [], 0);

        $this->expectException(\coding_exception::class);
        v4_signer::prepare(self::EMAIL, 'b', 'o', v4_signer::MAX_EXPIRES + 1, [], 0);
    }
}
