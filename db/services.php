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
 * AJAX services.
 *
 * @package   mod_videoquiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_videoquiz_update_progress' => [
        'classname' => '\\mod_videoquiz\\external\\update_progress',
        'methodname' => 'execute',
        'description' => 'Updates watched video progress.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/videoquiz:view',
    ],
    'mod_videoquiz_submit_answer' => [
        'classname' => '\\mod_videoquiz\\external\\submit_answer',
        'methodname' => 'execute',
        'description' => 'Submits an answer to an interactive video question.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/videoquiz:view',
    ],
];
