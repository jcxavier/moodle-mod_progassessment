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
//
// this file contains all the functions that aren't needed by core moodle
// but start becoming required once we're actually inside the assignment module.

require_once($CFG->dirroot . '/mod/progassessment/lib.php');
require_once($CFG->libdir . '/portfolio/caller.php');

/**
 * @package   mod-progassessment
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class progassessment_portfolio_caller extends portfolio_module_caller_base {

    /**
    * the progassessment subclass
    */
    private $progassessment;

    /**
    * the file to include when waking up to load the progassessment subclass def
    */
    private $progassessmentfile;

    /**
    * callback arg for a single file export
    */
    protected $fileid;

    public static function expected_callbackargs() {
        return array(
            'id'     => true,
            'fileid' => false,
        );
    }

    public function load_data() {
        global $DB, $CFG;

        if (! $this->cm = get_coursemodule_from_id('progassessment', $this->id)) {
            throw new portfolio_caller_exception('invalidcoursemodule');
        }

        if (! $progassessment = $DB->get_record("progassessment", array("id"=>$this->cm->instance))) {
            throw new portfolio_caller_exception('invalidid', 'progassessment');
        }
        
        $this->assignmentfile = '/mod/assignment/type/' . $assignment->assignmenttype . '/assignment.class.php';
        require_once($CFG->dirroot . $this->assignmentfile);
        $assignmentclass = "assignment_$assignment->assignmenttype";

        $this->assignment = new $assignmentclass($this->cm->id, $assignment, $this->cm);

        if (!$this->assignment->portfolio_exportable()) {
            throw new portfolio_caller_exception('notexportable', 'portfolio', $this->get_return_url());
        }

        if (is_callable(array($this->assignment, 'portfolio_load_data'))) {
            return $this->assignment->portfolio_load_data($this);
        }

        $submission = $DB->get_record('assignment_submissions', array('assignment'=>$assignment->id, 'userid'=>$this->user->id));

        $this->set_file_and_format_data($this->fileid, $this->assignment->context->id, 'mod_assignment', 'submission', $submission->id, 'timemodified', false);
    }

    public function prepare_package() {
        global $CFG, $DB;
        if (is_callable(array($this->progassessment, 'portfolio_prepare_package'))) {
            return $this->assignment->portfolio_prepare_package($this->exporter, $this->user);
        }
        if ($this->exporter->get('formatclass') == PORTFOLIO_FORMAT_LEAP2A) {
            $leapwriter = $this->exporter->get('format')->leap2a_writer();
            $files = array();
            if ($this->singlefile) {
                $files[] = $this->singlefile;
            } elseif ($this->multifiles) {
                $files = $this->multifiles;
            } else {
                throw new portfolio_caller_exception('invalidpreparepackagefile', 'portfolio', $this->get_return_url());
            }
            $baseid = 'assignment' . $this->assignment->assignment->assignmenttype . $this->assignment->assignment->id . 'submission';
            $entryids = array();
            foreach ($files as $file) {
                $entry = new portfolio_format_leap2a_file($file->get_filename(), $file);
                $entry->author = $this->user;
                $leapwriter->add_entry($entry);
                $this->exporter->copy_existing_file($file);
                $entryids[] = $entry->id;
            }
            if (count($files) > 1) {
                // if we have multiple files, they should be grouped together into a folder
                $entry = new portfolio_format_leap2a_entry($baseid . 'group', $this->assignment->assignment->name, 'selection');
                $leapwriter->add_entry($entry);
                $leapwriter->make_selection($entry, $entryids, 'Folder');
            }
            return $this->exporter->write_new_file($leapwriter->to_xml(), $this->exporter->get('format')->manifest_name(), true);
        }
        return $this->prepare_package_file();
    }

    public function get_sha1() {
        global $CFG;
        if (is_callable(array($this->assignment, 'portfolio_get_sha1'))) {
            return $this->assignment->portfolio_get_sha1($this);
        }
        return $this->get_sha1_file();
    }

    public function expected_time() {
        if (is_callable(array($this->assignment, 'portfolio_get_expected_time'))) {
            return $this->assignment->portfolio_get_expected_time();
        }
        return $this->expected_time_file();
    }

    public function check_permissions() {
        $context = get_context_instance(CONTEXT_MODULE, $this->assignment->cm->id);
        return has_capability('mod/assignment:exportownsubmission', $context);
    }

    public function __wakeup() {
        global $CFG;
        if (empty($CFG)) {
            return true; // too early yet
        }
        require_once($CFG->dirroot . $this->assignmentfile);
        $this->assignment = unserialize(serialize($this->assignment));
    }

    public static function display_name() {
        return get_string('modulename', 'assignment');
    }

    public static function base_supported_formats() {
        return array(PORTFOLIO_FORMAT_FILE, PORTFOLIO_FORMAT_LEAP2A);
    }
}
