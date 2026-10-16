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
 * Token provider that caches tokens until shortly before they expire.
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\local\store\gcs\auth;

/**
 * Caches tokens in the instance and in an application cache shared by every web node and cron process.
 *
 * Without the shared cache, every web request would fetch a token: a POST to Google for a key file.
 */
abstract class cached_token_provider implements token_provider {
    /** @var int Seconds before expiry when a cached token is replaced, so no request starts with a dying one. */
    const REFRESH_MARGIN = 300;

    /** @var array|null The current token and its expiry, as ['token' => string, 'expires' => int]. */
    private ?array $current = null;

    /**
     * Identifies the account, so that two accounts never share a cache entry.
     *
     * @return string
     */
    abstract protected function get_identity(): string;

    /**
     * Fetches a new token.
     *
     * @return array ['token' => string, 'expires' => int unix time].
     * @throws \moodle_exception When the token endpoint fails.
     */
    abstract protected function fetch_token(): array;

    /**
     * A token valid for at least REFRESH_MARGIN seconds.
     *
     * @return string
     * @throws \moodle_exception When no token can be obtained.
     */
    public function get_token(): string {
        $key = sha1(static::class . '|' . $this->get_identity());
        $cache = self::get_cache();

        $entry = $this->current ?? $cache->get($key);
        if (!is_array($entry) || $entry['expires'] - self::REFRESH_MARGIN <= time()) {
            $entry = $this->fetch_token();
            $cache->set($key, $entry);
        }

        $this->current = $entry;
        return $entry['token'];
    }

    /**
     * The shared token cache.
     *
     * @return \cache_application
     */
    protected static function get_cache(): \cache_application {
        return \cache::make_from_params(\cache_store::MODE_APPLICATION, 'tool_objectfs', 'gcstoken', [], ['simplekeys' => true]);
    }

    /**
     * Reads a token endpoint's JSON answer.
     *
     * Google's explanation goes to the debug info, not the message: the message can reach whoever requested a
     * file, and the explanation can name the service account.
     *
     * @param string $body Response body.
     * @return array ['token' => string, 'expires' => int unix time].
     * @throws \moodle_exception When the answer carries no token.
     */
    protected static function parse_token_response(string $body): array {
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['access_token']) || !is_string($data['access_token']) || empty($data['expires_in'])) {
            $error = is_array($data) ? ($data['error_description'] ?? $data['error'] ?? '') : '';
            $debuginfo = is_string($error) ? $error : '';
            throw new \moodle_exception('settings:gcs:autherror', 'tool_objectfs', '', 'token endpoint', $debuginfo);
        }
        return ['token' => $data['access_token'], 'expires' => time() + (int)$data['expires_in']];
    }
}
