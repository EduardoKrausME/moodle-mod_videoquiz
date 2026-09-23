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
 * Activity configuration form.
 *
 * @package   mod_videoquiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Defines the Video Quiz activity form.
 */
class mod_videoquiz_mod_form extends moodleform_mod {
    /**
     * Defines form elements.
     *
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;
        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('videoquizname', 'videoquiz'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $this->standard_intro_elements();

        $mform->addElement('html', '<h3>' . get_string('sourceheader', 'videoquiz') . '</h3>');
        $mform->addElement('select', 'videosource', get_string('videosource', 'videoquiz'), [
            'upload' => get_string('sourceupload', 'videoquiz'),
            'url' => get_string('sourceurl', 'videoquiz'),
            'youtube' => get_string('sourceyoutube', 'videoquiz'),
            'vimeo' => get_string('sourcevimeo', 'videoquiz'),
        ]);
        $mform->setDefault('videosource', 'upload');

        $mform->addElement('filemanager', 'videofile', get_string('videofile', 'videoquiz'), null, [
            'subdirs' => 0,
            'accepted_types' => ['video'],
        ]);
        $mform->hideIf('videofile', 'videosource', 'neq', 'upload');

        $mform->addElement('text', 'videourl', get_string('videourl', 'videoquiz'), ['size' => 80]);
        $mform->setType('videourl', PARAM_URL);
        $mform->hideIf('videourl', 'videosource', 'eq', 'upload');

        $mform->addElement('filemanager', 'poster', get_string('poster', 'videoquiz'), null, [
            'subdirs' => 0,
            'accepted_types' => ['image'],
        ]);
        $mform->addElement('filemanager', 'captions', get_string('captions', 'videoquiz'), null, [
            'subdirs' => 0,
            'maxfiles' => 10,
            'accepted_types' => ['.vtt'],
        ]);

        $mform->addElement('html', '<h3>' . get_string('playbackheader', 'videoquiz') . '</h3>');
        $mform->addElement('select', 'resumeplayback', get_string('resumeplayback', 'videoquiz'), [
            1 => get_string('resumeautomatic', 'videoquiz'),
            2 => get_string('resumeask', 'videoquiz'),
            0 => get_string('resumefromstart', 'videoquiz'),
        ]);
        $mform->setDefault('resumeplayback', 1);
        $mform->addElement('selectyesno', 'allowseek', get_string('allowseek', 'videoquiz'));
        $mform->setDefault('allowseek', 1);
        $mform->addElement('select', 'maxplaybackrate', get_string('maxplaybackrate', 'videoquiz'), [
            '1' => '1x', '1.25' => '1.25x', '1.5' => '1.5x', '1.75' => '1.75x', '2' => '2x',
        ]);
        $mform->setDefault('maxplaybackrate', '2');
        $mform->addElement('selectyesno', 'disabledownload', get_string('disabledownload', 'videoquiz'));
        $mform->setDefault('disabledownload', 0);
        $mform->addElement('selectyesno', 'disablecontextmenu', get_string('disablecontextmenu', 'videoquiz'));
        $mform->setDefault('disablecontextmenu', 0);

        $mform->addElement('html', '<h3>' . get_string('gradingheader', 'videoquiz') . '</h3>');
        $mform->addElement('text', 'grade', get_string('maxgrade', 'videoquiz'), ['size' => 6]);
        $mform->setType('grade', PARAM_FLOAT);
        $mform->setDefault('grade', 100);
        $mform->addElement('select', 'grademode', get_string('grademode', 'videoquiz'), [
            'questions' => get_string('gradequestions', 'videoquiz'),
            'combined' => get_string('gradecombined', 'videoquiz'),
            'watched' => get_string('gradewatched', 'videoquiz'),
        ]);
        $mform->setDefault('grademode', 'questions');
        $mform->addElement('text', 'questionweight', get_string('questionweight', 'videoquiz'), ['size' => 5]);
        $mform->setType('questionweight', PARAM_INT);
        $mform->setDefault('questionweight', 50);
        $mform->addHelpButton('questionweight', 'questionweight', 'videoquiz');
        $mform->hideIf('questionweight', 'grademode', 'neq', 'combined');
        $mform->addElement('text', 'mingrade', get_string('mingrade', 'videoquiz'), ['size' => 5]);
        $mform->setType('mingrade', PARAM_FLOAT);
        $mform->setDefault('mingrade', 0);
        $mform->addHelpButton('mingrade', 'mingrade', 'videoquiz');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Server-side validation.
     *
     * @param array $data Submitted values.
     * @param array $files Submitted files.
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (($data['videosource'] ?? '') !== 'upload' && empty(trim((string)($data['videourl'] ?? '')))) {
            $errors['videourl'] = get_string('required');
        }
        if (isset($data['questionweight']) && ((int)$data['questionweight'] < 0 || (int)$data['questionweight'] > 100)) {
            $errors['questionweight'] = get_string('errorgradepercent', 'videoquiz');
        }
        if (isset($data['mingrade']) && ((float)$data['mingrade'] < 0 || (float)$data['mingrade'] > 100)) {
            $errors['mingrade'] = get_string('errorgradepercent', 'videoquiz');
        }
        $percentfield = $this->suffix('completionpercent');
        if (isset($data[$percentfield]) && ((int)$data[$percentfield] < 1 || (int)$data[$percentfield] > 100)) {
            $errors[$percentfield] = get_string('errorpercent', 'videoquiz');
        }
        foreach (['videofile', 'poster'] as $field) {
            $draftid = (int)($data[$field] ?? 0);
            if ($draftid > 0) {
                $draftinfo = file_get_draft_area_info($draftid);
                if ((int)$draftinfo['filecount'] > 1) {
                    $errors[$field] = get_string('errormaxfiles', 'videoquiz');
                }
            }
        }
        return $errors;
    }

    /**
     * Prepares file drafts and custom completion values.
     *
     * @param array $defaultvalues Defaults.
     * @return void
     */
    public function data_preprocessing(&$defaultvalues): void {
        foreach (['completionwatch', 'completionpercent', 'completionquestions', 'completiongrade'] as $field) {
            if (array_key_exists($field, $defaultvalues)) {
                $defaultvalues[$this->suffix($field)] = $defaultvalues[$field];
            }
        }
        if (empty($this->current->instance)) {
            return;
        }
        foreach (['video', 'poster', 'caption'] as $area) {
            $field = $area === 'video' ? 'videofile' : ($area === 'caption' ? 'captions' : 'poster');
            $draftid = file_get_submitted_draft_itemid($field);
            file_prepare_draft_area($draftid, $this->context->id, 'mod_videoquiz', $area, 0, ['subdirs' => 0]);
            $defaultvalues[$field] = $draftid;
        }
    }

