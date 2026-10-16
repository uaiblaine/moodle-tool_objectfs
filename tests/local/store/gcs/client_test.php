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
use tool_objectfs\local\store\gcs\auth\token_provider;
use tool_objectfs\local\store\gcs\signing\blob_signer;

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
     * @param \stdClass|null $config Plugin config, null for the HMAC config.
     * @param token_provider|null $tokens Token source of the OAuth modes, null for the real one.
     * @return client
     */
    private function mocked_client(
        array $answers,
        array $optionoverrides = [],
        array $uploaderoverrides = [],
        ?\stdClass $config = null,
        ?token_provider $tokens = null
    ): client {
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

        return new class ($config ?? self::config(), $mock, $optionoverrides, $uploaderoverrides, $tokens) extends client {
            /**
             * Constructor; the promoted properties are set before the parent builds the SDK client. The extra
             * parameters are optional because object_client declares the constructor.
             *
             * @param \stdClass $config Plugin config.
             * @param MockHandler|null $mock SDK handler.
             * @param array $optionoverrides SDK options replacing the client's own.
             * @param array $uploaderoverrides ObjectUploader options added to the client's own.
             * @param token_provider|null $tokens Token source of the OAuth modes, null for the real one.
             */
            public function __construct(
                $config,
                /** @var MockHandler|null SDK handler. */
                private ?MockHandler $mock = null,
                /** @var array SDK options replacing the client's own. */
                private array $optionoverrides = [],
                /** @var array ObjectUploader options added to the client's own. */
                private array $uploaderoverrides = [],
                /** @var token_provider|null Token source of the OAuth modes. */
                private ?token_provider $tokens = null
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

            /**
             * The injected token source, or the real one.
             *
             * @param \stdClass $config Plugin config.
             * @return token_provider
             */
            protected function create_token_provider(\stdClass $config): token_provider {
                return $this->tokens ?? parent::create_token_provider($config);
            }
        };
    }

    /**
     * A config in an OAuth mode, without HMAC keys.
     *
     * @param string $mode client::AUTH_METADATA or client::AUTH_KEYFILE.
     * @return \stdClass
     */
    private static function oauth_config(string $mode = client::AUTH_METADATA): \stdClass {
        $config = self::config();
        $config->gcs_authmode = $mode;
        $config->gcs_hmac_key = '';
        $config->gcs_hmac_secret = '';
        $config->gcs_keyfile = $mode === client::AUTH_KEYFILE ? '/etc/moodle/key.json' : '';
        return $config;
    }

    /**
     * A token source with a fixed token, or one that fails.
     *
     * @param string|null $failure Message of the failure, null to succeed.
     * @return token_provider
     */
    private static function fixed_tokens(?string $failure = null): token_provider {
        return new class ($failure) implements token_provider {
            /**
             * Constructor.
             *
             * @param string|null $failure
             */
            public function __construct(
                /** @var string|null Failure message, null to succeed. */
                private ?string $failure
            ) {
            }

            /**
             * The fixed token, or the failure.
             *
             * @return string
             */
            public function get_token(): string {
                if ($this->failure !== null) {
                    throw new \moodle_exception('settings:gcs:autherror', 'tool_objectfs', '', 'metadata server', $this->failure);
                }
                return 'oauth-token';
            }

            /**
             * The fixed account.
             *
             * @return string
             */
            public function get_email(): string {
                return 'moodle@project.iam.gserviceaccount.com';
            }
        };
    }

    /**
     * A token source that also signs locally, as the key file does; its signature is "sig".
     *
     * @return token_provider
     */
    private static function signing_tokens(): token_provider {
        return new class () implements blob_signer, token_provider {
            /**
             * The fixed token.
             *
             * @return string
             */
            public function get_token(): string {
                return 'oauth-token';
            }

            /**
             * The fixed account.
             *
             * @return string
             */
            public function get_email(): string {
                return 'moodle@project.iam.gserviceaccount.com';
            }

            /**
             * A fixed signature.
             *
             * @param string $data
             * @return string
             */
            public function sign(string $data): string {
                return 'sig';
            }
        };
    }

    /**
     * The query parameters of a signed URL.
     *
     * @param \tool_objectfs\local\store\signed_url $signedurl
     * @return array
     */
    private static function query_of(\tool_objectfs\local\store\signed_url $signedurl): array {
        parse_str((string)parse_url($signedurl->url->out(false), PHP_URL_QUERY), $params);
        return $params;
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
        $this->assertFalse($request->hasHeader('Cache-Control'));
        $this->assert_plain_upload_request($request);
    }

    /**
     * A configured Cache-Control reaches the object's metadata.
     */
    public function test_upload_sets_the_configured_cache_control(): void {
        $config = self::config();
        $config->gcs_cachecontrol = 'private, max-age=31536000, immutable';
        $client = $this->mocked_client([null], [], [], $config);

        $client->upload_to_s3($this->temp_file('hello'), self::HASH, 'text/plain');

        $this->assertSame('private, max-age=31536000, immutable', $this->requests[0]->getHeaderLine('Cache-Control'));
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
        // Explicit: with neither a mode nor an access ID the store would use the metadata server.
        $config->gcs_authmode = client::AUTH_HMAC;
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

    /**
     * Authentication modes read from configs.
     *
     * @return array
     */
    public static function authmode_provider(): array {
        return [
            'no mode, HMAC key: the G1 setup keeps working' => [['gcs_hmac_key' => 'GOOG1X'], client::AUTH_HMAC],
            'no mode, no key' => [[], client::AUTH_METADATA],
            'key file' => [['gcs_authmode' => 'keyfile', 'gcs_hmac_key' => 'GOOG1X'], client::AUTH_KEYFILE],
            'metadata' => [['gcs_authmode' => 'metadata'], client::AUTH_METADATA],
            'unknown mode, HMAC key' => [['gcs_authmode' => 'other', 'gcs_hmac_key' => 'GOOG1X'], client::AUTH_HMAC],
        ];
    }

    /**
     * get_authmode() keeps a site with an HMAC key on it until a mode is chosen.
     *
     * @param array $config
     * @param string $expected
     */
    #[DataProvider('authmode_provider')]
    public function test_get_authmode(array $config, string $expected): void {
        $this->assertSame($expected, client::get_authmode((object)$config));
    }

    /**
     * Each mode needs its own inputs, and every mode needs the bucket.
     */
    public function test_is_configured_by_mode(): void {
        $client = new client(null);

        $this->assertTrue($client->is_configured(self::oauth_config(client::AUTH_METADATA)));
        $this->assertTrue($client->is_configured(self::oauth_config(client::AUTH_KEYFILE)));
        $nokeyfile = self::oauth_config(client::AUTH_KEYFILE);
        $nokeyfile->gcs_keyfile = '';
        $this->assertFalse($client->is_configured($nokeyfile));
        $nobucket = self::oauth_config(client::AUTH_METADATA);
        $nobucket->gcs_bucket = '';
        $this->assertFalse($client->is_configured($nobucket));
    }

    /**
     * In an OAuth mode a request carries the bearer token and no SigV4 signature.
     */
    public function test_oauth_requests_carry_the_bearer_token(): void {
        $client = $this->mocked_client([null], [], [], self::oauth_config(), self::fixed_tokens());

        $client->upload_to_s3($this->temp_file('hello'), self::HASH, 'text/plain');

        $request = $this->requests[0];
        $this->assertSame('Bearer oauth-token', $request->getHeaderLine('Authorization'));
        $this->assert_plain_upload_request($request);
    }

    /**
     * A token that cannot be obtained fails the connection test with its reason.
     */
    public function test_connection_reports_a_token_failure(): void {
        $client = $this->mocked_client([], [], [], self::oauth_config(), self::fixed_tokens('metadata server unreachable'));

        $result = $client->test_connection();

        $this->assertFalse($result->success);
        $this->assertStringContainsString('metadata server unreachable', $result->details);
    }

    /**
     * In an OAuth mode a signed URL is a V4 URL signed by the service account, with the response headers.
     */
    public function test_oauth_signed_url_is_v4_by_the_service_account(): void {
        $client = $this->mocked_client([], [], [], self::oauth_config(client::AUTH_KEYFILE), self::signing_tokens());

        $signedurl = $client->generate_presigned_url(self::HASH, [
            'Expires' => time() + HOURSECS,
            'Content-Disposition' => 'attachment; filename="relatório.pdf"',
            'Content-Type' => 'application/pdf',
        ]);

        $this->assertStringStartsWith(
            'https://storage.googleapis.com/moodle-files/site/ab/cd/' . self::HASH . '?',
            $signedurl->url->out(false)
        );
        $params = self::query_of($signedurl);
        $this->assertSame('GOOG4-RSA-SHA256', $params['X-Goog-Algorithm']);
        $this->assertStringStartsWith('moodle@project.iam.gserviceaccount.com/', $params['X-Goog-Credential']);
        $this->assertSame(bin2hex('sig'), $params['X-Goog-Signature']);
        $this->assertSame(
            "attachment; filename=\"relat_rio.pdf\"; filename*=UTF-8''relat%C3%B3rio.pdf",
            $params['response-content-disposition']
        );
        $this->assertSame('application/pdf', $params['response-content-type']);
        // Signed at the start of a minute, and valid until the expiry the URL reports.
        $this->assertStringEndsWith('00Z', $params['X-Goog-Date']);
        $signedat = \DateTime::createFromFormat('Ymd\THis\Z', $params['X-Goog-Date'], new \DateTimeZone('UTC'))->getTimestamp();
        $this->assertSame($signedurl->expiresat, $signedat + (int)$params['X-Goog-Expires']);
    }

    /**
     * signBlob signatures are reused for the same object and headers, and never across headers.
     */
    public function test_signblob_signatures_are_cached_per_object_and_headers(): void {
        $this->resetAfterTest();
        $client = $this->mocked_client([], [], [], self::oauth_config(), self::fixed_tokens());
        $headers = ['Expires' => time() + HOURSECS, 'Content-Disposition' => 'inline; filename="a.pdf"'];
        \curl::mock_response(json_encode(['keyId' => 'k', 'signedBlob' => base64_encode('first')]));

        $first = $client->generate_presigned_url(self::HASH, $headers)->url->out(false);
        // Nothing is queued: a second signBlob call would reach the network and fail.
        $again = $client->generate_presigned_url(self::HASH, $headers)->url->out(false);
        $this->assertSame($first, $again);
        $this->assertStringContainsString('X-Goog-Signature=' . bin2hex('first'), $first);

        \curl::mock_response(json_encode(['keyId' => 'k', 'signedBlob' => base64_encode('second')]));
        $headers['Content-Disposition'] = 'inline; filename="b.pdf"';
        $other = $client->generate_presigned_url(self::HASH, $headers)->url->out(false);
        $this->assertStringContainsString('X-Goog-Signature=' . bin2hex('second'), $other);
        $this->assertStringContainsString('b.pdf', $other);
    }

    /**
     * A cached signBlob signature is never returned past the expiry the caller asked for.
     */
    public function test_signblob_signature_never_outlives_the_requested_expiry(): void {
        $this->resetAfterTest();
        $client = $this->mocked_client([], [], [], self::oauth_config(), self::fixed_tokens());
        \curl::mock_response(json_encode(['keyId' => 'k', 'signedBlob' => base64_encode('long')]));
        $long = $client->generate_presigned_url(self::HASH, ['Expires' => time() + HOURSECS]);

        \curl::mock_response(json_encode(['keyId' => 'k', 'signedBlob' => base64_encode('short')]));
        $short = $client->generate_presigned_url(self::HASH, ['Expires' => time() + 5 * MINSECS]);

        $this->assertStringContainsString('X-Goog-Signature=' . bin2hex('short'), $short->url->out(false));
        $this->assertLessThan($long->expiresat, $short->expiresat);
    }

    /**
     * Content-Disposition values and their URL-safe forms.
     *
     * @return array
     */
    public static function content_disposition_provider(): array {
        return [
            'ASCII name kept' => ['attachment; filename="report.pdf"', 'attachment; filename="report.pdf"'],
            'UTF-8 name' => [
                'inline; filename="relatório.pdf"',
                "inline; filename=\"relat_rio.pdf\"; filename*=UTF-8''relat%C3%B3rio.pdf",
            ],
            'quote in a UTF-8 name' => [
                'attachment; filename="a"b é.txt"',
                "attachment; filename=\"a_b _.txt\"; filename*=UTF-8''a%22b%20%C3%A9.txt",
            ],
            'no file name' => ['attachment', 'attachment'],
            'invalid UTF-8 name' => ["inline; filename=\"a\xff.txt\"", 'inline; filename="a_.txt"'],
        ];
    }

    /**
     * normalise_content_disposition() keeps ASCII and rewrites UTF-8 names per RFC 6266.
     *
     * @param string $value
     * @param string $expected
     */
    #[DataProvider('content_disposition_provider')]
    public function test_normalise_content_disposition(string $value, string $expected): void {
        $this->assertSame($expected, client::normalise_content_disposition($value));
    }

    /**
     * A config that sends media through Cloud CDN.
     *
     * @return \stdClass
     */
    private static function cdn_config(): \stdClass {
        $config = self::config();
        $config->signingmethod = client::SIGNING_CDN;
        $config->gcs_cdn_baseurl = 'https://media.example.org';
        $config->gcs_cdn_keyname = 'moodle-key-1';
        $config->gcs_cdn_key = 'nZtRohdNF9m3cKM24IcK4w==';
        return $config;
    }

    /**
     * Inline media goes through the CDN, without response overrides by default.
     */
    public function test_inline_media_goes_through_the_cdn(): void {
        $client = new client(self::cdn_config());

        $url = $client->generate_presigned_url(self::HASH, [
            'Expires' => time() + HOURSECS,
            'Content-Disposition' => 'inline; filename="lecture.mp4"',
            'Content-Type' => 'video/mp4',
        ])->url->out(false);

        $this->assertStringStartsWith('https://media.example.org/site/ab/cd/' . self::HASH . '?Expires=', $url);
        $this->assertStringContainsString('&KeyName=moodle-key-1&Signature=', $url);
        $this->assertStringNotContainsString('response-content-disposition', $url);
    }

    /**
     * With file names on, the overrides are signed into the CDN URL.
     */
    public function test_cdn_file_names_are_signed_when_enabled(): void {
        $config = self::cdn_config();
        $config->gcs_cdn_filenames = 1;
        $client = new client($config);

        $url = $client->generate_presigned_url(self::HASH, [
            'Expires' => time() + HOURSECS,
            'Content-Disposition' => 'inline; filename="lecture.mp4"',
            'Content-Type' => 'video/mp4',
        ])->url->out(false);

        $this->assertStringStartsWith('https://media.example.org/', $url);
        $this->assertStringContainsString('response-content-disposition=inline%3B%20filename%3D%22lecture.mp4%22', $url);
    }

    /**
     * Requests that keep Cloud Storage URLs under the CDN method.
     *
     * @return array
     */
    public static function storage_url_provider(): array {
        return [
            'download of media' => ['attachment; filename="lecture.mp4"', true],
            'inline non-media' => ['inline; filename="notes.pdf"', true],
            'no file name' => ['inline', true],
            'name hiding another extension' => ['inline; filename="a.mp4; b.html"', true],
            'CDN not configured' => ['inline; filename="lecture.mp4"', false],
        ];
    }

    /**
     * Downloads, other types and an unconfigured CDN keep Cloud Storage signed URLs.
     *
     * @param string $disposition Content-Disposition of the response.
     * @param bool $cdnconfigured Whether the CDN key is set.
     */
    #[DataProvider('storage_url_provider')]
    public function test_storage_url_outside_the_cdn_rule(string $disposition, bool $cdnconfigured): void {
        $config = self::cdn_config();
        if (!$cdnconfigured) {
            $config->gcs_cdn_key = '';
        }
        $client = new client($config);

        $url = $client->generate_presigned_url(self::HASH, [
            'Expires' => time() + HOURSECS,
            'Content-Disposition' => $disposition,
        ])->url->out(false);

        $this->assertStringStartsWith('https://storage.googleapis.com/moodle-files/', $url);
    }

    /**
     * CDN settings that are present but unusable give media a Cloud Storage URL and a developer notice.
     */
    public function test_unusable_cdn_settings_fall_back_to_storage(): void {
        $config = self::cdn_config();
        $config->gcs_cdn_key = 'too-short';
        $client = new client($config);

        $download = $client->generate_presigned_url(self::HASH, [
            'Expires' => time() + HOURSECS,
            'Content-Disposition' => 'attachment; filename="lecture.mp4"',
        ])->url->out(false);
        $this->assertDebuggingNotCalled();

        $media = $client->generate_presigned_url(self::HASH, [
            'Expires' => time() + HOURSECS,
            'Content-Disposition' => 'inline; filename="lecture.mp4"',
        ])->url->out(false);
        $this->assertDebuggingCalled();

        $this->assertStringStartsWith('https://storage.googleapis.com/', $download);
        $this->assertStringStartsWith('https://storage.googleapis.com/', $media);
    }

    /**
     * A Cache-Control value holding a line break is not sent.
     */
    public function test_upload_ignores_a_cache_control_with_a_line_break(): void {
        $config = self::config();
        $config->gcs_cachecontrol = "private\r\nX-Injected: 1";
        $client = $this->mocked_client([null], [], [], $config);

        $client->upload_to_s3($this->temp_file('hello'), self::HASH, 'text/plain');

        $this->assertFalse($this->requests[0]->hasHeader('Cache-Control'));
        $this->assertFalse($this->requests[0]->hasHeader('X-Injected'));
    }

    /**
     * The store names its signing methods for the settings page.
     */
    public function test_get_signing_methods(): void {
        $this->assertSame(['s3', client::SIGNING_CDN], array_keys((new client(null))->get_signing_methods()));
    }
}
