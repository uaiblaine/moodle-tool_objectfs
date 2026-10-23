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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The pull path in a process that has not loaded filelib.
 *
 * The puller task builds the file system directly, without get_file_storage(), so in a cron process
 * lib/filelib.php (which declares file_exception) is not loaded. No test can see that state in its own
 * process, so the call runs in a child PHP process that boots the test site as admin/tool/phpunit/cli/util.php
 * does (PHPUNIT_UTIL: lib/setup.php and nothing more). A boot that rebuilds the plugin caches may include
 * filelib, so a child that finds it loaded is run once more, on the caches the first one left.
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(object_file_system::class)]
final class object_file_system_without_filelib_test extends \basic_testcase {
    /** @var string The child script; argv: Composer's autoload.php, lib/phpunit/bootstrap.php, an uncreatable directory. */
    private const CHILD = <<<'PHP'
        <?php
        define('PHPUNIT_UTIL', true);
        require($argv[1]);
        require($argv[2]);
        $blocked = $argv[3];
        $fs = new class extends \tool_objectfs\tests\test_file_system {
            /** @var string Directory that cannot be created. */
            public string $blocked = '';

            public function get_object_location_from_hash($contenthash) {
                return OBJECT_LOCATION_EXTERNAL;
            }

            protected function get_fulldir_from_hash($contenthash) {
                return $this->blocked;
            }
        };
        $fs->blocked = $blocked;
        $result = ['filelibbefore' => class_exists('file_exception', false)];
        error_reporting(0);
        try {
            $fs->copy_object_from_external_to_local_by_hash(sha1('objectfs-without-filelib'));
            $result['thrown'] = null;
        } catch (\Throwable $e) {
            $result['thrown'] = get_class($e);
        }
        echo json_encode($result);
        PHP;

    /**
     * A directory that cannot be created ends in file_exception, not in "Class not found".
     *
     * @return void
     */
    public function test_uncreatable_directory_throws_file_exception_without_filelib(): void {
        $result = $this->run_child();
        if ($result['filelibbefore']) {
            $result = $this->run_child();
        }

        // The state of the cron request: without it the test proves nothing.
        $this->assertFalse($result['filelibbefore'], 'The child process booted with lib/filelib.php loaded.');
        $this->assertSame('file_exception', $result['thrown']);
    }

    /**
     * Run the child script and decode what it prints.
     *
     * @return array Keys: filelibbefore, thrown.
     */
    private function run_child(): array {
        global $CFG;
        $directory = make_request_directory();
        // A file where the directory must go: mkdir() fails whoever runs it.
        file_put_contents($directory . '/file', 'x');
        $script = $directory . '/pull_child.php';
        file_put_contents($script, self::CHILD);
        $loader = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
        $command = [
            PHP_BINARY,
            $script,
            dirname($loader->getFileName(), 2) . '/autoload.php',
            $CFG->libdir . '/phpunit/bootstrap.php',
            $directory . '/file/sub',
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $result = json_decode((string) $stdout, true);
        $this->assertIsArray($result, "The child process exited with $exit:\n$stdout\n$stderr");
        return $result;
    }
}
