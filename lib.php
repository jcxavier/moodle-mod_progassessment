<?php  // $Id: lib.php,v 1.7.2.5 2009/04/22 21:30:57 skodak Exp $

/**
 * Library of functions and constants for module progassessment
 * This file should have two well differenced parts:
 *   - All the core Moodle functions, neeeded to allow
 *     the module to work integrated in Moodle.
 *   - All the progassessment specific functions, needed
 *     to implement all the module logic. Please, note
 *     that, if the module become complex and this lib
 *     grows a lot, it's HIGHLY recommended to move all
 *     these module specific functions to a new php file,
 *     called "locallib.php" (see forum, quiz...). This will
 *     help to save some memory when Moodle is performing
 *     actions across all modules.
 */

require_once($CFG->libdir.'/formslib.php');

define('FILTER_ALL',        0);
define('FILTER_SUBMITTED',  1);

define('PROGASSESSMENT_SERVER_DEFINITION', "http://domserver.fe.up.pt/domjudge/frontend/frontend.wsdl");

define('PROGASSESSMENT_IMMEDIATE_FEEDBACK', 1);
define('PROGASSESSMENT_NON_IMMEDIATE_FEEDBACK', 0);

define('PROGASSESSMENT_LAST_SUBMISSION', 0);
define('PROGASSESSMENT_BEST_SUBMISSION', 1);

define('PROGASSESSMENT_TEST_CASE_IDENTIFIER', "#testcase");
define('PROGASSESSMENT_TEST_CASE_WEIGHT', "#weight");
define('PROGASSESSMENT_TEST_CASE_NAME', "#name");
define('PROGASSESSMENT_TEST_CASE_WRONG', "#wrong");
define('PROGASSESSMENT_TEST_CASE_RIGHT', "#right");

$progassessment_languages = array();


/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @param object $progassessment An object from the form in mod_form.php
 * @return int The id of the newly inserted progassessment record
 */
function progassessment_add_instance($progassessment, $mform) {
    global $DB;

    $progassessment->timecreated = time();
    $progassessment->timemodified = time();

    $progassessment->proglanguages = progassessment_process_form_languages($progassessment, $mform);
	progassessment_process_static_analysis($progassessment, $mform);

    $id = $DB->insert_record('progassessment', $progassessment);
    $progassessment->id = $id;

    progassessment_update_metrics($progassessment);

    $progassessment->skeletonfile = progassessment_process_skeleton_file($progassessment, $mform);
    $DB->set_field('progassessment', 'skeletonfile', $progassessment->skeletonfile, array("id" => $progassessment->id));

    $progassessment->introfile = progassessment_process_intro_file($progassessment, $mform);

    $DB->set_field('progassessment', 'introfile', $progassessment->introfile, array("id" => $progassessment->id));


    if (progassessment_add_instance_to_server($progassessment)) {

        $DB->update_record('progassessment', $progassessment); //update the serverid value

        //insert the test cases
        progassessment_add_test_cases($progassessment, $mform);

        progassessment_grade_item_update($progassessment);

        return $id;
    }
}


/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @param object $progassessment An object from the form in mod_form.php
 * @return boolean Success/Fail
 */
function progassessment_update_instance($progassessment, $mform) {
    global $DB;

    $progassessment->timemodified = time();
    $progassessment->id = $progassessment->instance;

    $progassessment->proglanguages = progassessment_process_form_languages($progassessment, $mform);
	progassessment_process_static_analysis($progassessment, $mform);

	progassessment_update_metrics($progassessment);
	
    $old_progassessment = $DB->get_record('progassessment', array('id' => $progassessment->instance));

    $progassessment->skeletonfile = progassessment_process_skeleton_file($old_progassessment, $mform);
    $progassessment->introfile = progassessment_process_intro_file($old_progassessment, $mform);
    
    progassessment_update_instance_in_server($old_progassessment->serverid, $progassessment);

    $progassessment->serverid = $old_progassessment->serverid;

    progassessment_update_test_cases($progassessment, $mform);

    progassessment_grade_item_update($progassessment);
    
    return $DB->update_record('progassessment', $progassessment);
}


/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id Id of the module instance
 * @return boolean Success/Failure
 */
function progassessment_delete_instance($id) {
    global $DB;

    if (! $progassessment = $DB->get_record('progassessment', array('id' => $id))) {
        return false;
    }

    $result = $DB->delete_records('progassessment', array('id' => $progassessment->id));
    progassessment_remove_instance_from_server($progassessment);
    
    progassessment_delete_metrics($id);

    $submissions = $DB->get_records('progassessment_submissions', array('progassessment' => $progassessment->id));

    //remove the progassessment_submissions_testcases entries from the database
    foreach ($submissions as $submission) {
        $DB->delete_records('progassessment_submissions_testcases', array('submission' => $submission->id));
    }

    //remove the test cases from moodle's database
    $DB->delete_records('progassessment_testcases', array('progassessment' => $progassessment->id));

    //remove the submissions from moodle's database
    $DB->delete_records('progassessment_submissions', array('progassessment' => $progassessment->id));

    return $result;
}


/**
 * Return a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 * $return->time = the time they did it
 * $return->info = a short text description
 *
 * @return null
 */
function progassessment_user_outline($course, $user, $mod, $progassessment) {
    return $return;
}


/**
 * Print a detailed representation of what a user has done with
 * a given particular instance of this module, for user activity reports.
 *
 */
function progassessment_user_complete($course, $user, $mod, $progassessment) {
    return true;
}


/**
 * Given a course and a time, this module should find recent activity
 * that has occurred in progassessment activities and print it out.
 * Return true if there was output, or false is there was none.
 *
 */
function progassessment_print_recent_activity($course, $isteacher, $timestart) {
    return false;  //  True if anything was printed, otherwise false
}

function progassessment_is_result_correct($result, $output_error) {
    return $result == "correct" || ($result == "wrong-answer" && strpos($output_error, "Presentation error"));
}

/**
 * Function to be run periodically according to the moodle cron
 *
 **/
function progassessment_cron () {
    global $DB;

    //get all non processed entries from table progassessment_submissions_testcases
    $non_processed_submissions_testcases = $DB->get_records('progassessment_submissions_testcases', array('result' => NULL));
    
    //get all non processed entries which got conflicted because they were still being evaluated
    $non_processed_submissions_testcases_conflicted = $DB->get_records('progassessment_submissions_testcases', array('result' => ''));

    //merge all entries
    $non_processed_submissions_testcases += $non_processed_submissions_testcases_conflicted;

    $client = progassessment_get_client();
    $processed = array();
    $non_processed = array();
    $progassessments = array();

    //for each non processed entry, check if the server already has a result
    foreach ($non_processed_submissions_testcases as $entry) {
        $answ = $client->getSubmissionResult($entry->serverid);
        
        if (! $answ) {
            $non_processed[] = $entry->submission;
        } else {
            
            if (isset($answ->string))
                $processed[$entry->id] = $answ->string;
            else
                $processed[$entry->id] = $answ->anyType;
                
        }
    }

    foreach ($processed as $pid => $answ) {
        $p = $non_processed_submissions_testcases[$pid];

        $result = $answ[0];
        $output_compile = $answ[1];
        $output_run = $answ[2];
        $output_diff = $answ[3];
        $output_error = $answ[4];        
                
        //update the result and output fields in the progassessment_submissions_testcases table
        $DB->set_field('progassessment_submissions_testcases', "result", $result, array("submission" => $p->submission, "testcase" => $p->testcase));
        $DB->set_field('progassessment_submissions_testcases', "output_compile", $output_compile, array("submission" => $p->submission, "testcase" => $p->testcase));
        $DB->set_field('progassessment_submissions_testcases', "output_run", $output_run, array("submission" => $p->submission, "testcase" => $p->testcase));
        $DB->set_field('progassessment_submissions_testcases', "output_diff", $output_diff, array("submission" => $p->submission, "testcase" => $p->testcase));
        $DB->set_field('progassessment_submissions_testcases', "output_error", $output_error, array("submission" => $p->submission, "testcase" => $p->testcase));

        $testcase = $DB->get_record('progassessment_testcases', array('id' => $p->testcase));
        $submission = $DB->get_record('progassessment_submissions', array('id' => $p->submission));
        $progassessment = $DB->get_record('progassessment', array('id' => $submission->progassessment));
        $progassessments[$progassessment->id] = $progassessment;

        if (progassessment_is_result_correct($result, $output_error)) {
            //update the grade in the progassessment_submissions table
            $weight = $testcase->weight;

            //apply penalty if the submission was a late one
            if ($progassessment->timetolerance && $submission->timecreated > $progassessment->timedue) {
                $weight = (int) ($weight * (1.0 - $progassessment->tolerancepenalty/100.0));
            }

            if ($progassessment->saenabled) {
                $stanstr = "## static analysis ##";
                $pos = strrpos($output_compile, $stanstr);
                $arrstr = substr($output_compile, $pos + strlen($stanstr) + 1);
                eval("\$metricresult = ".$arrstr.";");

                $sagrade = progassessment_evaluate_static_analysis($progassessment, $metricresult);
                $DB->set_field('progassessment_submissions', "sagrade", $sagrade, array("id" => $p->submission));
            
                $temp = $submission->grade + $weight;
                $grade = (int)($temp * ((100.0 - $progassessment->sagrade) / 100.0) + $sagrade * ($progassessment->sagrade / 100.0));
            }
            else
                $grade = $submission->grade + $weight;
                
            $DB->set_field('progassessment_submissions', "grade", $grade, array("id" => $p->submission));
        }

        if ($progassessment->immediatefeedback == PROGASSESSMENT_IMMEDIATE_FEEDBACK) {
            //this submission may be already graded (if not, the next cycle handles this)
            $DB->set_field('progassessment_submissions', "isgraded", 1, array("id" => $p->submission));
        }
    }

    foreach ($non_processed as $np) {
        //this submission is still not completely graded
        $DB->set_field('progassessment_submissions', "isgraded", 0, array("id" => $np));
    }

    //update the grades of the affected programming assessments
    foreach ($progassessments as $k => $progassessment) {
        $grades = progassessment_get_user_grades($progassessment);
        progassessment_grade_item_update($progassessment, $grades);
    }

    //get all non processed entries from table progassessment_compilation_results
    $non_processed_compilation_results = $DB->get_records('progassessment_compilation_results', array('result' => 'NULL'));

    foreach ($non_processed_compilation_results as $k => $v) {
        $answ = $client->getSubmissionResult($v->serverid);

        if ($answ) {
            
            if (isset($answ->string))
                $answ = $answ->string;
            else
                $answ = $answ->anyType;
            
            
            // if condition met, it is an Oracle query which did not compile
            if (beginsWith($answ[3], "ORA-") && beginsWith($answ[4], "Unknown result"))
            {
                $result = "compiler-error";
                $output_compile = $answ[3];
            }
            else
            {
                $result = $answ[0];
                $output_compile = $answ[1];
            }
            
            $DB->set_field('progassessment_compilation_results', 'result', $result, array("id" => $k));
            $DB->set_field('progassessment_compilation_results', 'output_compile', $output_compile, array("id" => $k));
        }
    }

    return true;
}

