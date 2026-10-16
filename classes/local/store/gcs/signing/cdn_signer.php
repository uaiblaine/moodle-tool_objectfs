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
 * Cloud CDN signed URLs.
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\local\store\gcs\signing;

/**
 * Cloud CDN signed URLs for one object each: Expires, KeyName and an HMAC-SHA1 Signature over the whole URL.
 *
 * Neither URL prefixes nor signed cookies are offered: object names are content hashes, so any prefix would
 * cover every object in the bucket (docs/gcp/DESIGN.md, section 3.1).
 *
 * The query is encoded with rawurlencode(), as \moodle_url re-encodes it, so the URL a browser sends is byte for
 * byte the one signed.
 */
class cdn_signer {
    /** @var string CDN origin and optional path, without a trailing slash. */
    private string $baseurl;

    /** @var string Name of the key on the backend bucket. */
    private string $keyname;

    /** @var string The 16-byte key, raw. */
    private string $key;

    /**
     * Constructor.
     *
     * @param string $baseurl https URL of the CDN host in front of the backend bucket.
     * @param string $keyname Key name as added to the backend bucket.
     * @param string $encodedkey The key as gcloud writes it: base64url, padding optional.
     * @throws \moodle_exception When the base URL, key name or key is not usable.
     */
    public function __construct(string $baseurl, string $keyname, string $encodedkey) {
        $key = base64_decode(strtr(trim($encodedkey), '-_', '+/'), true);
        if (
            !str_starts_with($baseurl, 'https://') || !preg_match('/^[A-Za-z0-9_-]{1,63}$/', $keyname) ||
            $key === false || strlen($key) !== 16
        ) {
            throw new \moodle_exception('settings:gcs:cdnmisconfigured', 'tool_objectfs');
        }
        $this->baseurl = rtrim($baseurl, '/');
        $this->keyname = $keyname;
        $this->key = $key;
    }

    /**
     * A signed URL for an object.
     *
     * @param string $objectname Object name in the backend bucket; slashes stay path separators.
     * @param array $query Query parameters to sign before Expires, by name (response-content-disposition, ...).
     * @param int $expires Unix time the URL stops working.
     * @return string
     */
    public function sign_url(string $objectname, array $query, int $expires): string {
        $parts = [];
        foreach ($query as $name => $value) {
            $parts[] = rawurlencode((string)$name) . '=' . rawurlencode((string)$value);
        }
        // Cloud CDN wants Expires then KeyName, and signs the URL up to and including KeyName.
        $parts[] = 'Expires=' . $expires;
        $parts[] = 'KeyName=' . $this->keyname;

        $path = implode('/', array_map('rawurlencode', explode('/', $objectname)));
        $unsigned = $this->baseurl . '/' . $path . '?' . implode('&', $parts);
        $signature = strtr(base64_encode(hash_hmac('sha1', $unsigned, $this->key, true)), '+/', '-_');
        return $unsigned . '&Signature=' . $signature;
    }
}
