<?php

require_once("../../config.php");
require_once("lib.php");
require_once($CFG->libdir.'/plagiarismlib.php');

$id   = optional_param('id', 0, PARAM_INT);          // Course module ID
$a    = optional_param('a', 0, PARAM_INT);           // Progassessment ID
$mode = optional_param('mode', 'all', PARAM_ALPHA);  // What mode are we in?
$download = optional_param('download' , 'none', PARAM_ALPHA); //ZIP download asked for?

$url = new moodle_url('/mod/progassessment/submissions.php');
if ($id) {
    if (! $cm = get_coursemodule_from_id('progassessment', $id)) {
        print_error('invalidcoursemodule');
    }

    if (! $progassessment = $DB->get_record("progassessment", array("id"=>$cm->instance))) {
        print_error('invalidid', 'progassessment');
    }

    if (! $course = $DB->get_record("course", array("id"=>$progassessment->course))) {
        print_error('coursemisconf', 'progassessment');
    }
    $url->param('id', $id);
} else {
    if (!$progassessment = $DB->get_record("progassessment", array("id"=>$a))) {
        print_error('invalidcoursemodule');
    }
    if (! $course = $DB->get_record("course", array("id"=>$progassessment->course))) {
        print_error('invalidid', 'progassessment');
    }
    if (! $cm = get_coursemodule_from_instance("progassessment", $progassessment->id, $course->id)) {
        print_error('invalidcoursemodule', 'progassessment');
    }
    $url->param('a', $a);
}

if ($mode !== 'all') {
    $url->param('mode', $mode);
}
$PAGE->set_url($url);
require_login($course->id, false, $cm);
require_capability('mod/progassessment:grade', get_context_instance(CONTEXT_MODULE, $cm->id));
$PAGE->requires->js('/mod/progassessment/progassessment.js');

if ($download == "zip") {
    progassessment_download_submissions($progassessment, $cm);
} else {
    progassessment_display_submissions($progassessment, $cm, $mode);
}