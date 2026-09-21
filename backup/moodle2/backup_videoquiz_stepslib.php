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
 * Backup structure.
 *
 * @package   mod_videoquiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines Video Quiz backup structure.
 */
class backup_videoquiz_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines backup tree.
     *
     * @return backup_nested_element
     */
    protected function define_structure(): backup_nested_element {
        $userinfo = $this->get_setting_value('userinfo');
        $videoquiz = new backup_nested_element('videoquiz', ['id'], [
            'name', 'intro', 'introformat', 'videosource', 'videourl', 'resumeplayback', 'allowseek',
            'maxplaybackrate', 'disabledownload', 'disablecontextmenu', 'grade', 'grademode', 'questionweight',
            'mingrade', 'completionwatch', 'completionpercent', 'completionquestions', 'completiongrade',
            'timecreated', 'timemodified',
        ]);
        $questions = new backup_nested_element('questions');
        $question = new backup_nested_element('question', ['id'], [
            'timepoint', 'qtype', 'questiontext', 'required', 'attemptsallowed', 'allowchange', 'correctfeedback',
            'incorrectfeedback', 'points', 'sortorder', 'timecreated', 'timemodified',
        ]);
        $options = new backup_nested_element('options');
        $option = new backup_nested_element('option', ['id'],
            ['answertext', 'iscorrect', 'sortorder']);
        $attempts = new backup_nested_element('attempts');
        $attempt = new backup_nested_element('attempt', ['id'],
            ['userid', 'attemptno', 'response', 'fraction', 'correct', 'timecreated']);
        $progresses = new backup_nested_element('progresses');
        $progress = new backup_nested_element('progress', ['id'], [
            'userid', 'duration', 'lastposition', 'uniquewatched', 'totalwatchtime', 'percent', 'watchedsegments',
            'score', 'completed', 'timecreated', 'timemodified',
        ]);
        $sessions = new backup_nested_element('sessions');
        $session = new backup_nested_element('session', ['id'], [
            'userid', 'sessionkey', 'timestart', 'timeend', 'watchtime', 'lastposition', 'sequence',
            'lastheartbeat', 'lastclienttime', 'playerstate', 'timecreated', 'timemodified',
        ]);

        $videoquiz->add_child($questions);
        $questions->add_child($question);
        $question->add_child($options);
        $options->add_child($option);
        $question->add_child($attempts);
        $attempts->add_child($attempt);
        $videoquiz->add_child($progresses);
        $progresses->add_child($progress);
        $videoquiz->add_child($sessions);
        $sessions->add_child($session);

        $videoquiz->set_source_table('videoquiz', ['id' => backup::VAR_ACTIVITYID]);
        $question->set_source_table('videoquiz_questions', ['videoquizid' => backup::VAR_ACTIVITYID]);
        $option->set_source_table('videoquiz_options', ['questionid' => backup::VAR_PARENTID]);
        if ($userinfo) {
            $attempt->set_source_table('videoquiz_attempts', ['questionid' => backup::VAR_PARENTID]);
            $progress->set_source_table('videoquiz_progress', ['videoquizid' => backup::VAR_ACTIVITYID]);
            $session->set_source_table('videoquiz_sessions', ['videoquizid' => backup::VAR_ACTIVITYID]);
            $attempt->annotate_ids('user', 'userid');
            $progress->annotate_ids('user', 'userid');
            $session->annotate_ids('user', 'userid');
        }

        $videoquiz->annotate_files('mod_videoquiz', 'video', null);
        $videoquiz->annotate_files('mod_videoquiz', 'poster', null);
        $videoquiz->annotate_files('mod_videoquiz', 'caption', null);
        return $this->prepare_activity_structure($videoquiz);
    }
}
