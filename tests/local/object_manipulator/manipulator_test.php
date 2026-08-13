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

/**
 * Tests for the manipulator base class reached-count contract.
 *
 * @covers \tool_objectfs\local\object_manipulator\manipulator
 * @package   tool_objectfs
 * @copyright Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class manipulator_test extends \tool_objectfs\tests\testcase {
    /**
     * Builds a manipulator whose manipulate_object() throws for records
     * carrying a truthy "fail" property.
     *
     * @param int $maxtaskruntime Time budget for the run.
     * @return manipulator
     */
    private function build_manipulator(int $maxtaskruntime = MINSECS): manipulator {
        $config = manager::get_objectfs_config();
        $config->maxtaskruntime = $maxtaskruntime;
        $logger = new \tool_objectfs\log\aggregate_logger();

        return new class ($this->filesystem, $config, $logger) extends manipulator {
            /**
             * Throws for records marked to fail, otherwise reports duplicated.
             *
             * @param \stdClass $objectrecord
             * @return int OBJECT_LOCATION_*
             */
            public function manipulate_object(\stdClass $objectrecord) {
                if (!empty($objectrecord->fail)) {
                    throw new \Exception('Simulated transient storage error');
                }
                return OBJECT_LOCATION_DUPLICATED;
            }
        };
    }

    public function test_execute_returns_count_of_reached_records(): void {
        $records = [
            $this->create_local_object('manipulator reached 1'),
            $this->create_local_object('manipulator reached 2'),
            $this->create_local_object('manipulator reached 3'),
        ];

        ob_start();
        $reached = $this->build_manipulator()->execute($records);
        ob_end_clean();

        $this->assertSame(3, $reached);
    }

    public function test_execute_reports_trailing_failures_as_unreached(): void {
        $records = [
            $this->create_local_object('manipulator trail 1'),
            $this->create_local_object('manipulator trail 2'),
            $this->create_local_object('manipulator trail 3'),
        ];
        $records[2]->fail = true;

        ob_start();
        $reached = $this->build_manipulator()->execute($records);
        ob_end_clean();

        // The trailing failure is retried next run: only two records count.
        $this->assertSame(2, $reached);
    }

    public function test_execute_counts_mid_batch_failures_as_reached(): void {
        $records = [
            $this->create_local_object('manipulator mid 1'),
            $this->create_local_object('manipulator mid 2'),
            $this->create_local_object('manipulator mid 3'),
        ];
        $records[1]->fail = true;

        ob_start();
        $reached = $this->build_manipulator()->execute($records);
        ob_end_clean();

        // A failure followed by a success is a per-object problem, not an
        // outage: the cursor must advance past it.
        $this->assertSame(3, $reached);
    }

    public function test_execute_counts_fully_failed_batch_as_reached(): void {
        $records = [
            $this->create_local_object('manipulator allfail 1'),
            $this->create_local_object('manipulator allfail 2'),
        ];
        $records[0]->fail = true;
        $records[1]->fail = true;

        ob_start();
        $reached = $this->build_manipulator()->execute($records);
        ob_end_clean();

        // An entirely failed batch advances in full so a persistently failing
        // object can never stall the cursor.
        $this->assertSame(2, $reached);
    }

    public function test_execute_reaches_nothing_when_time_budget_is_spent(): void {
        $records = [
            $this->create_local_object('manipulator capped 1'),
            $this->create_local_object('manipulator capped 2'),
        ];

        ob_start();
        $reached = $this->build_manipulator(0)->execute($records);
        ob_end_clean();

        $this->assertSame(0, $reached);
    }
}