function progassessment_evaluate_static_analysis($progassessment, $metricresults) {
    $metrics = progassessment_get_metrics($progassessment);
    $lang = $progassessment->proglanguages;
    
    $total = 0;
    $weightsum = 0;
    
    foreach ($metrics as $groupkey => $group) {
        foreach ($group as $key => $metricinfo) {
            $originalkey = substr($key, 0, strlen($key) - strlen($lang));
            $value = $metricresults[$groupkey][$originalkey];
            
            if ($metricinfo['min'] <= $value && $value <= $metricinfo['max'])
                $total += $metricinfo['weight'];
                
            $weightsum += $metricinfo['weight'];
        }
    }
    
    return (int)(($total * 100.0) / $weightsum);
}

/**
 * Returns a link with info about the state of the assignment submissions
 *
 * This is used by view_header to put this link at the top right of the page.
 * For teachers it gives the number of submitted assignments with a link
 * For students it gives the time of their submission.
 * This will be suitable for most assignment types.
 *
 * @global object
 * @global object
 * @param bool $allgroup print all groups info if user can access all groups, suitable for index.php
 * @return string
 */
function submittedlink($cm, $allgroups=false) {
    global $USER, $CFG, $COURSE;

    $submitted = '';
    $urlbase = "{$CFG->wwwroot}/mod/progassessment/";

    $context = get_context_instance(CONTEXT_MODULE, $cm->id);
    if (has_capability('mod/progassessment:grade', $context)) {
        if ($allgroups and has_capability('moodle/site:accessallgroups', $context)) {
            $group = 0;
        } else {
            $group = groups_get_activity_group($cm);
        }
        if ($count = count_real_submissions($cm, $group)) {
            $submitted = '<a href="'.$urlbase.'submissions.php?id='.$cm->id.'">'.
                         get_string('viewsubmissions', 'progassessment', $count).'</a>';
        } else {
            $submitted = '<a href="'.$urlbase.'submissions.php?id='.$cm->id.'">'.
                         get_string('noattempts', 'progassessment').'</a>';
        }
    }

    return $submitted;
}

/**
 * Counts all real assessment submissions by ENROLLED students (not empty ones)
 *
 * @param $groupid int optional If nonzero then count is restricted to this group
 * @return int The number of submissions
 */
function count_real_submissions($cm, $groupid=0) {
    global $CFG, $DB;

    $context = get_context_instance(CONTEXT_MODULE, $cm->id);

    // this is all the users with this capability set, in this context or higher
    if ($users = get_enrolled_users($context, 'mod/progassessment:view', $groupid, 'u.id')) {
        $users = array_keys($users);
    }

    // if groupmembersonly used, remove users who are not in any group
    if ($users and !empty($CFG->enablegroupmembersonly) and $cm->groupmembersonly) {
        if ($groupingusers = groups_get_grouping_members($cm->groupingid, 'u.id', 'u.id')) {
            $users = array_intersect($users, array_keys($groupingusers));
        }
    }

    if (empty($users)) {
        return 0;
    }

    $userlists = implode(',', $users);

    $count = $DB->count_records_sql("SELECT COUNT(*) FROM (SELECT userid, COUNT(*)
                                       FROM {progassessment_submissions}
                                      WHERE progassessment = ? AND
                                            timecreated > 0 AND
                                            userid IN ($userlists)
                                   GROUP BY userid) AS TEMPTABLE", array($cm->instance));

    return $count;
}

function progassessment_get_submission($progassessment, $userid) {
    global $CFG, $DB;
    
    if ($progassessment->gradingmethod == PROGASSESSMENT_BEST_SUBMISSION)
        $select = "SELECT b.id AS id, b.progassessment, b.userid, MAX(b.grade) AS grade";
    else
        $select = "SELECT MAX(b.id) AS id, b.progassessment, b.userid, b.grade AS grade";
    
    $subquery = $select." ".
        "FROM {progassessment_submissions} b
         INNER JOIN {files} g ON b.file = g.id
         WHERE b.userid = $userid
         AND b.progassessment = $progassessment->id
         GROUP BY b.userid";
    
    $result = $DB->get_record_sql("SELECT a.id, a.progassessment, a.grade, a.userid, a.timecreated, a.file, a.isgraded, f.itemid, f.filepath ".
                                  "FROM mdl_progassessment_submissions a INNER JOIN mdl_files f ON a.file = f.id, ".
                                  "(".$subquery.") stub ".
                                  "WHERE a.grade = stub.grade AND a.progassessment = stub.progassessment AND a.userid = stub.userid");
                                   
    return $result;
}

/**
 * Return all progassessment submissions by ENROLLED students (even empty)
 *
 * @param $sort string optional field names for the ORDER BY in the sql query
 * @param $dir string optional specifying the sort direction, defaults to DESC
 * @return array The submission objects indexed by id
 */
function progassessment_get_all_submissions($progassessment, $sort="", $dir="DESC") {
    global $CFG, $DB;

    if ($sort == "lastname" or $sort == "firstname") {
        $sort = "u.$sort $dir";
    } else if (empty($sort)) {
        $sort = "a.timecreated DESC";
    } else {
        $sort = "a.$sort $dir";
    }

	/*
    if ($progassessment->gradingmethod == PROGASSESSMENT_BEST_SUBMISSION)
        $select = "SELECT a.id AS id, a.progassessment, a.userid, a.timecreated, a.file, MAX(a.grade) AS grade, a.isgraded, f.itemid";
    else
        $select = "SELECT MAX(a.id) AS id, a.progassessment, a.userid, a.timecreated, a.file, a.grade AS grade, a.isgraded, f.itemid";
    
	
    $results = $DB->get_records_sql($select." ".
                                           "FROM {user} u, {progassessment_submissions} a
                                            INNER JOIN {files} f ON a.file = f.id
                                            WHERE u.id = a.userid
                                            AND a.progassessment = ?
                                            GROUP BY a.userid
                                            ORDER BY $sort",
                                   array($progassessment->id));
    */
								   
	// all submissions
	$results = $DB->get_records_sql("SELECT a.* , f.* 
									 FROM mdl_progassessment_submissions a
									 INNER JOIN mdl_files f ON a.file = f.id
									 WHERE a.progassessment = ?
									 ORDER BY $sort",
									array($progassessment->id));
         
    return $results;
}

/**
 * Creates a zip of all assignment submissions and sends a zip to the browser
 * @param $progassessment a progassessment instance
 * @param $cm course module
 */
function progassessment_download_submissions($progassessment, $cm) {
    global $CFG, $DB, $COURSE;
    require_once($CFG->libdir.'/filelib.php');
    
    $submissions = progassessment_get_all_submissions($progassessment);
    $context = get_context_instance(CONTEXT_MODULE, $cm->id);
    
    if (empty($submissions)) {
        print_error('errornosubmissions', 'progassessment');
    }
    $filesforzipping = array();
    $fs = get_file_storage();

    $groupmode = groups_get_activity_groupmode($cm);
    $groupid = 0;   // All users
    $groupname = '';
    if ($groupmode) {
        $groupid = groups_get_activity_group($cm, true);
        $groupname = groups_get_group_name($groupid).'-';
    }
    $filename = str_replace(' ', '_', clean_filename($COURSE->shortname.'-'.$progassessment->name.'-'.$groupname.$progassessment->id.".zip")); //name of new zip file.
    
    foreach ($submissions as $submission) {
        $a_userid = $submission->userid; //get userid
        if ((groups_is_member($groupid, $a_userid) or !$groupmode or !$groupid)) {
            $a_assignid = $submission->progassessment; //get name of this assignment for use in the file names.
            $a_user = $DB->get_record("user", array("id"=>$a_userid),'id,username,firstname,lastname'); //get user firstname/lastname

            $files = $fs->get_area_files($context->id, 'mod_progassessment', 'progassessment_submission', $submission->itemid, "timemodified", false);
            
            foreach ($files as $file) {
                //get files new name.
                $fileext = strstr($file->get_filename(), '.');
                $fileoriginal = str_replace($fileext, '', $file->get_filename());
				// attach the submission number
				$structure = split("/", $file->get_filepath(), 4);
                $fileforzipname =  clean_filename(fullname($a_user) . "_" . $fileoriginal."_".$structure[2]."_".$a_userid.$fileext);
				
                // save file name to array for zipping.
                $filesforzipping[$fileforzipname] = $file;
            }
        }
    } // end of foreach loop
    
    // zip
    $tempzip = tempnam($CFG->dataroot.'/temp/', 'progassessment');
    $zipper = new zip_packer();
    if ($zipper->archive_to_pathname($filesforzipping, $tempzip)) {
        send_temp_file($tempzip, $filename); //send file and delete after sending.
    }
}


