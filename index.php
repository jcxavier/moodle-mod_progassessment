<?php // $Id: index.php,v 1.7.2.3 2009/08/31 22:00:00 mudrd8mz Exp $

/**
 * This page lists all the instances of progassessment in a particular course
 *
 * @author  Pedro Pacheco <pedro.a.x.pacheco@gmail.com>
 * @version $Id: index.php,v 1.7.2.3 2009/08/31 22:00:00 mudrd8mz Exp $
 * @package mod/progassessment
 */

require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once(dirname(__FILE__).'/lib.php');

$id = required_param('id', PARAM_INT);   // course

if (! $course = get_record('course', 'id', $id)) {
    error('Course ID is incorrect');
}

require_course_login($course);

add_to_log($course->id, 'progassessment', 'view all', "index.php?id=$course->id", '');


/// Get all required stringsprogassessment

$strprogassessments = get_string('modulenameplural', 'progassessment');
$strprogassessment  = get_string('modulename', 'progassessment');


/// Print the header

$navlinks = array();
$navlinks[] = array('name' => $strprogassessments, 'link' => '', 'type' => 'activity');
$navigation = build_navigation($navlinks);

print_header_simple($strprogassessments, '', $navigation, '', '', true, '', navmenu($course));

/// Get all the appropriate data

if (! $progassessments = get_all_instances_in_course('progassessment', $course)) {
    notice('There are no instances of progassessment', "../../course/view.php?id=$course->id");
    die;
}

/// Print the list of instances

$timenow  = time();
$strname  = get_string('name');
$strweek  = get_string('week');
$strtopic = get_string('topic');

if ($course->format == 'weeks') {
    $table->head  = array ($strweek, $strname);
    $table->align = array ('center', 'left');
} else if ($course->format == 'topics') {
    $table->head  = array ($strtopic, $strname);
    $table->align = array ('center', 'left', 'left', 'left');
} else {
    $table->head  = array ($strname);
    $table->align = array ('left', 'left', 'left');
}

foreach ($progassessments as $progassessment) {
    if (!$progassessment->visible) {
        //Show dimmed if the mod is hidden
        $link = '<a class="dimmed" href="view.php?id='.$progassessment->coursemodule.'">'.format_string($progassessment->name).'</a>';
    } else {
        //Show normal if the mod is visible
        $link = '<a href="view.php?id='.$progassessment->coursemodule.'">'.format_string($progassessment->name).'</a>';
    }

    if ($course->format == 'weeks' or $course->format == 'topics') {
        $table->data[] = array ($progassessment->section, $link);
    } else {
        $table->data[] = array ($link);
    }
}

print_heading($strprogassessments);
print_table($table);

/// Finish the page

print_footer($course);

?>
