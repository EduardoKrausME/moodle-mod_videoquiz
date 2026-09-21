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
 * Core callbacks for mod_videoquiz.
 *
 * @package   mod_videoquiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Returns supported Moodle features.
 *
 * @param string $feature Feature constant.
 * @return bool|null
 */
function videoquiz_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_ARCHETYPE:
            return MOD_ARCHETYPE_OTHER;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
            return true;
        default:
            return null;
    }
}

/**
 * Creates an activity instance.
 *
 * @param stdClass $data Form data.
 * @param mod_videoquiz_mod_form|null $mform Form instance.
 * @return int
 */
function videoquiz_add_instance(stdClass $data, ?mod_videoquiz_mod_form $mform = null): int {
    global $DB;

    $now = time();
    $data->timecreated = $now;
    $data->timemodified = $now;
    $id = $DB->insert_record('videoquiz', $data);
    $data->id = $id;
    videoquiz_save_files($data);
    videoquiz_grade_item_update($data);
    return $id;
}

/**
 * Updates an activity instance.
 *
 * @param stdClass $data Form data.
 * @param mod_videoquiz_mod_form|null $mform Form instance.
 * @return bool
 */
function videoquiz_update_instance(stdClass $data, ?mod_videoquiz_mod_form $mform = null): bool {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();
    $result = $DB->update_record('videoquiz', $data);
    videoquiz_save_files($data);
    $activity = $DB->get_record('videoquiz', ['id' => $data->id], '*', MUST_EXIST);
    videoquiz_grade_item_update($activity);

    // Grading mode, weights or completion thresholds may have changed. Recalculate stored learners.
    $cm = get_coursemodule_from_id('videoquiz', (int)$data->coursemodule, 0, false, MUST_EXIST);
    $userids = $DB->get_fieldset_select('videoquiz_progress', 'userid', 'videoquizid = :id', ['id' => $activity->id]);
    $manager = new \mod_videoquiz\videoquiz_manager();
    foreach ($userids as $userid) {
        $manager->refresh_user($activity, $cm, (int)$userid);
    }
    return $result;
}

/**
 * Deletes an activity instance and related data.
 *
 * @param int $id Activity ID.
 * @return bool
 */
function videoquiz_delete_instance(int $id): bool {
    global $DB;

    $activity = $DB->get_record('videoquiz', ['id' => $id]);
    if (!$activity) {
        return false;
    }
    $questions = $DB->get_fieldset_select('videoquiz_questions', 'id', 'videoquizid = :id', ['id' => $id]);
    if ($questions) {
        [$insql, $params] = $DB->get_in_or_equal($questions, SQL_PARAMS_NAMED, 'qid');
        $DB->delete_records_select('videoquiz_options', "questionid $insql", $params);
        $DB->delete_records_select('videoquiz_attempts', "questionid $insql", $params);
    }
    $DB->delete_records('videoquiz_questions', ['videoquizid' => $id]);
    $DB->delete_records('videoquiz_attempts', ['videoquizid' => $id]);
    $DB->delete_records('videoquiz_progress', ['videoquizid' => $id]);
    $DB->delete_records('videoquiz_sessions', ['videoquizid' => $id]);

    $cm = get_coursemodule_from_instance('videoquiz', $id, $activity->course, false, IGNORE_MISSING);
    if ($cm) {
        $context = context_module::instance($cm->id);
        get_file_storage()->delete_area_files($context->id, 'mod_videoquiz');
    }
    $DB->delete_records('videoquiz', ['id' => $id]);
    videoquiz_grade_item_delete($activity);
    return true;
}

/**
 * Saves draft media files into protected File API areas.
 *
 * @param stdClass $data Activity data.
 * @return void
 */
function videoquiz_save_files(stdClass $data): void {
    $context = context_module::instance((int)$data->coursemodule);
    $fs = get_file_storage();

    if ((string)$data->videosource === 'upload' && !empty($data->videofile)) {
        file_save_draft_area_files($data->videofile, $context->id, 'mod_videoquiz', 'video', 0,
            ['subdirs' => 0, 'maxfiles' => 1]);
    } else if ((string)$data->videosource !== 'upload') {
        $fs->delete_area_files($context->id, 'mod_videoquiz', 'video');
    }
    if (!empty($data->poster)) {
        file_save_draft_area_files($data->poster, $context->id, 'mod_videoquiz', 'poster', 0,
            ['subdirs' => 0, 'maxfiles' => 1]);
    }
    if (!empty($data->captions)) {
        file_save_draft_area_files($data->captions, $context->id, 'mod_videoquiz', 'caption', 0,
            ['subdirs' => 0, 'maxfiles' => 10, 'accepted_types' => ['.vtt']]);
    }
}