function progassessment_display_grade($progassessment, $isgraded, $grade) {
    if ($isgraded) {
        if ($grade == -1) {
            return '-';
        } else {
            return $grade.' / '.$progassessment->maxgrade;
        }
    }
    
    return '-';
}

function progassessment_print_student_answer($progassessment, $cm, $userid, $return=false) {
    global $CFG, $OUTPUT, $PAGE;

    $submission = progassessment_get_submission($progassessment, $userid);
    $context = get_context_instance(CONTEXT_MODULE, $cm->id);
    $output = '';

    $renderer = $PAGE->get_renderer('mod_progassessment');
    $output = $OUTPUT->box_start('files').$output;
    $output .= $renderer->progassessment_files($context, $submission->filepath, $submission->itemid);
    $output .= $OUTPUT->box_end();

    return $output;
}


function progassessment_display_submissions($progassessment, $cm, $mode) {
    global $CFG, $PAGE, $OUTPUT, $DB;

    // print header
    $course = $DB->get_record('course', array('id' => $progassessment->course));
    $course_context = get_context_instance(CONTEXT_COURSE, $course->id);
    $context = get_context_instance(CONTEXT_MODULE, $cm->id);
    $strprogassessment  = get_string('modulename', 'progassessment');
    $pagetitle = strip_tags($course->shortname.': '.$strprogassessment.': '.format_string($progassessment->name,true));

    $PAGE->set_title($pagetitle);
    $PAGE->set_heading($course->fullname);

    echo $OUTPUT->header();
    groups_print_activity_menu($cm, $CFG->wwwroot . '/mod/progassessment/view.php?id=' . $cm->id);

    echo '<div class="clearer"></div>';
    
    
    require_once($CFG->libdir.'/gradelib.php');
    /* first we check to see if the form has just been submitted
     * to request user_preference updates
     */
    $filters = array(FILTER_ALL             => get_string('all'),
                     FILTER_SUBMITTED       => get_string('submitted', 'progassessment'));

    $updatepref = optional_param('updatepref', 0, PARAM_INT);

    if (isset($_POST['updatepref'])){
        $perpage = optional_param('perpage', 10, PARAM_INT);
        $perpage = ($perpage <= 0) ? 10 : $perpage ;
        $filter = optional_param('filter', 0, PARAM_INT);
        set_user_preference('progassessment_perpage', $perpage);
        set_user_preference('progassessment_filter', $filter);
    }

    // next we get perpage params from database
    $perpage    = get_user_preferences('progassessment_perpage', 10);
    $filter = get_user_preferences('progassessment_filter', 0);
    $grading_info = grade_get_grades($course->id, 'mod', 'progassessment', $progassessment->id);

    $page    = optional_param('page', 0, PARAM_INT);
    
    
    echo '<div class="usersubmissions">';
    plagiarism_update_status($course, $cm); //hook to allow plagiarism plugins to update status/print links.
    
    if (has_capability('gradereport/grader:view', $course_context) && has_capability('moodle/grade:viewall', $course_context)) {
        echo '<div class="allcoursegrades"><a href="' . $CFG->wwwroot . '/grade/report/grader/index.php?id=' . $course->id . '">'
            . get_string('seeallcoursegrades', 'grades') . '</a></div>';
    }
    
    $groupmode = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm, true);
    groups_print_activity_menu($cm, $CFG->wwwroot . '/mod/progassessment/submissions.php?id=' . $cm->id);

    /// Get all ppl that are allowed to submit assignments
    list($esql, $params) = get_enrolled_sql($context, 'mod/progassessment:view', $currentgroup);
    
    if ($filter == FILTER_ALL) {
        $sql = "SELECT u.id FROM {user} u ".
               "LEFT JOIN ($esql) eu ON eu.id=u.id ".
               "WHERE u.deleted = 0 AND eu.id=u.id ";
    } else {
        $wherefilter = '';
        if ($filter == FILTER_SUBMITTED) {
           $wherefilter = ' AND s.timecreated > 0';
        }
        
        $sql = "SELECT DISTINCT u.id FROM {user} u ".
               "LEFT JOIN ($esql) eu ON eu.id=u.id ".
               "LEFT JOIN {progassessment_submissions} s ON (u.id = s.userid) " .
               "WHERE u.deleted = 0 AND eu.id=u.id ".
               'AND s.progassessment = '. $progassessment->id .
                $wherefilter;
    }
    
    $users = $DB->get_records_sql($sql, $params);
    if (!empty($users)) {
       $users = array_keys($users);
    }
    
    // if groupmembersonly used, remove users who are not in any group
    if ($users and !empty($CFG->enablegroupmembersonly) and $cm->groupmembersonly) {
        if ($groupingusers = groups_get_grouping_members($cm->groupingid, 'u.id', 'u.id')) {
            $users = array_intersect($users, array_keys($groupingusers));
        }
    }
    
    $tablecolumns = array('picture', 'fullname', 'grade', 'timecreated', 'isgraded');
    
    $tableheaders = array('',
        get_string('fullname'),
        get_string('grade'),
        get_string('lastmodified').' ('.get_string('submission', 'progassessment').')',
        get_string('isgraded', 'progassessment'));
        
    require_once($CFG->libdir.'/tablelib.php');
    $table = new flexible_table('mod-progassessment-submissions');

    $table->define_columns($tablecolumns);
    $table->define_headers($tableheaders);
    $table->define_baseurl($CFG->wwwroot.'/mod/progassessment/submissions.php?id='.$cm->id.'&amp;currentgroup='.$currentgroup);

    $table->sortable(true, 'lastname');//sorted by lastname by default
    $table->collapsible(true);
    $table->initialbars(true);

    $table->column_suppress('picture');
    $table->column_suppress('fullname');

    $table->column_class('picture', 'picture');
    $table->column_class('fullname', 'fullname');
    $table->column_class('grade', 'grade');
    $table->column_class('timecreated', 'timecreated');
    $table->column_class('isgraded', 'isgraded');

    $table->set_attribute('cellspacing', '0');
    $table->set_attribute('id', 'attempts');
    $table->set_attribute('class', 'submissions');
    $table->set_attribute('width', '100%');
    //$table->set_attribute('align', 'center');
    
    $table->no_sorting('isgraded');

    // Start working -- this is necessary as soon as the niceties are over
    $table->setup();

    if (empty($users)) {
        echo $OUTPUT->heading(get_string('nosubmitusers','progassessment'));
        echo '</div>';
        return true;
    }
    
    echo '<div style="text-align:right"><a href="submissions.php?id='.$cm->id.'&amp;download=zip">'.get_string('downloadall', 'progassessment').'</a></div>';
    
    /// Construct the SQL

    $where = "";
    if ($where) {
        $where .= ' AND ';
    }
    
    if ($filter == FILTER_SUBMITTED) {
       $where .= 's.timecreated > 0 AND ';
    }
    
    if ($sort = $table->get_sql_sort()) {
        $sort = ' ORDER BY '.$sort;
    }

    $ufields = user_picture::fields('u');
    
	if ($progassessment->gradingmethod == PROGASSESSMENT_LAST_SUBMISSION) {
		$subquery = "(SELECT MAX(id) AS submissionid FROM mdl_progassessment_submissions WHERE progassessment=$progassessment->id GROUP BY userid)";
	
		$select = "SELECT $ufields, s.id AS submissionid, s.grade AS grade, s.timecreated, s.isgraded ";
		
		$sql = 'FROM {user} u '.
			   'LEFT JOIN {progassessment_submissions} s ON u.id = s.userid
				AND s.progassessment = '.$progassessment->id.' '.
			   'WHERE '.$where.'u.id IN ('.implode(',',$users).') AND s.id IN '.$subquery.
			   'GROUP BY u.id';
	} else {
		$select = "SELECT $ufields, s.id AS submissionid, MAX(s.grade) AS grade, s.timecreated, s.isgraded ";
	
		$sql = 'FROM {user} u '.
			   'LEFT JOIN {progassessment_submissions} s ON u.id = s.userid
				AND s.progassessment = '.$progassessment->id.' '.
			   'WHERE '.$where.'u.id IN ('.implode(',',$users).') '.
			   'GROUP BY u.id';
	}
   
    $ausers = $DB->get_records_sql($select.$sql.$sort, $params, $table->get_page_start(), $table->get_page_size());
    $table->pagesize($perpage, count($users));
    
    // offset used to calculate index of student in that particular query, needed for the pop up to know who's next
    $offset = $page * $perpage;
    
    $grademenu = make_grades_menu($progassessment->maxgrade);
    
    // fill table
    if ($ausers !== false) {
        $grading_info = grade_get_grades($course->id, 'mod', 'progassessment', $progassessment->id, array_keys($ausers));
        
        $endposition = $offset + $perpage;
        $currentposition = 0;
        
        foreach ($ausers as $auser) {
            
            if ($currentposition >= $offset && $currentposition < $endposition) {
                
                $final_grade = $grading_info->items[0]->grades[$auser->id];
                $grademax = $grading_info->items[0]->grademax;
                $final_grade->formatted_grade = round($final_grade->grade,2) .' / ' . round($grademax,2);
                $locked_overridden = 'locked';
                if ($final_grade->overridden) {
                    $locked_overridden = 'overridden';
                }

                $picture = $OUTPUT->user_picture($auser);

                if (empty($auser->submissionid)) {
                    $auser->grade = -1; //no submission yet
                }

                if (!empty($auser->submissionid)) {
                ///Prints student answer and student modified date
                ///attach file or print link to student answer, depending on the type of the assignment.
                ///Refer to print_student_answer in inherited classes.
                    if ($auser->timecreated > 0) {
                        $studentmodified = '<div id="ts'.$auser->id.'">'.progassessment_print_student_answer($progassessment, $cm, $auser->id)
                                         . userdate($auser->timecreated).'</div>';
                    } else {
                        $studentmodified = '<div id="ts'.$auser->id.'">&nbsp;</div>';
                    }
                ///Print grade, dropdown or text
                    if ($auser->isgraded == 1) {
                        
                        if ($final_grade->locked or $final_grade->overridden) {
                            $grade = '<div id="g'.$auser->id.'" class="'. $locked_overridden .'">'.$final_grade->formatted_grade.'</div>';
                        } else {
                            $grade = '<div id="g'.$auser->id.'">'.progassessment_display_grade($progassessment, $auser->isgraded, $auser->grade).'</div>';
                        }

                    } else {
                       
                        if ($final_grade->locked or $final_grade->overridden) {
                            $grade = '<div id="g'.$auser->id.'" class="'. $locked_overridden .'">'.$final_grade->formatted_grade.'</div>';
                        } else {
                            $grade = '<div id="g'.$auser->id.'">'.progassessment_display_grade($progassessment, $auser->isgraded, $auser->grade).'</div>';
                        }
                    }
                } else {
                    $studentmodified = '<div id="ts'.$auser->id.'">&nbsp;</div>';

                    if ($final_grade->locked or $final_grade->overridden) {
                        $grade = '<div id="g'.$auser->id.'">'.$final_grade->formatted_grade . '</div>';
                    } else {
                        $grade = '<div id="g'.$auser->id.'">-</div>';
                    }
                }

                if (empty($auser->isgraded)) { /// Confirm we have exclusively 0 or 1
                    $auser->isgraded = 0;
                } else {
                    $auser->isgraded = 1;
                }

                $isgradedtext = ($auser->isgraded == 1) ? get_string('yes', 'progassessment') : get_string('no', 'progassessment');
                
                $isgraded  = '<div id="up'.$auser->id.'" class="s'.$auser->isgraded.'">'.$isgradedtext.'</div>';
         
                $userlink = '<a href="' . $CFG->wwwroot . '/user/view.php?id=' . $auser->id . '&amp;course=' . $course->id . '">' . fullname($auser, has_capability('moodle/site:viewfullnames', $context)) . '</a>';
                $row = array($picture, $userlink, $grade, $studentmodified, $isgraded);
            
                $table->add_data($row);
            }
            
            $currentposition++;
        }
    }
    
    $table->print_html();  /// Print the whole table
    echo '</div>';
    
    
    /// Mini form for setting user preference

    $formaction = new moodle_url('/mod/progassessment/submissions.php', array('id'=>$cm->id));
    $mform = new MoodleQuickForm('optionspref', 'post', $formaction, '', array('class'=>'optionspref'));

    $mform->addElement('hidden', 'updatepref');
    $mform->setDefault('updatepref', 1);
    $mform->addElement('header', 'qgprefs', get_string('optionalsettings', 'progassessment'));
    $mform->addElement('select', 'filter', get_string('show'),  $filters);

    $mform->setDefault('filter', $filter);

    $mform->addElement('text', 'perpage', get_string('pagesize', 'assignment'), array('size'=>1));
    $mform->setDefault('perpage', $perpage);

    $mform->addElement('submit', 'savepreferences', get_string('savepreferences'));

    $mform->display();
    
    // display footer
    echo $OUTPUT->footer();
}

