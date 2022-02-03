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
 * Class for displaying the necessary upload form.
 *
 * Provides the basic form elements 'file upload' - restricted to file
 * type .json only.
 *
 * @package    tool_optionalplugins
 * @copyright  2022 Greg Pedder
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/formslib.php");

/**
 * This class is responsible for rendering the import/export form
 */
class importexport_form extends moodleform {

    /**
     * This function defines the elements on the form.
     *
     * @return void
     * @throws coding_exception
     */
    public function definition() {
        global $CFG;

        $mform = $this->_form; // Don't forget the underscore!

        $mform->addElement('html', '<div class="box py-3 generalbox alert alert-primary">' . get_string('configwarningtext_intro',
                'tool_optionalplugins') . '</div>');

        $mform->addElement('html', '<div class="box py-3 generalbox alert alert-warning">' .
            get_string('configwarningtext_codematurity', 'tool_optionalplugins',
                get_string('maturity'.$CFG->updateminmaturity, 'core_admin')) . '</div>');

        $linkurl = new moodle_url('/admin/settings.php', array('section' => 'updatenotifications'));
        $link = html_writer::link($linkurl, get_string('configwarningtext_linktext', 'tool_optionalplugins'));

        $mform->addElement('html', '<div class="box py-3 generalbox alert alert-info">' .
            get_string('configwarningtext_link', 'tool_optionalplugins', $link) . '</div>');

        $reporturl = new moodle_url('/admin/tool/optionalplugins/pluginreport.php');
        $reportlink = html_writer::link($reporturl, get_string('reportname', 'tool_optionalplugins'));

        $mform->addElement('html', '<div class="box py-3 generalbox alert alert-secondary">' .
            get_string('configwarningtext_report', 'tool_optionalplugins', $reportlink) . '</div>');

        $mform->addElement('html', '<h2>' . get_string('exportfiles', 'tool_optionalplugins') . '</h2>');
        $mform->addElement('html', '<p>' . get_string('exportinstructions', 'tool_optionalplugins') . '</p>');
        $url = '<a class ="btn btn-primary" href="' . $CFG->wwwroot
            . '/admin/tool/optionalplugins/controller.php?action=exportoptionalplugins&sesskey'
            . $this->_customdata['sesskey'] . '">'
            . get_string('exportpluginsstring', 'tool_optionalplugins') . '</a>';
        $mform->addElement('html', $url . '<hr />');

        $maxbytes = 8192;
        $mform->addElement('html', '<h2>' . get_string('importfile', 'tool_optionalplugins'). '</h2>');
        $mform->addElement('html', '<p>' . get_string('importinstructions', 'tool_optionalplugins') . '</p>');
        $mform->addElement('filepicker', 'importfile', get_string('file'), null,
            array('maxbytes' => $maxbytes, 'accepted_types' => '.json'));
        $mform->addRule('importfile', get_string('required'), 'required');

        $this->add_action_buttons(true, get_string('action_btn_string', 'tool_optionalplugins'));
    }
}
