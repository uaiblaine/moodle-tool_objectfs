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
 * Google Cloud Storage client.
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\local\store\gcs;

use Psr\Http\Message\RequestInterface;
use tool_objectfs\local\manager;
use tool_objectfs\local\store\gcs\auth\keyfile_token_provider;
use tool_objectfs\local\store\gcs\auth\metadata_token_provider;
use tool_objectfs\local\store\gcs\auth\token_provider;
use tool_objectfs\local\store\gcs\signing\blob_signer;
use tool_objectfs\local\store\gcs\signing\iam_blob_signer;
use tool_objectfs\local\store\gcs\signing\v4_signer;
use tool_objectfs\local\store\s3\client as s3_client;
use tool_objectfs\local\store\signed_url;

/**
 * Google Cloud Storage through its XML API, on the S3 client and core's AWS SDK.
 *
 * Fork-owned (FORK.md P16, docs/gcp/DESIGN.md). It overrides only public and protected members of the S3
 * client: the private ones it must not reach ($bucketacl, $signingmethod, the CloudFront signer) are bypassed
 * by overriding the public methods that read them.
 *
 * Three ways to authenticate: an HMAC key (requests and signed URLs in SigV4, which Cloud Storage accepts on
 * the XML endpoint), or OAuth tokens of a service account, from the metadata server or from a key file. In the
 * OAuth modes the SDK signs nothing: a middleware adds the bearer token, and signed URLs are V4 URLs signed by
 * the service account.
 */
class client extends s3_client {
    /** @var string Cloud Storage XML API endpoint. */
    const ENDPOINT = 'https://storage.googleapis.com';

    /** @var string Key the connection test reads: only "no such key" proves the bucket and the credentials. */
    const SENTINEL_KEY = 'tool_objectfs_connection_check';

    /** @var int Bytes copied to the output per read when proxying a range. */
    const RANGE_CHUNK = 1048576;

    /** @var string Authentication by an HMAC key. */
    const AUTH_HMAC = 'hmac';

    /** @var string OAuth tokens of the service account attached to the VM, GKE workload or Cloud Run service. */
    const AUTH_METADATA = 'metadata';

    /** @var string OAuth tokens from a service-account key file. */
    const AUTH_KEYFILE = 'keyfile';

    /** @var string One of the AUTH_* modes. */
    protected $authmode = self::AUTH_HMAC;

    /** @var token_provider|null Token source in the OAuth modes. */
    protected $tokenprovider = null;

    /**
     * Constructor.
     *
     * @param \stdClass|null $config Plugin config, null when only availability is asked.
     */
    public function __construct($config) {
        $this->config = $config;
        if ($this->get_availability() && !empty($config)) {
            $this->apply_config($config);
        }
    }

    /**
     * Reads the store's keys and builds the SDK client.
     *
     * The keys are read with defaults because manager::get_objectfs_config() only knows upstream's keys.
     *
     * @param \stdClass $config Plugin config.
     * @return void
     */
    protected function apply_config(\stdClass $config): void {
        // Multipart uploads reach the 5 TiB object limit of Cloud Storage.
        $this->maxupload = OBJECTFS_BYTES_IN_TERABYTE * 5;
        $this->bucket = $config->gcs_bucket ?? '';
        $this->bucketkeyprefix = $config->key_prefix ?? '';
        $this->expirationtime = $config->expirationtime ?? 2 * HOURSECS;
        $this->presignedminfilesize = $config->presignedminfilesize ?? 0;
        $this->enablepresignedurls = $config->enablepresignedurls ?? 0;
        $this->authmode = self::get_authmode($config);
        $this->set_client($config);
    }

    /**
     * The configured authentication mode.
     *
     * Before the setting existed the store knew only HMAC keys, so a site that has one and no mode keeps it.
     *
     * @param \stdClass $config Plugin config.
     * @return string One of the AUTH_* modes.
     */
    public static function get_authmode(\stdClass $config): string {
        $mode = $config->gcs_authmode ?? '';
        if (in_array($mode, [self::AUTH_HMAC, self::AUTH_METADATA, self::AUTH_KEYFILE], true)) {
            return $mode;
        }
        return empty($config->gcs_hmac_key) ? self::AUTH_METADATA : self::AUTH_HMAC;
    }

