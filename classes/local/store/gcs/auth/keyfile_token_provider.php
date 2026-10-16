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
 * Tokens and signatures from a service-account key file.
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\local\store\gcs\auth;

use tool_objectfs\local\store\gcs\signing\blob_signer;

/**
 * A service-account JSON key file: JWT-bearer tokens, and local signatures for V4 signed URLs.
 *
 * For hosts outside Google Cloud. The file is read on first use, so a missing or broken file fails the request
 * that needs it, never the settings page.
 */
class keyfile_token_provider extends cached_token_provider implements blob_signer {
    /** @var string Token endpoint; fixed rather than read from the file, so the file cannot redirect the assertion. */
    const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** @var string Scope of the tokens: objects only. */
    const SCOPE = 'https://www.googleapis.com/auth/devstorage.read_write';

    /** @var string Absolute path of the key file. */
    private string $path;

    /** @var string|null Service account email, once the file is read. */
    private ?string $email = null;

    /** @var \OpenSSLAsymmetricKey|null Private key, once the file is read. */
    private ?\OpenSSLAsymmetricKey $key = null;

    /**
     * Constructor.
     *
     * @param string $path Absolute path, or a path relative to $CFG->dataroot.
     */
    public function __construct(string $path) {
        $this->path = self::resolve_path($path);
    }

    /**
     * Resolves a configured path: absolute as given, otherwise below $CFG->dataroot.
     *
     * @param string $path
     * @return string
     */
    public static function resolve_path(string $path): string {
        global $CFG;
        return str_starts_with($path, '/') ? $path : $CFG->dataroot . '/' . $path;
    }

    /**
     * Reads and checks the key file once.
     *
     * @return void
     * @throws \moodle_exception When the file is unreadable or not a service-account key.
     */
    private function load(): void {
        if ($this->key !== null) {
            return;
        }
        $content = is_readable($this->path) ? file_get_contents($this->path) : false;
        $data = $content === false ? null : json_decode($content, true);
        if (
            !is_array($data) || ($data['type'] ?? '') !== 'service_account' ||
            !validate_email($data['client_email'] ?? '') || empty($data['private_key'])
        ) {
            throw new \moodle_exception('settings:gcs:autherror', 'tool_objectfs', '', 'key file', 'unusable key file');
        }
        $key = openssl_pkey_get_private($data['private_key']);
        if ($key === false) {
            throw new \moodle_exception('settings:gcs:autherror', 'tool_objectfs', '', 'key file', 'unusable private key');
        }
        $this->email = $data['client_email'];
        $this->key = $key;
    }

    /**
     * The key's service account is its cache identity.
     *
     * @return string
     */
    protected function get_identity(): string {
        return $this->get_email();
    }

    /**
     * The service account email from the key file.
     *
     * @return string
     */
    public function get_email(): string {
        $this->load();
        return $this->email;
    }

    /**
     * Exchanges a signed JWT for an access token.
     *
     * @return array
     * @throws \moodle_exception
     */
    protected function fetch_token(): array {
        $now = time();
        $assertion = $this->create_jwt([
            'iss' => $this->get_email(),
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URI,
            'iat' => $now,
            'exp' => $now + HOURSECS,
        ]);

        $curl = new \curl();
        $body = $curl->post(self::TOKEN_URI, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);
        if ($curl->get_errno()) {
            throw new \moodle_exception('settings:gcs:autherror', 'tool_objectfs', '', 'token endpoint', $curl->error);
        }
        return self::parse_token_response((string)$body);
    }

    /**
     * An RS256 JWT with the given claims.
     *
     * @param array $claims
     * @return string
     * @throws \moodle_exception When signing fails.
     */
    public function create_jwt(array $claims): string {
        $unsigned = self::base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' .
            self::base64url(json_encode($claims, JSON_UNESCAPED_SLASHES));
        return $unsigned . '.' . self::base64url($this->sign($unsigned));
    }

    /**
     * Signs with the file's private key.
     *
     * @param string $data
     * @return string Raw signature.
     * @throws \moodle_exception
     */
    public function sign(string $data): string {
        $this->load();
        if (!openssl_sign($data, $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new \moodle_exception('settings:gcs:autherror', 'tool_objectfs', '', 'key file', 'signing failed');
        }
        return $signature;
    }

    /**
     * Base64url without padding (RFC 7515).
     *
     * @param string $data
     * @return string
     */
    public static function base64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
