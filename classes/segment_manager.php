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
 * Watched segment utilities.
 *
 * @package   mod_videoquiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoquiz;

/**
 * Normalises and queries watched video intervals.
 */
class segment_manager {
    /**
     * Decodes stored segments.
     *
     * @param string|null $encoded JSON value.
     * @return array
     */
    public static function decode(?string $encoded): array {
        $segments = json_decode((string)$encoded, true);
        return is_array($segments) ? self::merge($segments) : [];
    }

    /**
     * Encodes normalised segments.
     *
     * @param array $segments Segment list.
     * @return string
     */
    public static function encode(array $segments): string {
        return json_encode(self::merge($segments), JSON_UNESCAPED_SLASHES);
    }

    /**
     * Validates one interval against duration.
     *
     * @param array $segment Two numeric positions.
     * @param float $duration Duration.
     * @return array|null
     */
    public static function validate_interval(array $segment, float $duration): ?array {
        if (count($segment) < 2 || !is_numeric($segment[0]) || !is_numeric($segment[1])) {
            return null;
        }
        $start = max(0.0, (float)$segment[0]);
        $end = max(0.0, (float)$segment[1]);
        if ($duration > 0) {
            $start = min($duration, $start);
            $end = min($duration, $end);
        }
        return $end > $start ? [$start, $end] : null;
    }

    /**
     * Merges overlapping or adjacent intervals.
     *
     * @param array $segments Segment list.
     * @param float $duration Optional maximum duration.
     * @return array
     */
    public static function merge(array $segments, float $duration = 0.0): array {
        $clean = [];
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $valid = self::validate_interval($segment, $duration);
            if ($valid) {
                $clean[] = $valid;
            }
        }
        usort($clean, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($clean as $segment) {
            if (!$merged) {
                $merged[] = $segment;
                continue;
            }
            $last = count($merged) - 1;
            if ($segment[0] <= $merged[$last][1] + 0.5) {
                $merged[$last][1] = max($merged[$last][1], $segment[1]);
            } else {
                $merged[] = $segment;
            }
        }
        return array_map(static fn(array $segment): array => [
            round($segment[0], 3),
            round($segment[1], 3),
        ], $merged);
    }

    /**
     * Returns unique watched seconds.
     *
     * @param array $segments Segment list.
     * @return float
     */
    public static function unique_seconds(array $segments): float {
        $seconds = 0.0;
        foreach (self::merge($segments) as $segment) {
            $seconds += max(0.0, $segment[1] - $segment[0]);
        }
        return $seconds;
    }

    /**
     * Tests whether a position is inside a watched interval.
     *
     * @param array $segments Segment list.
     * @param float $position Position.
     * @param float $tolerance Tolerance.
     * @return bool
     */
    public static function contains_position(array $segments, float $position, float $tolerance = 0.0): bool {
        foreach ($segments as $segment) {
            if ($position >= (float)$segment[0] - $tolerance && $position <= (float)$segment[1] + $tolerance) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the furthest watched position.
     *
     * @param array $segments Segment list.
     * @return float
     */
    public static function furthest_watched_position(array $segments): float {
        $furthest = 0.0;
        foreach ($segments as $segment) {
            $furthest = max($furthest, (float)$segment[1]);
        }
        return $furthest;
    }
}