    /**
     * Restores the client after unserialisation; the parent restores the bucket only.
     *
     * @return void
     */
    public function __wakeup() {
        $config = manager::get_objectfs_config();
        $this->config = $config;
        $this->apply_config($config);
        if ($this->is_functional()) {
            $this->client->registerStreamWrapper();
        }
    }

    /**
     * Check if the client is configured.
     *
     * @param \stdClass $config Client config.
     * @return bool
     */
    public function is_configured($config) {
        if (empty($config->gcs_bucket)) {
            return false;
        }
        return match (self::get_authmode($config)) {
            self::AUTH_HMAC => !empty($config->gcs_hmac_key) && !empty($config->gcs_hmac_secret),
            self::AUTH_KEYFILE => !empty($config->gcs_keyfile),
            // The metadata server needs nothing configured; off Google Cloud the connection test says so.
            default => true,
        };
    }

    /**
     * Builds the SDK client, or clears it when the store is not configured.
     *
     * @param \stdClass $config Client config.
     * @return void
     */
    public function set_client($config) {
        if (!$this->is_configured($config)) {
            $this->client = null;
            return;
        }

        $this->ensure_sdk_loaded();
        $this->client = new \Aws\S3\S3Client($this->get_client_options($config));

        $this->tokenprovider = null;
        if (self::get_authmode($config) !== self::AUTH_HMAC) {
            $tokens = $this->create_token_provider($config);
            $this->tokenprovider = $tokens;
            // The SDK signs anonymously in this mode (credentials false); the token is added after that step.
            $bearer = fn(RequestInterface $request): RequestInterface =>
                $request->withHeader('Authorization', 'Bearer ' . $tokens->get_token());
            $this->client->getHandlerList()->appendSign(\Aws\Middleware::mapRequest($bearer), 'tool_objectfs_gcs_bearer');
        }
    }

    /**
     * The token source of an OAuth mode.
     *
     * @param \stdClass $config Plugin config.
     * @return token_provider
     */
    protected function create_token_provider(\stdClass $config): token_provider {
        if (self::get_authmode($config) === self::AUTH_KEYFILE) {
            return new keyfile_token_provider($config->gcs_keyfile);
        }
        return new metadata_token_provider(trim($config->gcs_serviceaccount ?? ''));
    }

    /**
     * The signer of V4 URLs in an OAuth mode: the key file itself, or IAM signBlob for the attached account.
     *
     * @return blob_signer
     */
    protected function create_blob_signer(): blob_signer {
        if ($this->tokenprovider instanceof blob_signer) {
            return $this->tokenprovider;
        }
        return new iam_blob_signer($this->tokenprovider);
    }

    /**
     * SDK client options for the Cloud Storage XML API.
     *
     * @param \stdClass $config Client config.
     * @return array S3Client constructor options.
     */
    protected function get_client_options(\stdClass $config): array {
        $options = [
            'version' => AWS_API_VERSION,
            'endpoint' => self::ENDPOINT,
            // Virtual-hosted addressing cannot reach a bucket whose name contains dots; path style reaches all.
            'use_path_style_endpoint' => true,
            // Google's own S3 interoperability samples sign for 'auto' (DESIGN.md D6).
            'region' => 'auto',
            // Since SDK 3.337 every upload carries x-amz-checksum headers or aws-chunked trailers by default.
            // Cloud Storage documents only Content-MD5 and x-goog-hash (DESIGN.md D5).
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'credentials' => self::get_authmode($config) === self::AUTH_HMAC
                ? ['key' => $config->gcs_hmac_key, 'secret' => $config->gcs_hmac_secret]
                : false,
        ];

        if (!empty($config->useproxy)) {
            $options['http'] = ['proxy' => $this->get_proxy_string()];
        }

        return $options;
    }

