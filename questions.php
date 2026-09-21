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
 * Question management page.
 *
 * @package   mod_videoquiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$qid = optional_param('qid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$cm = get_coursemodule_from_id('videoquiz', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$activity = $DB->get_record('videoquiz', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($course, true, $cm);
require_capability('mod/videoquiz:managequestions', $context);

$PAGE->set_url('/mod/videoquiz/questions.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('managequestions', 'videoquiz'));
$PAGE->set_heading(format_string($activity->name));

if ($action === 'delete' && $qid) {
    require_sesskey();
    $question = $DB->get_record('videoquiz_questions', ['id' => $qid, 'videoquizid' => $activity->id], '*', MUST_EXIST);
    if (!$confirm) {
        echo $OUTPUT->header();
        $yes = new moodle_url('/mod/videoquiz/questions.php', [
            'id' => $cm->id,
            'action' => 'delete',
            'qid' => $qid,
            'confirm' => 1,
            'sesskey' => sesskey(),
        ]);
        $no = new moodle_url('/mod/videoquiz/questions.php', ['id' => $cm->id]);
        echo $OUTPUT->confirm(get_string('deletequestionconfirm', 'videoquiz'), $yes, $no);
        echo $OUTPUT->footer();
        exit;
    }
    $DB->delete_records('videoquiz_options', ['questionid' => $qid]);
    $DB->delete_records('videoquiz_attempts', ['questionid' => $qid]);
    $DB->delete_records('videoquiz_questions', ['id' => $qid]);
    $users = $DB->get_fieldset_select('videoquiz_progress', 'userid', 'videoquizid = :id', ['id' => $activity->id]);
    $manager = new \mod_videoquiz\videoquiz_manager();
    foreach ($users as $userid) {
        $manager->refresh_user($activity, $cm, (int)$userid);
    }
    redirect($PAGE->url, get_string('questiondeleted', 'videoquiz'));
}

$questions = $DB->get_records('videoquiz_questions', ['videoquizid' => $activity->id], 'timepoint ASC, sortorder ASC, id ASC');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managequestions', 'videoquiz'));
$buttons = html_writer::link(new moodle_url('/mod/videoquiz/question.php', ['cmid' => $cm->id]),
    get_string('addquestion', 'videoquiz'), ['class' => 'btn btn-primary me-2']);
$buttons .= html_writer::link(new moodle_url('/mod/videoquiz/view.php', ['id' => $cm->id]),
    get_string('backtoactivity', 'videoquiz'), ['class' => 'btn btn-secondary']);
echo html_writer::div($buttons, 'mb-3');

if (!$questions) {
    echo $OUTPUT->notification(get_string('noquestions', 'videoquiz'), 'info');
} else {
    $table = new html_table();
    $table->head = [get_string('timepoint', 'videoquiz'), get_string('questiontype', 'videoquiz'),
        get_string('questiontext', 'videoquiz'), get_string('points', 'videoquiz'), get_string('actions')];
    foreach ($questions as $question) {
        $edit = html_writer::link(new moodle_url('/mod/videoquiz/question.php', ['cmid' => $cm->id, 'qid' => $question->id]),
            get_string('edit'));
        $delete = html_writer::link(new moodle_url('/mod/videoquiz/questions.php', [
            'id' => $cm->id, 'action' => 'delete', 'qid' => $question->id, 'sesskey' => sesskey(),
        ]), get_string('delete'));
        $table->data[] = [
            \mod_videoquiz\timecode::format((float)$question->timepoint),
            get_string('qtype' . $question->qtype, 'videoquiz'),
            s($question->questiontext),
            format_float((float)$question->points, 2),
            $edit . ' | ' . $delete,
        ];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
