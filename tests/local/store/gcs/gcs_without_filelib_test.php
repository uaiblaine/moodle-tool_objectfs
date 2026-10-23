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

use PHPUnit\Framework\Attributes\CoversClass;
use tool_objectfs\local\store\gcs\auth\keyfile_token_provider;
use tool_objectfs\local\store\gcs\signing\iam_blob_signer;

/**
 * The token and signature requests in a process that has not loaded filelib.
 *
 * The pusher, puller and deleter tasks fetch tokens from cron, where nothing in objectfs loads lib/filelib.php
 * (which declares \curl). No test can see that state in its own process, so each call runs in a child PHP
 * process that boots the test site as admin/tool/phpunit/cli/util.php does (PHPUNIT_UTIL: lib/setup.php and
 * nothing more). A boot that rebuilds the plugin caches may include filelib, so a child that finds it loaded is
 * run once more, on the caches the first one left.
 *
 * The child cannot queue \curl::mock_response() before the call, because that would load the class under test.
 * The signer queues it from its stub token provider, which runs after the signer constructed its \curl; the
 * key-file provider reaches a host that core's curl security helper blocks, which fails before any connection.
 * The metadata provider has no such seam (it ignores the helper and its host is a constant), so it is not covered.
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(keyfile_token_provider::class)]
#[CoversClass(iam_blob_signer::class)]
final class gcs_without_filelib_test extends \basic_testcase {
    /** @var string The child script; argv: Composer's autoload.php, lib/phpunit/bootstrap.php, case, key file. */
    private const CHILD = <<<'PHP'
        <?php
        define('PHPUNIT_UTIL', true);
        require($argv[1]);
        require($argv[2]);
        $result = ['curlbefore' => class_exists('curl', false)];
        error_reporting(0);
        try {
            if ($argv[3] === 'iam') {
                $tokens = new class implements \tool_objectfs\local\store\gcs\auth\token_provider {
                    public function get_token(): string {
                        \curl::mock_response(json_encode(['signedBlob' => base64_encode('signature')]));
                        return 'token';
                    }

                    public function get_email(): string {
                        return 'moodle@project.iam.gserviceaccount.com';
                    }
                };
                $result['value'] = (new \tool_objectfs\local\store\gcs\signing\iam_blob_signer($tokens))->sign('data');
            } else {
                $CFG->curlsecurityblockedhosts = 'oauth2.googleapis.com';
                (new \tool_objectfs\local\store\gcs\auth\keyfile_token_provider($argv[4]))->get_token();
                $result['value'] = null;
            }
            $result['thrown'] = null;
        } catch (\Throwable $e) {
            $result['thrown'] = get_class($e);
        }
        echo json_encode($result);
        PHP;

    /**
     * The signBlob request constructs its \curl without filelib loaded.
     *
     * @return void
     */
    public function test_iam_signer_loads_filelib(): void {
        $result = $this->run_child('iam');

        $this->assertNull($result['thrown']);
        $this->assertSame('signature', $result['value']);
    }

    /**
     * The key-file token request constructs its \curl without filelib loaded; the blocked host ends it in
     * the provider's own exception, not in "Class not found".
     *
     * @return void
     */
    public function test_keyfile_provider_loads_filelib(): void {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $path = make_request_directory() . '/key.json';
        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'client_email' => 'moodle@project.iam.gserviceaccount.com',
            'private_key' => $pem,
        ]));

        $result = $this->run_child('keyfile', $path);

        $this->assertSame(\core\exception\moodle_exception::class, $result['thrown']);
    }

    /**
     * Run a child case and decode what it prints.
     *
     * @param string $case iam or keyfile.
     * @param string $keyfile Key file path for the keyfile case.
     * @return array Keys: curlbefore, thrown, value.
     */
    private function run_child(string $case, string $keyfile = ''): array {
        $result = $this->run_process($case, $keyfile);
        if ($result['curlbefore']) {
            $result = $this->run_process($case, $keyfile);
        }

        // The state of the cron request: without it the test proves nothing.
        $this->assertFalse($result['curlbefore'], 'The child process booted with lib/filelib.php loaded.');
        return $result;
    }

    /**
     * One child process.
     *
     * @param string $case
     * @param string $keyfile
     * @return array
     */
    private function run_process(string $case, string $keyfile): array {
        global $CFG;
        $script = make_request_directory() . '/gcs_child.php';
        file_put_contents($script, self::CHILD);
        $loader = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
        $command = [
            PHP_BINARY,
            $script,
            dirname($loader->getFileName(), 2) . '/autoload.php',
            $CFG->libdir . '/phpunit/bootstrap.php',
            $case,
            $keyfile,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        // The blocked request triggers core's url_blocked event, whose debugging() lands on stdout under a
        // developer debug level, so the answer is the last line.
        $lines = preg_split('/\R/', trim((string) $stdout));
        $result = json_decode((string) end($lines), true);
        $this->assertIsArray($result, "The child process exited with $exit:\n$stdout\n$stderr");
        return $result;
    }
}
