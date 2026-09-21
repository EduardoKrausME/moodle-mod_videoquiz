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
 * Timecode helper.
 *
 * @package   mod_videoquiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoquiz;

/**
 * Converts timeline timecodes to and from seconds.
 */
class timecode {
    /**
     * Parses seconds, MM:SS or HH:MM:SS.
     *
     * @param string $value Input.
     * @return float|null
     */
    public static function parse(string $value): ?float {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float)$value >= 0 ? (float)$value : null;
        }
        $parts = explode(':', $value);
        if (count($parts) < 2 || count($parts) > 3) {
            return null;
        }
        foreach ($parts as $part) {
            if (!is_numeric($part)) {
                return null;
            }
        }
        if (count($parts) === 2) {
            [$minutes, $seconds] = array_map('floatval', $parts);
            if ($seconds < 0 || $seconds >= 60 || $minutes < 0) {
                return null;
            }
            return ($minutes * 60) + $seconds;
        }
        [$hours, $minutes, $seconds] = array_map('floatval', $parts);
        if ($seconds < 0 || $seconds >= 60 || $minutes < 0 || $minutes >= 60 || $hours < 0) {
            return null;
        }
        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    /**
     * Formats seconds for display.
     *
     * @param float $seconds Seconds.
     * @return string
     */
    public static function format(float $seconds): string {
        $seconds = max(0, (int)round($seconds));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;
        return $hours > 0
            ? sprintf('%02d:%02d:%02d', $hours, $minutes, $secs)
            : sprintf('%02d:%02d', $minutes, $secs);
    }
}