/**
 * Tests if a string begins with a given substring.
 *
 * @param string $str the string to search
 * @param string $sub the given substring
 * @return boolean result
 */
function beginsWith($str, $sub) {
    return (strncmp($str, $sub, strlen($sub)) == 0);
}


/**
 * Must return an array of user records (all data) who are participants
 * for a given instance of progassessment. Must include every user involved
 * in the instance, independient of his role (student, teacher, admin...)
 * See other modules as example.
 *
 * @param int $progassessmentid ID of an instance of this module
 * @return mixed boolean/array of students
 */
function progassessment_get_participants($progassessmentid) {
    return false;
}


/**
 * This function returns if a scale is being used by one progassessment
 * if it has support for grading and scales. Commented code should be
 * modified if necessary. See forum, glossary or journal modules
 * as reference.
 *
 * @param int $progassessmentid ID of an instance of this module
 * @return mixed
 */
function progassessment_scale_used($progassessmentid, $scaleid) {
    $return = false;

    //$rec = get_record("progassessment","id","$progassessmentid","scale","-$scaleid");
    //
    //if (!empty($rec) && !empty($scaleid)) {
    //    $return = true;
    //}

    return $return;
}


/**
 * Checks if scale is being used by any instance of progassessment.
 * This function was added in 1.9
 *
 * This is used to find out if scale used anywhere
 * @param $scaleid int
 * @return boolean True if the scale is used by any progassessment
 */
function progassessment_scale_used_anywhere($scaleid) {
    global $DB;

    if ($scaleid and $DB->record_exists('progassessment', 'grade', -$scaleid)) {
        return true;
    } else {
        return false;
    }
}


/**
 * Execute post-install custom actions for the module
 * This function was added in 1.9
 *
 * @return boolean true if success, false on error
 */
function progassessment_install() {
    $client = progassessment_get_client();
    $id = $client->setupProgassessmentModule();
    return $id >= 0;
}


/**
 * Execute post-uninstall custom actions for the module
 * This function was added in 1.9
 *
 * @return boolean true if success, false on error
 */
function progassessment_uninstall() {
    return true;
}


///////////////////////////////////////////////////////////////////////////////
/// Any other progassessment functions go here.  Each of them must have a name
/// that starts with progassessment_

/**
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, null if doesn't know
 */
function progassessment_supports($feature) {
    switch($feature) {
        //case FEATURE_GROUPS:                  return true;
        //case FEATURE_GROUPINGS:               return true;
        //case FEATURE_GROUPMEMBERSONLY:        return true;
        case FEATURE_MOD_INTRO:               return true;
        //case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_GRADE_HAS_GRADE:         return true;
        //case FEATURE_GRADE_OUTCOMES:          return true;

        default: return null;
    }
}

/**
 * Return grade for given user or all users.
 *
 * @param int $progassessment id of progassessment
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none
 */
function progassessment_get_user_grades($progassessment, $userid=0) {
    global $DB;

    if ($userid) {
        $submission = progassessment_get_current_submission($progassessment, $userid);
        return array($userid => $submission->grade);
    } else {
        $submissions = $DB->get_records("progassessment_submissions", array("progassessment" => $progassessment->id));
        $users = array();
        $grades = array();

        foreach($submissions as $submission) {
            if (! in_array($submission->userid, $users)) {
                $users[] = $submission->userid;
            }
        }

        foreach($users as $user) {
            $submission = progassessment_get_current_submission($progassessment, $user);
            $grades[$user] = array('id' => $user, 'userid' => $user, 'rawgrade' => $submission->grade);
        }

        return $grades;
    }
}

function progassessment_grade_item_update($progassessment, $grades=NULL) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    $params = array('itemname'=> $progassessment->name);

    if ($progassessment->maxgrade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $progassessment->maxgrade;
        $params['grademin']  = 0;

    } else {
        $params['gradetype'] = GRADE_TYPE_TEXT;
    }

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = NULL;
    }

    return grade_update('mod/progassessment', $progassessment->course, 'mod', 'progassessment', $progassessment->id, 0, $grades, $params);
}

function progassessment_update_grades($progassessment, $userid=0) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    if ($progassessment->maxgrade == 0) {
        progassessment_grade_item_update($assignment);
    }
    else {
        $grades = assignment_get_user_grades($assignment, $userid);
        assignment_grade_item_update($assignment, $grades);
    }
}

function progassessment_get_remaining_duration($progassessment, $userid=0) {
    global $DB, $USER;

    if ($progassessment->duration == 0) {
        return 0;
    }

    if ($userid == 0) {
        $userid = $USER->id;
    }

    $view_records = $DB->get_records('log', array('module' => 'progassessment', 'action' => 'view', 'info' => $progassessment->id, 'userid' => $userid), "time asc");

    if (sizeof($view_records)) {
        $record = array_shift($view_records);
        return max(0, $progassessment->duration - (time() - $record->time));
    } else {
        return $progassessment->duration;
    }
}

function progassessment_can_submit($progassessment, $context, $userid=0) {
    global $DB, $USER;

    if ($userid == 0) {
        $userid = $USER->id;
    }

    if (! has_capability('mod/progassessment:submit', $context)) {
        return false;
    }

    //no time remaining
    if ($progassessment->duration && progassessment_get_remaining_duration($progassessment) <= 0) {
        return false;
    }

    //get the submissions from the user
    $submissions = $DB->get_records('progassessment_submissions', array('userid' => $userid, 'progassessment' => $progassessment->id));

    //the maximum number of submissions has already been reached
    if ($progassessment->maxsubmissions > 0 && count($submissions) >= $progassessment->maxsubmissions) {
        return false;
    }

    //check for the submissions time
    $time = time();

    if ($progassessment->timetolerance && $time <= $progassessment->timetolerance) {
        return true;
    }
    
    if ($progassessment->timedue && $time > $progassessment->timedue) {
        return false;
    }

    return true;
}


function progassessment_get_client() {
    ini_set("soap.wsdl_cache_enabled", "0"); // disabling WSDL cache
    return new SoapClient(PROGASSESSMENT_SERVER_DEFINITION, array('features' => SOAP_SINGLE_ELEMENT_ARRAYS));
}

//Returns an array with the list of programming languages available in the server.
//If an error occurs while connecting to the server, the resulting array is empty.
function progassessment_get_available_languages() {
    $languages = array();
    $client = progassessment_get_client();
    $answ = $client->getLanguages();
    
    if (is_array($answ->string)) {
       $languages = $answ->string;
    } else {
        array_push($languages, $answ->string);
    }

    return $languages;
}

//Returns an array with the list of metrics available in the server.
//If an error occurs while connecting to the server, the resulting array is empty.
function progassessment_get_available_metrics() {

    $metrics = array();
    $client = progassessment_get_client();
    $result = $client->getMetrics();
    
    foreach ($result->item as $langarray) {
        
        if ($langarray->value === "") {
            $metrics[$langarray->key] = array();
            continue;
        }
        
        foreach ($langarray->value->item as $metrictype) {
            
            foreach ($metrictype->value->item as $metric) {
                
                $metrics[$langarray->key][$metrictype->key][$metric->key] = $metric->value;
                
            }
        }
    }

    return $metrics;
}

