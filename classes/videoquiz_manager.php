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
 * Core progress, grading and completion calculations.
 *
 * @package   mod_videoquiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoquiz;

use cm_info;
use completion_info;
use stdClass;

/**
 * Maintains server-authoritative progress and grades.
 */
class videoquiz_manager {
    /**
     * Loads or creates the progress row.
     *
     * @param int $activityid Activity ID.
     * @param int $userid User ID.
     * @return stdClass
     */
    public function get_or_create_progress(int $activityid, int $userid): stdClass {
        global $DB;

        $conditions = ['videoquizid' => $activityid, 'userid' => $userid];
        $record = $DB->get_record('videoquiz_progress', $conditions);
        if ($record) {
            return $record;
        }

        $now = time();
        $record = (object)[
            'videoquizid' => $activityid,
            'userid' => $userid,
            'duration' => 0,
            'lastposition' => 0,
            'uniquewatched' => 0,
            'totalwatchtime' => 0,
            'percent' => 0,
            'watchedsegments' => '[]',
            'score' => 0,
            'completed' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        try {
            $record->id = $DB->insert_record('videoquiz_progress', $record);
        } catch (\dml_write_exception $exception) {
            $record = $DB->get_record('videoquiz_progress', $conditions, '*', MUST_EXIST);
        }
        return $record;
    }

    /**
     * Updates watched progress from one actually played segment.
     *
     * @param stdClass $activity Activity record.
     * @param cm_info|stdClass $cm Course module.
     * @param int $userid User ID.
     * @param float $duration Video duration.
     * @param float $currentposition Current position.
     * @param float $segmentstart Segment start.
     * @param float $segmentend Segment end.
     * @param float $playbackrate Playback rate.
     * @return stdClass Updated progress.
     */
    public function update_progress(stdClass $activity, $cm, int $userid, array $data): array {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $progress = $this->get_or_create_progress((int)$activity->id, $userid);
        $progress = $DB->get_record_sql(
            'SELECT * FROM {videoquiz_progress} WHERE id = :id FOR UPDATE',
            ['id' => $progress->id],
            MUST_EXIST
        );

        $session = $DB->get_record('videoquiz_sessions', [
            'videoquizid' => $activity->id,
            'userid' => $userid,
            'sessionkey' => $data['sessionkey'],
        ]);
        if ($session) {
            $session = $DB->get_record_sql(
                'SELECT * FROM {videoquiz_sessions} WHERE id = :id FOR UPDATE',
                ['id' => $session->id],
                MUST_EXIST
            );
        }

        $now = time();
        $tracking = (new tracking_manager())->validate($activity, $progress, $session, $data, $now);
        if (!$tracking['accepted']) {
            $transaction->allow_commit();
            return [
                'progress' => $progress,
                'accepted' => false,
                'reason' => (string)$tracking['reason'],
                'correctposition' => (float)$tracking['correctposition'],
            ];
        }

        // Mandatory unanswered questions are server-side barriers too, so crafted seeks cannot bypass them.
        $blocking = $DB->get_records_sql(
            'SELECT q.*
               FROM {videoquiz_questions} q
              WHERE q.videoquizid = :activityid
                AND q.required = 1
                AND q.timepoint < :position
                AND NOT EXISTS (
                    SELECT 1
                      FROM {videoquiz_attempts} a
                     WHERE a.questionid = q.id
                       AND a.userid = :userid
                )
           ORDER BY q.timepoint ASC, q.id ASC',
            [
                'activityid' => $activity->id,
                'position' => (float)$tracking['currentposition'] - 0.01,
                'userid' => $userid,
            ],
            0,
            1
        );
        $blocking = $blocking ? reset($blocking) : false;
        if ($blocking && (float)$tracking['currentposition'] > (float)$blocking->timepoint + 0.25) {
            $blockposition = (float)$blocking->timepoint;
            if (!empty($tracking['segment'])) {
                if ((float)$tracking['segment'][0] >= $blockposition) {
                    $tracking['segment'] = null;
                } else {
                    $tracking['segment'][1] = min((float)$tracking['segment'][1], $blockposition);
                    if ($tracking['segment'][1] <= $tracking['segment'][0]) {
                        $tracking['segment'] = null;
                    }
                }
            }
            $tracking['currentposition'] = $blockposition;
            $tracking['correctposition'] = $blockposition;
            $tracking['reason'] = 'interactionrequired';
            $tracking['watchtime'] = !empty($tracking['segment'])
                ? min(
                    (float)$tracking['watchtime'],
                    ($tracking['segment'][1] - $tracking['segment'][0]) /
                    max(0.25, (float)$tracking['playbackrate'])
                )
                : 0.0;
        }

        $segments = segment_manager::decode($progress->watchedsegments ?? '');
        if (!empty($tracking['segment'])) {
            $segments = segment_manager::merge(array_merge($segments, [$tracking['segment']]), (float)$tracking['duration']);
        }
        $progress->watchedsegments = segment_manager::encode($segments);
        $progress->duration = max((float)$progress->duration, (float)$tracking['duration']);
        $progress->lastposition = (float)$tracking['currentposition'];
        $progress->uniquewatched = segment_manager::unique_seconds($segments);
        $progress->totalwatchtime = round((float)$progress->totalwatchtime + (float)$tracking['watchtime'], 3);
        $progress->percent = $progress->duration > 0
            ? round(min(100.0, ($progress->uniquewatched / $progress->duration) * 100.0), 2)
            : 0.0;
        $progress->timemodified = $now;
        $DB->update_record('videoquiz_progress', $progress);

        if (!$session) {
            $session = (object)[
                'videoquizid' => $activity->id,
                'userid' => $userid,
                'sessionkey' => $data['sessionkey'],
                'timestart' => $now,
                'timeend' => 0,
                'watchtime' => 0,
                'lastposition' => (float)$tracking['currentposition'],
                'sequence' => 0,
                'lastheartbeat' => $now,
                'lastclienttime' => (int)$data['clienttime'],
                'playerstate' => 'paused',
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $session->id = $DB->insert_record('videoquiz_sessions', $session);
        }
        $session->watchtime = round((float)$session->watchtime + (float)$tracking['watchtime'], 3);
        $session->lastposition = (float)$tracking['currentposition'];
        $session->sequence = (int)$data['sequence'];
        $session->lastheartbeat = $now;
        $session->lastclienttime = (int)$data['clienttime'];
        $session->playerstate = (string)$data['playerstate'];
        $session->timemodified = $now;
        if (in_array($data['playerstate'], ['ended', 'closed'], true)) {
            $session->timeend = $now;
        }
        $DB->update_record('videoquiz_sessions', $session);
        $transaction->allow_commit();

        $progress = $this->refresh_user($activity, $cm, $userid);
        return [
            'progress' => $progress,
            'accepted' => true,
            'reason' => (string)$tracking['reason'],
            'correctposition' => (float)$tracking['correctposition'],
        ];
    }

    /**
     * Recalculates score, completion and gradebook state.
     *
     * @param stdClass $activity Activity record.
     * @param cm_info|stdClass $cm Course module.
     * @param int $userid User ID.
     * @return stdClass Updated progress.
     */
    public function refresh_user(stdClass $activity, $cm, int $userid): stdClass {
        global $CFG, $DB;

        $progress = $this->get_or_create_progress((int)$activity->id, $userid);
        $questionscore = $this->get_question_score((int)$activity->id, $userid);
        $watched = min(100.0, max(0.0, (float)$progress->percent));

        if ($activity->grademode === 'watched') {
            $score = $watched;
        } else if ($activity->grademode === 'combined') {
            $weight = min(100.0, max(0.0, (float)$activity->questionweight));
            $score = (($questionscore * $weight) + ($watched * (100.0 - $weight))) / 100.0;
        } else {
            $score = $questionscore;
        }

        $complete = true;
        $hasrule = false;
        if (!empty($activity->completionwatch)) {
            $hasrule = true;
            $complete = $complete && $watched >= (float)$activity->completionpercent;
        }
        if (!empty($activity->completionquestions)) {
            $hasrule = true;
            $complete = $complete && $this->required_questions_answered((int)$activity->id, $userid);
        }
        if (!empty($activity->completiongrade)) {
            $hasrule = true;
            $complete = $complete && $score >= (float)$activity->mingrade;
        }
        if (!$hasrule) {
            $complete = false;
        }

        $progress->score = round($score, 2);
        $progress->completed = $complete ? 1 : 0;
        $progress->timemodified = time();
        $DB->update_record('videoquiz_progress', $progress);

        require_once($CFG->libdir . '/gradelib.php');
        $rawgrade = ((float)$activity->grade > 0) ? ((float)$activity->grade * ($score / 100.0)) : null;
        grade_update('mod/videoquiz', $activity->course, 'mod', 'videoquiz', $activity->id, 0, [
            $userid => (object)['userid' => $userid, 'rawgrade' => $rawgrade],
        ]);

        $course = get_course($activity->course);
        $completion = new completion_info($course);
        if ($completion->is_enabled($cm)) {
            // Individual custom rules may change before the aggregate activity state becomes complete.
            $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
        }

        return $progress;
    }

    /**
     * Calculates weighted best-question result as a percentage.
     *
     * @param int $activityid Activity ID.
     * @param int $userid User ID.
     * @return float
     */
    public function get_question_score(int $activityid, int $userid): float {
        global $DB;

        $questions = $DB->get_records('videoquiz_questions', ['videoquizid' => $activityid], 'sortorder, timepoint, id');
        if (!$questions) {
            return 0.0;
        }
        $totalpoints = 0.0;
        $earned = 0.0;
        foreach ($questions as $question) {
            $points = max(0.0, (float)$question->points);
            $totalpoints += $points;
            $best = $DB->get_field_sql(
                'SELECT MAX(fraction) FROM {videoquiz_attempts} WHERE questionid = :questionid AND userid = :userid',
                ['questionid' => $question->id, 'userid' => $userid]
            );
            $earned += $points * min(1.0, max(0.0, (float)$best));
        }
        return $totalpoints > 0 ? ($earned / $totalpoints) * 100.0 : 0.0;
    }

    /**
     * Tests whether each mandatory question has at least one submitted answer.
     *
     * @param int $activityid Activity ID.
     * @param int $userid User ID.
     * @return bool
     */
    public function required_questions_answered(int $activityid, int $userid): bool {
        global $DB;

        $required = $DB->get_records('videoquiz_questions', ['videoquizid' => $activityid, 'required' => 1], '', 'id');
        foreach ($required as $question) {
            if (!$DB->record_exists('videoquiz_attempts', ['questionid' => $question->id, 'userid' => $userid])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns learner state for all questions.
     *
     * @param int $activityid Activity ID.
     * @param int $userid User ID.
     * @return array
     */
    public function get_question_states(int $activityid, int $userid): array {
        global $DB;

        $states = [];
        $questions = $DB->get_records('videoquiz_questions', ['videoquizid' => $activityid]);
        foreach ($questions as $question) {
            $attempts = $DB->get_records('videoquiz_attempts',
                ['questionid' => $question->id, 'userid' => $userid], 'attemptno ASC');
            $best = 0.0;
            foreach ($attempts as $attempt) {
                $best = max($best, (float)$attempt->fraction);
            }
            $count = count($attempts);
            $remaining = (int)$question->attemptsallowed === 0 ? -1 : max(0, (int)$question->attemptsallowed - $count);
            $states[$question->id] = [
                'answered' => $count > 0,
                'attempts' => $count,
                'bestfraction' => $best,
                'remaining' => $remaining,
            ];
        }
        return $states;
    }

    /**
     * Merges overlapping watched segments.
     *
     * @param array $segments Segment list.
     * @return array
     */
    private function merge_segments(array $segments): array {
        $clean = [];
        foreach ($segments as $segment) {
            if (!is_array($segment) || count($segment) < 2) {
                continue;
            }
            $start = max(0.0, (float)$segment[0]);
            $end = max($start, (float)$segment[1]);
            if ($end > $start) {
                $clean[] = [$start, $end];
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
        return array_map(static fn(array $s): array => [round($s[0], 3), round($s[1], 3)], $merged);
    }
}
