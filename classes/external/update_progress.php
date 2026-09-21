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
 * AJAX endpoint for watched-progress tracking.
 *
 * @package   mod_videoquiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoquiz\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use invalid_parameter_exception;
use mod_videoquiz\videoquiz_manager;

/**
 * Stores one actually played interval.
 */
class update_progress extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, get_string('ws:cmid', 'videoquiz')),
            'currentposition' => new external_value(PARAM_FLOAT, get_string('ws:currentposition', 'videoquiz')),
            'duration' => new external_value(PARAM_FLOAT, get_string('ws:duration', 'videoquiz')),
            'segmentstart' => new external_value(PARAM_FLOAT, get_string('ws:segmentstart', 'videoquiz')),
            'segmentend' => new external_value(PARAM_FLOAT, get_string('ws:segmentend', 'videoquiz')),
            'playbackrate' => new external_value(PARAM_FLOAT, get_string('ws:playbackrate', 'videoquiz')),
            'sequence' => new external_value(PARAM_INT, get_string('ws:sequence', 'videoquiz')),
            'sessionkey' => new external_value(PARAM_ALPHANUMEXT, get_string('ws:sessionkey', 'videoquiz')),
            'clienttime' => new external_value(PARAM_INT, get_string('ws:clienttime', 'videoquiz')),
            'playerstate' => new external_value(PARAM_ALPHA, get_string('ws:playerstate', 'videoquiz')),
        ]);
    }

    /**
     * Executes update.
     *
     * @param int $cmid Course module ID.
     * @param float $currentposition Current position.
     * @param float $duration Duration.
     * @param float $segmentstart Segment start.
     * @param float $segmentend Segment end.
     * @param float $playbackrate Playback rate.
     * @param int $sequence Session sequence number.
     * @param string $sessionkey Playback session key.
     * @param int $clienttime Client timestamp.
     * @param string $playerstate Player state.
     * @return array
     */
    public static function execute(int    $cmid, float $currentposition, float $duration, float $segmentstart,
                                   float  $segmentend, float $playbackrate, int $sequence, string $sessionkey, int $clienttime,
                                   string $playerstate): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), compact(
            'cmid', 'currentposition', 'duration', 'segmentstart', 'segmentend', 'playbackrate',
            'sequence', 'sessionkey', 'clienttime', 'playerstate'
        ));
        $cm = get_coursemodule_from_id('videoquiz', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/videoquiz:view', $context);
        if (isguestuser() || !is_enrolled($context, $USER, 'mod/videoquiz:view', true)) {
            throw new \required_capability_exception($context, 'mod/videoquiz:view', 'nopermissions', '');
        }
        if ($params['duration'] <= 0 || $params['duration'] > 604800 || $params['currentposition'] < 0 ||
            $params['segmentstart'] < 0 || $params['segmentend'] < $params['segmentstart'] ||
            $params['playbackrate'] < 0.25 || $params['playbackrate'] > 4 ||
            $params['sequence'] < 1 || strlen($params['sessionkey']) < 16 ||
            $params['clienttime'] <= 0 || $params['clienttime'] > time() + 300 ||
            !in_array($params['playerstate'], ['playing', 'paused', 'seeking', 'ended', 'hidden', 'closed'], true)) {
            throw new invalid_parameter_exception(get_string('invalidtrackingdata', 'videoquiz'));
        }
        $activity = $DB->get_record('videoquiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $result = (new videoquiz_manager())->update_progress($activity, $cm, $USER->id, $params);
        $progress = $result['progress'];
        return [
            'accepted' => !empty($result['accepted']),
            'reason' => (string)$result['reason'],
            'correctposition' => (float)$result['correctposition'],
            'percent' => (float)$progress->percent,
            'lastposition' => (float)$progress->lastposition,
            'score' => (float)$progress->score,
            'completed' => !empty($progress->completed),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'accepted' => new external_value(PARAM_BOOL, get_string('ws:accepted', 'videoquiz')),
            'reason' => new external_value(PARAM_ALPHAEXT, get_string('ws:reason', 'videoquiz')),
            'correctposition' => new external_value(PARAM_FLOAT, get_string('ws:correctposition', 'videoquiz')),
            'percent' => new external_value(PARAM_FLOAT, get_string('ws:percent', 'videoquiz')),
            'lastposition' => new external_value(PARAM_FLOAT, get_string('ws:lastposition', 'videoquiz')),
            'score' => new external_value(PARAM_FLOAT, get_string('ws:score', 'videoquiz')),
            'completed' => new external_value(PARAM_BOOL, get_string('ws:completed', 'videoquiz')),
        ]);
    }
}
