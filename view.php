<?php  // $Id: view.php,v 1.6.2.3 2009/04/17 22:06:25 skodak Exp $

/**
 * This page prints a particular instance of progassessment
 *
 * @author  Pedro Pacheco <pedro.a.x.pacheco@gmail.com>
 * @author  João Xavier <ei06116@gmail.com>
 * @version $Id: view.php,v 1.6.2.3 2009/04/17 22:06:25 skodak Exp $
 * @package mod/progassessment
 */


require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once(dirname(__FILE__).'/lib.php');
require_once($CFG->libdir . '/plagiarismlib.php');
require_once($CFG->dirroot.'/mod/progassessment/languages_config.php');

$id = optional_param('id', 0, PARAM_INT); // course_module ID, or
$a  = optional_param('a', 0, PARAM_INT);  // progassessment instance ID
$submission_id = optional_param('sub', 0, PARAM_INT);

$url = new moodle_url('/mod/progassessment/view.php');

if ($id) {
    if (! $cm = get_coursemodule_from_id('progassessment', $id)) {
        error('Course Module ID was incorrect');
    }

    if (! $course = $DB->get_record('course', array('id' => $cm->course))) {
        error('Course is misconfigured');
    }

    if (! $progassessment = $DB->get_record('progassessment', array('id' => $cm->instance))) {
        error('Course module is incorrect');
    }

} else if ($a) {
    if (! $progassessment = $DB->get_record('progassessment', array('id' => $a))) {
        error('Course module is incorrect');
    }
    if (! $course = $DB->get_record('course', array('id' => $progassessment->course))) {
        error('Course is misconfigured');
    }
    if (! $cm = get_coursemodule_from_instance('progassessment', $progassessment->id, $course->id)) {
        error('Course Module ID was incorrect');
    }

} else {
    error('You must specify a course_module ID or an instance ID');
}

$PAGE->set_url($url);
$PAGE->requires->js('/mod/progassessment/js/domcollapse/domcollapse.js');
$PAGE->requires->css('/mod/progassessment/js/domcollapse/domcollapse.css');
require_login($course, true, $cm);

/// Mark as viewed
$completion=new completion_info($course);
$completion->set_module_viewed($cm);

$context = get_context_instance(CONTEXT_MODULE,$cm->id);
require_capability('mod/progassessment:view', $context);

add_to_log($course->id, "progassessment", "view", "view.php?id=$cm->id", "$progassessment->id");

$grades = progassessment_get_user_grades($progassessment);
progassessment_grade_item_update($progassessment, $grades);

progassessment_view_header($progassessment, $cm);

view_initial_info($progassessment, $context);

view_description($progassessment, $cm, $context);

view_input_and_output_data($progassessment, $context);

view_submission($progassessment, $context);

view_feedback($progassessment, $context, $submission_id);

// doesn't make sense to provide a compilation playground for SQL statements
if ($progassessment->proglanguages !== "SQL")
    view_compilation_playground($progassessment, $context);

progassessment_view_footer();


function build_file_link($context, $file, $area, $linkid, $name = false, $path = "/") {
    global $CFG, $OUTPUT;

    $component = '/mod_progassessment/';

    $name = ($name ? $name : $file->get_filename());
//  $path = file_encode_url($CFG->wwwroot.'/pluginfile.php', '/'.$context->id."/$area/".$linkid.$path.$file->get_filename());

	$path = file_encode_url($CFG->wwwroot.'/pluginfile.php', '/'.$context->id.$component.$area.'/'.$linkid.$path.$file->get_filename());

    return '<a href="'.$path.'" ><img src="'.$OUTPUT->pix_url(file_mimetype_icon($file->get_mimetype())).'" class="icon" alt="'.$file->get_mimetype().'" />'.s($name).'</a>';
}

function sec_to_time($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor($seconds % 3600 / 60);
    $seconds = $seconds % 60;

    return sprintf("%d:%02d:%02d", $hours, $minutes, $seconds);
}