function progassessment_get_metric_keys() {
    
    $keys = array ( 'halstead' => get_string('keyshalstead', 'progassessment'),
                    'style' => get_string('keysstyle', 'progassessment'),
                    'misc' => get_string('keysmisc', 'progassessment')
                  );
                  
    return $keys;
}

function progassessment_process_form_languages($progassessment, $mform) {
    global $progassessment_languages;
    
    $raw_data = $mform->get_raw_data();
	$progassessment->proglanguage = (int)$raw_data['proglanguage'];
	
    return $progassessment_languages[$progassessment->proglanguage];
}

function progassessment_process_static_analysis(&$progassessment, $mform) {
    global $CFG;
    
	$lang = $progassessment->proglanguages;
	
	$metrics = progassessment_get_available_metrics();
	$metrics = $metrics[$lang];
	
	$lang = progassessment_parse_language($lang);	
	
	$raw_data = $mform->get_raw_data();
	$progassessment->sagrade = (isset($raw_data['maxsa'.$lang]) ? $raw_data['maxsa'.$lang] : $CFG->progassessment_maxstatic);
	
	if (!isset($raw_data['staticanalysistoggle'.$lang]) || ($raw_data['staticanalysistoggle'.$lang] == 0) || (!$metrics)) {
		$progassessment->saenabled = false;
		return;
	}
	
	$progassessment->saenabled = true;
	
	foreach ($metrics as $key => $group)
		foreach (array_keys($group) as $metric_keys) {
		
			if (isset($raw_data[$metric_keys.$lang])) {
	
				$sametrics[$key][$metric_keys] = array( 'min' 		=> $raw_data[$metric_keys.$lang.'_min'],
														'max' 		=> $raw_data[$metric_keys.$lang.'_max'],
														'weight' 	=> $raw_data[$metric_keys.$lang.'_weight'] );
				
			}
		}
		
	$progassessment->sametrics = $sametrics;
}

