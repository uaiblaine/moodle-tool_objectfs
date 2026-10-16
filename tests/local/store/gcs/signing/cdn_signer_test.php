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
 * Cloud CDN signed URLs.
 *
 * The expected signatures were computed outside PHP, with Python's hmac and base64.urlsafe_b64encode over the same
 * URL strings, following Google's documented scheme (HMAC-SHA1 of the URL up to KeyName, raw 16-byte key).
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(cdn_signer::class)]
final class cdn_signer_test extends \advanced_testcase {
    /** @var string Test key, base64url as gcloud writes it. */
    private const KEY = 'nZtRohdNF9m3cKM24IcK4w==';

    /** @var string Object name. */
    private const OBJECT = 'site/ab/cd/abcdef0123456789abcdef0123456789abcdef01';

    /** @var int Expiry of the vectors. */
    private const EXPIRES = 1792130400;

    /**
     * A URL without overrides matches the independent vector.
     */
    public function test_plain_url_matches_the_vector(): void {
        $signer = new cdn_signer('https://media.example.org/', 'moodle-key-1', self::KEY);

        $this->assertSame(
            'https://media.example.org/' . self::OBJECT . '?Expires=1792130400&KeyName=moodle-key-1' .
                '&Signature=0SzrEVNzKM0wmHy4jIIN5lqASms=',
            $signer->sign_url(self::OBJECT, [], self::EXPIRES)
        );
    }

    /**
     * Response-header overrides are encoded, placed before Expires, and signed.
     */
    public function test_overrides_are_signed_before_expires(): void {
        $signer = new cdn_signer('https://media.example.org', 'moodle-key-1', rtrim(self::KEY, '='));

        $url = $signer->sign_url(self::OBJECT, [
            'response-content-disposition' => 'inline; filename="vídeo.mp4"',
            'response-content-type' => 'video/mp4',
        ], self::EXPIRES);

        $this->assertSame(
            'https://media.example.org/' . self::OBJECT .
                '?response-content-disposition=inline%3B%20filename%3D%22v%C3%ADdeo.mp4%22&response-content-type=video%2Fmp4' .
                '&Expires=1792130400&KeyName=moodle-key-1&Signature=8cDXLonbOgHCLUF8CTXJbWId0n4=',
            $url
        );
    }

    /**
     * \moodle_url re-encodes the query the same way, so the signed part reaches the browser unchanged.
     */
    public function test_moodle_url_keeps_the_signed_part(): void {
        $signer = new cdn_signer('https://media.example.org', 'moodle-key-1', self::KEY);
        $url = $signer->sign_url(self::OBJECT, ['response-content-disposition' => 'inline; filename="a b.mp4"'], self::EXPIRES);

        $out = (new \moodle_url($url))->out(false);

        $this->assertSame(strstr($url, '&Signature=', true), strstr($out, '&Signature=', true));
    }

    /**
     * Unusable settings.
     *
     * @return array
     */
    public static function unusable_provider(): array {
        return [
            'http base' => ['http://media.example.org', 'moodle-key-1', self::KEY],
            'key name with a space' => ['https://media.example.org', 'moodle key', self::KEY],
            'key of 15 bytes' => ['https://media.example.org', 'moodle-key-1', base64_encode(str_repeat('k', 15))],
            'key not base64' => ['https://media.example.org', 'moodle-key-1', '%%%'],
        ];
    }

    /**
     * Unusable settings are refused at construction.
     *
     * @param string $baseurl
     * @param string $keyname
     * @param string $key
     */
    #[DataProvider('unusable_provider')]
    public function test_unusable_settings_are_refused(string $baseurl, string $keyname, string $key): void {
        $this->expectException(\moodle_exception::class);
        new cdn_signer($baseurl, $keyname, $key);
    }
}
