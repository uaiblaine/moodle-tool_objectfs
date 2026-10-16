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

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use tool_objectfs\local\manager;

/**
 * Google Cloud Storage client, against the AWS SDK's MockHandler: no network.
 *
 * The handler receives the serialised and signed HTTP request, so the assertions read what Cloud Storage
 * would receive.
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(client::class)]
#[CoversClass(file_system::class)]
final class client_test extends \advanced_testcase {
    /** @var string A content hash used as object key. */
    private const HASH = 'abcdef0123456789abcdef0123456789abcdef01';

    /** @var RequestInterface[] Requests the mock handler received, in order. */
    private array $requests = [];

    /** @var CommandInterface[] Commands the mock handler received, in order. */
    private array $commands = [];

    /**
     * A configured store config.
     *
     * @return \stdClass
     */
    private static function config(): \stdClass {
        return (object)[
            'gcs_bucket' => 'moodle-files',
            'gcs_hmac_key' => 'GOOG1EXAMPLEACCESSID',
            'gcs_hmac_secret' => 'examplesecretexamplesecretexamplesecret0',
            'key_prefix' => 'site/',
            'expirationtime' => HOURSECS,
            'presignedminfilesize' => 0,
            'enablepresignedurls' => 1,
            'proxyrangerequests' => 1,
            'useproxy' => 0,
        ];
    }

    /**
     * A client whose SDK sends every request to a mock handler.
     *
     * Each queued answer is a Result, an exception, or null for an empty Result. The handler records the
     * command and the signed request first.
     *
     * @param array $answers Answers, in request order.
     * @param array $optionoverrides SDK options replacing the client's own.
     * @param array $uploaderoverrides ObjectUploader options added to the client's own.
     * @return client
     */
    private function mocked_client(array $answers, array $optionoverrides = [], array $uploaderoverrides = []): client {
        $mock = new MockHandler();
        foreach ($answers as $answer) {
            $mock->append(function (CommandInterface $command, RequestInterface $request) use ($answer) {
                $this->commands[] = $command;
                $this->requests[] = $request;
                if ($answer instanceof \Throwable) {
                    // The SDK passes the failing command to its exception: rebuild the double around it.
                    return $answer instanceof S3Exception ? new S3Exception(
                        $answer->getMessage(),
                        $command,
                        ['code' => $answer->getAwsErrorCode(), 'response' => $answer->getResponse()]
                    ) : $answer;
                }
                return $answer ?? new Result([]);
            });
        }

        return new class (self::config(), $mock, $optionoverrides, $uploaderoverrides) extends client {
            /**
             * Constructor; the promoted properties are set before the parent builds the SDK client. The extra
             * parameters are optional because object_client declares the constructor.
             *
             * @param \stdClass $config Plugin config.
             * @param MockHandler|null $mock SDK handler.
             * @param array $optionoverrides SDK options replacing the client's own.
             * @param array $uploaderoverrides ObjectUploader options added to the client's own.
             */
            public function __construct(
                $config,
                /** @var MockHandler|null SDK handler. */
                private ?MockHandler $mock = null,
                /** @var array SDK options replacing the client's own. */
                private array $optionoverrides = [],
                /** @var array ObjectUploader options added to the client's own. */
                private array $uploaderoverrides = []
            ) {
                parent::__construct($config);
            }

            /**
             * The client's options with the mock handler and the overrides.
             *
             * @param \stdClass $config Plugin config.
             * @return array
             */
            protected function get_client_options(\stdClass $config): array {
                return ['handler' => $this->mock] + $this->optionoverrides + parent::get_client_options($config);
            }

            /**
             * The client's uploader options with the overrides.
             *
             * @param string $mimetype The mimetype.
             * @return array
             */
            protected function get_uploader_options(string $mimetype): array {
                return $this->uploaderoverrides + parent::get_uploader_options($mimetype);
            }
        };
    }

    /**
     * An S3 error as the SDK raises it.
     *
     * @param string $code AWS error code.
     * @param int $status HTTP status.
     * @return S3Exception
     */
    private static function s3_error(string $code, int $status): S3Exception {
        $command = new \Aws\Command('GetObject');
        return new S3Exception($code, $command, ['code' => $code, 'response' => new Response($status)]);
    }

    /**
     * A temporary file with the given content.
     *
     * @param string $content File content.
     * @return string Path.
     */
    private function temp_file(string $content): string {
        $path = make_request_directory() . '/content';
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Names of the request's headers, lower case.
     *
     * @param RequestInterface $request
     * @return string[]
     */
    private static function header_names(RequestInterface $request): array {
        return array_map('strtolower', array_keys($request->getHeaders()));
    }

    /**
     * Asserts the request carries none of the SDK's flexible-checksum headers nor a canned ACL.
     *
     * @param RequestInterface $request
     * @return void
     */
    private function assert_plain_upload_request(RequestInterface $request): void {
        foreach (self::header_names($request) as $name) {
            $this->assertStringStartsNotWith('x-amz-checksum-', $name);
        }
        $this->assertFalse($request->hasHeader('x-amz-sdk-checksum-algorithm'));
        $this->assertFalse($request->hasHeader('x-amz-trailer'));
        $this->assertFalse($request->hasHeader('x-amz-acl'));
        $this->assertStringNotContainsString('aws-chunked', $request->getHeaderLine('Content-Encoding'));
    }

    /**
     * A single upload is a path-style PUT with the content type and no ACL or checksum headers.
     */
    public function test_upload_sends_a_plain_put_to_the_path_style_xml_endpoint(): void {
        $client = $this->mocked_client([null]);

        $client->upload_to_s3($this->temp_file('hello'), self::HASH, 'text/plain');

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('storage.googleapis.com', $request->getUri()->getHost());
        $this->assertSame('/moodle-files/site/ab/cd/' . self::HASH, $request->getUri()->getPath());
        $this->assertSame('text/plain', $request->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('/auto/s3/aws4_request', $request->getHeaderLine('Authorization'));
        $this->assert_plain_upload_request($request);
    }

    /**
     * Control: with the SDK defaults the same upload carries checksum headers.
     */
    public function test_sdk_defaults_would_add_checksums_to_the_upload(): void {
        // Control for the test above: without the client's when_required options the SDK adds checksums.
        $client = $this->mocked_client([null], [
            'request_checksum_calculation' => 'when_supported',
            'response_checksum_validation' => 'when_supported',
        ]);

        $client->upload_to_s3($this->temp_file('hello'), self::HASH, 'text/plain');

        $checksumheaders = array_filter(
            self::header_names($this->requests[0]),
            fn(string $name): bool => str_starts_with($name, 'x-amz-checksum-') || $name === 'x-amz-sdk-checksum-algorithm'
        );
        $this->assertNotEmpty($checksumheaders);
    }

    /**
     * No request of a multipart upload carries an ACL or checksum headers.
     */
    public function test_multipart_upload_sends_no_acl_and_no_checksums(): void {
        $client = $this->mocked_client(
            [
                new Result(['UploadId' => 'upload-1']),
                new Result(['ETag' => '"part-1"']),
                new Result([]),
            ],
            [],
            ['mup_threshold' => 1]
        );

        $client->upload_to_s3($this->temp_file('hello multipart'), self::HASH, 'text/plain');

        $names = array_map(fn(CommandInterface $command): string => $command->getName(), $this->commands);
        $this->assertSame(['CreateMultipartUpload', 'UploadPart', 'CompleteMultipartUpload'], $names);
        foreach ($this->requests as $request) {
            $this->assert_plain_upload_request($request);
        }
    }

    /**
     * A failed part aborts the multipart upload and surfaces a plain exception.
     */
    public function test_upload_failure_aborts_the_multipart_upload(): void {
        $client = $this->mocked_client(
            [
                new Result(['UploadId' => 'upload-1']),
                self::s3_error('InternalError', 500),
                null,
            ],
            ['retries' => 0],
            ['mup_threshold' => 1]
        );

        try {
            $client->upload_to_s3($this->temp_file('hello multipart'), self::HASH, 'text/plain');
            $this->fail('The upload must fail.');
        } catch (\Exception $e) {
            $this->assertNotInstanceOf(\Aws\Exception\MultipartUploadException::class, $e);
        }

        $this->assertSame('AbortMultipartUpload', end($this->commands)->getName());
        $this->assertSame('upload-1', end($this->commands)['UploadId']);
    }

    /**
     * x-goog-hash values and the digests read from them.
     *
     * @return array
     */
    public static function goog_hash_provider(): array {
        $both = ['crc32c' => '4waSgw==', 'md5' => 'JfnnlDI7RTiF9RgfG2JNCw=='];
        return [
            'folded' => ['crc32c=4waSgw==,md5=JfnnlDI7RTiF9RgfG2JNCw==', $both],
            'folded with space' => ['crc32c=4waSgw==, md5=JfnnlDI7RTiF9RgfG2JNCw==', $both],
            'crc32c only (composite or first repeated header)' => ['crc32c=4waSgw==', ['crc32c' => '4waSgw==']],
            'unknown algorithm ignored' => ['sha256=abc=,crc32c=4waSgw==', ['crc32c' => '4waSgw==']],
            'empty value ignored' => ['md5=', []],
            'absent' => ['', []],
        ];
    }

    /**
     * Parsing x-goog-hash.
     *
     * @param string $header Header value.
     * @param array $expected Digests by algorithm.
     */
    #[DataProvider('goog_hash_provider')]
    public function test_parse_goog_hash(string $header, array $expected): void {
        $this->assertSame($expected, client::parse_goog_hash($header));
    }

    /**
     * PHP's raw crc32c digest has the byte order Cloud Storage reports.
     */
    public function test_crc32c_digest_is_big_endian_like_cloud_storage(): void {
        // CRC-32C check value of "123456789" is 0xE3069283; Cloud Storage sends the big-endian bytes in base64.
        $path = $this->temp_file('123456789');
        $this->assertSame('e3069283', bin2hex(hash_file('crc32c', $path, true)));
        $this->assertTrue(client::local_file_matches_hashes($path, ['crc32c' => '4waSgw==']));
    }

    /**
     * Digest sets for verify_object() over a local file containing "123456789".
     *
     * @return array
     */
    public static function verify_object_provider(): array {
        return [
            'crc32c and md5 match' => ['crc32c=4waSgw==,md5=JfnnlDI7RTiF9RgfG2JNCw==', true],
            'crc32c alone matches' => ['crc32c=4waSgw==', true],
            'md5 alone matches' => ['md5=JfnnlDI7RTiF9RgfG2JNCw==', true],
            'crc32c differs' => ['crc32c=AAAAAA==,md5=JfnnlDI7RTiF9RgfG2JNCw==', false],
            'md5 differs' => ['crc32c=4waSgw==,md5=AAAAAAAAAAAAAAAAAAAAAA==', false],
            'no digest reported' => [null, false],
        ];
    }

    /**
     * verify_object() compares content, not existence.
     *
     * @param string|null $googhash The x-goog-hash header, null for none.
     * @param bool $expected Verdict.
     */
    #[DataProvider('verify_object_provider')]
    public function test_verify_object_compares_the_reported_digests(?string $googhash, bool $expected): void {
        $headers = $googhash === null ? [] : ['x-goog-hash' => $googhash];
        $client = $this->mocked_client([new Result(['ETag' => '"x"', '@metadata' => ['headers' => $headers]])]);
        $path = $this->temp_file('123456789');

        if (!$expected) {
            $this->expectOutputRegex('/does not match the local file/');
        }
        $this->assertSame($expected, $client->verify_object(self::HASH, $path));
        $this->assertSame('HEAD', $this->requests[0]->getMethod());
    }

    /**
     * A missing object never verifies.
     */
    public function test_verify_object_fails_when_the_object_is_missing(): void {
        $client = $this->mocked_client([self::s3_error('NotFound', 404)], ['retries' => 0]);

        $this->assertFalse($client->verify_object(self::HASH, $this->temp_file('123456789')));
    }

    /**
     * Connection test verdicts by the answer to the sentinel read.
     *
     * @return array
     */
    public static function connection_provider(): array {
        return [
            'missing key: bucket and credentials work' => ['NoSuchKey', 404, true],
            'missing bucket' => ['NoSuchBucket', 404, false],
            'no permission' => ['AccessDenied', 403, false],
            'bad signature' => ['SignatureDoesNotMatch', 403, false],
        ];
    }

    /**
     * test_connection() reads a sentinel key and only "no such key" passes.
     *
     * @param string $code AWS error code of the answer.
     * @param int $status HTTP status of the answer.
     * @param bool $success Expected verdict.
     */
    #[DataProvider('connection_provider')]
    public function test_test_connection(string $code, int $status, bool $success): void {
        $client = $this->mocked_client([self::s3_error($code, $status)], ['retries' => 0]);

        $result = $client->test_connection();

        $this->assertSame($success, $result->success);
        $this->assertSame('GET', $this->requests[0]->getMethod());
        $this->assertSame('/moodle-files/site/' . client::SENTINEL_KEY, $this->requests[0]->getUri()->getPath());
        if (!$success) {
            $this->assertStringContainsString($code, $result->details);
        }
    }

    /**
     * An existing sentinel object also proves the connection.
     */
    public function test_test_connection_passes_when_the_sentinel_exists(): void {
        $client = $this->mocked_client([new Result([])]);

        $this->assertTrue($client->test_connection()->success);
    }

    /**
     * Configs that leave the store unconfigured.
     *
     * @return array
     */
    public static function not_configured_provider(): array {
        return [
            'no bucket' => ['gcs_bucket'],
            'no access id' => ['gcs_hmac_key'],
            'no secret' => ['gcs_hmac_secret'],
        ];
    }

    /**
     * An incomplete config builds no SDK client.
     *
     * @param string $missing The config key left empty.
     */
    #[DataProvider('not_configured_provider')]
    public function test_not_configured(string $missing): void {
        $config = self::config();
        $config->$missing = '';
        $client = new client($config);

        $this->assertFalse($client->is_configured($config));
        $result = $client->test_connection();
        $this->assertFalse($result->success);
        $this->assertSame(get_string('settings:notconfigured', 'tool_objectfs'), $result->details);
        $this->assertTrue($client->is_configured(self::config()));
    }

    /**
     * Tagging is reported unsupported.
     */
    public function test_tagging_is_reported_unsupported(): void {
        $this->assertFalse((new client(self::config()))->supports_object_tagging());
        // Control: the S3 client it extends reports tagging.
        $this->assertTrue((new \tool_objectfs\local\store\s3\client(null))->supports_object_tagging());
    }

    /**
     * A range is read with a signed request header, not a pre-signed URL.
     */
    public function test_get_object_range_reads_on_the_client_without_presigning(): void {
        $body = \GuzzleHttp\Psr7\Utils::streamFor(str_repeat('x', 1000));
        $client = $this->mocked_client([new Result([
            'Body' => $body,
            'ContentRange' => 'bytes 0-999/5000000',
            'ContentLength' => 1000,
            '@metadata' => ['statusCode' => 206, 'headers' => []],
        ])]);

        $result = $client->get_object_range(self::HASH, (object)['rangefrom' => 0, 'rangeto' => 999]);

        $request = $this->requests[0];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('bytes=0-999', $request->getHeaderLine('Range'));
        $this->assertStringNotContainsString('X-Amz-Signature', $request->getUri()->getQuery());
        $this->assertStringStartsWith('AWS4-HMAC-SHA256', $request->getHeaderLine('Authorization'));
        $this->assertSame(1000, (int)$result['ContentLength']);
    }

    /**
     * An answer other than partial content is an error.
     */
    public function test_get_object_range_rejects_a_full_response(): void {
        $client = $this->mocked_client([new Result(['Body' => '', '@metadata' => ['statusCode' => 200, 'headers' => []]])]);

        $this->expectException(\file_exception::class);
        $client->get_object_range(self::HASH, (object)['rangefrom' => 0, 'rangeto' => 999]);
    }

    /**
     * A failed range read names the key and the status, for the debug info.
     */
    public function test_get_object_range_failure_names_the_key_and_status(): void {
        $client = $this->mocked_client([self::s3_error('AccessDenied', 403)], ['retries' => 0]);

        try {
            $client->get_object_range(self::HASH, (object)['rangefrom' => 0, 'rangeto' => 999]);
            $this->fail('The range read must fail.');
        } catch (\file_exception $e) {
            $this->assertStringContainsString('site/ab/cd/' . self::HASH, $e->debuginfo);
            $this->assertStringContainsString('HTTP 403 AccessDenied', $e->debuginfo);
        }
    }

    /**
     * The whole body reaches the output, across chunk boundaries.
     */
    public function test_send_object_range_streams_the_body(): void {
        $client = new class (self::config()) extends client {
            /** @var string[] Header lines sent. */
            public array $sentheaders = [];

            /**
             * Exposes send_object_range().
             *
             * @param Result $result
             * @return void
             */
            public function send(Result $result): void {
                $this->send_object_range($result);
            }

            /**
             * Records the headers instead of sending them, which the CLI cannot do after output.
             *
             * @param string[] $headers
             * @return void
             */
            protected function send_range_headers(array $headers): void {
                $this->sentheaders = $headers;
            }
        };
        $content = str_repeat('a', client::RANGE_CHUNK) . 'tail';
        $result = new Result([
            'Body' => \GuzzleHttp\Psr7\Utils::streamFor($content),
            'ContentRange' => 'bytes 0-' . (strlen($content) - 1) . '/9999999',
            'ContentLength' => strlen($content),
        ]);

        $this->expectOutputString($content);
        $client->send($result);
        $this->assertSame('HTTP/1.1 206 Partial Content', $client->sentheaders[0]);
        $this->assertContains('Content-Length: ' . strlen($content), $client->sentheaders);
    }

    /**
     * Signed URLs are SigV4 on the path-style XML endpoint.
     */
    public function test_presigned_url_is_sigv4_on_the_xml_endpoint(): void {
        $client = new client(self::config());

        $signedurl = $client->generate_presigned_url(self::HASH, ['Expires' => time() + HOURSECS]);

        $url = $signedurl->url->out(false);
        $this->assertStringStartsWith('https://storage.googleapis.com/moodle-files/site/ab/cd/' . self::HASH . '?', $url);
        $this->assertStringContainsString('X-Amz-Algorithm=AWS4-HMAC-SHA256', $url);
        $this->assertStringContainsString(rawurlencode('/auto/s3/aws4_request'), $url);
    }

    /**
     * The store is offered and its name maps to its client.
     */
    public function test_store_is_registered_and_mapped(): void {
        $this->resetAfterTest();

        $this->assertArrayHasKey('\tool_objectfs\gcs_file_system', manager::get_available_fs_list());
        $this->assertSame(
            '\tool_objectfs\local\store\gcs\client',
            manager::get_client_classname_from_fs('\tool_objectfs\gcs_file_system')
        );
        $this->assertInstanceOf(file_system::class, new \tool_objectfs\gcs_file_system());
    }

    /**
     * A client restored from serialisation is configured again.
     */
    public function test_client_survives_serialisation(): void {
        $this->resetAfterTest();
        foreach ((array)self::config() as $name => $value) {
            set_config($name, $value, 'tool_objectfs');
        }

        $client = unserialize(serialize(new client(manager::get_objectfs_config())));

        $this->assertTrue($client->is_configured(manager::get_objectfs_config()));
        $url = $client->generate_presigned_url(self::HASH, ['Expires' => time() + HOURSECS])->url->out(false);
        $this->assertStringContainsString('/moodle-files/site/ab/cd/', $url);
    }
}
