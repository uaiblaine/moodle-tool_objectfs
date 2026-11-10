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

namespace tool_objectfs\local\report;

/**
 * File type categories of the mime type report (FORK.md P23).
 *
 * Before upstream #479, image and video files fell through to the ELSE branch, whose
 * substr(mimetype, 0, ...) yields the major type on PostgreSQL and an empty string on MariaDB
 * and MySQL; H5P packages counted as "other".
 *
 * @covers \tool_objectfs\local\report\mime_type_report_builder
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mime_type_report_builder_test extends \advanced_testcase {
    /**
     * Store a file whose type core derives from its extension.
     *
     * @param string $filename File name.
     * @param int $size Content length; distinct per type, because the report rows are keyed by size.
     */
    private function create_file(string $filename, int $size): void {
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'tool_objectfs',
            'filearea' => 'test',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], str_repeat('x', $size));
    }

    /**
     * Images, videos and H5P packages get categories of their own on every database.
     */
    public function test_media_and_h5p_categories(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->delete_records('files');
        $this->create_file('picture.png', 11);
        $this->create_file('clip.mp4', 23);
        $this->create_file('package.h5p', 37);
        $this->create_file('document.pdf', 53);

        $report = (new mime_type_report_builder())->build_report(0);
        $sums = [];
        foreach ($report->get_rows() as $row) {
            $sums[$row->datakey] = (int)$row->objectsum;
        }

        ksort($sums);
        $this->assertSame(['H5P' => 37, 'image' => 11, 'pdf' => 53, 'video' => 23], $sums);
    }
}