function progassessment_get_metrics($progassessment) {
    global $DB;
    
    $res = $DB->get_records_sql(   'SELECT s.id, s.sagroup, s.metric, s.min, s.max, s.weight
                                    FROM {progassessment} p INNER JOIN {progassessment_static_analysis} s ON p.id = s.progassessment
                                    WHERE p.id = '.$progassessment->id);
    
    if (count($res) == 0)
        return null;
    
    foreach ($res as $prog)
        $metrics[$prog->sagroup][$prog->metric] = array('min' => $prog->min, 'max' => $prog->max, 'weight' => $prog->weight);
    
    return $metrics;
}

function progassessment_parse_language($lang) {
    
    if ($lang === "C++")
		$lang = "CPP";
	else if ($lang === "C#")
		$lang = "CS";
		
	return $lang;
}

function progassessment_delete_metrics($id) {
    global $DB;
    
    $DB->delete_records('progassessment_static_analysis', array('progassessment' => $id));
}

function progassessment_update_metrics($progassessment) {
    global $DB;
    
    progassessment_delete_metrics($progassessment->id);
    
    $lang = progassessment_parse_language($progassessment->proglanguages);
    
    $metric = array();
    $metric['progassessment'] = $progassessment->id;
    
    foreach ($progassessment->sametrics as $key => $group) {
    
        $metric['sagroup'] = $key;
    
        foreach ($group as $metric_key => $metric_array) {
    
            $metric['metric'] = $metric_key.$lang;
            $metric['min'] = (double)$metric_array['min'];
            $metric['max'] = (double)$metric_array['max'];
            $metric['weight'] = (double)$metric_array['weight'];
            
            $DB->insert_record('progassessment_static_analysis', $metric);
        }
    }
}

function progassessment_is_student_code_line($line, $comment) {
    $line = trim($line);
    $commentlen = strlen($comment);

    //the line has to start with a comment
    if (strlen($line) > $commentlen && substr_compare($line, $comment, 0, $commentlen) == 0) {
        $studentcode = trim(substr($line, $commentlen));

        if ($studentcode == STUDENT_CODE_IDENTIFIER) {
            return true;
        }
    }

    return false;
}


function progassessment_get_language_comment($language) {
    global $progassessment_languages_comments;

    foreach ($progassessment_languages_comments as $lang => $com) {
        if ($lang == $language) {
            return $com;
        }
    }

    return false;
}

function progassessment_validate_skeletonfile($language, $file_handle) {
    $comment =  progassessment_get_language_comment($language);

    if (! $comment) {
        return false;
    }

    //looks for the line that identifies the point where the student code should be included
    while (!feof($file_handle)) {
        $line = fgets($file_handle);

        if (progassessment_is_student_code_line($line, $comment)) {
            fclose($file_handle);
            return true;
        }
    }

    fclose($file_handle);
    return false;
}

function progassessment_merge_skeletonfile_studentfile($language, $skeletonfile_handle, $studentfile_handle, &$result_handle) {
    $comment =  progassessment_get_language_comment($language);

    if (! $comment) {
        return;
    }

    $included = false;

    while (!feof($skeletonfile_handle)) {
        $line = fgets($skeletonfile_handle);

        //we've reached the point where the student code should be included
        if (!$included &&  progassessment_is_student_code_line($line, $comment)) {
            while (!feof($studentfile_handle)) {
                $line = fgets($studentfile_handle);
                fwrite($result_handle, $line);
            }
            $included = true;
        } else {
            fwrite($result_handle, $line);
        }
    }
}

function progassessment_validate_skeleton_file($progassessment, $skeleton_file) {
    global $CFG;

    $client = progassessment_get_client();
    $language = progassessment_validate_file_language($progassessment, $skeleton_file->get_filename(), $client);

    if (! $language) {
        return false;
    }

    return progassessment_validate_skeletonfile($language, $skeleton_file->get_content_file_handle());
}

function progassessment_process_skeleton_file($progassessment, $mform) {

    $skeleton_filename = $mform->get_new_filename("skeleton");

    if (! $skeleton_filename) {
        return 0;
    }

    $file_storage = get_file_storage();

    if (isset($progassessment->coursemodule)) {
        $cmid = $progassessment->coursemodule;
    } else {
        $cm = get_coursemodule_from_instance('progassessment', $progassessment->id, $progassessment->course);
        $cmid = $cm->id;
    }

    $context = get_context_instance(CONTEXT_MODULE, $cmid);
    $data = $mform->get_data();

    if ($file_storage->file_exists($context->id, 'mod_progassessment', 'progassessment_skeleton', $data->skeleton, "/$progassessment->id/", $skeleton_filename)) {
        $skeleton_file = $file_storage->get_file($context->id, 'mod_progassessment', 'progassessment_skeleton', $data->skeleton, "/$progassessment->id/", $skeleton_filename);
        $skeleton_file->delete();
    }

    $skeleton_file = $mform->save_stored_file("skeleton", $context->id, 'mod_progassessment', 'progassessment_skeleton', $data->skeleton, "/$progassessment->id/", $skeleton_filename);

    return progassessment_validate_skeleton_file($progassessment, $skeleton_file) ? $skeleton_file->get_id() : 0;
}

function progassessment_process_intro_file($progassessment, $mform) {

    $intro_filename = $mform->get_new_filename('descriptionfile');

    if (! $intro_filename) {
        return 0;
    }

    $file_storage = get_file_storage();

    if (isset($progassessment->coursemodule)) {
        $cmid = $progassessment->coursemodule;
    } else {
        $cm = get_coursemodule_from_instance('progassessment', $progassessment->id, $progassessment->course);
        $cmid = $cm->id;
    }

    $context = get_context_instance(CONTEXT_MODULE, $cmid);
    $data = $mform->get_data();

    
    if ($file_storage->file_exists($context->id, 'mod_progassessment', 'progassessment_description', $data->descriptionfile, "/", $intro_filename)) {
        $intro_file = $file_storage->get_file($context->id, 'mod_progassessment', 'progassessment_description', $data->descriptionfile, "/", $intro_filename);
        $intro_file->delete();
    }

//  $intro_file = $mform->save_stored_file("descriptionfile", $context->id, 'progassessment_description', $data->descriptionfile, "/", $intro_filename);
	$intro_file = $mform->save_stored_file('descriptionfile', $context->id, 'mod_progassessment',
		'progassessment_description', $data->descriptionfile, "/", $intro_filename);

    return $intro_file->get_id();
}

//Adds a new programming assessment to the automatic assessment server
function progassessment_add_instance_to_server($progassessment) {

    $id = $progassessment->id;
    $name = $progassessment->name;
    $timeLimit = 5;

    $client = progassessment_get_client();
    
    // Oracle support
    if ($progassessment->proglanguages === "SQL")
        $serverid = $client->addNewAssessmentSpecial($id, $name, $timeLimit, "oracle.sh");
    else
        $serverid = $client->addNewAssessment($id, $name, $timeLimit);

    if ($serverid >= 0) {
        $progassessment->serverid = $serverid;
        return true;
    } else {
        return false;
    }
}

function progassessment_add_dummy_test_case($progassessment) {
    global $DB;
    
    $client = progassessment_get_client();
    $serverid = $client->addTestCase($progassessment->serverid, "", "", -1);
    $DB->set_field('progassessment', 'dummytestcase', $serverid, array("id" => $progassessment->id));
}


function progassessment_line_starts_with($line, $identifier) {
    $line = trim($line);
    $identifierlen = strlen($identifier);
    return (strlen($line) >= $identifierlen && substr_compare($line, $identifier, 0, $identifierlen) == 0);
}

function progassessment_read_test_case_arguments($file_handle, &$args) {

    while (!feof($file_handle)) {
        $line = fgets($file_handle);

        if (progassessment_line_starts_with($line, PROGASSESSMENT_TEST_CASE_WEIGHT)) {
            $weight = trim(substr($line, strlen(PROGASSESSMENT_TEST_CASE_WEIGHT)));

            if ($weight) {
                $args[PROGASSESSMENT_TEST_CASE_WEIGHT] = (int) $weight;
            }
            
        } else if (progassessment_line_starts_with($line, PROGASSESSMENT_TEST_CASE_NAME)) {
            $name = trim(substr($line, strlen(PROGASSESSMENT_TEST_CASE_NAME)));

            if ($name) {
                $args[PROGASSESSMENT_TEST_CASE_NAME] = $name;
            }

        } else if (progassessment_line_starts_with($line, PROGASSESSMENT_TEST_CASE_RIGHT)) {
            $right = trim(substr($line, strlen(PROGASSESSMENT_TEST_CASE_RIGHT)));

            if ($right) {
                $args[PROGASSESSMENT_TEST_CASE_RIGHT] = $right;
            }

        } else if (progassessment_line_starts_with($line, PROGASSESSMENT_TEST_CASE_WRONG)) {
            $wrong = trim(substr($line, strlen(PROGASSESSMENT_TEST_CASE_WRONG)));

            if ($wrong) {
                $args[PROGASSESSMENT_TEST_CASE_WRONG] = $wrong;
            }

        } else {
            return $line;
        }
    }

    return false;
}

function progassessment_split_input_file($file_handle) {
    $inputs = array();
    $input = "";
    $in_testcase = false;
    $args = array();
    $line = false;

    while (!feof($file_handle)) {
        $line = fgets($file_handle);
        
        if (progassessment_line_starts_with($line, PROGASSESSMENT_TEST_CASE_IDENTIFIER)) {

            //not the first test case
            if ($in_testcase) {
                $inputs[] = array($input, $args);
            }

            $args = array();
            $in_testcase = true;
            $line = progassessment_read_test_case_arguments($file_handle, $args);
            $input = "";
        }

        if ($in_testcase && $line) {
            $input .= $line;
        }
    }

    if ($in_testcase) {
        $inputs[] = array($input, $args);
    }

    return $inputs;
}

function progassessment_split_output_file($file_handle) {
    $outputs = array();
    $output = "";
    $intestcase = false;
    $last_line = "";

    while (!feof($file_handle)) {
        $line = fgets($file_handle);

        if (progassessment_line_starts_with($line, PROGASSESSMENT_TEST_CASE_IDENTIFIER)) {

            if ($intestcase) {
                $outputs[] = $output;
                $output = "";
            }

            $intestcase = true;
        } else if (trim($line) != "") {
            $output .= $line;
        }
    }

    if ($intestcase) {
        $outputs[] = $output;
    }

    return $outputs;
}



function progassessment_add_test_cases_file($progassessment, $mform, $context, $i) {
    global $DB;

    $input_filename = $mform->get_new_filename("inputfile_$i");
    $output_filename = $mform->get_new_filename("outputfile_$i");
    $testcases = array();

    if ($input_filename && $output_filename) {
        $input_content = $mform->get_file_content("inputfile_$i");
        $output_content = $mform->get_file_content("outputfile_$i");
        $client = progassessment_get_client();
        $data = $mform->get_data();
        $in = "inputfile_$i";
        $out = "outputfile_$i";
        $file_storage = get_file_storage();


        if ($file_storage->file_exists($context->id, 'mod_progassessment', 'progassessment_input', $data->$in, "/$progassessment->id/", $input_filename)) {
            $input_file = $file_storage->get_file($context->id, 'mod_progassessment', 'progassessment_input', $data->$in, "/$progassessment->id/", $input_filename);
            $input_file->delete();
        }

        $input_file = $mform->save_stored_file($in, $context->id, 'mod_progassessment',
            'progassessment_input', $data->$in, "/", $input_filename);
        	
        if ($file_storage->file_exists($context->id, 'mod_progassessment', 'progassessment_output', $data->$out, "/$progassessment->id/", $output_filename)) {
            $output_file = $file_storage->get_file($context->id, 'mod_progassessment', 'progassessment_output', $data->$out, "/$progassessment->id/", $output_filename);
            $output_file->delete();
        }

        $output_file = $mform->save_stored_file($out, $context->id, 'mod_progassessment',
            'progassessment_output', $data->$out, "/", $output_filename);
        		

        $testcasefile = new Object();
        $testcasefile->progassessment = $progassessment->id;
        $testcasefile->inputfile = $input_file->get_id();
        $testcasefile->outputfile = $output_file->get_id();

        $testfile_id = $DB->insert_record('progassessment_testfiles', $testcasefile);

        //split the test cases of the input and output files
        $inputs = progassessment_split_input_file($input_file->get_content_file_handle());
        $outputs = progassessment_split_output_file($output_file->get_content_file_handle());

        $ntestcases = min(sizeof($inputs), sizeof($outputs));

        for ($i = 0; $i < $ntestcases; $i++) {
            $input_content = $inputs[$i][0];
            $output_content = $outputs[$i];

            //prepare the test case object
            $testcase = new Object();
            $testcase->progassessment = $progassessment->id;
            $testcase->input = $input_content;
            $testcase->output = $output_content;
            $testcase->testfile = $testfile_id;

            if (isset($inputs[$i][1][PROGASSESSMENT_TEST_CASE_WEIGHT])) {
                $testcase->weight = $inputs[$i][1][PROGASSESSMENT_TEST_CASE_WEIGHT];
            } else {
                $testcase->weight = 0;
            }

            if (isset($inputs[$i][1][PROGASSESSMENT_TEST_CASE_NAME])) {
                $testcase->name = $inputs[$i][1][PROGASSESSMENT_TEST_CASE_NAME];
            } else {
                $testcase->name = $i + 1;
            }

            if (isset($inputs[$i][1][PROGASSESSMENT_TEST_CASE_WRONG])) {
                $testcase->wrong_feedback = $inputs[$i][1][PROGASSESSMENT_TEST_CASE_WRONG];
            }
            if (isset($inputs[$i][1][PROGASSESSMENT_TEST_CASE_RIGHT])) {
                $testcase->right_feedback = $inputs[$i][1][PROGASSESSMENT_TEST_CASE_RIGHT];
            }

            $testcase->id = $DB->insert_record('progassessment_testcases', $testcase);

            $testcase->serverid = $client->addTestCase($progassessment->serverid, $input_content, $output_content, $testcase->id);
            $DB->set_field('progassessment_testcases', "serverid", $testcase->serverid, array("id" => $testcase->id));

            $testcases[] = $testcase;
        }
    }

    return $testcases;
}


function progassessment_normalize_test_cases_weights($progassessment, $testcases) {
    global $DB;

    $totalweight = 0;
    foreach ($testcases as $t) {
        $totalweight += $t->weight;
    }

    if ($totalweight > 0) {
        $ratio = $progassessment->maxgrade / $totalweight;
        $totalweight = 0;

        foreach ($testcases as $t) {
            $t->weight = (int) $t->weight * $ratio;
            $totalweight  += $t->weight;
        }
    } else {
        foreach ($testcases as $t) {
            $t->weight = (int) ($progassessment->maxgrade / count($testcases));
            $totalweight += $t->weight;
        }
    }

    foreach ($testcases as $t) {
        if ($totalweight < $progassessment->maxgrade) {
            ++$totalweight;
            ++$t->weight;
        }
        $DB->set_field('progassessment_testcases', "weight", $t->weight, array("id" => $t->id));
    }

    foreach ($testcases as $t) {
        if ($totalweight > $progassessment->maxgrade) {
            --$totalweight;
            --$t->weight;
        }
        $DB->set_field('progassessment_testcases', "weight", $t->weight, array("id" => $t->id));
    }

    //recalculate the grades of the submissions related to the assessment
    $submissions =  $DB->get_records('progassessment_submissions', array('progassessment' => $progassessment->id));

    foreach ($submissions as $submission) {
        $grade = 0;
        $submissions_testcases = $DB->get_records('progassessment_submissions_testcases', array('submission' => $submission->id));

        foreach($submissions_testcases as $s_t) {
            if (progassessment_is_result_correct($s_t->result, $s_t->output_error)) {
                $testcase = $DB->get_record('progassessment_testcases', array('id' => $s_t->testcase));
                $grade += $testcase->weight;
            }
        }
        $DB->set_field('progassessment_submissions', 'grade', $grade, array('id' => $submission->id));
    }
}


function progassessment_add_test_cases($progassessment, $mform) {
    global $DB;

    if (isset($progassessment->coursemodule)) {
        $cmid = $progassessment->coursemodule;
    } else {
        $cm = get_coursemodule_from_instance('progassessment', $progassessment->id, $progassessment->course);
        $cmid = $cm->id;
    }

    $ntestcase_files = $progassessment->testfiles_repeats;
    $context = get_context_instance(CONTEXT_MODULE, $cmid);
    $progassessment = $DB->get_record('progassessment', array('id' => $progassessment->id), '*', MUST_EXIST);
    $testcases = array();

    //add a dummy test case, used for the compilation playground
    progassessment_add_dummy_test_case($progassessment);

    for ($i = 0; $i < $ntestcase_files; $i++) {
        $tc = progassessment_add_test_cases_file($progassessment, $mform, $context, $i);
        $testcases = array_merge($testcases, $tc);
    }

    //normalize the weight of the test cases
    progassessment_normalize_test_cases_weights($progassessment, $testcases);
}

function progassessment_remove_instance_from_server($progassessment) {
    global $DB;

    $client = progassessment_get_client();

    //remove the dummy testcase
    $client->removeTestCase($progassessment->dummytestcase);

    $testcases = $DB->get_records('progassessment_testcases', array('progassessment' => $progassessment->id));

    //remove all the test cases from the server
    foreach ($testcases as $key => $value) {
        $client->removeTestCase($value->serverid);
    }

    $client->removeAssessment($progassessment->serverid);
}



function progassessment_update_instance_in_server($serverid, $progassessment) {
    global $DB;

    $client = progassessment_get_client();

    $name = $progassessment->name;
    $timeLimit = 5;

    //check the number of test cases of this problem
    $testcases = $DB->get_records('progassessment_testcases', array('progassessment' => $progassessment->id));
    $ntestcases = sizeof($testcases);

    // Oracle support
    if ($progassessment->proglanguages === "SQL")
        $client->updateAssessmentSpecial($serverid, $name, $timeLimit, $ntestcases, "oracle.sh");
    else
        $client->updateAssessment($serverid, $name, $timeLimit, $ntestcases);
}

function progassessment_update_test_cases($progassessment, $mform) {
    global $DB;

    if (isset($progassessment->coursemodule)) {
        $cmid = $progassessment->coursemodule;
    } else {
        $cm = get_coursemodule_from_instance('progassessment', $progassessment->id, $progassessment->course);
        $cmid = $cm->id;
    }

    $testfiles = $DB->get_records('progassessment_testfiles', array('progassessment' => $progassessment->id), 'id ASC');
    $keys = array_keys($testfiles);
    $ntestcase_files = $progassessment->testfiles_repeats;
    $context = get_context_instance(CONTEXT_MODULE, $cmid);
    $file_storage = get_file_storage();
    $data = $mform->get_data();
    $testcases = array();

    for ($i = 0; $i < $ntestcase_files; $i++) {

        //check if this testfile was modified
        if ($i < count($testfiles)) {
            $testfile = $testfiles[$keys[$i]];
            $tc = $DB->get_records('progassessment_testcases', array('progassessment' => $progassessment->id, 'testfile' => $testfile->id));

            $in = "inputfile_$i";
            $out = "outputfile_$i";
            $input = $file_storage->get_file_by_id($testfile->inputfile);
            $output = $file_storage->get_file_by_id($testfile->outputfile);

            //the testfile has changed
            if ($input->get_itemid() != $data->$in || $output->get_itemid() != $data->$out) {

                $client = progassessment_get_client();

                foreach ($testcases as $key => $value) {
                    $client->removeTestCase($value->serverid);
                    $DB->delete_records('progassessment_testcases', array('id' => $key));
                }

                $DB->delete_records('progassessment_testfiles', array('id' => $testfile->id));
                $tc = progassessment_add_test_cases_file($progassessment, $mform, $context, $i);
                $testcases = array_merge($testcases, $tc);
            } else {
                $testcases = array_merge($testcases, array_values($tc));
            }

            
        } else {
            $tc = progassessment_add_test_cases_file($progassessment, $mform, $context, $i);
            $testcases = array_merge($testcases, $tc);
        }
    }

    progassessment_normalize_test_cases_weights($progassessment, $testcases);
}


function progassessment_pluginfile($course, $cminfo, $context, $filearea, $args, $forcedownload) {
    global $CFG, $DB;

    if (!$progassessment = $DB->get_record('progassessment', array('id'=>$cminfo->instance))) {
        return false;
    }
    
    if (!$cm = get_coursemodule_from_instance('progassessment', $progassessment->id, $course->id)) {
        return false;
    }

    require_login($course, false, $cm);

    $fs = get_file_storage();
    $fileid = (int) array_shift($args);
    $relativepath = '/'.implode('/', $args);
    $fullpath = '/'.$context->id.'/mod_progassessment/'.$filearea.'/'.$fileid.$relativepath;
    
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, true);
}

