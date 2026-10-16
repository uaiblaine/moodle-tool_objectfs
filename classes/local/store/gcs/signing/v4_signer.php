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
 * Cloud Storage V4 signed URLs.
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\local\store\gcs\signing;

/**
 * GOOG4-RSA-SHA256 signed GET URLs on the path-style XML endpoint, signed by a service account.
 *
 * Only the host header is signed and the payload is unsigned, which is all a browser GET needs. The canonical
 * request follows Google's conformance vectors (tests/local/store/gcs/signing/v4_signer_test.php).
 */
class v4_signer {
    /** @var string Signing algorithm. */
    const ALGORITHM = 'GOOG4-RSA-SHA256';

    /** @var string Host of the XML endpoint. */
    const HOST = 'storage.googleapis.com';

    /** @var string Credential-scope location; Google's own conformance vectors sign with it. */
    const LOCATION = 'auto';

    /** @var int Longest validity Cloud Storage accepts, in seconds. */
    const MAX_EXPIRES = 604800;

    /** @var blob_signer Signs the string to sign. */
    private blob_signer $signer;

    /**
     * Constructor.
     *
     * @param blob_signer $signer
     */
    public function __construct(blob_signer $signer) {
        $this->signer = $signer;
    }

    /**
     * A signed GET URL.
     *
     * @param string $bucket Bucket name.
     * @param string $key Object name; slashes stay path separators.
     * @param int $expiresin Validity in seconds from $now.
     * @param array $query Extra query parameters to sign, by name (response-content-disposition, ...).
     * @param int $now Signing time.
     * @return string
     * @throws \moodle_exception When signing fails.
     */
    public function sign_url(string $bucket, string $key, int $expiresin, array $query, int $now): string {
        $prepared = self::prepare($this->signer->get_email(), $bucket, $key, $expiresin, $query, $now);
        return $prepared['url'] . '&X-Goog-Signature=' . bin2hex($this->signer->sign($prepared['stringtosign']));
    }

    /**
     * Builds the canonical request, the string to sign and the URL without its signature.
     *
     * @param string $email Service account email.
     * @param string $bucket Bucket name.
     * @param string $key Object name.
     * @param int $expiresin Validity in seconds, 1 to MAX_EXPIRES.
     * @param array $query Extra query parameters, by name.
     * @param int $now Signing time.
     * @return array With canonicalrequest, stringtosign and url.
     * @throws \coding_exception When the validity is out of range.
     */
    public static function prepare(string $email, string $bucket, string $key, int $expiresin, array $query, int $now): array {
        if ($expiresin < 1 || $expiresin > self::MAX_EXPIRES) {
            throw new \coding_exception('V4 signed URL validity out of range: ' . $expiresin);
        }

        $datetime = gmdate('Ymd\THis\Z', $now);
        $scope = gmdate('Ymd', $now) . '/' . self::LOCATION . '/storage/goog4_request';
        $params = [
            'X-Goog-Algorithm' => self::ALGORITHM,
            'X-Goog-Credential' => $email . '/' . $scope,
            'X-Goog-Date' => $datetime,
            'X-Goog-Expires' => (string)$expiresin,
            'X-Goog-SignedHeaders' => 'host',
        ] + $query;

        $encoded = [];
        foreach ($params as $name => $value) {
            $encoded[rawurlencode((string)$name)] = rawurlencode((string)$value);
        }
        ksort($encoded, SORT_STRING);
        $querystring = implode('&', array_map(
            fn(string $name, string $value): string => $name . '=' . $value,
            array_keys($encoded),
            $encoded
        ));

        $path = '/' . rawurlencode($bucket) . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
        $canonicalrequest = implode("\n", [
            'GET',
            $path,
            $querystring,
            'host:' . self::HOST,
            '',
            'host',
            'UNSIGNED-PAYLOAD',
        ]);
        $stringtosign = implode("\n", [self::ALGORITHM, $datetime, $scope, hash('sha256', $canonicalrequest)]);

        return [
            'canonicalrequest' => $canonicalrequest,
            'stringtosign' => $stringtosign,
            'url' => 'https://' . self::HOST . $path . '?' . $querystring,
        ];
    }
}
