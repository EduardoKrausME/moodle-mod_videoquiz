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
 * Activity reports.
 *
 * @package   mod_videoquiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$view = optional_param('view', 'students', PARAM_ALPHA);
$cm = get_coursemodule_from_id('videoquiz', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$activity = $DB->get_record('videoquiz', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($course, true, $cm);
require_capability('mod/videoquiz:viewreport', $context);

$PAGE->set_url('/mod/videoquiz/report.php', ['id' => $cm->id, 'view' => $view]);
$PAGE->set_title(get_string('report', 'videoquiz'));
$PAGE->set_heading(format_string($activity->name));

$tabs = [
    new tabobject('students', new moodle_url('/mod/videoquiz/report.php', ['id' => $cm->id, 'view' => 'students']),
        get_string('studentsreport', 'videoquiz')),
    new tabobject('questions', new moodle_url('/mod/videoquiz/report.php', ['id' => $cm->id, 'view' => 'questions']),
        get_string('questionsreport', 'videoquiz')),
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report', 'videoquiz'));
echo $OUTPUT->tabtree($tabs, $view);

if ($view === 'questions') {
    $questions = $DB->get_records('videoquiz_questions', ['videoquizid' => $activity->id], 'timepoint, sortorder, id');
    $rows = [];
    foreach ($questions as $question) {
        $attempts = $DB->get_records('videoquiz_attempts', ['questionid' => $question->id], 'userid, attemptno');
        $bestbyuser = [];
        foreach ($attempts as $attempt) {
            $uid = (int)$attempt->userid;
            $bestbyuser[$uid] = max($bestbyuser[$uid] ?? 0.0, (float)$attempt->fraction);
        }
        $answered = count($bestbyuser);
        $correct = count(array_filter($bestbyuser, static fn(float $fraction): bool => $fraction >= 0.9999));
        $wrong = count(array_filter($attempts, static fn($attempt): bool => (float)$attempt->fraction < 0.9999));
        $rows[] = [
            'question' => $question,
            'answered' => $answered,
            'correct' => $correct,
            'wrong' => $wrong,
            'attempts' => count($attempts),
            'rate' => $answered ? ($correct / $answered) * 100.0 : 0.0,
        ];
    }
    usort($rows, static fn(array $a, array $b): int => $b['wrong'] <=> $a['wrong']);
    if (!$rows) {
        echo $OUTPUT->notification(get_string('noquestions', 'videoquiz'), 'info');
    } else {
        $table = new html_table();
        $table->head = [get_string('timepoint', 'videoquiz'), get_string('questiontext', 'videoquiz'),
            get_string('answered', 'videoquiz'), get_string('correctcount', 'videoquiz'),
            get_string('incorrectcount', 'videoquiz'), get_string('attempts', 'videoquiz'),
            get_string('successrate', 'videoquiz')];
        foreach ($rows as $row) {
            $table->data[] = [
                \mod_videoquiz\timecode::format((float)$row['question']->timepoint),
                s($row['question']->questiontext),
                $row['answered'],
                $row['correct'],
                $row['wrong'],
                $row['attempts'],
                format_float($row['rate'], 1) . '%',
            ];
        }
        echo html_writer::table($table);
    }
} else {
    $groupid = groups_get_activity_group($cm, true);
    groups_print_activity_menu($cm, $PAGE->url);
    $users = get_enrolled_users($context, 'mod/videoquiz:view', $groupid,
        "u.id,u.firstname,u.lastname,u.email,u.picture,u.imagealt,u.firstnamephonetic," .
        "u.lastnamephonetic,u.middlename,u.alternatename",
        "u.lastname,u.firstname");
    if (!$users) {
        echo $OUTPUT->notification(get_string('noparticipants', 'videoquiz'), 'info');
    } else {
        $table = new html_table();
        $table->head = [get_string('student', 'videoquiz'), get_string('watchedpercent', 'videoquiz'),
            get_string('answered', 'videoquiz'), get_string('correctcount', 'videoquiz'),
            get_string('incorrectcount', 'videoquiz'), get_string('attempts', 'videoquiz'),
            get_string('grade', 'videoquiz'), get_string('status', 'videoquiz')];
        foreach ($users as $user) {
            $progress = $DB->get_record('videoquiz_progress', ['videoquizid' => $activity->id, 'userid' => $user->id]);
            $attempts = $DB->get_records('videoquiz_attempts', ['videoquizid' => $activity->id, 'userid' => $user->id]);
            $best = [];
            foreach ($attempts as $attempt) {
                $qid = (int)$attempt->questionid;
                $best[$qid] = max($best[$qid] ?? 0.0, (float)$attempt->fraction);
            }
            $answered = count($best);
            $correct = count(array_filter($best, static fn(float $fraction): bool => $fraction >= 0.9999));
            $rawgrade = $progress ? (float)$activity->grade * ((float)$progress->score / 100.0) : 0.0;
            $table->data[] = [
                fullname($user),
                format_float($progress ? (float)$progress->percent : 0.0, 1) . '%',
                $answered,
                $correct,
                $answered - $correct,
                count($attempts),
                format_float($rawgrade, 2) . ' / ' . format_float((float)$activity->grade, 2),
                $progress && $progress->completed ? get_string('complete', 'videoquiz') : get_string('incomplete', 'videoquiz'),
            ];
        }
        echo html_writer::table($table);
    }
}

echo html_writer::link(new moodle_url('/mod/videoquiz/view.php', ['id' => $cm->id]), get_string('backtoactivity', 'videoquiz'),
    ['class' => 'btn btn-secondary mt-3']);
echo $OUTPUT->footer();
