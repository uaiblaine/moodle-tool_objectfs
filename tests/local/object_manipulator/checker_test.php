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

namespace tool_objectfs\local\object_manipulator;

use tool_objectfs\local\manager;
use tool_objectfs\local\object_manipulator\candidates\candidates_finder;
use tool_objectfs\local\object_manipulator\candidates\candidates_cursor;

/**
 * Tests for object checker.
 *
 * @covers \tool_objectfs\local\object_manipulator\checker
 * @package   tool_objectfs
 * @copyright Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class checker_test extends \tool_objectfs\tests\testcase {
    /** @var string $manipulator */
    protected $manipulator = checker::class;

    /** @var checker Checker */
    protected $checker;

    protected function setUp(): void {
        parent::setUp();
        $config = manager::get_objectfs_config();
        manager::set_objectfs_config($config);
        $this->logger = new \tool_objectfs\log\aggregate_logger();
        $this->checker = new checker($this->filesystem, $config, $this->logger);
        ob_start();
    }

    protected function tearDown(): void {
        ob_end_clean();
        parent::tearDown();
    }

    public function test_checker_get_location_local_if_object_is_local(): void {
        global $DB;
        $file = $this->create_local_object();
        $location = $DB->get_field('tool_objectfs_objects', 'location', ['contenthash' => $file->contenthash]);
        $this->assertEquals('string', gettype($location));
        $this->assertEquals(OBJECT_LOCATION_LOCAL, $location);
    }

    public function test_checker_get_location_duplicated_if_object_is_duplicated(): void {
        global $DB;
        $file = $this->create_duplicated_object();
        $location = $DB->get_field('tool_objectfs_objects', 'location', ['contenthash' => $file->contenthash]);
        $this->assertEquals('string', gettype($location));
        $this->assertEquals(OBJECT_LOCATION_DUPLICATED, $location);
    }

    public function test_checker_get_location_external_if_object_is_external(): void {
        global $DB;
        $file = $this->create_remote_object();
        $location = $DB->get_field('tool_objectfs_objects', 'location', ['contenthash' => $file->contenthash]);
        $this->assertEquals('string', gettype($location));
        $this->assertEquals(OBJECT_LOCATION_EXTERNAL, $location);
    }

    public function test_checker_get_candidate_objects_will_not_get_objects(): void {
        $localobject = $this->create_local_object('test_checker_get_candidate_objects_will_not_get_objects_local');
        $remoteobject = $this->create_remote_object('test_checker_get_candidate_objects_will_not_get_objects_remote');
        $duplicatedbject = $this->create_duplicated_object('test_checker_get_candidate_objects_will_not_get_objects_duplicated');

        self::assertFalse($this->objects_contain_hash($localobject->contenthash));
        self::assertFalse($this->objects_contain_hash($remoteobject->contenthash));
        self::assertFalse($this->objects_contain_hash($duplicatedbject->contenthash));
    }

    public function test_checker_get_candidate_objects_will_get_object(): void {
        global $DB;
        $localobject = $this->create_local_object('test_checker_get_candidate_objects_will_get_object');
        $DB->delete_records('tool_objectfs_objects', ['contenthash' => $localobject->contenthash]);

        self::assertTrue($this->objects_contain_hash($localobject->contenthash));
    }

    public function test_checker_can_update_object(): void {
        global $DB;
        $localobject = $this->create_local_object('test_checker_can_update_object');
        $localobject->id = null;
        $DB->delete_records('tool_objectfs_objects', ['contenthash' => $localobject->contenthash]);
        $this->checker->execute([$localobject]);
        $dblocation = $DB->get_field('tool_objectfs_objects', 'location', ['contenthash' => $localobject->contenthash]);

        $this->assertEquals('string', gettype($dblocation));
        $this->assertEquals(OBJECT_LOCATION_LOCAL, $dblocation);
        self::assertFalse($this->objects_contain_hash($localobject->contenthash));
    }

    public function test_checker_manipulate_object_method_will_get_correct_location_if_file_is_local(): void {
        $file = $this->create_local_object();
        $reflection = new \ReflectionMethod(checker::class, "manipulate_object");
        $reflection->setAccessible(true);
        $this->assertEquals(OBJECT_LOCATION_LOCAL, $reflection->invokeArgs($this->checker, [$file]));
    }

    public function test_checker_manipulate_object_method_will_get_correct_location_if_file_is_duplicated(): void {
        $file = $this->create_duplicated_object();
        $reflection = new \ReflectionMethod(checker::class, "manipulate_object");
        $reflection->setAccessible(true);
        $this->assertEquals(OBJECT_LOCATION_DUPLICATED, $reflection->invokeArgs($this->checker, [$file]));
    }

    public function test_checker_manipulate_object_method_will_get_correct_location_if_file_is_external(): void {
        $file = $this->create_remote_object();
        $reflection = new \ReflectionMethod(checker::class, "manipulate_object");
        $reflection->setAccessible(true);
        $this->assertEquals(OBJECT_LOCATION_EXTERNAL, $reflection->invokeArgs($this->checker, [$file]));
    }

    public function test_checker_manipulate_object_method_will_get_error_location_on_error_file(): void {
        $file = $this->create_error_object();
        $reflection = new \ReflectionMethod(checker::class, "manipulate_object");
        $reflection->setAccessible(true);
        $this->assertEquals(OBJECT_LOCATION_ERROR, $reflection->invokeArgs($this->checker, [$file]));
    }

    public function test_checker_keyset_pages_through_all_candidates(): void {
        global $DB;

        // Create several untracked files (no objectfs row) so they are checker candidates.
        $expected = [];
        for ($i = 0; $i < 5; $i++) {
            $object = $this->create_local_object("keyset candidate $i");
            $expected[$object->contenthash] = true;
            $DB->delete_records('tool_objectfs_objects', ['contenthash' => $object->contenthash]);
        }

        // Force a small batch so the keyset cursor pages across runs.
        $config = manager::get_objectfs_config();
        $config->filesystem = get_class($this->filesystem);
        $config->batchsize = 2;
        $finder = new candidates_finder($this->manipulator, $config);

        // Page until a short batch marks the end of the pass.
        $seen = [];
        $pages = 0;
        do {
            $batch = $finder->get();
            $pages++;
            $this->assertLessThanOrEqual(2, count($batch));
            foreach ($batch as $hash => $record) {
                // No contenthash is returned on more than one page.
                $this->assertArrayNotHasKey($hash, $seen);
                $seen[$hash] = true;
            }
        } while (count($batch) === 2);

        // Paging actually happened, and every candidate was found exactly once.
        $this->assertGreaterThan(1, $pages);
        foreach (array_keys($expected) as $hash) {
            $this->assertArrayHasKey($hash, $seen);
        }

        // The pass completed, so the cursor reset to the start sentinel.
        $this->assertSame('', (new candidates_cursor('checker_lasthash', ''))->get());
    }

    public function test_checker_keyset_resumes_from_cursor(): void {
        global $DB;

        $hashes = [];
        for ($i = 0; $i < 4; $i++) {
            $object = $this->create_local_object("keyset resume $i");
            $hashes[] = $object->contenthash;
            $DB->delete_records('tool_objectfs_objects', ['contenthash' => $object->contenthash]);
        }
        sort($hashes, SORT_STRING);

        // Seed the cursor at the first hash; the query is exclusive (>).
        set_config('checker_lasthash', $hashes[0], 'tool_objectfs');

        $config = manager::get_objectfs_config();
        $config->filesystem = get_class($this->filesystem);
        $finder = new candidates_finder($this->manipulator, $config);
        $batch = $finder->get();

        $this->assertArrayNotHasKey($hashes[0], $batch);
        $this->assertArrayHasKey($hashes[1], $batch);
        $this->assertArrayHasKey($hashes[2], $batch);
        $this->assertArrayHasKey($hashes[3], $batch);
    }
}