function progassessment_get_user_submissions($progassessment, $userid=0) {
    global $DB, $USER;

    if (! $userid) {
        $userid = $USER->id;
    }

    return $DB->get_records('progassessment_submissions', array('progassessment' => $progassessment->id, 'userid' => $userid), "timecreated DESC");
}

function progassessment_get_current_submission($progassessment, $userid=0) {
    global $DB, $USER;

    if (! $userid) {
        $userid = $USER->id;
    }

    $submissions = $DB->get_records('progassessment_submissions', array('progassessment' => $progassessment->id, 'userid' => $userid), "timecreated DESC");

    //if there are no submissions
    if (! sizeof($submissions)) {
        return false;
    }

    //last submission
    if ($progassessment->gradingmethod == PROGASSESSMENT_LAST_SUBMISSION) {
        $keys = array_keys($submissions);
        return $submissions[$keys[0]];
    }
    //best submission
    else {
        $bestgrade = -1;
        $bestsubmission = false;
        foreach($submissions as $k => $v) {
            if ($v->grade > $bestgrade) {
                $bestgrade = $v->grade;
                $bestsubmission = $v;
            }
        }
        return $bestsubmission;
    }

}

function progassessment_get_file_language($filename, $languages) {

    $extension = substr($filename, strrpos($filename, '.') + 1);

    foreach ($languages as $lang) {
        $info = explode(',', $lang);

        if ($info[2] == $extension) {
            return $info[0];
        }
    }

    return false;
}

function progassessment_is_valid_language($progassessment, $languages, $langid) {

    if (! $langid) {
        return false;
    }

    foreach ($languages as $lang) {
        $info = explode(',', $lang);

        if ($info[0] == $langid) {
            $progassessment_languages = explode(',', $progassessment->proglanguages);
            return in_array($info[1], $progassessment_languages);
        }
    }

    return false;
}

function progassessment_validate_file_language($progassessment, $filename, $client) {
    $languages = array();
    $answ = $client->getAllLanguagesInfo();

    if (is_array($answ->string)) {
       $languages = $answ->string;
    } else {
        array_push($languages, $answ->string);
    }

    //infer the programming language
    $language = progassessment_get_file_language($filename, $languages);

    if (progassessment_is_valid_language($progassessment, $languages, $language)) {
        return $language;
    }

    return false;
}

function progassessment_validate_submission_language($progassessment, $filename, $client, $cm, $returnurl) {
    global $OUTPUT;

    //infer the programming language
    $language = progassessment_validate_file_language($progassessment, $filename, $client);

    if (! $language) {
        progassessment_view_header($progassessment, $cm, get_string('upload'));
        echo $OUTPUT->notification($filename . ' ' . get_string('invalidextension', 'progassessment'));

        $validextensions = "";
        $n = 0;
        $progassessment_languages = explode(',', $progassessment->proglanguages);

        foreach ($languages as $lang) {
            $info = explode(',', $lang);

            //admitted language
            if (in_array($info[1], $progassessment_languages)) {
               $validextensions .= ($n == 0 ? $info[2] . " ($info[1])" : ', ' . $info[2] . " ($info[1])");
                ++$n;
            }
        }

        echo $OUTPUT->notification(get_string('validextensions', 'progassessment') . " $validextensions");
        echo $OUTPUT->continue_button($returnurl);
        progassessment_view_footer();
        die;
    }

    return $language;
}


function progassessment_add_submission_to_server($progassessment, $client, $language, $file, $submissionid, $compilation=false) {
    global $USER, $DB, $CFG;

    //check if the user already exists in the server
    if (! $client->participantExists($USER->username)) {
        $client->addParticipant($USER->username, $USER->username);
    }

    //if the programming assessment has a skeleton file, the submission file and the skeleton have to be merged
    if ($progassessment->skeletonfile) {
        $file_storage = get_file_storage();
        $skeletonfile = $file_storage->get_file_by_id($progassessment->skeletonfile);
        $content = "";

        //create a new file to hold the result of merging the two files
        $resultfile = fopen($CFG->dirroot."/mod/progassessment/temp/$submissionid", "wb");
        progassessment_merge_skeletonfile_studentfile($language, $skeletonfile->get_content_file_handle(), $file->get_content_file_handle(), $resultfile);
        fclose($resultfile);

        $resultfile = fopen($CFG->dirroot."/mod/progassessment/temp/$submissionid", "rb");
        while (!feof($resultfile)) {
            $content .= fread($resultfile, 1024);
        }

        fclose($resultfile);
        unlink($CFG->dirroot."/mod/progassessment/temp/$submissionid");
    } else {
        $content = $file->get_content();
    }

    if ($compilation) {
        
        // Special case for SQL
        if ($progassessment->proglanguages === "SQL") {
            
            $testcases = $DB->get_records('progassessment_testcases', array('progassessment' => $progassessment->id));
            $testcases_ids = array_keys($testcases);
            
            return $client->addCompileSubmissionSpecial($USER->username, $progassessment->serverid, $testcases_ids, $language, $content);
        }
        else
            return $client->addCompileSubmission($USER->username, $progassessment->serverid, $language, $content);
        
    } else {
        $testcases = $DB->get_records('progassessment_testcases', array('progassessment' => $progassessment->id));
        $testcases_ids = array_keys($testcases);
        $serverids = array();

        //send to the server and receive the serverids for all pairs (testcase, submission)
        $answ = $client->addSubmission($USER->username, $progassessment->serverid, $testcases_ids, $language, $content);

        if (is_array($answ->int)) {
           $serverids = $answ->int;
        } else {
            array_push($serverids, $answ->int);
        }

        $i = 0;

        //for each pair (testcase, submission) insert a record in the database
        foreach ($serverids as $serverid) {
            if ($serverid <= 0) {
                ++$i;
                continue;
            }

            $testcase = $DB->get_record('progassessment_testcases', array('id' => $testcases_ids[$i]));
            $submission_testcase = new Object();
            $submission_testcase->submission = $submissionid;
            $submission_testcase->testcase = $testcase->id;
            $submission_testcase->result = NULL;
            $submission_testcase->serverid = $serverid;
            $DB->insert_record('progassessment_submissions_testcases', $submission_testcase);

            ++$i;
        }
    }
}

