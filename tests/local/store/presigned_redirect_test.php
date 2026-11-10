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

namespace tool_objectfs\local\store;

use tool_objectfs\tests\test_file_system;

/**
 * Which path a pre-signed redirect takes: the direct 303 or core redirect() (FORK.md P22).
 *
 * The direct path ends in exit, so the file system under test records the location instead of
 * sending it. Under PHPUnit CLI_SCRIPT is true, so the real should_use_core_redirect() always
 * answers yes: the tests choose its answer, and core redirect() then throws as it does in any CLI
 * script, which redirect_to_presigned_url() reports as a failed redirect.
 *
 * @covers \tool_objectfs\local\store\object_file_system::redirect_to_presigned_url
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class presigned_redirect_test extends \advanced_testcase {
    /**
     * A file system whose client signs a fixed URL and whose direct redirect is recorded.
     *
     * @param string $url The URL the client signs.
     * @param bool $usecore What should_use_core_redirect() answers.
     * @return object_file_system
     */
    private function make_filesystem(string $url, bool $usecore): object_file_system {
        $filesystem = new class extends test_file_system {
            /** @var bool What should_use_core_redirect() answers. */
            public $usecore = false;

            /** @var string[] Locations the direct path would have sent. */
            public $sent = [];

            /**
             * The answer the test chose.
             *
             * @return bool
             */
            protected function should_use_core_redirect(): bool {
                return $this->usecore;
            }

            /**
             * Record the location instead of sending it and exiting.
             *
             * @param string $url The sanitised location.
             */
            protected function send_presigned_redirect(string $url): void {
                $this->sent[] = $url;
            }
        };
        $filesystem->usecore = $usecore;
        $filesystem->externalclient = new class ($url) {
            /** @var string The URL to sign. */
            private $url;

            /**
             * Constructor.
             *
             * @param string $url The URL to sign.
             */
            public function __construct(string $url) {
                $this->url = $url;
            }

            /**
             * A signed URL for any content hash.
             *
             * @param string $contenthash Ignored.
             * @param array $headers Ignored.
             * @return signed_url
             */
            public function generate_presigned_url($contenthash, $headers = []) {
                return new signed_url(new \moodle_url($this->url), time() + 600);
            }
        };
        return $filesystem;
    }

    /**
     * Without a reason to use core, the sanitised URL is sent directly.
     */
    public function test_direct_path_sends_the_signed_url(): void {
        $this->resetAfterTest();
        $url = 'https://storage.googleapis.com/bucket/ab/cd/abcd?X-Goog-Signature=deadbeef&X-Goog-Expires=600';
        $filesystem = $this->make_filesystem($url, false);

        $result = $filesystem->redirect_to_presigned_url('abcd');
        $this->resetDebugging();

        $this->assertNotFalse($result);
        $this->assertCount(1, $filesystem->sent);
        // The signed URL may carry the origin anchor signed_url adds.
        $this->assertStringStartsWith($url, $filesystem->sent[0]);
    }

    /**
     * When core must render the redirect, nothing is sent directly.
     */
    public function test_core_path_is_taken_when_core_redirect_is_required(): void {
        $this->resetAfterTest();
        $filesystem = $this->make_filesystem('https://storage.googleapis.com/bucket/ab/cd/abcd?X-Goog-Signature=x', true);

        $result = $filesystem->redirect_to_presigned_url('abcd');
        $messages = $this->getDebuggingMessages();
        $this->resetDebugging();

        $this->assertFalse($result);
        $this->assertSame([], $filesystem->sent);
        $this->assertStringContainsString(
            'Failed to redirect to pre-signed url',
            implode("\n", array_map(fn($m) => $m->message, $messages))
        );
    }

    /**
     * A URL the sanitiser rejects goes to core, never out as a bare Location.
     */
    public function test_rejected_url_falls_back_to_core(): void {
        $this->resetAfterTest();
        $filesystem = $this->make_filesystem('ftp://storage.example.com/ab/cd/abcd', false);

        $result = $filesystem->redirect_to_presigned_url('abcd');
        $this->resetDebugging();

        $this->assertFalse($result);
        $this->assertSame([], $filesystem->sent);
    }
}