    /**
     * Uploads a local file, multipart above the SDK threshold.
     *
     * @param string $localpath Path to a local file.
     * @param string $contenthash Content hash of the file.
     * @param string $mimetype The mimetype of the file being uploaded.
     * @return void
     * @throws \Exception If the upload fails.
     */
    public function upload_to_s3($localpath, $contenthash, string $mimetype) {
        $filehandle = fopen($localpath, 'rb');
        if (!$filehandle) {
            throw new \Exception('Can not open the file for reading: ' . $localpath);
        }

        // No canned ACL: uniform bucket-level access rejects ACL requests, and IAM on the bucket decides access.
        // The multipart path leaves a null ACL out, but the single PUT would send it as an empty x-amz-acl
        // header, so the parameter is removed from that command.
        $options = $this->get_uploader_options($mimetype);
        $options['before_upload'] = function (\Aws\CommandInterface $command): void {
            unset($command['ACL']);
        };

        try {
            $uploader = new \Aws\S3\ObjectUploader(
                $this->client,
                $this->bucket,
                $this->bucketkeyprefix . $this->get_filepath_from_hash($contenthash),
                $filehandle,
                null,
                $options
            );
            $uploader->upload();
        } catch (\Aws\Exception\MultipartUploadException $e) {
            $this->client->abortMultipartUpload($e->getState()->getId());
            throw new \Exception($e->getMessage());
        } finally {
            fclose($filehandle);
        }
    }

    /**
     * ObjectUploader options.
     *
     * @param string $mimetype The mimetype of the file being uploaded.
     * @return array Options for \Aws\S3\ObjectUploader.
     */
    protected function get_uploader_options(string $mimetype): array {
        return ['params' => ['ContentType' => $mimetype]];
    }

