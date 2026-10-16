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

namespace tool_objectfs\check;

use core\check\result;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Client-address check behind a Google Cloud load balancer, against core's own getremoteaddr().
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(gcp_load_balancer::class)]
final class gcp_load_balancer_test extends \advanced_testcase {
    /** @var array The request variables before the test. */
    private array $server;

    /**
     * Saves the request variables the test changes.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->server = $_SERVER;
    }

    /**
     * Restores the request variables.
     */
    protected function tearDown(): void {
        $_SERVER = $this->server;
        parent::tearDown();
    }

    /**
     * Requests and settings, and the verdict.
     *
     * The load balancer is 34.120.1.1; the client it saw is 203.0.113.5. Entries are written without spaces, as the
     * load balancer sends them.
     *
     * @return array
     */
    public static function request_provider(): array {
        $lb = '203.0.113.5,34.120.1.1';
        $spoofed = '198.51.100.7,' . $lb;
        return [
            'not through a proxy' => [null, 3, '', result::NA],
            'X-Forwarded-For ignored (core default)' => [$lb, 3, '', result::ERROR],
            'Client-IP read' => [$lb, 0, '34.120.1.1', result::ERROR],
            'load balancer not ignored: everyone is the load balancer' => [$lb, 1, '', result::ERROR],
            'load balancer ignored' => [$lb, 1, '34.120.1.1', result::OK],
            'client-supplied entry before them is not used' => [$spoofed, 1, '34.120.1.1', result::OK],
            'ignore list covers the client: a supplied entry is used' => [
                $spoofed,
                1,
                '34.120.1.1,203.0.113.0/24',
                result::WARNING,
            ],
            'single entry' => ['203.0.113.5', 1, '', result::WARNING],
            'entries with ports: core drops them, and still takes the load balancer' => [
                '203.0.113.5:4711,34.120.1.1:80',
                1,
                '',
                result::ERROR,
            ],
        ];
    }

    /**
     * The verdict follows what core decides for the request.
     *
     * @param string|null $forwarded X-Forwarded-For, null for none.
     * @param int $getremoteaddrconf
     * @param string $reverseproxyignore
     * @param string $status Expected result status.
     */
    #[DataProvider('request_provider')]
    public function test_result(?string $forwarded, int $getremoteaddrconf, string $reverseproxyignore, string $status): void {
        global $CFG;
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CLIENT_IP']);
        if ($forwarded !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $forwarded;
        }
        $_SERVER['REMOTE_ADDR'] = '35.191.0.10';
        $CFG->getremoteaddrconf = $getremoteaddrconf;
        $CFG->reverseproxyignore = $reverseproxyignore;

        $this->assertSame($status, (new gcp_load_balancer())->get_result()->get_status());
    }

    /**
     * The OK verdict names the address Moodle records.
     */
    public function test_ok_names_the_client(): void {
        global $CFG;
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5,34.120.1.1';
        $CFG->getremoteaddrconf = GETREMOTEADDR_SKIP_HTTP_CLIENT_IP;
        $CFG->reverseproxyignore = '34.120.1.1';

        $this->assertStringContainsString('203.0.113.5', (new gcp_load_balancer())->get_result()->get_summary());
    }
}
