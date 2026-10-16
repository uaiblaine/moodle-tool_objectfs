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
use tool_objectfs\local\store\gcs\client;

/**
 * Bucket probe check and the registration of the store's checks (FORK.md P19).
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(gcs_bucket::class)]
final class gcs_bucket_test extends \advanced_testcase {
    /**
     * A client whose probe gives a fixed answer, or throws.
     *
     * @param string $outcome 'pass', 'fail' or 'throw'.
     * @return client
     */
    private static function probing_client(string $outcome): client {
        return new class (null, $outcome) extends client {
            /**
             * Constructor; object_client declares the first parameter, so the outcome comes second.
             *
             * @param \stdClass|null $config
             * @param string $outcome
             */
            public function __construct(
                $config,
                /** @var string The probe's outcome. */
                private string $outcome = 'pass'
            ) {
                parent::__construct($config);
            }

            /**
             * A fixed answer, with markup in its details.
             *
             * @return \stdClass
             */
            public function run_probe(): \stdClass {
                if ($this->outcome === 'throw') {
                    throw new \RuntimeException('unexpected failure');
                }
                return (object)['success' => $this->outcome === 'pass', 'details' => 'probe <b>detail</b>'];
            }
        };
    }

    /**
     * The verdict follows the probe.
     */
    public function test_result_follows_the_probe(): void {
        $this->assertSame(result::OK, (new gcs_bucket(self::probing_client('pass')))->get_result()->get_status());

        $failed = (new gcs_bucket(self::probing_client('fail')))->get_result();
        $this->assertSame(result::ERROR, $failed->get_status());
        // The details are store and SDK text in an HTML summary: escaped.
        $this->assertStringContainsString('probe &lt;b&gt;detail&lt;/b&gt;', $failed->get_summary());
    }

    /**
     * An unexpected exception stays this check's ERROR instead of breaking the status report.
     */
    public function test_unexpected_exception_is_an_error_result(): void {
        $result = (new gcs_bucket(self::probing_client('throw')))->get_result();

        $this->assertSame(result::ERROR, $result->get_status());
        $this->assertStringContainsString('unexpected failure', $result->get_summary());
    }

    /**
     * Without the Cloud Storage store the check does not apply.
     */
    public function test_not_applicable_to_other_stores(): void {
        $this->resetAfterTest();
        set_config('filesystem', '\tool_objectfs\s3_file_system', 'tool_objectfs');

        $this->assertSame(result::NA, (new gcs_bucket())->get_result()->get_status());
    }

    /**
     * The status report lists the store's checks only when the Cloud Storage store is selected.
     */
    public function test_status_checks_registration(): void {
        global $CFG;
        require_once($CFG->dirroot . '/admin/tool/objectfs/lib.php');
        $this->resetAfterTest();
        $classes = fn(): array => array_map('get_class', tool_objectfs_status_checks());

        set_config('filesystem', '\tool_objectfs\s3_file_system', 'tool_objectfs');
        $this->assertNotContains(gcs_bucket::class, $classes());

        set_config('filesystem', '\tool_objectfs\gcs_file_system', 'tool_objectfs');
        $this->assertContains(gcs_bucket::class, $classes());
        $this->assertContains(gcp_load_balancer::class, $classes());
    }
}
