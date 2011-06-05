<?php //$Id: mod_form.php,v 1.2.2.3 2009/03/19 12:23:11 mudrd8mz Exp $

/**
 * This file defines the main progassessment configuration form
 * It uses the standard core Moodle (>1.8) formslib. For
 * more info about them, please visit:
 *
 * http://docs.moodle.org/en/Development:lib/formslib.php
 *
 * The form must provide support for, at least these fields:
 *   - name: text element of 64cc max
 *
 * Also, it's usual to use these fields:
 *   - intro: one htmlarea element to describe the activity
 *            (will be showed in the list of activities of
 *             progassessment type (index.php) and in the header
 *             of the progassessment main page (view.php).
 *   - introformat: The format used to write the contents
 *             of the intro field. It automatically defaults
 *             to HTML when the htmleditor is used and can be
 *             manually selected if the htmleditor is not used
 *             (standard formats are: MOODLE, HTML, PLAIN, MARKDOWN)
 *             See lib/weblib.php Constants and the format_text()
 *             function for more info
 */

require_once($CFG->dirroot.'/course/moodleform_mod.php');
require_once($CFG->dirroot.'/mod/progassessment/languages_config.php');

class mod_progassessment_mod_form extends moodleform_mod {

    function definition() {
        global $CFG, $COURSE, $DB, $progassessment_languages,
               $progassessment_feedback_levels,
               $progassessment_max_submission_choices,
               $progassessment_max_grade_choices;

        $mform =& $this->_form;
        $instance = false;

        if (!empty($this->_instance)) {
            $instance = $DB->get_record('progassessment', array('id' => $this->_instance));
        }

        $progassessment_feedback_levels = array('minimalist', 'moderated', 'detailed');

        $progassessment_max_grade_choices = array();
        array_push($progassessment_max_grade_choices, get_string('nograde', 'progassessment'));
        for($i = 1; $i <= 100; $i++) {
            array_push($progassessment_max_grade_choices, $i);
        }

        $progassessment_gradingmethod_choices = array(get_string('lastsubmission', 'progassessment'), get_string('bestsubmission', 'progassessment'));

        $progassessment_max_submission_choices = array();
        array_push($progassessment_max_submission_choices, get_string('unlimited', 'progassessment'));
        for($i = 1; $i <= 10; $i++) {
            $progassessment_max_submission_choices[$i] = $i;
        }

        $progassessment_tolerancepenalty_choices = array();
        for($i = 0; $i <= 100; $i++) {
            array_push($progassessment_tolerancepenalty_choices, "$i %");
        }

        $filepickeroptions = array();
        $filepickeroptions['filetypes'] = '*';
        $filepickeroptions['maxbytes'] = $COURSE->maxbytes;

        $file_storage = get_file_storage();

        $progassessment_languages = progassessment_get_available_languages();
        
        $progassessment_metrics = progassessment_get_available_metrics();
        $progassessment_keys = progassessment_get_metric_keys();
        
//-------------------------------------------------------------------------------
        //Adding the "general" fieldset, where all the common settings are showed
        $mform->addElement('header', 'general', get_string('general', 'form'));

        //Standard "name" field
        $mform->addElement('text', 'name', get_string('name', 'progassessment'), array('size'=>'65'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        //Description of the assessment
        $this->add_intro_editor(true, get_string('description', 'progassessment'));

        //optional file with the description
        $mform->addElement('filepicker', 'descriptionfile', get_string('descriptionfile', 'progassessment'), null, $filepickeroptions);

        if ($instance && $instance->introfile) {
            $file = $file_storage->get_file_by_id($instance->introfile);
            $mform->setDefault('descriptionfile', $file->get_itemid());
        }

        $mform->addHelpButton('descriptionfile', 'descriptionfile', 'progassessment');

        //Start date
        $mform->addElement('date_time_selector', 'timeavailable', get_string('availabledate', 'progassessment'), array('optional'=>true));
        $mform->setDefault('timeavailable', time());
        
        //Due date
        $mform->addElement('date_time_selector', 'timedue', get_string('duedate', 'progassessment'), array('optional'=>true));
        $mform->setDefault('timedue', time()+7*24*3600);

        //Duration
        $mform->addElement('duration', 'duration', get_string('duration', 'progassessment'), array('optional' => true));
        $mform->setDefault('duration', 0);

        //Tolerance date
        $mform->addElement('date_time_selector', 'timetolerance', get_string('tolerancedate', 'progassessment'), array('optional'=>true));
        $mform->setDefault('timetolerance', 0);
        $mform->setAdvanced('timetolerance');

        //Penalty for late submissions
        $mform->addElement('select', 'tolerancepenalty', get_string('penaltylatesubmissions', 'progassessment'), $progassessment_tolerancepenalty_choices);
        $mform->setDefault('tolerancepenalty', $CFG->progassessment_tolerancepenalty);
        $mform->setAdvanced('tolerancepenalty');
        $mform->addHelpButton('tolerancepenalty', 'penaltylatesubmissions', 'progassessment');

//-------------------------------------------------------------------------------
        //Grading
        $mform->addElement('header', 'gradingheader', get_string('grading', 'progassessment'));

        //Maximum grade
        $mform->addElement('select', 'maxgrade', get_string('maxgrade', 'progassessment'), $progassessment_max_grade_choices);
        $mform->setDefault('maxgrade', $CFG->progassessment_maxgrade);

        //Grading method
        $mform->addElement('select', 'gradingmethod', get_string('gradingmethod', 'progassessment'), $progassessment_gradingmethod_choices);
        $mform->setDefault('gradingmethod', $CFG->progassessment_gradingmethod);
        $mform->addHelpButton('gradingmethod', 'gradingmethod', 'progassessment');

//-------------------------------------------------------------------------------
        //Options for the upload of files
        $mform->addElement('header', 'uploadheader', get_string('uploadfiles', 'progassessment'));

        //file size
        $choices = get_max_upload_sizes($CFG->maxbytes, $COURSE->maxbytes);
        $choices[0] = get_string('courseuploadlimit') . ' ('.display_size($COURSE->maxbytes).')';
        $mform->addElement('select', 'maxbytes', get_string('maxsize', 'progassessment'), $choices);
        $mform->setDefault('maxbytes', $CFG->progassessment_maxbytes);

        //maximum number of submissions
        $mform->addElement('select', 'maxsubmissions', get_string('maxsubmissions', 'progassessment'), $progassessment_max_submission_choices);
        $mform->setDefault('maxsubmissions', $CFG->progassessment_maxsubmissions);

//-------------------------------------------------------------------------------
        //Programming languages
        $mform->addElement('html',
            '<script>
                function progLangChanged() {
                    var select = document.getElementById("id_proglanguage");
                    var lang = select.options[select.selectedIndex].text;
                    
                    if (lang == "C++")
                        lang = "CPP";
                    else if (lang == "C#")
                        lang = "CS";
                    
                    hideAllStatic();
                    showStatic(lang);
                }
                
                function showStatic(lang) {
                    document.getElementById("saheader" + lang).style.display = \'block\';
                }
                
                function hideAllStatic() {
                    document.getElementById("saheaderC").style.display = \'none\';
                    document.getElementById("saheaderCPP").style.display = \'none\';
                    document.getElementById("saheaderCS").style.display = \'none\';
                    document.getElementById("saheaderSQL").style.display = \'none\';
                    document.getElementById("saheaderScheme").style.display = \'none\';
                    document.getElementById("saheaderPython").style.display = \'none\';
                    document.getElementById("saheaderJava").style.display = \'none\';
                }
            </script>'
        );
        
        $mform->addElement('header', 'proglanguagesheader', get_string('proglanguages', 'progassessment'));
        
        $select_list = "";
        
        for ($i = 0; $i != count($progassessment_languages); $i++)
            if ($instance && ($progassessment_languages[$i] == $instance->proglanguages))
                $select_list .= '<option value="'.$i.'" selected="selected">'.$progassessment_languages[$i].'</option>';
            else
                $select_list .= '<option value="'.$i.'">'.$progassessment_languages[$i].'</option>';
        
        $mform->addElement('html', '
        	<div class="fitem"><div class="fitemtitle"><label for="id_proglanguage">'.get_string('proglanguage', 'progassessment').' </label></div>
        	<div class="felement fselect">
        	<select name="proglanguage" id="id_proglanguage" onChange="progLangChanged()">' 
        	    .$select_list.
            '</select></div></div>');

        if (sizeof($progassessment_languages) == 0) {
            $mform->addElement('static', get_string('noproglanguages', 'progassessment'), get_string('noproglanguagesdesc', 'progassessment'));
        }

//-------------------------------------------------------------------------------
        //Skeleton code
        $mform->addElement('header', 'proglanguagesheader', get_string('skeletoncode', 'progassessment'));

        $mform->addElement('filepicker', "skeleton", get_string('skeletonfile', 'progassessment'), null, $filepickeroptions);

        if ($instance && $instance->skeletonfile) {
            $skeleton = $file_storage->get_file_by_id($instance->skeletonfile);
            $mform->setDefault("skeleton", $skeleton->get_itemid());
        }

        $mform->addHelpButton('skeleton', 'skeletonfile', 'progassessment');

//-------------------------------------------------------------------------------
        //Feedback options
        $mform->addElement('header', 'feedbackheader', get_string('feedback', 'progassessment'));

        $ynoptions = array( 0 => get_string('no'), 1 => get_string('yes'));

        //toggle immediate/non-immediate feedback
        $mform->addElement('select', 'immediatefeedback', get_string('immediatefeedback', 'progassessment'), $ynoptions);
        $mform->setDefault('immediatefeedback', true);
        
        $choices = array();

        foreach ($progassessment_feedback_levels as $level) {
            array_push($choices, get_string($level, 'progassessment'));
        }

        //regulation of the level of feedback
        $mform->addElement('select', 'feedbackdetail', get_string('feedbackdetail', 'progassessment'), $choices);
        $mform->setDefault('feedbackdetail', '1');


//-------------------------------------------------------------------------------
        //Test Cases
        $mform->addElement('header', 'testcasesheader', get_string('testcases', 'progassessment'));

        $testfiles = array();

        if ($instance) {
            $testfiles = $DB->get_records('progassessment_testfiles', array('progassessment' => $instance->id), "id ASC");
        }

        $num_testfiles = max(1, count($testfiles));
        $this->repeat_testcases($num_testfiles, $testfiles, $filepickeroptions);
    
//-------------------------------------------------------------------------------
        //Static analysis 

         $mform->addElement('html',
                '<script type="text/javascript">
                    function visToggle(f) {       
                        if (f.checked) {
                            document.getElementById(f.id + "_min").style.visibility=\'visible\';
                            document.getElementById(f.id + "_max").style.visibility=\'visible\';
                            document.getElementById(f.id + "_weight").style.visibility=\'visible\';
                            document.getElementById(f.id + "_min_label").style.visibility=\'visible\';
                            document.getElementById(f.id + "_max_label").style.visibility=\'visible\';
                            document.getElementById(f.id + "_weight_label").style.visibility=\'visible\';
                        } else {
                            document.getElementById(f.id + "_min").style.visibility=\'hidden\';
                            document.getElementById(f.id + "_max").style.visibility=\'hidden\';
                            document.getElementById(f.id + "_weight").style.visibility=\'hidden\';
                            document.getElementById(f.id + "_min_label").style.visibility=\'hidden\';
                            document.getElementById(f.id + "_max_label").style.visibility=\'hidden\';
                            document.getElementById(f.id + "_weight_label").style.visibility=\'hidden\';
                        }
                    }
                    
                    function saToggled(lang) {
                        var select = document.getElementById("id_staticanalysistoggle" + lang);
                        var idx = select.selectedIndex;

                        if (idx == 0)
                            document.getElementById("divsa" + lang).style.display = \'none\';
                        else
                            document.getElementById("divsa" + lang).style.display = \'block\';
                    }
                </script>');
        
        $width = 80;

        foreach ($progassessment_metrics as $lang => $metrics) {
        
            if ($lang === "C++")
                $lang = "CPP";
            else if ($lang === "C#")
                $lang = "CS";
        
            $mform->addElement('header', 'saheader'.$lang, get_string('titlesa', 'progassessment'));
                
            if (count($metrics) > 0) {
                
                //toggle static analysis
                
                $select_list = "";

                for ($i = 0; $i != count($ynoptions); $i++) {
                    $select_list .= '<option value="'.$i.'" '.
                    ($instance && ($instance->saenabled == $i) ? 'selected="selected"' : "").
                    '>'.$ynoptions[$i].'</option>';
                }
                
                $mform->addElement('html',
                    '<div class="fitem"><div class="fitemtitle"><label for="id_staticanalysistoggle'.$lang.'">'.get_string('enablesa', 'progassessment').'</label></div>
                    <div class="felement fselect"><select onChange="saToggled(\''.$lang.'\')" name="staticanalysistoggle'.$lang.'" id="id_staticanalysistoggle'.$lang.'">'
                    	.$select_list.
                    '</select></div></div>');
                
                $mform->addElement('select', 'maxsa'.$lang, get_string('valuesa', 'progassessment'), $progassessment_max_grade_choices);
                $mform->setDefault('maxsa'.$lang, ($instance ? $instance->sagrade : $CFG->progassessment_maxstatic));
                $mform->disabledIf('maxsa'.$lang, 'staticanalysistoggle'.$lang, 'eq', 0);
                $mform->addHelpButton('maxsa'.$lang, 'valuesa', 'progassessment');
        
                $mform->addElement('html', '<div id="divsa'.$lang.'">');
        
                foreach ($metrics as $key => $group) {
                    $mform->addElement('html', '<br /><h5>'.$progassessment_keys[$key].'</h5><br />');
                    
                    $style_hide = ($key === "style" ? "display:none; " : "");
					$val = ($key === "style" ? '1' : '0');
            
                    foreach ($group as $id => $metric) {
                        $mform->addElement('html',
                            '<div class="fitem"><div class="fitemtitle"><label for="'.$id.$lang.'">'.$metric.'</label></div><div class="felement fcheckbox"><span>
                                <input type="checkbox" value="1" onClick="visToggle(this.form.'.$id.$lang.')"
									id="'.$id.$lang.'" name="'.$id.$lang.'" />
                                
                                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                
                                <label style="'.$style_hide.'visibility:hidden" id="'.$id.$lang.'_min_label">'.get_string('min', 'progassessment').'
                                    <input required style="width:'.$width.'px; visibility:hidden" type="number" value="'.$val.'" min="0"
                                        id="'.$id.$lang.'_min" name="'.$id.$lang.'_min" />
                                </label>
                                <label style="'.$style_hide.'visibility:hidden" id="'.$id.$lang.'_max_label">'.get_string('max', 'progassessment').'
                                    <input required style="width:'.$width.'px; visibility:hidden" type="number" value="'.$val.'" min="0"
                                        id="'.$id.$lang.'_max" name="'.$id.$lang.'_max" />
                                </label>
                                <label style="visibility:hidden" id="'.$id.$lang.'_weight_label">'.get_string('weight', 'progassessment').'
                                    <input required style="width:'.$width.'px; visibility:hidden" type="number" value="1" min="0" max="100" step="1"
                                        id="'.$id.$lang.'_weight" name="'.$id.$lang.'_weight" />
                                </label>
                            </span></div></div>');
							
						$mform->addElement('html', '<script>visToggle(document.getElementById("'.$id.$lang.'"));</script>');
                            
                        $mform->disabledIf($id.$lang,           'staticanalysistoggle'.$lang, 'eq', 0);
                        $mform->disabledIf($id.$lang.'_min',    'staticanalysistoggle'.$lang, 'eq', 0);
                        $mform->disabledIf($id.$lang.'_max',    'staticanalysistoggle'.$lang, 'eq', 0);
                        $mform->disabledIf($id.$lang.'_weight', 'staticanalysistoggle'.$lang, 'eq', 0);
                    }
                }
                
                $mform->addElement('html', '</div><script>saToggled(\''.$lang.'\');</script>');
            }
            else {
                $mform->addElement('static', get_string('nometrics', 'progassessment'), get_string('nometricsdesc', 'progassessment'));
            }
        }
        
        if ($instance)
            $static_str = 'showStatic("'.$instance->proglanguages.'");';
        else {
            $akeys = array_keys($progassessment_metrics);
            $static_str = 'showStatic("'.$akeys[0].'")';
        }
            
        $mform->addElement('html', '<script>hideAllStatic(); '.$static_str.'</script>');
        
//-------------------------------------------------------------------------------
        // Plagiarism block
        $course_context = get_context_instance(CONTEXT_COURSE, $COURSE->id);
        plagiarism_get_form_elements_module($mform, $course_context);
        
        
//-------------------------------------------------------------------------------
        // add standard elements, common to all modules
        $this->standard_coursemodule_elements();
//-------------------------------------------------------------------------------
        // add standard buttons, common to all modules
        $this->add_action_buttons();
    }
	
	function get_raw_data() {
		return $this->_form->_submitValues;
	}
	
    function repeat_testcases($repeats, $testfiles, $filepickeroptions) {
        $addstring = get_string('addtestcasefile', 'progassessment');
        $repeathiddenname = 'testfiles_repeats';
        $addfieldsname = 'testfiles_add_fields';
        $addfieldsno = 1;
        $repeats = optional_param($repeathiddenname, $repeats, PARAM_INT);
        $addfields = optional_param($addfieldsname, '', PARAM_TEXT);
        $keys = array_keys($testfiles);
        $file_storage = get_file_storage();

        if (!empty($addfields)){
            $repeats += $addfieldsno;
        }

        $mform =& $this->_form;
        $mform->registerNoSubmitButton($addfieldsname);
        $mform->addElement('hidden', $repeathiddenname, $repeats);
        $mform->setType($repeathiddenname, PARAM_INT);

        $mform->setConstants(array($repeathiddenname=>$repeats));

        for ($i = 0; $i < $repeats; $i++) {
            $ix = $i + 1;
            $mform->addElement('static', "testcase_$i", '<b>' . get_string('testfile', 'progassessment') . " $ix</b>");
            $mform->addElement('filepicker', "inputfile_$i", get_string('inputfile', 'progassessment'), null, $filepickeroptions);
            $mform->addElement('filepicker', "outputfile_$i", get_string('outputfile', 'progassessment'), null, $filepickeroptions);

            //set default values
            if ($i < sizeof($testfiles) && isset($testfiles[$keys[$i]])) {
                $input = $file_storage->get_file_by_id($testfiles[$keys[$i]]->inputfile);
                $output = $file_storage->get_file_by_id($testfiles[$keys[$i]]->outputfile);
                $mform->setDefault("inputfile_$i", $input->get_itemid());
                $mform->setDefault("outputfile_$i", $output->get_itemid());
            }
        }

        $mform->addElement('submit', $addfieldsname, $addstring);

        return $repeats;
    }
}

?>
