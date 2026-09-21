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
 * Plausibility checks for video tracking heartbeats.
 *
 * @package   mod_videoquiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoquiz;

use stdClass;

/**
 * Validates sequence, elapsed time, playback rate and watched intervals.
 */
class tracking_manager {
    /** @var float Timing tolerance for network/player latency. */
    private const LATENCY_TOLERANCE = 3.0;

    /**
     * Validates one heartbeat.
     *
     * @param stdClass $activity Activity.
     * @param stdClass $progress Progress row.
     * @param stdClass|false|null $session Playback session.
     * @param array $data Tracking payload.
     * @param int $now Server timestamp.
     * @return array
     */
    public function validate(stdClass $activity, stdClass $progress, $session, array $data, int $now): array {
        $duration = max(0.0, (float)$data['duration']);
        $current = max(0.0, min($duration, (float)$data['currentposition']));
        $rate = max(0.25, min(4.0, (float)$data['playbackrate']));
        if ((float)$activity->maxplaybackrate > 0) {
            $rate = min($rate, (float)$activity->maxplaybackrate);
        }
        if ($session && (int)$data['sequence'] <= (int)$session->sequence) {
            return [
                'accepted' => false,
                'reason' => 'stale',
                'correctposition' => (float)$session->lastposition,
            ];
        }

        $elapsed = $session ? max(0.0, min(120.0, $now - (int)$session->lastheartbeat)) : 10.0;
        if ($session && !empty($session->lastclienttime) && !empty($data['clienttime'])) {
            $clientelapsed = (int)$data['clienttime'] - (int)$session->lastclienttime;
            if ($clientelapsed > 0 && $clientelapsed <= 120 && (int)$data['clienttime'] <= $now + 300) {
                $elapsed = (float)$clientelapsed;
            }
        }
        $maxcontent = max(self::LATENCY_TOLERANCE, ($elapsed * $rate) + self::LATENCY_TOLERANCE);
        $segments = segment_manager::decode($progress->watchedsegments ?? '');
        $segment = segment_manager::validate_interval([
            (float)$data['segmentstart'],
            (float)$data['segmentend'],
        ], $duration);
        $acceptedsegment = null;
        if ($segment) {
            [$start, $end] = $segment;
            if (($end - $start) > $maxcontent) {
                $end = min($duration, $start + $maxcontent);
            }
            $anchored = !empty($activity->allowseek)
                || ($session && abs($start - (float)$session->lastposition) <= self::LATENCY_TOLERANCE + $rate)
                || segment_manager::contains_position($segments, $start, self::LATENCY_TOLERANCE)
                || (!$session && $start <= self::LATENCY_TOLERANCE);
            if ($anchored) {
                $acceptedsegment = $end > $start ? [$start, $end] : null;
            }
        }

        $correctposition = $current;
        $seekblocked = false;
        if (empty($activity->allowseek)) {
            $alreadywatched = segment_manager::contains_position($segments, $current, 0.5);
            $legitimateend = $session
                ? (float)$session->lastposition + $maxcontent
                : ($acceptedsegment[1] ?? segment_manager::furthest_watched_position($segments));
            if (($current > $legitimateend + 0.5 && !$alreadywatched) || !$acceptedsegment) {
                if ($current > $legitimateend + 0.5 && !$alreadywatched) {
                    $correctposition = min($duration, $session
                        ? (float)$session->lastposition
                        : segment_manager::furthest_watched_position($segments));
                    $seekblocked = true;
                    $acceptedsegment = null;
                }
            }
        }

        $watchtime = 0.0;
        if ($acceptedsegment) {
            $contentseconds = $acceptedsegment[1] - $acceptedsegment[0];
            $watchtime = min($elapsed + self::LATENCY_TOLERANCE, $contentseconds / max(0.25, $rate));
        }

        return [
            'accepted' => true,
            'segment' => $acceptedsegment,
            'currentposition' => $correctposition,
            'correctposition' => $correctposition,
            'duration' => $duration,
            'playbackrate' => $rate,
            'watchtime' => round(max(0.0, $watchtime), 3),
            'seekblocked' => $seekblocked,
            'reason' => $seekblocked ? 'seekblocked' : '',
        ];
    }
}
