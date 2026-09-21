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
 * AJAX endpoint for question answers.
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
 * Grades and stores one answer attempt.
 */
class submit_answer extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, get_string('ws:cmid', 'videoquiz')),
            'questionid' => new external_value(PARAM_INT, get_string('ws:questionid', 'videoquiz')),
            'response' => new external_value(PARAM_RAW, get_string('ws:response', 'videoquiz')),
        ]);
    }

    /**
     * Executes submission.
     *
     * @param int $cmid Course module ID.
     * @param int $questionid Question ID.
     * @param string $response JSON response.
     * @return array
     */
    public static function execute(int $cmid, int $questionid, string $response): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), compact('cmid', 'questionid', 'response'));
        $cm = get_coursemodule_from_id('videoquiz', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/videoquiz:view', $context);
        if (isguestuser() || !is_enrolled($context, $USER, 'mod/videoquiz:view', true)) {
            throw new \required_capability_exception($context, 'mod/videoquiz:view', 'nopermissions', '');
        }

        $activity = $DB->get_record('videoquiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $question = $DB->get_record('videoquiz_questions', [
            'id' => $params['questionid'],
            'videoquizid' => $activity->id,
        ]);
        if (!$question) {
            throw new invalid_parameter_exception(get_string('invalidquestion', 'videoquiz'));
        }

        $attemptcount = $DB->count_records('videoquiz_attempts', [
            'questionid' => $question->id,
            'userid' => $USER->id,
        ]);
        if ((int)$question->attemptsallowed > 0 && $attemptcount >= (int)$question->attemptsallowed) {
            throw new invalid_parameter_exception(get_string('attemptlimitreached', 'videoquiz'));
        }

        $decoded = json_decode($params['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new invalid_parameter_exception(get_string('invalidanswer', 'videoquiz'));
        }
        [$fraction, $normalised] = self::grade_response($question, $decoded);
        $attemptno = $attemptcount + 1;
        $record = (object)[
            'videoquizid' => $activity->id,
            'questionid' => $question->id,
            'userid' => $USER->id,
            'attemptno' => $attemptno,
            'response' => json_encode($normalised, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'fraction' => $fraction,
            'correct' => $fraction >= 0.9999 ? 1 : 0,
            'timecreated' => time(),
        ];
        $DB->insert_record('videoquiz_attempts', $record);

        $progress = (new videoquiz_manager())->refresh_user($activity, $cm, $USER->id);
        $best = (float)$DB->get_field_sql(
            'SELECT MAX(fraction) FROM {videoquiz_attempts} WHERE questionid = :questionid AND userid = :userid',
            ['questionid' => $question->id, 'userid' => $USER->id]
        );
        $remaining = (int)$question->attemptsallowed === 0 ? -1 : max(0, (int)$question->attemptsallowed - $attemptno);
        $correct = $fraction >= 0.9999;
        $feedback = $correct ? trim((string)$question->correctfeedback) : trim((string)$question->incorrectfeedback);
        if ($feedback === '') {
            $feedback = get_string($correct ? 'correctanswer' : 'incorrectanswer', 'videoquiz');
        }
        return [
            'correct' => $correct,
            'fraction' => $fraction,
            'feedback' => $feedback,
            'attemptno' => $attemptno,
            'attemptsremaining' => $remaining,
            'cancontinue' => true,
            'canretry' => !$correct && ($remaining === -1 || $remaining > 0),
            'bestfraction' => $best,
            'score' => (float)$progress->score,
            'completed' => !empty($progress->completed),
        ];
    }

    /**
     * Grades a decoded response.
     *
     * @param \stdClass $question Question.
     * @param mixed $response Decoded response.
     * @return array Fraction and normalised response.
     */
    private static function grade_response(\stdClass $question, $response): array {
        global $DB;

        $options = $DB->get_records('videoquiz_options', ['questionid' => $question->id], 'sortorder, id');
        if ($question->qtype === 'shortanswer') {
            if (!is_string($response) && !is_numeric($response)) {
                throw new invalid_parameter_exception(get_string('invalidanswer', 'videoquiz'));
            }
            $answer = trim((string)$response);
            $normal = \core_text::strtolower($answer);
            foreach ($options as $option) {
                if (!empty($option->iscorrect) && \core_text::strtolower(trim((string)$option->answertext)) === $normal) {
                    return [1.0, $answer];
                }
            }
            return [0.0, $answer];
        }

        if ($question->qtype === 'multianswer') {
            if (!is_array($response)) {
                throw new invalid_parameter_exception(get_string('invalidanswer', 'videoquiz'));
            }
            $selected = array_values(array_unique(array_map('intval', $response)));
            $allowed = array_map('intval', array_keys($options));
            foreach ($selected as $id) {
                if (!in_array($id, $allowed, true)) {
                    throw new invalid_parameter_exception(get_string('invalidanswer', 'videoquiz'));
                }
            }
            $correctids = [];
            foreach ($options as $option) {
                if (!empty($option->iscorrect)) {
                    $correctids[] = (int)$option->id;
                }
            }
            $right = count(array_intersect($selected, $correctids));
            $wrong = count(array_diff($selected, $correctids));
            $fraction = $correctids ? max(0.0, min(1.0, ($right - $wrong) / count($correctids))) : 0.0;
            sort($selected);
            return [$fraction, $selected];
        }

        $id = is_numeric($response) ? (int)$response : 0;
        if (!$id || !isset($options[$id])) {
            throw new invalid_parameter_exception(get_string('invalidanswer', 'videoquiz'));
        }
        return [!empty($options[$id]->iscorrect) ? 1.0 : 0.0, $id];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'correct' => new external_value(PARAM_BOOL, get_string('ws:correct', 'videoquiz')),
            'fraction' => new external_value(PARAM_FLOAT, get_string('ws:fraction', 'videoquiz')),
            'feedback' => new external_value(PARAM_TEXT, get_string('ws:feedback', 'videoquiz')),
            'attemptno' => new external_value(PARAM_INT, get_string('ws:attemptno', 'videoquiz')),
            'attemptsremaining' => new external_value(PARAM_INT, get_string('ws:attemptsremaining', 'videoquiz')),
            'cancontinue' => new external_value(PARAM_BOOL, get_string('ws:cancontinue', 'videoquiz')),
            'canretry' => new external_value(PARAM_BOOL, get_string('ws:canretry', 'videoquiz')),
            'bestfraction' => new external_value(PARAM_FLOAT, get_string('ws:bestfraction', 'videoquiz')),
            'score' => new external_value(PARAM_FLOAT, get_string('ws:score', 'videoquiz')),
            'completed' => new external_value(PARAM_BOOL, get_string('ws:completed', 'videoquiz')),
        ]);
    }
}
