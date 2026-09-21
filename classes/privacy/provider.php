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
 * Privacy provider.
 *
 * @package   mod_videoquiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoquiz\privacy;

use context;
use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Describes, exports and deletes learner data.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {
    /**
     * Metadata.
     *
     * @param collection $collection Collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('videoquiz_progress', [
            'userid' => 'privacy:metadata:userid',
            'watchedsegments' => 'privacy:metadata:progressdata',
            'lastposition' => 'privacy:metadata:progressdata',
            'percent' => 'privacy:metadata:progressdata',
            'score' => 'privacy:metadata:progressdata',
        ], 'privacy:metadata:progress');
        $collection->add_database_table('videoquiz_attempts', [
            'userid' => 'privacy:metadata:userid',
            'response' => 'privacy:metadata:response',
        ], 'privacy:metadata:attempts');
        $collection->add_database_table('videoquiz_sessions', [
            'userid' => 'privacy:metadata:userid',
            'sessionkey' => 'privacy:metadata:sessionkey',
            'lastposition' => 'privacy:metadata:progressdata',
            'watchtime' => 'privacy:metadata:progressdata',
        ], 'privacy:metadata:sessions');
        return $collection;
    }

    /**
     * Contexts containing user data.
     *
     * @param int $userid User ID.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {videoquiz} vq ON vq.id = cm.instance
             LEFT JOIN {videoquiz_progress} p ON p.videoquizid = vq.id AND p.userid = :puserid
             LEFT JOIN {videoquiz_attempts} a ON a.videoquizid = vq.id AND a.userid = :auserid
             LEFT JOIN {videoquiz_sessions} s ON s.videoquizid = vq.id AND s.userid = :suserid
                 WHERE p.id IS NOT NULL OR a.id IS NOT NULL OR s.id IS NOT NULL";
        return (new contextlist())->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'videoquiz',
            'puserid' => $userid,
            'auserid' => $userid,
            'suserid' => $userid,
        ]);
    }

    /**
     * Exports user data.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('videoquiz', $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            $progress = $DB->get_record('videoquiz_progress',
                ['videoquizid' => $cm->instance, 'userid' => $contextlist->get_user()->id]);
            $attempts = $DB->get_records('videoquiz_attempts',
                ['videoquizid' => $cm->instance, 'userid' => $contextlist->get_user()->id]);
            $sessions = $DB->get_records('videoquiz_sessions',
                ['videoquizid' => $cm->instance, 'userid' => $contextlist->get_user()->id]);
            $data = (object)[
                'progress' => $progress,
                'attempts' => array_values($attempts),
                'sessions' => array_values($sessions),
            ];
            writer::with_context($context)->export_data([], $data);
        }
    }

    /**
     * Deletes all user data in a context.
     *
     * @param context $context Context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;
        if (!$context instanceof context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('videoquiz', $context->instanceid, 0, false, IGNORE_MISSING);
        if ($cm) {
            $DB->delete_records('videoquiz_attempts', ['videoquizid' => $cm->instance]);
            $DB->delete_records('videoquiz_progress', ['videoquizid' => $cm->instance]);
            $DB->delete_records('videoquiz_sessions', ['videoquizid' => $cm->instance]);
        }
    }

    /**
     * Deletes one user's approved data.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('videoquiz', $context->instanceid, 0, false, IGNORE_MISSING);
            if ($cm) {
                $DB->delete_records('videoquiz_attempts',
                    ['videoquizid' => $cm->instance, 'userid' => $contextlist->get_user()->id]);
                $DB->delete_records('videoquiz_progress',
                    ['videoquizid' => $cm->instance, 'userid' => $contextlist->get_user()->id]);
                $DB->delete_records('videoquiz_sessions',
                    ['videoquizid' => $cm->instance, 'userid' => $contextlist->get_user()->id]);
            }
        }
    }

    /**
     * Adds users with data to a userlist.
     *
     * @param userlist $userlist User list.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }
        $params = ['cmid' => $context->instanceid, 'modname' => 'videoquiz'];
        $sql = "SELECT p.userid
                  FROM {videoquiz_progress} p
                  JOIN {course_modules} cm ON cm.instance = p.videoquizid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, $params);
        $sql = "SELECT a.userid
                  FROM {videoquiz_attempts} a
                  JOIN {course_modules} cm ON cm.instance = a.videoquizid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, $params);
        $sql = "SELECT s.userid
                  FROM {videoquiz_sessions} s
                  JOIN {course_modules} cm ON cm.instance = s.videoquizid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Deletes approved users in a context.
     *
     * @param approved_userlist $userlist Approved users.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('videoquiz', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm || !$userlist->get_userids()) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED, 'uid');
        $params['activityid'] = $cm->instance;
        $DB->delete_records_select('videoquiz_attempts', "videoquizid = :activityid AND userid $insql", $params);
        $DB->delete_records_select('videoquiz_progress', "videoquizid = :activityid AND userid $insql", $params);
        $DB->delete_records_select('videoquiz_sessions', "videoquizid = :activityid AND userid $insql", $params);
    }
}
