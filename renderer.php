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
 * A custom renderer class that extends the plugin_renderer_base and
 * is used by the assignment module.
 *
 * @package mod-progassessment
 * @copyright 2010 Dongsheng Cai <dongsheng@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/
class mod_progassessment_renderer extends plugin_renderer_base {
    /**
     * @return string
     */
    public function progassessment_files($context, $filepath, $itemid, $filearea='progassessment_submission') {
        return $this->render(new progassessment_files($context, $filepath, $itemid, $filearea));
    }

    public function render_progassessment_files(progassessment_files $tree) {
        $module = array('name'=>'mod_progassessment_files', 'fullpath'=>'/mod/progassessment/progassessment.js', 'requires'=>array('yui2-treeview'));
        $this->htmlid = 'progassessment_files_tree_'.uniqid();
        $this->page->requires->js_init_call('M.mod_progassessment.init_tree', array(true, $this->htmlid));
        $html = '<div id="'.$this->htmlid.'">';
        $html .= $this->htmllize_tree($tree, $tree->dir);
        $html .= '</div>';

        if ($tree->portfolioform) {
            $html .= $tree->portfolioform;
        }
        return $html;
    }

    /**
     * Internal function - creates htmls structure suitable for YUI tree.
     */
    protected function htmllize_tree($tree, $dir) {
        global $CFG;
        $yuiconfig = array();
        $yuiconfig['type'] = 'html';

        if (empty($dir['subdirs']) and empty($dir['files'])) {
            return '';
        }

        $result = '<ul>';
        foreach ($dir['subdirs'] as $subdir) {
            $image = $this->output->pix_icon("f/folder", $subdir['dirname'], 'moodle', array('class'=>'icon'));
            $result .= '<li yuiConfig=\''.json_encode($yuiconfig).'\'><div>'.$image.' '.s($subdir['dirname']).'</div> '.$this->htmllize_tree($tree, $subdir).'</li>';
        }

        foreach ($dir['files'] as $file) {
            $filename = $file->get_filename();
            $icon = mimeinfo("icon", $filename);
            $plagiarsmlinks = plagiarism_get_links(array('userid'=>$file->get_userid(), 'file'=>$file, 'cmid'=>$tree->cm->id, 'course'=>$tree->course));
            $image = $this->output->pix_icon("f/$icon", $filename, 'moodle', array('class'=>'icon'));
            $result .= '<li yuiConfig=\''.json_encode($yuiconfig).'\'><div>'.$image.' '.$file->fileurl.' '.$plagiarsmlinks.$file->portfoliobutton.'</div></li>';
        }
        
        $result .= '</ul>';

        return $result;
    }
}

class progassessment_files implements renderable {
    public $context;
    public $dir;
    public $portfolioform;
    public $cm;
    public $course;
    public function __construct($context, $filepath, $itemid, $filearea='progassessment_submission') {
        global $USER, $CFG;
        require_once($CFG->libdir . '/portfoliolib.php');
        $this->context = $context;
        list($context, $course, $cm) = get_context_info_array($context->id);
        $this->cm = $cm;
        $this->course = $course;
        $fs = get_file_storage();
        $this->dir = $fs->get_area_tree($this->context->id, 'mod_progassessment', $filearea, $itemid);
        $files = $fs->get_area_files($this->context->id, 'mod_progassessment', $filearea, $itemid, "timemodified", false);

        $structure = split("/", $filepath, 4);
        $this->dir = $this->dir['subdirs'][$structure[1]]; // progassessment folder
        //$this->dir = $this->dir['subdirs'][$structure[2]]; // submission folder

        if (count($files) >= 1 && has_capability('mod/progassessment:exportownsubmission', $this->context)) {
            $button = new portfolio_add_button();
            $button->set_callback_options('progassessment_portfolio_caller', array('id' => $this->cm->id), '/mod/progassessment/locallib.php');
            $button->reset_formats();
            $this->portfolioform = $button->to_html();
        }
        $this->preprocess($this->dir, $filearea);
    }
    public function preprocess($dir, $filearea) {
        global $CFG;
    
        foreach ($dir['subdirs'] as $subdir) {
            $this->preprocess($subdir, $filearea);
        }
        foreach ($dir['files'] as $file) {
            $button = new portfolio_add_button();
            if (has_capability('mod/progassessment:exportownsubmission', $this->context)) {
                $button->set_callback_options('progassessment_portfolio_caller', array('id' => $this->cm->id, 'fileid' => $file->get_id()), '/mod/progassessment/locallib.php');
                $button->set_format_by_file($file);
                $file->portfoliobutton = $button->to_html(PORTFOLIO_ADD_ICON_LINK);
            } else {
                $file->portfoliobutton = '';
            }
            $url = file_encode_url("$CFG->wwwroot/pluginfile.php", '/'.$this->context->id.'/mod_progassessment/'.$filearea.'/'.$file->get_itemid(). $file->get_filepath().$file->get_filename(), true);
            $filename = $file->get_filename();
            $file->fileurl = html_writer::link($url, $filename);
        }
    }
}
