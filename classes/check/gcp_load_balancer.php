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
 * Client-IP settings behind a Google Cloud load balancer.
 *
 * @package   tool_objectfs
 * @copyright 2026 Anderson Blaine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\check;

use core\check\check;
use core\check\result;

/**
 * Checks, on the request that shows the report, that Moodle reads the client's address the way the load balancer
 * reports it.
 *
 * A Google Cloud external Application Load Balancer sends X-Forwarded-For as "[supplied,]client,load-balancer".
 * Core's getremoteaddr() takes the last entry not in reverseproxyignore {@see getremoteaddr()}, so without the load
 * balancer's address there every user is the load balancer, and an over-broad list falls back to an entry the client
 * supplied. The check compares what core decides with what the load balancer recorded, rather than reading the
 * settings alone (docs/gcp/DESIGN.md section 4.1).
 */
class gcp_load_balancer extends check {
    /**
     * Name shown in the status report.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('settings:gcs:lbcheck', 'tool_objectfs');
    }

    /**
     * Link to the setting that most often needs changing.
     *
     * @return \action_link|null
     */
    public function get_action_link(): ?\action_link {
        $url = new \moodle_url('/admin/search.php', ['query' => 'reverseproxyignore']);
        return new \action_link($url, get_string('reverseproxyignore', 'admin'));
    }

    /**
     * The verdict for the current request.
     *
     * @return result
     */
    public function get_result(): result {
        global $CFG;

        $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded === '') {
            return new result(result::NA, get_string('settings:gcs:lbcheck_na', 'tool_objectfs'));
        }

        $skip = (int)($CFG->getremoteaddrconf ?? GETREMOTEADDR_SKIP_DEFAULT);
        if ($skip & GETREMOTEADDR_SKIP_HTTP_X_FORWARDED_FOR) {
            return new result(result::ERROR, get_string('settings:gcs:lbcheck_xffskipped', 'tool_objectfs'));
        }
        if (!($skip & GETREMOTEADDR_SKIP_HTTP_CLIENT_IP)) {
            return new result(result::ERROR, get_string('settings:gcs:lbcheck_clientip', 'tool_objectfs'));
        }

        $entries = array_values(array_filter(array_map('trim', explode(',', $forwarded)), 'strlen'));
        if (count($entries) < 2) {
            return new result(result::WARNING, get_string('settings:gcs:lbcheck_shortxff', 'tool_objectfs'));
        }

        // What core decided, against the two entries the load balancer itself appended.
        $client = (string)getremoteaddr('');
        $loadbalancer = self::address_of($entries[count($entries) - 1]);
        $seen = self::address_of($entries[count($entries) - 2]);

        if ($client === $loadbalancer) {
            return new result(result::ERROR, get_string('settings:gcs:lbcheck_lbip', 'tool_objectfs', s($loadbalancer)));
        }
        if ($client !== $seen) {
            $a = (object)['client' => s($client), 'expected' => s($seen)];
            return new result(result::WARNING, get_string('settings:gcs:lbcheck_spoofable', 'tool_objectfs', $a));
        }
        return new result(result::OK, get_string('settings:gcs:lbcheck_ok', 'tool_objectfs', s($client)));
    }

    /**
     * The address of an X-Forwarded-For entry, without a port, as getremoteaddr() reads it.
     *
     * Mirrors the port handling of {@see getremoteaddr()}, so the comparison is with what core actually decided.
     *
     * @param string $entry One entry.
     * @return string The cleaned address, '' when it is none.
     */
    private static function address_of(string $entry): string {
        if (substr_count($entry, ':') > 1) {
            if (preg_match('/\[(.*)\]:/', $entry, $matches)) {
                $entry = $matches[1];
            }
        } else if (substr_count($entry, ':') === 1) {
            $entry = explode(':', $entry)[0];
        }
        return (string)cleanremoteaddr($entry);
    }
}
