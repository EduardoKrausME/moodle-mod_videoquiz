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
 * Course module viewed event.
 *
 * @package   mod_videoquiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoquiz\event;

/**
 * Fired when a learner opens a Video Quiz activity.
 */
class course_module_viewed extends \core\event\course_module_viewed {
    /**
     * Initialises event properties.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['objecttable'] = 'videoquiz';
        parent::init();
    }

    /**
     * Returns object ID mapping for restore/logstore.
     *
     * @return array
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'videoquiz', 'restore' => 'videoquiz'];
    }
}