    /**
     * Adds custom completion rules.
     *
     * @return array
     */
    public function add_completion_rules(): array {
        $mform = $this->_form;
        $watch = $this->suffix('completionwatch');
        $percent = $this->suffix('completionpercent');
        $questions = $this->suffix('completionquestions');
        $grade = $this->suffix('completiongrade');

        $mform->addElement('checkbox', $watch, get_string('completionwatch', 'videoquiz'));
        $mform->setDefault($watch, 1);
        $mform->addElement('text', $percent, get_string('completionpercent', 'videoquiz'), ['size' => 5]);
        $mform->setType($percent, PARAM_INT);
        $mform->setDefault($percent, 80);
        $mform->disabledIf($percent, $watch, 'notchecked');
        $mform->addElement('checkbox', $questions, get_string('completionquestions', 'videoquiz'));
        $mform->setDefault($questions, 1);
        $mform->addElement('checkbox', $grade, get_string('completiongrade', 'videoquiz'));
        return [$watch, $percent, $questions, $grade];
    }

    /**
     * Returns whether at least one custom completion rule is enabled.
     *
     * @param stdClass|array $data Form data.
     * @return bool
     */
    public function completion_rule_enabled($data): bool {
        foreach (['completionwatch', 'completionquestions', 'completiongrade'] as $field) {
            $name = $this->suffix($field);
            $value = is_array($data) ? ($data[$name] ?? 0) : ($data->{$name} ?? 0);
            if (!empty($value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalises suffixed completion fields.
     *
     * @return stdClass|null
     */
    public function get_data() {
        $data = parent::get_data();
        if (!$data) {
            return $data;
        }
        foreach (['completionwatch', 'completionpercent', 'completionquestions', 'completiongrade'] as $field) {
            $name = $this->suffix($field);
            $data->{$field} = property_exists($data, $name) ? $data->{$name} : 0;
            unset($data->{$name});
        }
        return $data;
    }

    /**
     * Builds a field name unique to this module.
     *
     * @param string $field Base field.
     * @return string
     */
    private function suffix(string $field): string {
        return $field . '_videoquiz';
    }
}