    /**
     * Checks that the external object has the local file's content, by the hashes Cloud Storage reports.
     *
     * The S3 client only checks that an ETag exists, which a foreign or corrupt object at the key also has.
     * Cloud Storage reports CRC32C for every object and MD5 for single-part ones in x-goog-hash; every hash
     * reported must match, and an object reporting none fails.
     *
     * @param string $contenthash File content hash.
     * @param string $localpath Path of the local copy.
     * @return bool
     */
    public function verify_object($contenthash, $localpath) {
        if (!$this->is_functional()) {
            return false;
        }

        try {
            $result = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->bucketkeyprefix . $this->get_filepath_from_hash($contenthash),
            ]);
        } catch (\Aws\Exception\AwsException $e) {
            return false;
        }

        $hashes = self::parse_goog_hash((string)($result['@metadata']['headers']['x-goog-hash'] ?? ''));
        if (self::local_file_matches_hashes($localpath, $hashes)) {
            return true;
        }

        mtrace("ObjectFS: the external object of $contenthash does not match the local file's hashes, or reports none.");
        return false;
    }

    /**
     * Parses an x-goog-hash header value into base64 digests by algorithm.
     *
     * The value is "crc32c=<b64>,md5=<b64>" when the header is folded, or one pair when it is repeated (the
     * SDK keeps only the first of repeated headers). Only crc32c and md5 are returned.
     *
     * @param string $header Header value.
     * @return array Base64 digests keyed by PHP hash algorithm name.
     */
    public static function parse_goog_hash(string $header): array {
        $hashes = [];
        foreach (explode(',', $header) as $pair) {
            // Base64 padding contains '=': split on the first one only.
            $parts = explode('=', trim($pair), 2);
            if (count($parts) === 2 && in_array($parts[0], ['crc32c', 'md5'], true) && $parts[1] !== '') {
                $hashes[$parts[0]] = $parts[1];
            }
        }
        return $hashes;
    }

    /**
     * Whether a local file has every digest given.
     *
     * Cloud Storage encodes CRC32C big-endian, the byte order of PHP's raw crc32c output.
     *
     * @param string $localpath Path of the local file.
     * @param array $hashes Base64 digests keyed by PHP hash algorithm name.
     * @return bool False when no digest is given or the file cannot be read.
     */
    public static function local_file_matches_hashes(string $localpath, array $hashes): bool {
        if (empty($hashes) || !is_readable($localpath)) {
            return false;
        }

        foreach ($hashes as $algorithm => $expected) {
            $actual = hash_file($algorithm, $localpath, true);
            if ($actual === false || !hash_equals($expected, base64_encode($actual))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Tests the credentials and the bucket.
     *
     * A HEAD answers 404 for a missing bucket and a missing key alike, so the sentinel key is read with GET,
     * whose error body names which one is missing. Bucket-scoped rights suffice: no ListBuckets.
     *
     * @return \stdClass With success and details.
     */
    public function test_connection() {
        $connection = (object)['success' => true, 'details' => ''];

        if (!$this->is_functional()) {
            $connection->success = false;
            $connection->details = get_string('settings:notconfigured', 'tool_objectfs');
            return $connection;
        }

        try {
            $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $this->bucketkeyprefix . self::SENTINEL_KEY,
            ]);
        } catch (\Aws\S3\Exception\S3Exception $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchKey') {
                $connection->success = false;
                $connection->details = $this->get_exception_details($e);
            }
        } catch (\Aws\Exception\AwsException | \GuzzleHttp\Exception\InvalidArgumentException | \moodle_exception $e) {
            // A moodle_exception is a token that could not be obtained.
            $connection->success = false;
            $connection->details = $this->get_exception_details($e);
        }

        return $connection;
    }

    /**
     * A signed URL for the object: SigV4 with an HMAC key, otherwise V4 signed by the service account.
     *
     * @param string $contenthash File content hash.
     * @param array $headers Response headers set so far (Expires, Content-Disposition, Content-Type).
     * @return signed_url
     * @throws \moodle_exception When signing fails.
     */
    public function generate_presigned_url($contenthash, $headers = []) {
        if ($this->authmode === self::AUTH_HMAC) {
            return parent::generate_presigned_url($contenthash, $headers);
        }

        $now = time();
        $expires = $this->get_expiration_time($now, manager::get_header($headers, 'Expires'));
        // Signed at the start of the minute: with the expiry rounded to the minute too (#457), the URL of a file
        // is the same for a whole minute, so browsers and the signature cache reuse it.
        $signedat = $now - $now % MINSECS;

        $query = [];
        $disposition = manager::get_header($headers, 'Content-Disposition');
        if ($disposition !== '') {
            $query['response-content-disposition'] = self::normalise_content_disposition($disposition);
        }
        $contenttype = manager::get_header($headers, 'Content-Type');
        if ($contenttype !== '') {
            $query['response-content-type'] = $contenttype;
        }

        $key = $this->bucketkeyprefix . $this->get_filepath_from_hash($contenthash);
        [$url, $expires] = $this->sign_v4($key, $query, $signedat, $expires);
        return new signed_url(new \moodle_url($url), $expires);
    }

    /**
     * Signs a V4 URL; signBlob signatures are reused while at least half their validity remains.
     *
     * signBlob is a network call per URL, a local key is not, so only the former is cached. The cache key holds
     * no time, so each object and response-header set has one entry.
     *
     * @param string $key Object name.
     * @param array $query Response-header overrides to sign.
     * @param int $signedat Signing time.
     * @param int $expires Requested expiry.
     * @return array The URL and its expiry.
     * @throws \moodle_exception When signing fails.
     */
    protected function sign_v4(string $key, array $query, int $signedat, int $expires): array {
        $signer = $this->create_blob_signer();
        if (!$signer instanceof iam_blob_signer) {
            return [(new v4_signer($signer))->sign_url($this->bucket, $key, $expires - $signedat, $query, $signedat), $expires];
        }

        $cache = \cache::make_from_params(
            \cache_store::MODE_APPLICATION,
            'tool_objectfs',
            'gcssignedurl',
            [],
            ['simplekeys' => true, 'ttl' => v4_signer::MAX_EXPIRES]
        );
        $cachekey = sha1(json_encode([$signer->get_email(), $this->bucket, $key, $query]));
        $cached = $cache->get($cachekey);
        $now = time();
        // Reused only while at least half the requested validity remains, and never beyond the requested expiry.
        $usable = is_array($cached) && $cached['expires'] <= $expires &&
            $cached['expires'] - $now >= max(2 * MINSECS, intdiv($expires - $now, 2));
        if ($usable) {
            return [$cached['url'], $cached['expires']];
        }

        $url = (new v4_signer($signer))->sign_url($this->bucket, $key, $expires - $signedat, $query, $signedat);
        $cache->set($cachekey, ['url' => $url, 'expires' => $expires]);
        return [$url, $expires];
    }

    /**
     * Makes a Content-Disposition value safe for a URL-carried response header.
     *
     * Core sends the UTF-8 file name raw inside filename="...". An ASCII name is kept as it is; any other gets
     * an ASCII fallback and the RFC 6266 filename* form.
     *
     * @param string $value Content-Disposition value as Moodle sent it.
     * @return string
     */
    public static function normalise_content_disposition(string $value): string {
        $matched = preg_match('/^\s*([a-z-]+)\s*;\s*filename="(.*)"\s*$/is', $value, $matches);
        if (!$matched || mb_check_encoding($matches[2], 'ASCII')) {
            return $value;
        }
        if (!mb_check_encoding($matches[2], 'UTF-8')) {
            // Not UTF-8 either: no filename* can be stated, so the ASCII fallback alone is sent.
            return $matches[1] . '; filename="' . preg_replace('/[^\x20-\x7e]|["\\\\]/', '_', $matches[2]) . '"';
        }
        $fallback = preg_replace('/[^\x20-\x7e]|["\\\\]/u', '_', $matches[2]);
        return $matches[1] . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($matches[2]);
    }

    /**
     * Connection-test details; for a token failure they include Google's explanation, kept out of the message.
     *
     * @param \Throwable $exception
     * @return string
     */
    protected function get_exception_details(\Throwable $exception) {
        $details = parent::get_exception_details($exception);
        $debuginfo = $exception instanceof \moodle_exception ? (string)$exception->debuginfo : '';
        if ($debuginfo !== '' && !str_contains($details, $debuginfo)) {
            $details .= 'DETAILS: ' . $debuginfo . "\n";
        }
        return $details;
    }

    /**
     * Cloud Storage has no S3 object tagging.
     *
     * @return bool
     */
    public function supports_object_tagging(): bool {
        return false;
    }

    /**
     * Serves a byte range of the file from the bucket and ends the request.
     *
     * The range is read on the authenticated client: no pre-signed URL is made, so none can leak, and TLS is
     * verified by the SDK.
     *
     * @param \stored_file $file The file to send.
     * @param \stdClass $ranges With rangefrom, rangeto and length.
     * @return false When the feature is off or the file is small.
     * @throws \file_exception When the range cannot be read.
     */
    public function proxy_range_request(\stored_file $file, $ranges) {
        if (empty($this->config->proxyrangerequests) || $file->get_filesize() < self::MAX_TEMP_LIMIT) {
            return false;
        }

        $this->send_object_range($this->get_object_range($file->get_contenthash(), $ranges));
        die;
    }

    /**
     * Reads a byte range of an object as a stream.
     *
     * @param string $contenthash File content hash.
     * @param \stdClass $ranges With rangefrom and rangeto.
     * @return \Aws\Result The GetObject result; Body is not buffered.
     * @throws \file_exception When the range cannot be read or the answer is not partial content.
     */
    public function get_object_range(string $contenthash, \stdClass $ranges): \Aws\Result {
        $key = $this->bucketkeyprefix . $this->get_filepath_from_hash($contenthash);
        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Range' => 'bytes=' . (int)$ranges->rangefrom . '-' . (int)$ranges->rangeto,
                '@http' => ['stream' => true],
            ]);
        } catch (\Aws\Exception\AwsException $e) {
            $debuginfo = 'Range request for ' . $key . ' failed: HTTP ' . $e->getStatusCode() . ' ' . $e->getAwsErrorCode();
            throw new \file_exception('storedfilecannotread', '', $debuginfo);
        }

        $status = (int)($result['@metadata']['statusCode'] ?? 0);
        if ($status !== 206) {
            throw new \file_exception('storedfilecannotread', '', 'Range request for ' . $key . ' answered HTTP ' . $status);
        }
        return $result;
    }

    /**
     * Writes a partial-content response from a ranged GetObject result.
     *
     * @param \Aws\Result $result The result of get_object_range().
     * @return void
     */
    protected function send_object_range(\Aws\Result $result): void {
        $this->send_range_headers([
            'HTTP/1.1 206 Partial Content',
            'Accept-Ranges: bytes',
            'Content-Range: ' . $result['ContentRange'],
            'Content-Length: ' . $result['ContentLength'],
        ]);

        $body = $result['Body'];
        while (!$body->eof()) {
            echo $body->read(self::RANGE_CHUNK);
        }
    }

    /**
     * Sends response headers.
     *
     * @param string[] $headers Header lines, the status line first.
     * @return void
     */
    protected function send_range_headers(array $headers): void {
        foreach ($headers as $header) {
            header($header);
        }
    }

    /**
     * Tests a range read of the fixture video.
     *
     * @param \tool_objectfs\local\store\object_file_system $filesystem Filesystem to be tested.
     * @return \stdClass With result and error.
     */
    public function test_range_request($filesystem) {
        global $PAGE;
        $output = $PAGE->get_renderer('tool_objectfs');
        foreach ($output->presignedurl_tests_load_files($filesystem) as $file) {
            if ($file->get_filename() !== 'testvideo.mp4') {
                continue;
            }
            $ranges = (object)['rangefrom' => 0, 'rangeto' => 999, 'length' => 1000];
            try {
                $result = $this->get_object_range($file->get_contenthash(), $ranges);
                if ((int)$result['ContentLength'] === 1000) {
                    return (object)['result' => true];
                }
                $details = 'Content-Length ' . $result['ContentLength'];
            } catch (\file_exception $e) {
                $details = $e->debuginfo;
            }
            $a = (object)[
                'url' => self::ENDPOINT . '/' . $this->bucket . '/' . $this->bucketkeyprefix .
                    $this->get_filepath_from_hash($file->get_contenthash()),
                'httpcode' => '',
                'details' => $details,
            ];
            return (object)['result' => false, 'error' => get_string('rangerequestfailed', 'tool_objectfs', $a)];
        }
        return (object)['result' => false, 'error' => get_string('fixturefilemissing', 'tool_objectfs')];
    }

    /**
     * Store settings.
     *
     * @param \admin_settingpage $settings
     * @param \stdClass $config
     * @return \admin_settingpage
     */
    public function define_client_section($settings, $config) {
        $settings->add(new \admin_setting_heading(
            'tool_objectfs/gcs',
            new \lang_string('settings:gcs:header', 'tool_objectfs'),
            new \lang_string('settings:gcs:header_desc', 'tool_objectfs')
        ));

        $settings->add(new \admin_setting_configtext(
            'tool_objectfs/gcs_bucket',
            new \lang_string('settings:gcs:bucket', 'tool_objectfs'),
            new \lang_string('settings:gcs:bucket_help', 'tool_objectfs'),
            ''
        ));

        $authmode = self::get_authmode($config);
        $settings->add(new \admin_setting_configselect(
            'tool_objectfs/gcs_authmode',
            new \lang_string('settings:gcs:authmode', 'tool_objectfs'),
            new \lang_string('settings:gcs:authmode_help', 'tool_objectfs'),
            $authmode,
            [
                self::AUTH_METADATA => new \lang_string('settings:gcs:authmode_metadata', 'tool_objectfs'),
                self::AUTH_KEYFILE => new \lang_string('settings:gcs:authmode_keyfile', 'tool_objectfs'),
                self::AUTH_HMAC => new \lang_string('settings:gcs:authmode_hmac', 'tool_objectfs'),
            ]
        ));

        // Only the saved mode's inputs are shown, as the S3 store does for its SDK credentials.
        if ($authmode === self::AUTH_METADATA) {
            $settings->add(new \admin_setting_configtext(
                'tool_objectfs/gcs_serviceaccount',
                new \lang_string('settings:gcs:serviceaccount', 'tool_objectfs'),
                new \lang_string('settings:gcs:serviceaccount_help', 'tool_objectfs'),
                '',
                PARAM_EMAIL
            ));
        } else if ($authmode === self::AUTH_KEYFILE) {
            $settings->add(new \admin_setting_configtext(
                'tool_objectfs/gcs_keyfile',
                new \lang_string('settings:gcs:keyfile', 'tool_objectfs'),
                new \lang_string('settings:gcs:keyfile_help', 'tool_objectfs'),
                '',
                PARAM_PATH
            ));
        } else {
            $settings->add(new \admin_setting_configtext(
                'tool_objectfs/gcs_hmac_key',
                new \lang_string('settings:gcs:hmac_key', 'tool_objectfs'),
                new \lang_string('settings:gcs:hmac_key_help', 'tool_objectfs'),
                ''
            ));

            $settings->add(new \admin_setting_configpasswordunmask(
                'tool_objectfs/gcs_hmac_secret',
                new \lang_string('settings:gcs:hmac_secret', 'tool_objectfs'),
                new \lang_string('settings:gcs:hmac_secret_help', 'tool_objectfs'),
                ''
            ));
        }

        // The same key as the S3 store's, so that moving a bucket from the S3 store changes no object key.
        $settings->add(new \admin_setting_configtext(
            'tool_objectfs/key_prefix',
            new \lang_string('settings:aws:key_prefix', 'tool_objectfs'),
            new \lang_string('settings:aws:key_prefix_help', 'tool_objectfs'),
            ''
        ));

        return $settings;
    }
}
