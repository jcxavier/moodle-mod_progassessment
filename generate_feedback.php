<?php

require_once("../../config.php");
require_once("lib.php");

$id = optional_param('id', 0, PARAM_INT);  // Course module ID
$a  = optional_param('a', 0, PARAM_INT);   // Progassessment ID

$url = new moodle_url('/mod/progassessment/generate_feedback.php');

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

$PAGE->set_url($url);
require_login($course->id, false, $cm);

progassessment_generate_feedback($progassessment, $cm, $course);

?>
