<?php  //$Id: settings.php,v 1.1.2.3 2008/01/24 20:29:36 skodak Exp $

require_once($CFG->dirroot.'/mod/progassessment/lib.php');

//default value for the maximum bytes allowed in a submission
$settings->add(new admin_setting_configselect('progassessment_maxbytes',
                    get_string('maxsize', 'progassessment'),
                    get_string('configmaxbytes', 'progassessment'),
                    1048576,
                    get_max_upload_sizes($CFG->maxbytes)));

//default value for the maximum number of allowed submissions
$maxsubmissionschoices = array();

for($i = 1; $i <= 10; $i++) {
    $maxsubmissionschoices[$i] = $i;
}

array_push($maxsubmissionschoices, get_string('unlimited', 'progassessment'));

$settings->add(new admin_setting_configselect('progassessment_maxsubmissions',
                    get_string('maxsubmissions', 'progassessment'),
                    get_string('configmaxsubmissions', 'progassessment'),
                    3,
                    $maxsubmissionschoices));

$max_grade_choices = array();
array_push($max_grade_choices, get_string('nograde', 'progassessment'));

for($i = 1; $i <= 100; $i++) {
    array_push($max_grade_choices, $i);
}

$settings->add(new admin_setting_configselect('progassessment_maxgrade',
                    get_string('maxgrade', 'progassessment'),
                    get_string('configmaxgrade', 'progassessment'),
                    count($max_grade_choices)-1,
                    $max_grade_choices));

$progassessment_gradingmethod_choices = array(get_string('lastsubmission', 'progassessment'), get_string('bestsubmission', 'progassessment'));

$settings->add(new admin_setting_configselect('progassessment_gradingmethod',
                    get_string('gradingmethod', 'progassessment'),
                    get_string('configgradingmethod', 'progassessment'),
                    0,
                    $progassessment_gradingmethod_choices));

$progassessment_tolerancepenalty_choices = array();
for($i = 0; $i <= 100; $i++) {
    array_push($progassessment_tolerancepenalty_choices, "$i %");
}

$settings->add(new admin_setting_configselect('progassessment_tolerancepenalty',
               get_string('penaltylatesubmissions', 'progassessment'),
               get_string('configtolerancepenalty', 'progassessment'),
               0,
               $progassessment_tolerancepenalty_choices));
?>
