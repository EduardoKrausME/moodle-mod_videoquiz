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
 * Custom completion rules.
 *
 * @package   mod_videoquiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoquiz\completion;

use core_completion\activity_custom_completion;
use mod_videoquiz\videoquiz_manager;

/**
 * Evaluates watched percentage, mandatory questions and minimum grade.
 */
class custom_completion extends activity_custom_completion {
    /**
     * Returns rule state.
     *
     * @param string $rule Rule name.
     * @return int
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);
        $activity = $DB->get_record('videoquiz', ['id' => $this->cm->instance], '*', MUST_EXIST);
        $progress = $DB->get_record('videoquiz_progress', [
            'videoquizid' => $activity->id,
            'userid' => $this->userid,
        ]);
        if ($rule === 'completionwatch') {
            return $progress && (float)$progress->percent >= (float)$activity->completionpercent
                ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
        }
        if ($rule === 'completionquestions') {
            return (new videoquiz_manager())->required_questions_answered((int)$activity->id, $this->userid)
                ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
        }
        return $progress && (float)$progress->score >= (float)$activity->mingrade
            ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Defined rules.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return ['completionwatch', 'completionquestions', 'completiongrade'];
    }

    /**
     * Rule descriptions.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        global $DB;

        $activity = $DB->get_record('videoquiz', ['id' => $this->cm->instance], '*', MUST_EXIST);
        $descriptions = [];
        if (!empty($activity->completionwatch)) {
            $descriptions['completionwatch'] = get_string('completiondetail:watch', 'videoquiz', $activity->completionpercent);
        }
        if (!empty($activity->completionquestions)) {
            $descriptions['completionquestions'] = get_string('completiondetail:questions', 'videoquiz');
        }
        if (!empty($activity->completiongrade)) {
            $descriptions['completiongrade'] = get_string('completiondetail:grade', 'videoquiz', $activity->mingrade);
        }
        return $descriptions;
    }

    /**
     * Display order.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return ['completionview', 'completionwatch', 'completionquestions', 'completiongrade'];
    }
}