function view_dates($progassessment) {

    if ($progassessment->timeavailable) {
        echo '<tr><td class="c0">'.get_string('availabledate','progassessment').':</td>';
        echo '    <td class="c1">'.userdate($progassessment->timeavailable).'</td></tr>';
    }
    if ($progassessment->timedue) {
        echo '<tr><td class="c0">'.get_string('duedate','progassessment').':</td>';
        echo '    <td class="c1">'.userdate($progassessment->timedue).'</td></tr>';
    }
    if ($progassessment->timetolerance) {
        echo '<tr><td class="c0">'.get_string('tolerancedate','progassessment').':</td>';
        echo '    <td class="c1">'.userdate($progassessment->timetolerance).'</td></tr>';
    }
    if ($progassessment->timetolerance) {
        echo '<tr><td class="c0">'.get_string('penaltylatesubmissions','progassessment').':</td>';
        echo '    <td class="c1">'.$progassessment->tolerancepenalty.'%</td></tr>';
    }

    if ($progassessment->duration) {
        $duration = sec_to_time($progassessment->duration);
    } else {
        $duration = get_string('norestrictions','progassessment');
    }
    echo '<tr><td class="c0">'.get_string('duration','progassessment').':</td>';
    echo '    <td class="c1">'.$duration.'</td></tr>';

    if ($progassessment->duration) {
        echo '<tr><td class="c0">'.get_string('timeleft','progassessment').':</td>';
        echo '    <td class="c1">'.sec_to_time(progassessment_get_remaining_duration($progassessment)).'</td></tr>';
    } 
}


function view_initial_info($progassessment, $context) {
    global $OUTPUT;

    echo $OUTPUT->heading(get_string('progassessment', 'progassessment') . ": $progassessment->name", 2, 'main trigger');

    echo $OUTPUT->box_start('generalbox boxaligncenter', 'dates');

    echo '<table>';

    view_dates($progassessment);

    //empty line
    echo '<tr><td>&nbsp;</td></tr>';

    //maximum grade
    echo '<tr><td class="c0">'.get_string('maxgrade','progassessment').':</td>';
    echo '    <td class="c1">'.$progassessment->maxgrade.'</td></tr>';

    //grading method
    $gradingmethod = ($progassessment->gradingmethod ? get_string('bestsubmission','progassessment') : get_string('lastsubmission','progassessment'));
    echo '<tr><td class="c0">'.get_string('gradingmethod','progassessment').':</td>';
    echo '    <td class="c1">'.$gradingmethod.'</td></tr>';

    //programming languages
    echo '<tr><td class="c0">'.get_string('proglanguages','progassessment').':</td>';
    echo '    <td class="c1">'.$progassessment->proglanguages.'</td></tr>';

    //maximum number of submissions
    $submissions = $progassessment->maxsubmissions > 0 ? $progassessment->maxsubmissions : "Unlimited";
    echo '<tr><td class="c0">'.get_string('maxsubmissions','progassessment').':</td>';
    echo '    <td class="c1">'.$submissions.'</td></tr>';

    //empty line
    echo '<tr><td>&nbsp;</td></tr>';

    //immediate feedback
    $immediate_feedback = $progassessment->immediatefeedback ? "Yes" : "No";
    echo '<tr><td class="c0">'.get_string('immediatefeedback', 'progassessment').':</td>';
    echo '    <td class="c1">'.$immediate_feedback.'</td></tr>';

    //feedback detail
    $feedback_detail = "Moderated"; 
    
    if ($progassessment->feedbackdetail == 0) {
        $feedback_detail = "Minimalist";
    } else if ($progassessment->feedbackdetail == 2) {
        $feedback_detail = "Detailed";
    }

    echo '<tr><td class="c0">'.get_string('feedbackdetail', 'progassessment').':</td>';
    echo '    <td class="c1">'.$feedback_detail.'</td></tr>';

    //empty line
    echo '<tr><td>&nbsp;</td></tr>';
    
    // static analysis enabled
    $static_analysis = $progassessment->saenabled ? get_string('yes', 'progassessment') : get_string('no', 'progassessment');
    echo '<tr><td class="c0">'.get_string('titlesa', 'progassessment').':</td>';
    echo '    <td class="c1">'.$static_analysis.'</td></tr>';
    
    //grade % for static analysis
    if ($progassessment->saenabled) {
        echo '<tr><td class="c0">'.get_string('valuesa','progassessment').':</td>';
        echo '    <td class="c1">'.$progassessment->sagrade.'</td></tr>';
    }

    //show the skeleton file to graders
    if ($progassessment->skeletonfile && has_capability('mod/progassessment:grade', $context)) {
        //empty line
        echo '<tr><td>&nbsp;</td></tr>';

        $f_s = get_file_storage();
        $skeletonfile = $f_s->get_file_by_id($progassessment->skeletonfile);
        $itemid = $skeletonfile->get_itemid();
        $path = $skeletonfile->get_filepath();
        $skeletonfilelink = build_file_link($context, $skeletonfile, 'progassessment_skeleton', $itemid, $skeletonfile->get_filename(), $path);
        echo '<tr><td class="c0">'.get_string('skeletonfile', 'progassessment').':</td>';
        echo '    <td class="c1">'.$skeletonfilelink.'</td></tr>';
    }

    echo '</table>';
    echo $OUTPUT->box_end();
}



