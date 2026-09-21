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
 * Learner player page.
 *
 * @package   mod_videoquiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videoquiz\timecode;

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('videoquiz', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$activity = $DB->get_record('videoquiz', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($course, true, $cm);
require_capability('mod/videoquiz:view', $context);

$PAGE->set_url('/mod/videoquiz/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($activity->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$event = \mod_videoquiz\event\course_module_viewed::create([
    'objectid' => $activity->id,
    'context' => $context,
]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('course_modules', $cm);
$event->add_record_snapshot('videoquiz', $activity);
$event->trigger();

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$manager = new \mod_videoquiz\videoquiz_manager();
$progress = $manager->get_or_create_progress((int)$activity->id, (int)$USER->id);
$states = $manager->get_question_states((int)$activity->id, (int)$USER->id);

$fs = get_file_storage();
$getfirsturl = static function (string $area) use ($fs, $context): string {
    $files = $fs->get_area_files($context->id, 'mod_videoquiz', $area, 0, 'filename', false);
    if (!$files) {
        return '';
    }
    $file = reset($files);
    return moodle_url::make_pluginfile_url($context->id, 'mod_videoquiz', $area, 0,
        $file->get_filepath(), $file->get_filename())->out(false);
};

$source = (string)$activity->videosource;
$videourl = $source === 'upload' ? $getfirsturl('video') : (string)$activity->videourl;
$poster = $getfirsturl('poster');
$captions = [];
foreach ($fs->get_area_files($context->id, 'mod_videoquiz', 'caption', 0, 'filename', false) as $caption) {
    $filename = $caption->get_filename();
    $captions[] = [
        'url' => moodle_url::make_pluginfile_url($context->id, 'mod_videoquiz', 'caption', 0,
            $caption->get_filepath(), $filename)->out(false),
        'label' => pathinfo($filename, PATHINFO_FILENAME),
        'language' => 'und',
    ];
}

$questions = [];
$records = $DB->get_records('videoquiz_questions', ['videoquizid' => $activity->id], 'timepoint, sortorder, id');
foreach ($records as $question) {
    $options = [];
    if ($question->qtype !== 'shortanswer') {
        foreach ($DB->get_records('videoquiz_options', ['questionid' => $question->id], 'sortorder, id') as $option) {
            $optiontext = (string)$option->answertext;
            if ($question->qtype === 'truefalse') {
                $optiontext = ((int)$option->sortorder === 0)
                    ? get_string('true', 'videoquiz')
                    : get_string('false', 'videoquiz');
            }
            $options[] = ['id' => (int)$option->id, 'text' => $optiontext];
        }
    }
    $state = $states[$question->id] ??
        ['answered' => false, 'attempts' => 0, 'bestfraction' => 0, 'remaining' => (int)$question->attemptsallowed];
    $questions[] = [
        'id' => (int)$question->id,
        'timepoint' => (float)$question->timepoint,
        'qtype' => (string)$question->qtype,
        'text' => (string)$question->questiontext,
        'required' => !empty($question->required),
        'allowchange' => !empty($question->allowchange),
        'attemptsallowed' => (int)$question->attemptsallowed,
        'points' => (float)$question->points,
        'options' => $options,
        'state' => $state,
    ];
}

$templatecontext = [
    'cmid' => $cm->id,
    'ishtml5' => in_array($source, ['upload', 'url'], true),
    'isembed' => in_array($source, ['youtube', 'vimeo'], true),
    'url' => $videourl,
    'poster' => $poster,
    'captions' => $captions,
    'disabledownload' => !empty($activity->disabledownload),
    'watchedpercent' => format_float((float)$progress->percent, 1),
    'score' => format_float((float)$progress->score, 1),
];

$config = [
    'cmid' => (int)$cm->id,
    'source' => $source,
    'url' => $videourl,
    'resumeplayback' => (int)$activity->resumeplayback,
    'lastposition' => (float)$progress->lastposition,
    'allowseek' => !empty($activity->allowseek),
    'maxplaybackrate' => (float)$activity->maxplaybackrate,
    'disablecontextmenu' => !empty($activity->disablecontextmenu),
    'questions' => $questions,
    'labels' => [
        'submit' => get_string('saveanswer', 'videoquiz'),
        'continue' => get_string('continuevideo', 'videoquiz'),
        'required' => get_string('answerrequired', 'videoquiz'),
        'attemptlimit' => get_string('attemptlimitreached', 'videoquiz'),
        'resumequestion' => get_string('resumequestion', 'videoquiz', timecode::format((float)$progress->lastposition)),
    ],
];
$PAGE->requires->js_call_amd('mod_videoquiz/player', 'init', [$config]);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($activity->name));
if (trim((string)$activity->intro) !== '') {
    echo $OUTPUT->box(format_module_intro('videoquiz', $activity, $cm->id), 'generalbox mod_introbox');
}
if (has_capability('mod/videoquiz:managequestions', $context) || has_capability('mod/videoquiz:viewreport', $context)) {
    $buttons = '';
    if (has_capability('mod/videoquiz:managequestions', $context)) {
        $buttons .= html_writer::link(new moodle_url('/mod/videoquiz/questions.php', ['id' => $cm->id]),
            get_string('managequestions', 'videoquiz'), ['class' => 'btn btn-secondary me-2']);
    }
    if (has_capability('mod/videoquiz:viewreport', $context)) {
        $buttons .= html_writer::link(new moodle_url('/mod/videoquiz/report.php', ['id' => $cm->id]),
            get_string('viewreport', 'videoquiz'), ['class' => 'btn btn-secondary']);
    }
    echo html_writer::div($buttons, 'mb-3');
}
if ($videourl === '') {
    echo $OUTPUT->notification(get_string('novideo', 'videoquiz'), 'error');
} else {
    echo $OUTPUT->render_from_template('mod_videoquiz/player', $templatecontext);
}
echo $OUTPUT->footer();