function progassessment_upload_submission_file($progassessment, $cm, $course) {
    global $CFG, $USER, $COURSE, $DB, $OUTPUT;

    $returnurl = 'view.php?id='.$cm->id;
    $context = get_context_instance(CONTEXT_MODULE, $cm->id);

    $mform = new mod_progassessment_submit_file_form('upload.php', $progassessment);
    $data = $mform->get_data();

    if ($data && progassessment_can_submit($progassessment, $context)) {
        $fs = get_file_storage();
        $filename = $mform->get_new_filename('newfile');
        $client = progassessment_get_client();

        if ($filename) {

            //validate the submited language
            $language = progassessment_validate_submission_language($progassessment, $filename, $client, $cm, $returnurl);

            //get the number of submissions already made by this user
            $submissions = $DB->get_records('progassessment_submissions', array('userid' => $USER->id, 'progassessment' => $progassessment->id));
            $nsubmissions = count($submissions);

            if ($fs->file_exists($context->id, 'mod_progassessment', "progassessment_submission", $USER->id, "/$progassessment->id/$nsubmissions/", $filename)) {
                $file = $fs->get_file($context->id, 'mod_progassessment', "progassessment_submission", $USER->id, "/$progassessment->id/$nsubmissions/", $filename);
                $file->delete();
            }
            
            $file = $mform->save_stored_file('newfile', $context->id, 'mod_progassessment', "progassessment_submission", $USER->id, "/$progassessment->id/$nsubmissions/", $filename);

            if ($file) {

                //prepare the submission object
                $submission = new Object();
                $submission->progassessment = $progassessment->id;
                $submission->userid = $USER->id;
                $submission->timecreated = time();
                $submission->file = $file->get_id();
                $submissionid = $DB->insert_record('progassessment_submissions', $submission);

                if ($submissionid) {
                    
                    // Let Moodle know that assessable files were uploaded (eg for plagiarism detection)
                    $eventdata = new stdClass();
                    $eventdata->modulename   = 'progassessment';
                    $eventdata->cmid         = $cm->id;
                    $eventdata->itemid       = $file->get_itemid();
                    $eventdata->courseid     = $COURSE->id;
                    $eventdata->userid       = $USER->id;
                    $eventdata->file         = $file;
                    
                    events_trigger('assessable_file_uploaded', $eventdata);
                    
                    // Trigger assessable_files_done event to show files are complete
                    $eventdata = new stdClass();
                    $eventdata->modulename   = 'progassessment';
                    $eventdata->cmid         = $cm->id;
                    $eventdata->itemid       = $file->get_itemid();
                    $eventdata->courseid     = $COURSE->id;
                    $eventdata->userid       = $USER->id;
                    events_trigger('assessable_files_done', $eventdata);
                    
                    
                    progassessment_add_submission_to_server($progassessment, $client, $language, $file, $submissionid);
                    redirect($returnurl);
                } else {
                    $file->delete();
                }
            }
        }

        progassessment_view_header($progassessment, $cm, get_string('upload'));
        echo $OUTPUT->notification(get_string('uploaderror', 'progassessment'));
        echo $OUTPUT->continue_button($returnurl);
        progassessment_view_footer();
        die;
    }
}

function progassessment_upload_compilation_file($progassessment, $cm, $course) {
    global $CFG, $USER, $DB, $OUTPUT;

    $returnurl = 'view.php?id='.$cm->id;
    $context = get_context_instance(CONTEXT_MODULE, $cm->id);

    $mform = new mod_progassessment_compile_file_form('compile.php', $progassessment);
    $data = $mform->get_data();

    if ($data) {
        $fs = get_file_storage();
        $filename = $mform->get_new_filename('newfile');
        $client = progassessment_get_client();

        if ($filename) {

            //validate the submited language
            $language = progassessment_validate_submission_language($progassessment, $filename, $client, $cm, $returnurl);

            if ($fs->file_exists($context->id, 'mod_progassessment', "progassessment_compilation", $USER->id, "/$progassessment->id/", $filename)) {
                $file = $fs->get_file($context->id, 'mod_progassessment', "progassessment_compilation", $USER->id, "/$progassessment->id/", $filename);
                $file->delete();
            }

            $file = $mform->save_stored_file('newfile', $context->id, 'mod_progassessment', "progassessment_compilation", $USER->id, "/$progassessment->id/", $filename);

            if ($file) {

                //prepare the object to the database
                $compilation_result = new Object();
                $compilation_result->progassessment = $progassessment->id;
                $compilation_result->userid = $USER->id;
                $compilation_result->timecreated = time();
                $compilation_result->file = $file->get_id();
                $compilation_result_id = $DB->insert_record('progassessment_compilation_results', $compilation_result);

                if ($compilation_result_id) {
                    $serverid = progassessment_add_submission_to_server($progassessment, $client, $language, $file, $compilation_result_id, true);

                    if ($serverid > 0) {
                        $DB->set_field('progassessment_compilation_results', 'serverid', $serverid, array("id" => $compilation_result_id));
                    }
                    
                    redirect($returnurl);
                } else {
                    $file->delete();
                }
            }
        }

        progassessment_view_header($progassessment, $cm, get_string('upload'));
        echo $OUTPUT->notification(get_string('uploaderror', 'progassessment'));
        echo $OUTPUT->continue_button($returnurl);
        progassessment_view_footer();
        die;
    }
}

//in fact, the feedback has already been generated. This function only sets
//submissions' attribute 'isgrade' to 1 if all the test cases have already
//been processed by the server.
function progassessment_generate_feedback($progassessment, $cm, $course) {
    global $DB;

    $returnurl = 'view.php?id='.$cm->id;
    $context = get_context_instance(CONTEXT_MODULE, $cm->id);

    require_capability('mod/progassessment:grade', $context);

    $submissions = $DB->get_records('progassessment_submissions', array('progassessment' => $progassessment->id, 'isgraded' => 0));

    foreach ($submissions as $k => $submission) {
        $nonprocessed_submissions_testcases = $DB->get_records('progassessment_submissions_testcases', array('submission' => $submission->id, 'result' => NULL));

        if (! count($nonprocessed_submissions_testcases)) {
            $DB->set_field('progassessment_submissions', "isgraded", 1, array("id" => $submission->id));
        }
    }

    redirect($returnurl);
}

function progassessment_select_submission($progassessment, $cm, $course) {
    global $DB;

    $returnurl = 'view.php?id='.$cm->id;
    $context = get_context_instance(CONTEXT_MODULE, $cm->id);

    require_capability('mod/progassessment:view', $context);

    $mform = new mod_progassessment_select_submission_form('select_submission.php', $progassessment);
    $data = $mform->get_data();

    if ($data) {
        $returnurl .= "&sub=$data->submission";
    }

    redirect($returnurl);
}

function progassessment_view_header($progassessment, $cm, $subpage="") {
    global $CFG, $PAGE, $OUTPUT, $DB;

    $course = $DB->get_record('course', array('id' => $progassessment->course));

    $strprogassessment  = get_string('modulename', 'progassessment');
    $pagetitle = strip_tags($course->shortname.': '.$strprogassessment.': '.format_string($progassessment->name,true));

    $PAGE->set_title($pagetitle);
    $PAGE->set_heading($course->fullname);

    if ($subpage) {
        $PAGE->navbar->add($subpage);
    }

    echo $OUTPUT->header();
    groups_print_activity_menu($cm, $CFG->wwwroot . '/mod/progassessment/view.php?id=' . $cm->id);

    echo '<div class="reportlink">'.submittedlink($cm).'</div>';
    echo '<div class="clearer"></div>';
}

function progassessment_view_footer() {
    global $OUTPUT;
    echo $OUTPUT->footer();
}

class mod_progassessment_submit_file_form extends moodleform {
    function definition() {
        $mform = $this->_form;
        $instance = $this->_customdata;

        $mform->setMaxFileSize($instance->maxbytes);

        // visible elements
        $mform->addElement('filepicker', 'newfile', get_string('uploadafile'));

        // hidden params
        $mform->addElement('hidden', 'a', $instance->id);
        $mform->setType('a', PARAM_INT);
        $mform->addElement('hidden', 'action', 'uploadfile');
        $mform->setType('action', PARAM_ALPHA);

        $this->add_action_buttons(false, get_string('submitthisfile', 'progassessment'));
    }
}

class mod_progassessment_compile_file_form extends moodleform {
    function definition() {
        $mform = $this->_form;
        $instance = $this->_customdata;

        $mform->setMaxFileSize($instance->maxbytes);

        // visible elements
        $mform->addElement('filepicker', 'newfile', get_string('compileafile', 'progassessment'));

        // hidden params
        $mform->addElement('hidden', 'a', $instance->id);
        $mform->setType('a', PARAM_INT);
        $mform->addElement('hidden', 'action', 'compilefile');
        $mform->setType('action', PARAM_ALPHA);

        $this->add_action_buttons(false, get_string('compilethisfile', 'progassessment'));
    }
}

class mod_progassessment_generate_feedback_form extends moodleform {
    function definition() {
        $mform = $this->_form;
        $instance = $this->_customdata;
        
        $mform->addElement('hidden', 'a', $instance->id);
        $mform->setType('a', PARAM_INT);
        $mform->addElement('hidden', 'action', 'generatefeedback');
        $mform->setType('action', PARAM_ALPHA);

        // buttons
        $this->add_action_buttons(false, get_string('generatefeedback', 'progassessment'));
    }
}


class mod_progassessment_select_submission_form extends moodleform {
    function definition() {
        $mform = $this->_form;
        $instance = $this->_customdata;

        $submissions = progassessment_get_user_submissions($instance);
        $current_submission = progassessment_get_current_submission($instance);
        $submission_choices = array();
        $fs = get_file_storage();

        foreach ($submissions as $k => $v) {
            $file = $fs->get_file_by_id($v->file);
            $name = $file->get_filename();
            $time = date("H:i:s d-m-Y", $v->timecreated);
            $submission_choices[$k] = "$name - $time";

            if ($k == $current_submission->id) {
               $submission_choices[$k] .= " *";
            }
        }

        $mform->addElement('select', 'submission', get_string('selectsubmission', 'progassessment'), $submission_choices);

        if (isset($instance->submission_to_show)) {
            $mform->setDefault('submission', $instance->submission_to_show);
        }

        // hidden params
        $mform->addElement('hidden', 'a', $instance->id);
        $mform->setType('a', PARAM_INT);
        $mform->addElement('hidden', 'action', 'selectsubmission');
        $mform->setType('action', PARAM_ALPHA);

        $this->add_action_buttons(false, get_string('viewthissubmission', 'progassessment'));
    }
}

?>