function view_description($progassessment, $cm, $context) {
    global $OUTPUT;

    echo $OUTPUT->heading(get_string('description', 'progassessment'), 2, 'main trigger expanded');

    echo $OUTPUT->box_start('generalbox boxaligncenter', 'intro');
    echo format_module_intro('progassessment', $progassessment, $cm->id);

    if ($progassessment->introfile) {
        $fs = get_file_storage();
        $introfile = $fs->get_file_by_id($progassessment->introfile);
        $filelink = build_file_link($context, $introfile, 'progassessment_description', $introfile->get_itemid());
        echo '<center>' . $filelink . '</center>';
    }

    echo $OUTPUT->box_end();
}

function view_test_case_input_file_link($testcase, $context) {
    global $USER;

    $fs = get_file_storage();

    //build a file for holding the input data
    $file_record = new Object();
    $file_record->contextid = $context->id;
    $file_record->component = 'mod_progassessment';
    $file_record->filearea  = "progassessment_input";
    $file_record->itemid    = $testcase->id;
    $file_record->filepath  = '/';
    $file_record->filename  = 'input.txt';
    $file_record->userid    = $USER->id;

    if (!$fs->file_exists($context->id, $file_record->component, $file_record->filearea, $file_record->itemid, $file_record->filepath, $file_record->filename)) {
        $inputfile = $fs->create_file_from_string($file_record, $testcase->input);
    } else {
        $inputfile = $fs->get_file($context->id, $file_record->component, $file_record->filearea, $file_record->itemid, $file_record->filepath, $file_record->filename);
    }

    return build_file_link($context, $inputfile, $file_record->filearea, $testcase->id);
}

function view_test_case_output_file_link($testcase, $context) {
    global $USER;

    $fs = get_file_storage();

    //build a file for holding the output data
    $file_record = new Object();
    $file_record->contextid = $context->id;
    $file_record->component = 'mod_progassessment';
    $file_record->filearea  = "progassessment_output";
    $file_record->itemid    = $testcase->id;
    $file_record->filepath  = '/';
    $file_record->filename  = 'output.txt';
    $file_record->userid    = $USER->id;

    if (!$fs->file_exists($context->id, $file_record->component, $file_record->filearea, $file_record->itemid, $file_record->filepath, $file_record->filename)) {
        $outputfile = $fs->create_file_from_string($file_record, $testcase->output);
    } else {
        $outputfile = $fs->get_file($context->id, $file_record->component, $file_record->filearea, $file_record->itemid, $file_record->filepath, $file_record->filename);
    }

    return build_file_link($context, $outputfile, $file_record->filearea, $testcase->id);
}

function view_input_and_output_data($progassessment, $context) {
    global $OUTPUT, $DB, $CFG, $USER;

    if (! has_capability('mod/progassessment:grade', $context)) {
        return;
    }

    $testcases = $DB->get_records('progassessment_testcases', array('progassessment' => $progassessment->id), 'id ASC');

    echo $OUTPUT->heading(get_string('inputoutputdata', 'progassessment'), 2, 'main trigger');

    echo $OUTPUT->box_start('generalbox boxaligncenter', 'inputoutputdata');

    foreach ($testcases as $t) {
        echo $OUTPUT->heading(get_string('testcase', 'progassessment') . " $t->name (" . get_string('weight', 'progassessment') . ": $t->weight)", 3, 'main trigger');

        echo $OUTPUT->box_start('generalbox', 'testcase');

        echo $OUTPUT->heading(get_string('input', 'progassessment'), 4);

        //print the input content
        echo $OUTPUT->box_start('generalbox', 'intro');
        echo '<pre>';
        echo $t->input;
        echo '</pre>';
        echo $OUTPUT->box_end();

        echo view_test_case_input_file_link($t, $context);
        

        echo $OUTPUT->heading(get_string('output', 'progassessment'), 4);

        //print the output content
        echo $OUTPUT->box_start('generalbox', 'intro');
        echo '<pre>';
        echo $t->output;
        echo '</pre>';
        echo $OUTPUT->box_end();

        echo view_test_case_output_file_link($t, $context);

        echo $OUTPUT->box_end();

    }

    echo $OUTPUT->box_end();
}




