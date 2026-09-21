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
 * Restore structure.
 *
 * @package   mod_videoquiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores Video Quiz data.
 */
class restore_videoquiz_activity_structure_step extends restore_activity_structure_step {
    /**
     * Restore paths.
     *
     * @return array
     */
    protected function define_structure(): array {
        $paths = [
            new restore_path_element('videoquiz', '/activity/videoquiz'),
            new restore_path_element('videoquiz_question', '/activity/videoquiz/questions/question'),
            new restore_path_element('videoquiz_option', '/activity/videoquiz/questions/question/options/option'),
        ];
        if ($this->get_setting_value('userinfo')) {
            $paths[] = new restore_path_element('videoquiz_attempt', '/activity/videoquiz/questions/question/attempts/attempt');
            $paths[] = new restore_path_element('videoquiz_progress', '/activity/videoquiz/progresses/progress');
            $paths[] = new restore_path_element('videoquiz_session', '/activity/videoquiz/sessions/session');
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restores activity.
     *
     * @param array $data Data.
     * @return void
     */
    protected function process_videoquiz($data): void {
        global $DB;
        $data = (object)$data;
        $data->course = $this->get_courseid();
        $newid = $DB->insert_record('videoquiz', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * Restores question.
     *
     * @param array $data Data.
     * @return void
     */
    protected function process_videoquiz_question($data): void {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->videoquizid = $this->get_new_parentid('videoquiz');
        $newid = $DB->insert_record('videoquiz_questions', $data);
        $this->set_mapping('videoquiz_question', $oldid, $newid);
    }

    /**
     * Restores option.
     *
     * @param array $data Data.
     * @return void
     */
    protected function process_videoquiz_option($data): void {
        global $DB;
        $data = (object)$data;
        $data->questionid = $this->get_new_parentid('videoquiz_question');
        $DB->insert_record('videoquiz_options', $data);
    }

    /**
     * Restores attempt.
     *
     * @param array $data Data.
     * @return void
     */
    protected function process_videoquiz_attempt($data): void {
        global $DB;
        $data = (object)$data;
        $data->videoquizid = $this->get_new_parentid('videoquiz');
        $data->questionid = $this->get_new_parentid('videoquiz_question');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if ($data->userid) {
            $DB->insert_record('videoquiz_attempts', $data);
        }
    }

    /**
     * Restores progress.
     *
     * @param array $data Data.
     * @return void
     */
    protected function process_videoquiz_progress($data): void {
        global $DB;
        $data = (object)$data;
        $data->videoquizid = $this->get_new_parentid('videoquiz');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if ($data->userid) {
            $DB->insert_record('videoquiz_progress', $data);
        }
    }

    /**
     * Restores playback session.
     *
     * @param array $data Data.
     * @return void
     */
    protected function process_videoquiz_session($data): void {
        global $DB;
        $data = (object)$data;
        $data->videoquizid = $this->get_new_parentid('videoquiz');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if ($data->userid) {
            $DB->insert_record('videoquiz_sessions', $data);
        }
    }

    /**
     * Restores files.
     *
     * @return void
     */
    protected function after_execute(): void {
        $this->add_related_files('mod_videoquiz', 'video', null);
        $this->add_related_files('mod_videoquiz', 'poster', null);
        $this->add_related_files('mod_videoquiz', 'caption', null);
    }
}
