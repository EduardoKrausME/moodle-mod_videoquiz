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
 * Question editor page.
 *
 * @package   mod_videoquiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$qid = optional_param('qid', 0, PARAM_INT);
$cm = get_coursemodule_from_id('videoquiz', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$activity = $DB->get_record('videoquiz', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($course, true, $cm);
require_capability('mod/videoquiz:managequestions', $context);

$question = null;
if ($qid) {
    $question = $DB->get_record('videoquiz_questions', ['id' => $qid, 'videoquizid' => $activity->id], '*', MUST_EXIST);
}

$PAGE->set_url('/mod/videoquiz/question.php', ['cmid' => $cm->id, 'qid' => $qid]);
$PAGE->set_title(get_string($qid ? 'editquestion' : 'addquestion', 'videoquiz'));
$PAGE->set_heading(format_string($activity->name));

$form = new \mod_videoquiz\form\question_form(null, ['cmid' => $cm->id]);
if ($form->is_cancelled()) {
    redirect(new moodle_url('/mod/videoquiz/questions.php', ['id' => $cm->id]));
}

if ($data = $form->get_data()) {
    $now = time();
    $record = (object)[
        'videoquizid' => $activity->id,
        'timepoint' => \mod_videoquiz\timecode::parse($data->timecode),
        'qtype' => $data->qtype,
        'questiontext' => $data->questiontext,
        'required' => !empty($data->required) ? 1 : 0,
        'attemptsallowed' => (int)$data->attemptsallowed,
        'allowchange' => !empty($data->allowchange) ? 1 : 0,
        'correctfeedback' => $data->correctfeedback,
        'incorrectfeedback' => $data->incorrectfeedback,
        'points' => (float)$data->points,
        'sortorder' => (int)round((float)\mod_videoquiz\timecode::parse($data->timecode) * 1000),
        'timemodified' => $now,
    ];
    if ($qid) {
        $record->id = $qid;
        $DB->update_record('videoquiz_questions', $record);
        $questionid = $qid;
        // Existing attempts belong to the previous question definition and cannot be regraded reliably.
        $DB->delete_records('videoquiz_attempts', ['questionid' => $questionid]);
    } else {
        $record->timecreated = $now;
        $questionid = $DB->insert_record('videoquiz_questions', $record);
    }
    $DB->delete_records('videoquiz_options', ['questionid' => $questionid]);
    $options = [];
    if ($data->qtype === 'truefalse') {
        $options[] = ['text' => get_string('true', 'videoquiz'), 'correct' => $data->truefalseanswer === 'true'];
        $options[] = ['text' => get_string('false', 'videoquiz'), 'correct' => $data->truefalseanswer === 'false'];
    } else if ($data->qtype === 'shortanswer') {
        foreach (preg_split('/\\R/', (string)$data->acceptedanswers) as $answer) {
            $answer = trim($answer);
            if ($answer !== '') {
                $options[] = ['text' => $answer, 'correct' => true];
            }
        }
    } else {
        for ($i = 1; $i <= 6; $i++) {
            $field = 'answer' . $i;
            $correctfield = 'correct' . $i;
            $answer = trim((string)($data->{$field} ?? ''));
            if ($answer !== '') {
                $options[] = ['text' => $answer, 'correct' => !empty($data->{$correctfield})];
            }
        }
    }
    foreach ($options as $sort => $option) {
        $DB->insert_record('videoquiz_options', (object)[
            'questionid' => $questionid,
            'answertext' => $option['text'],
            'iscorrect' => $option['correct'] ? 1 : 0,
            'sortorder' => $sort,
        ]);
    }
    $users = $DB->get_fieldset_select('videoquiz_progress', 'userid', 'videoquizid = :id', ['id' => $activity->id]);
    $manager = new \mod_videoquiz\videoquiz_manager();
    foreach ($users as $userid) {
        $manager->refresh_user($activity, $cm, (int)$userid);
    }
    redirect(new moodle_url('/mod/videoquiz/questions.php', ['id' => $cm->id]),
        get_string($qid ? 'questionupdated' : 'questioncreated', 'videoquiz'));
}

if ($question) {
    $defaults = (array)$question;
    $defaults['cmid'] = $cm->id;
    $defaults['qid'] = $question->id;
    $defaults['timecode'] = \mod_videoquiz\timecode::format((float)$question->timepoint);
    $options = array_values($DB->get_records('videoquiz_options', ['questionid' => $question->id], 'sortorder, id'));
    if ($question->qtype === 'truefalse') {
        foreach ($options as $option) {
            if ($option->iscorrect) {
                $defaults['truefalseanswer'] = ((int)$option->sortorder === 0) ? 'true' : 'false';
            }
        }
    } else if ($question->qtype === 'shortanswer') {
        $defaults['acceptedanswers'] = implode("\n", array_map(static fn($o) => $o->answertext, $options));
    } else {
        foreach ($options as $i => $option) {
            if ($i >= 6) {
                break;
            }
            $n = $i + 1;
            $defaults['answer' . $n] = $option->answertext;
            $defaults['correct' . $n] = $option->iscorrect;
        }
    }
    $form->set_data($defaults);
} else {
    $form->set_data(['cmid' => $cm->id, 'qid' => 0]);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string($qid ? 'editquestion' : 'addquestion', 'videoquiz'));
$form->display();
echo $OUTPUT->footer();