function view_generate_feedback_form($progassessment, $context) {
    //button to generate feedback reports, when the feedback is non-immediate
    if ($progassessment->immediatefeedback == PROGASSESSMENT_NON_IMMEDIATE_FEEDBACK && has_capability('mod/progassessment:grade', $context)) {
        $mform = new mod_progassessment_generate_feedback_form("generate_feedback.php", $progassessment);
        $mform->display();
    }
}

function view_feedback($progassessment, $context, $submission_id) {
    global $OUTPUT, $DB, $USER;

    $submissions = progassessment_get_user_submissions($progassessment);
    $submission = false;
    $fs = get_file_storage();
    
    foreach($submissions as $k => $v) {
        if ($k == $submission_id) {
            $submission = $v;
        }
    }

    if (! $submission) {
        $submission = progassessment_get_current_submission($progassessment);
    }

    if ($submission && $submission->isgraded) {
        $submissions_testcases = $DB->get_records("progassessment_submissions_testcases", array('submission' => $submission->id), "testcase ASC");

        echo $OUTPUT->heading(get_string('feedback', 'progassessment'), 2, 'main trigger expanded');
        echo $OUTPUT->box_start('generalbox boxaligncenter', 'dates');

        //form to chose which submission to show
        $progassessment->submission_to_show = $submission->id;
        $chose_submission_form = new mod_progassessment_select_submission_form("select_submission.php", $progassessment);
        $chose_submission_form->display();
        $file_storage = get_file_storage();
        $file = $file_storage->get_file_by_id($submission->file);

        echo '<center><b>'.get_string('currentlyshowingfeedback', 'progassessment'). ': </b>'
                . $file->get_filename() . " - " . date("H:i:s d-m-Y", $submission->timecreated) . '</center>';

        //grade in the assignment
        echo $OUTPUT->heading(get_string('grade', 'progassessment'), 3, 'main trigger expanded');
        echo $OUTPUT->box_start('generalbox', 'intro');
        echo '<b><big><center>'.$submission->grade.'</b></big> '.get_string('outof','progassessment').' '.$progassessment->maxgrade."</center>";
        
        //if the grade is 0, check if there was a compile error
        if ($submission->grade == 0) {

            if (count($submissions_testcases)) {
                foreach ($submissions_testcases as $s_t) {
                    if ($s_t->result == "compiler-error") {
                        echo '<p><center>' . get_string('submissiondidnotcompile','progassessment') . '</center></p>';
                        echo '<p><pre>' . $s_t->output_compile . '</pre></p>';
                    }
                    break;
                }
            }
        }

        echo $OUTPUT->box_end();

        //full test cases results
        echo $OUTPUT->heading(get_string('fulltestcasesresults', 'progassessment'), 3, 'main trigger expanded');

        if (sizeof($submissions_testcases)) {
            echo $OUTPUT->box_start('generalbox', 'intro');
        }

        foreach($submissions_testcases as $s_t) {

            $testcase = $DB->get_record("progassessment_testcases", array('id' => $s_t->testcase));

            $color = (progassessment_is_result_correct($s_t->result, $s_t->output_error) ? "#347C2C" : "#FF0000");
            $result = $s_t->result;

            if ($s_t->result != "correct" && progassessment_is_result_correct($s_t->result, $s_t->output_error)) {
                $result = "correct";
            }
            
            echo '<table>';

            if ($testcase->name) {
                echo $OUTPUT->heading(get_string('testcase', 'progassessment') . " $testcase->name", 4);
            }
            
            echo '<tr><td><b>' . get_string('weight', 'progassessment') . ': </b></td><td>' . $testcase->weight . '</td></tr>';

            if ($result != "run-error") {
                echo '<tr><td><b>' . get_string('result', 'progassessment') . ": </b></td><td><font color=$color>" . $result . '</font></td></tr>';
            } else {
                //create a file to show the run error
                $file_record = new object();
                $file_record->contextid = $context->id;
                $file_record->filearea  = "progassessment_runerror";
                $file_record->itemid    = $s_t->id;
                $file_record->filepath  = '/';
                $file_record->filename  = 'run_error.txt';
                $file_record->userid    = $USER->id;

                if (!$fs->file_exists($context->id, $file_record->filearea, $file_record->itemid, $file_record->filepath, $file_record->filename)) {
                    $runerrorfile = $fs->create_file_from_string($file_record, str_replace("\n", "\r\n", $s_t->output_error));
                } else {
                    $runerrorfile = $fs->get_file($context->id, $file_record->filearea, $file_record->itemid, $file_record->filepath, $file_record->filename);
                }

                $runerrorfilelink = build_file_link($context, $runerrorfile, $file_record->filearea, $s_t->id);

                echo '<tr><td><b>' . get_string('result', 'progassessment') . ": </b></td><td><font color=$color>" . $result . "</font> ($runerrorfilelink)" . '</td></tr>';
            }

            if (progassessment_is_result_correct($s_t->result, $s_t->output_error) && $testcase->right_feedback) {
               echo '<tr><td><b>' . get_string('feedback', 'progassessment') . ": </b></td><td>" . $testcase->right_feedback . '</td></tr>';
            } else if (! progassessment_is_result_correct($s_t->result, $s_t->output_error) && $testcase->wrong_feedback) {
                echo '<tr><td><b>' . get_string('feedback', 'progassessment') . ": </b></td><td>" . $testcase->wrong_feedback . '</td></tr>';
            }


            /* handle SQL feedback (moderated and detailed) */
            if ($progassessment->proglanguages === "SQL") {
                
                // feedback is the same both for moderated and detailed
                if ($progassessment->feedbackdetail) {
                    
                    //obtained output
                    $file_record = new object();
                    $file_record->contextid = $context->id;
                    $file_record->component = 'mod_progassessment';
                    $file_record->filearea  = "progassessment_obtainedoutput";
                    $file_record->itemid    = $s_t->id;
                    $file_record->filepath  = '/';
                    $file_record->filename  = 'obtained_output.txt';
                    $file_record->userid    = $USER->id;
                    
                    if (!$fs->file_exists($context->id, $file_record->component, $file_record->filearea, $file_record->itemid, $file_record->filepath, $file_record->filename)) {
                        $obtainedoutputfile = $fs->create_file_from_string($file_record, str_replace("\n", "\r\n", $s_t->output_error));
                    } else {
                        $obtainedoutputfile = $fs->get_file($context->id, $file_record->component, $file_record->filearea, $file_record->itemid, $file_record->filepath, $file_record->filename);
                    }
                
                    $obtainedoutputlink = build_file_link($context, $obtainedoutputfile, $file_record->filearea, $s_t->id);
                    echo '<tr><td><b>' . get_string('obtainedoutput', 'progassessment') . ': </b></td><td>' . $obtainedoutputlink . '</td></tr>';
                }
            }
            /* handle regular feedback */
            else {
                //link for the input file only if the feedback is at least moderated
                if ($progassessment->feedbackdetail) {
                    $filelink = view_test_case_input_file_link($testcase, $context);
                    echo '<tr><td><b>' . get_string('input', 'progassessment') . ': </b></td><td>' . $filelink . '</td></tr>';
                }

                //show the expected and obtained outputs only if the feedback is detailed
                if ($progassessment->feedbackdetail == 2) {
                    //expected output
                    $expectedoutputlink = view_test_case_output_file_link($testcase, $context);
                    echo '<tr><td><b>' . get_string('expectedoutput', 'progassessment') . ': </b></td><td>' . $expectedoutputlink . '</td></tr>';

                    //obtained output
                    $file_record = new object();
                    $file_record->contextid = $context->id;
                    $file_record->component = 'mod_progassessment';
                    $file_record->filearea  = "progassessment_obtainedoutput";
                    $file_record->itemid    = $s_t->id;
                    $file_record->filepath  = '/';
                    $file_record->filename  = 'obtained_output.txt';
                    $file_record->userid    = $USER->id;

                    if (!$fs->file_exists($context->id, $file_record->component, $file_record->filearea, $file_record->itemid, $file_record->filepath, $file_record->filename)) {
                        $obtainedoutputfile = $fs->create_file_from_string($file_record, str_replace("\n", "\r\n", $s_t->output_run));
                    } else {
                        $obtainedoutputfile = $fs->get_file($context->id, $file_record->component, $file_record->filearea, $file_record->itemid, $file_record->filepath, $file_record->filename);
                    }
                
                    $obtainedoutputlink = build_file_link($context, $obtainedoutputfile, $file_record->filearea, $s_t->id);
                    echo '<tr><td><b>' . get_string('obtainedoutput', 'progassessment') . ': </b></td><td>' . $obtainedoutputlink . '</td></tr>';
                }
            }

            echo '</table>';
        }

        if (sizeof($submissions_testcases)) {
            echo $OUTPUT->box_end();
        }

        view_generate_feedback_form($progassessment, $context);
        echo $OUTPUT->box_end();
    }

    //show form for generating feedback reports
    else if ($progassessment->immediatefeedback == PROGASSESSMENT_NON_IMMEDIATE_FEEDBACK && has_capability('mod/progassessment:grade', $context)) {
        echo $OUTPUT->heading(get_string('feedback', 'progassessment'), 2);
        echo $OUTPUT->box_start('generalbox', 'intro');
        view_generate_feedback_form($progassessment, $context);
        echo $OUTPUT->box_end();
    }

}