/**
 * Serves protected media files.
 *
 * @param stdClass $course Course.
 * @param stdClass $cm Course module.
 * @param context $context Context.
 * @param string $filearea File area.
 * @param array $args Path arguments.
 * @param bool $forcedownload Force download.
 * @param array $options Options.
 * @return bool
 */
function mod_videoquiz_pluginfile($course, $cm, $context, string $filearea, array $args,
                                  bool $forcedownload, array $options = []): bool {
    if ($context->contextlevel !== CONTEXT_MODULE || !in_array($filearea, ['video', 'poster', 'caption'], true)) {
        return false;
    }
    require_login($course, true, $cm);
    require_capability('mod/videoquiz:view', $context);
    $itemid = (int)array_shift($args);
    if ($itemid !== 0) {
        return false;
    }
    $filename = array_pop($args);
    $filepath = '/' . ($args ? implode('/', $args) . '/' : '');
    $file = get_file_storage()->get_file($context->id, 'mod_videoquiz', $filearea, 0, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Creates or updates the gradebook item.
 *
 * @param stdClass $activity Activity.
 * @param array|null $grades Optional grades.
 * @return int
 */
function videoquiz_grade_item_update(stdClass $activity, ?array $grades = null): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $maxgrade = max(0.0, (float)$activity->grade);
    $item = [
        'itemname' => clean_param($activity->name, PARAM_NOTAGS),
        'gradetype' => $maxgrade > 0 ? GRADE_TYPE_VALUE : GRADE_TYPE_NONE,
        'grademin' => 0,
        'grademax' => $maxgrade > 0 ? $maxgrade : 100,
    ];
    if ($maxgrade > 0 && (float)$activity->mingrade > 0) {
        $item['gradepass'] = $maxgrade * ((float)$activity->mingrade / 100.0);
    }
    return grade_update('mod/videoquiz', $activity->course, 'mod', 'videoquiz', $activity->id, 0, $grades, $item);
}

/**
 * Recalculates grades for users with progress rows.
 *
 * @param stdClass $activity Activity.
 * @param int $userid Optional user ID.
 * @param bool $nullifnone Whether to clear missing grade.
 * @return void
 */
function videoquiz_update_grades(stdClass $activity, int $userid = 0, bool $nullifnone = true): void {
    global $DB;

    $conditions = ['videoquizid' => $activity->id];
    if ($userid) {
        $conditions['userid'] = $userid;
    }
    $records = $DB->get_records('videoquiz_progress', $conditions);
    $grades = [];
    foreach ($records as $record) {
        $grades[$record->userid] = (object)[
            'userid' => $record->userid,
            'rawgrade' => (float)$activity->grade > 0 ? (float)$activity->grade * ((float)$record->score / 100.0) : null,
        ];
    }
    if (!$grades && $userid && $nullifnone) {
        $grades[$userid] = (object)['userid' => $userid, 'rawgrade' => null];
    }
    videoquiz_grade_item_update($activity, $grades);
}

/**
 * Deletes gradebook item.
 *
 * @param stdClass $activity Activity.
 * @return int
 */
function videoquiz_grade_item_delete(stdClass $activity): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    return grade_update('mod/videoquiz', $activity->course, 'mod', 'videoquiz', $activity->id, 0, null, ['deleted' => 1]);
}

/**
 * Returns File API areas.
 *
 * @param stdClass $course Course.
 * @param stdClass $cm Course module.
 * @param context $context Context.
 * @return array
 */
function videoquiz_get_file_areas($course, $cm, $context): array {
    return [
        'video' => get_string('videofile', 'videoquiz'),
        'poster' => get_string('poster', 'videoquiz'),
        'caption' => get_string('captions', 'videoquiz'),
    ];
}
