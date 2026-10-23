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
 * Signs with the attached service account through the IAM Credentials API.
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\local\store\gcs\signing;

use tool_objectfs\local\store\gcs\auth\token_provider;

/**
 * Signs through IAM Credentials signBlob, for a service account whose key Moodle never sees.
 *
 * The account needs iam.serviceAccounts.signBlob on itself (Service Account Token Creator), and the token's
 * scopes must cover the IAM Credentials API.
 */
class iam_blob_signer implements blob_signer {
    /** @var string signBlob endpoint before the account email. */
    const ENDPOINT = 'https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/';

    /** @var token_provider Tokens of the account that signs. */
    private token_provider $tokens;

    /**
     * Constructor.
     *
     * @param token_provider $tokens Tokens of the account that signs.
     */
    public function __construct(token_provider $tokens) {
        $this->tokens = $tokens;
    }

    /**
     * The signing account's email.
     *
     * @return string
     */
    public function get_email(): string {
        return $this->tokens->get_email();
    }

    /**
     * Signs through signBlob.
     *
     * @param string $data
     * @return string Raw signature.
     * @throws \moodle_exception When the call fails.
     */
    public function sign(string $data): string {
        // The email becomes a path segment of the request: anything beyond an address's characters could redirect it.
        $email = $this->get_email();
        if (!preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+$/', $email)) {
            throw new \moodle_exception('settings:gcs:autherror', 'tool_objectfs', '', 'signBlob', 'invalid service account email');
        }

        global $CFG;
        // Cron tasks reach this without get_file_storage(), so filelib is not loaded.
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Bearer ' . $this->tokens->get_token(),
            'Content-Type: application/json',
        ]);
        $body = $curl->post(self::ENDPOINT . $email . ':signBlob', json_encode(['payload' => base64_encode($data)]));
        if ($curl->get_errno()) {
            throw new \moodle_exception('settings:gcs:autherror', 'tool_objectfs', '', 'signBlob', $curl->error);
        }

        $result = json_decode((string)$body, true);
        $signedblob = is_array($result) ? ($result['signedBlob'] ?? null) : null;
        $signature = is_string($signedblob) ? base64_decode($signedblob, true) : false;
        if ($signature === false || $signature === '') {
            $error = is_array($result) && is_array($result['error'] ?? null) ? (string)($result['error']['message'] ?? '') : '';
            throw new \moodle_exception('settings:gcs:autherror', 'tool_objectfs', '', 'signBlob', $error);
        }
        return $signature;
    }
}