function view_submission($progassessment, $context) {
    global $OUTPUT, $DB, $USER, $CFG;

    echo $OUTPUT->heading(get_string('submission', 'progassessment'), 2, 'main trigger expanded');

    $submission = progassessment_get_current_submission($progassessment);

    echo $OUTPUT->box_start('generalbox', 'submission');

    if ($submission) {
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($submission->file);
        $path = $file->get_filepath();
        
        //link for downloading the submission file
        echo '<center><b>'.get_string('currentsubmission', 'progassessment'). ': </b>'
                . build_file_link($context, $file, "progassessment_submission", $USER->id, $file->get_filename(), $file->get_filepath())
                . " - " . date("H:i:s d-m-Y", $submission->timecreated) . '</center>';
    } else {
        echo('<center><b>'.get_string('nosubmission', 'progassessment').'</b></center>');
    }

    //number of submissions left
    if ($progassessment->maxsubmissions > 0) {
        $submissions = $DB->get_records('progassessment_submissions', array('userid' => $USER->id, 'progassessment' => $progassessment->id));
        $submissionsleft = max(0, $progassessment->maxsubmissions - count($submissions));
        echo('<br></br><br></br>');
        echo '<center><b>'.$submissionsleft.'</b> '.get_string('submissionsleft', 'progassessment').'</center>';
    }

    //display the submission form
    if (progassessment_can_submit($progassessment, $context)) {
        GLOBAL $COURSE;
        
        $testcases = $DB->get_records('progassessment_testcases', array('progassessment' => $progassessment->id));

        //submissions can only be accepted if the progassessment has test cases
        if (sizeof($testcases)) {
            
            plagiarism_print_disclosure($COURSE->id);
            
            $mform = new mod_progassessment_submit_file_form("upload.php", $progassessment);
            $mform->display();
        }
    }

    echo $OUTPUT->box_end();
}

