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
 * Question editing form.
 *
 * @package   mod_videoquiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoquiz\form;

use moodleform;
use mod_videoquiz\timecode;

defined('MOODLE_INTERNAL') || die;

require_once("{$CFG->libdir}/formslib.php");

/**
 * Creates and edits timeline questions.
 */
class question_form extends moodleform {
    /**
     * Defines form.
     *
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'cmid');
        $mform->setType('cmid', PARAM_INT);
        $mform->addElement('hidden', 'qid');
        $mform->setType('qid', PARAM_INT);

        $mform->addElement('text', 'timecode', get_string('timepoint', 'videoquiz'), ['size' => 12]);
        $mform->setType('timecode', PARAM_TEXT);
        $mform->addRule('timecode', null, 'required', null, 'client');
        $mform->addHelpButton('timecode', 'timepoint', 'videoquiz');
        $mform->addElement('select', 'qtype', get_string('questiontype', 'videoquiz'), [
            'multichoice' => get_string('qtypemultichoice', 'videoquiz'),
            'truefalse' => get_string('qtypetruefalse', 'videoquiz'),
            'shortanswer' => get_string('qtypeshortanswer', 'videoquiz'),
            'multianswer' => get_string('qtypemultianswer', 'videoquiz'),
        ]);
        $mform->addElement('textarea', 'questiontext', get_string('questiontext', 'videoquiz'), ['rows' => 4, 'cols' => 70]);
        $mform->setType('questiontext', PARAM_TEXT);
        $mform->addRule('questiontext', null, 'required', null, 'client');
        $mform->addElement('advcheckbox', 'required', get_string('requiredquestion', 'videoquiz'));
        $mform->setDefault('required', 1);
        $mform->addElement('select', 'attemptsallowed', get_string('attemptsallowed', 'videoquiz'), [
            1 => '1', 2 => '2', 3 => '3', 5 => '5', 0 => get_string('unlimited', 'videoquiz'),
        ]);
        $mform->setDefault('attemptsallowed', 1);
        $mform->addElement('advcheckbox', 'allowchange', get_string('allowchange', 'videoquiz'));
        $mform->addElement('text', 'points', get_string('points', 'videoquiz'), ['size' => 6]);
        $mform->setType('points', PARAM_FLOAT);
        $mform->setDefault('points', 1);
        $mform->addElement('textarea', 'correctfeedback', get_string('correctfeedback', 'videoquiz'), ['rows' => 2, 'cols' => 70]);
        $mform->setType('correctfeedback', PARAM_TEXT);
        $mform->addElement('textarea', 'incorrectfeedback',
            get_string('incorrectfeedback', 'videoquiz'), ['rows' => 2, 'cols' => 70]);
        $mform->setType('incorrectfeedback', PARAM_TEXT);

        $mform->addElement('html', '<h3>' . get_string('answers', 'videoquiz') . '</h3>');
        for ($i = 1; $i <= 6; $i++) {
            $group = [];
            $group[] = $mform->createElement('text', 'answer' . $i, get_string('answer', 'videoquiz'), ['size' => 55]);
            $group[] = $mform->createElement('advcheckbox', 'correct' . $i, '', get_string('correct', 'videoquiz'));
            $mform->addGroup($group, 'answerrow' . $i, get_string('answer', 'videoquiz') . ' ' . $i, [' '], false);
            $mform->setType('answer' . $i, PARAM_TEXT);
        }
        $mform->hideIf('choiceanswers', 'qtype', 'in', ['shortanswer', 'truefalse']);
        for ($i = 1; $i <= 6; $i++) {
            $mform->hideIf('answerrow' . $i, 'qtype', 'in', ['shortanswer', 'truefalse']);
        }

        $mform->addElement('select', 'truefalseanswer', get_string('correct', 'videoquiz'), [
            'true' => get_string('true', 'videoquiz'),
            'false' => get_string('false', 'videoquiz'),
        ]);
        $mform->hideIf('truefalseanswer', 'qtype', 'neq', 'truefalse');

        $mform->addElement('textarea', 'acceptedanswers', get_string('acceptedanswers', 'videoquiz'), ['rows' => 6, 'cols' => 60]);
        $mform->setType('acceptedanswers', PARAM_TEXT);
        $mform->addHelpButton('acceptedanswers', 'acceptedanswers', 'videoquiz');
        $mform->hideIf('acceptedanswers', 'qtype', 'neq', 'shortanswer');

        $this->add_action_buttons(true);
    }

    /**
     * Validates question data.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (timecode::parse((string)($data['timecode'] ?? '')) === null) {
            $errors['timecode'] = get_string('invalidtimecode', 'videoquiz');
        }
        if ((float)($data['points'] ?? 0) <= 0) {
            $errors['points'] = get_string('invalidpoints', 'videoquiz');
        }
        $qtype = $data['qtype'] ?? '';
        if ($qtype === 'shortanswer') {
            $accepted = array_filter(array_map('trim', preg_split('/\\R/', (string)($data['acceptedanswers'] ?? ''))));
            if (!$accepted) {
                $errors['acceptedanswers'] = get_string('needacceptedanswer', 'videoquiz');
            }
        } else if (in_array($qtype, ['multichoice', 'multianswer'], true)) {
            $answers = 0;
            $correct = 0;
            for ($i = 1; $i <= 6; $i++) {
                if (trim((string)($data['answer' . $i] ?? '')) !== '') {
                    $answers++;
                    if (!empty($data['correct' . $i])) {
                        $correct++;
                    }
                }
            }
            if ($answers < 2) {
                $errors['answerrow1'] = get_string('needtwoanswers', 'videoquiz');
            } else if ($correct < 1) {
                $errors['answerrow1'] = get_string('needcorrectanswer', 'videoquiz');
            } else if ($qtype === 'multichoice' && $correct !== 1) {
                $errors['answerrow1'] = get_string('needonecorrectanswer', 'videoquiz');
            }
        }
        return $errors;
    }
}