function view_compilation_playground($progassessment, $context) {
    global $OUTPUT, $DB, $USER;

    echo $OUTPUT->heading(get_string('compilationplayground', 'progassessment'), 2, 'main trigger');

    echo $OUTPUT->box_start('generalbox', 'compilationplayground');

    $entries = $DB->get_records('progassessment_compilation_results', array('progassessment' => $progassessment->id, 'userid' => $USER->id), "timecreated DESC");
    
    if (count($entries)) {
        $keys = array_keys($entries);
        $entry = $entries[$keys[0]];
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($entry->file);
        $path = $file->get_filepath();

        //link for downloading the last compiled file
        echo '<center><b>'.get_string('lastcompiledfile', 'progassessment'). ': </b>' . build_file_link($context, $file, "progassessment_compilation", $USER->id, $file->get_filename(), $file->get_filepath()) . '</center>';

        echo('<br></br><br></br>');

        if ($entry->result != "NULL") {
            echo $OUTPUT->heading(get_string('compilationresult', 'progassessment'), 4);

            //print the input content
            echo $OUTPUT->box_start('generalbox', 'intro');
            echo '<pre>';

            if ($entry->result !== "compiler-error") {
                echo '<font color="#347C2C">' . get_string('compilationwassuccessful', 'progassessment') . '</font>';
            } else {
                echo '<font color="#FF0000">' . $entry->output_compile . '</font>';
            }
            
            echo '</pre>';
            echo $OUTPUT->box_end();
        }
    }

    //display the compilation form
    $mform = new mod_progassessment_compile_file_form("compile.php", $progassessment);
    $mform->display();

    echo $OUTPUT->box_end();
}


?>
