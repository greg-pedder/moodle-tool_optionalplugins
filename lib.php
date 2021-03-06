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
 * Functions used by the Optional Plugins feature
 *
 * @package    tool_optionalplugins
 * @copyright  2022 Greg Pedder <greg.pedder@glasgow.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * This function offers up a JSON encoded list of the optional plugins for download.
 *
 * @return false|void
 * @throws coding_exception
 */
function export_optional_plugins() {
    global $CFG;

    $pluginman = core_plugin_manager::instance();
    $pageparams = array('updatesonly' => 0, 'contribonly' => 1);

    admin_externalpage_setup('pluginsoverview', '', $pageparams);

    $plugininfo = $pluginman->get_plugins();
    $contribs = array();

    foreach ($plugininfo as $plugintype => $pluginnames) {
        foreach ($pluginnames as $pluginname => $pluginfo) {
            if (!$pluginfo->is_standard()) {
                $contribs[$plugintype][$pluginname] = $pluginfo;
            }
        }
    }

    $plugininfo = $contribs;

    if (empty($plugininfo)) {
        return false;
    }

    $data = array();

    $counter = 0;
    foreach ($plugininfo as $plugins) {

        if (empty($plugins)) {
            continue;
        }

        foreach ($plugins as $plugin) {
            $data[$plugin->component] = array('pluginname' => $plugin->component, 'displayname' => $plugin->displayname,
                'version' => $plugin->versiondb, 'release' => $plugin->release, 'versionrequires' => $plugin->versionrequires);

            if (isset($plugin->dependencies) && count($plugin->dependencies) > 0) {
                $data[$plugin->component]['dependencies'] = $plugin->dependencies;
            }

            $counter++;
        }
    }

    $columns = array(
        'pluginname' => get_string('pluginname', 'tool_optionalplugins'),
        'displayname' => get_string('displayname', 'core_plugin'),
        'version' => get_string('version', 'core_plugin'),
        'release' => get_string('release', 'core_plugin'),
        'versionrequires' => get_string('requires', 'core_plugin'),
    );

    $filename = 'optionalplugins-moodle-' . $CFG->version;
    return \core\dataformat::download_data($filename, 'json', $columns, $data);
}

/**
 * This function validates a given source file, to see what can be installed.
 *
 * @param string $filecontents
 * @param int $updateminmaturity
 * @param int $cfgversion
 * @param string $cfgrelease
 * @return void
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 */
function validate_source_plugin_list($filecontents, $updateminmaturity, $cfgversion, $cfgrelease) {

    global $SESSION, $DB;

    if (isset($filecontents)) {

        $jsondata = json_decode($filecontents, $assoc = false, $depth = 512, $options = JSON_THROW_ON_ERROR);

        // Set up some initial 'containers' for plugin info...
        $canbeinstalled = array();
        $alreadyinstalled = array();
        $cannotbeinstalled = array();
        // Knowing that not all plugins keep up with Moodle releases, exclude plugins that will render the site unusable.
        $donotinstall = array('format_flexsections');
        $alreadyseen = array();

        // Clear anything that was set previously...
        unset($SESSION->canbeinstalled);
        unset($SESSION->alreadyinstalled);
        unset($SESSION->cannotbeinstalled);

        $pluginman = core_plugin_manager::instance();

        // Needed for checking various aspects of the remote plugin.
        $minmaturity = ((isset($updateminmaturity)) ? $updateminmaturity : MATURITY_STABLE);
        $current_version = $cfgrelease;
        $env_version = normalize_version($current_version);

        $plugindata = array();
        foreach ($jsondata as $tmpplugindata) {

            // We need the key name later on, sadly the Moodle export feature doesn't allow us to name the indexes :-(.
            foreach ($tmpplugindata as $tmpsourceplugin) {
                $plugindata[$tmpsourceplugin->pluginname] = $tmpsourceplugin;
            }

            foreach ($plugindata as $sourceplugin) {

                $remoteplugindata = '';

                // Begin by storing some info about the plugin we're trying to install...
                $plugindetails = array('displayname' => $sourceplugin->displayname, 'pluginname' => $sourceplugin->pluginname,
                    'version' => $sourceplugin->version, 'versiontobeinstalled' => $sourceplugin->version);

                if (isset($sourceplugin->release)) {
                    $plugindetails['release'] = $sourceplugin->release;
                }

                // First off, does this plugin meet our environments target version...
                if ($sourceplugin->versionrequires <= $cfgversion) {

                    // Currently, for Moodle 4.0 not all plugins will work. Sideline those that we're aware will break things.
                    if ($env_version == 4.0) {
                        if (in_array($sourceplugin->pluginname, $donotinstall)) {
                            $plugindetails['notes'] = get_string('pluginrequired_text', 'tool_optionalplugins')
                                . get_string('incompatibleversion', 'core_plugin', $cfgrelease);
                            $cannotbeinstalled[] = $plugindetails;
                            continue;
                        }
                    }

                    // Does the plugin already exist? (shouldn't, but will eventually if this is being run multiple times).
                    $plugincheck = $DB->count_records('config_plugins',
                        ['plugin' => $sourceplugin->pluginname, 'name' => 'version']
                    );

                    if ($plugincheck > 0) {

                        // Check if the installed version is the latest, if not, just attempt to update it...
                        $remoteplugin = $pluginman->get_remote_plugin_info($sourceplugin->pluginname,
                            $sourceplugin->version, false);

                        if (!empty($remoteplugin)) {

                            // Check if the plugin we're installing has dependencies...
                            $plugindetails['requiredby'] = '';
                            $requiredby = $pluginman->other_plugins_that_require($sourceplugin->pluginname);
                            if ($requiredby) {
                                foreach ($requiredby as $ix => $val) {
                                    $inf = $pluginman->get_plugin_info($val);
                                    if ($inf) {
                                        $requirebytext = get_string('pluginrequired_text', 'tool_optionalplugins')
                                            . strtolower(get_string('requiredby', 'core_plugin'));
                                        $requiredby[$ix] = $requirebytext . $inf->displayname.' ('.$inf->component.')';
                                    }
                                }

                                $plugindetails['requiredby'] = $requiredby;
                            }

                            // If the version that's installed matches the current plugin repository version, all is good...
                            if ($sourceplugin->version == $remoteplugin->version->version) {
                                $alreadyinstalled[] = $plugindetails;
                                $alreadyseen[] = $sourceplugin->pluginname;
                                continue;
                            }

                            // If an update is available however...
                            if ($remoteplugin->version->version > $sourceplugin->version) {
                                if ($pluginman->is_remote_plugin_installable($sourceplugin->pluginname, $sourceplugin->version)) {

                                    // ... if the config minmaturity level matches, this should be safe to install...
                                    if ($remoteplugin->version->maturity == $minmaturity) {
                                        $remoteplugindata = $remoteplugin;
                                        $remotepluginversion = $remoteplugindata->version->version;
                                        $plugindetails['remotepluginrelease'] = $remoteplugindata->version->release;
                                        $plugindetails['conditiontext'] = get_string('conditiontext', 'tool_optionalplugins');
                                        $plugindetails['checkbox_n'] = true;
                                    }

                                    // ...Looks like a newer version *is* available, but not one matching our current maturity level
                                    if ($remoteplugin->version->maturity < $minmaturity) {

                                        // Go and fetch the level nearest to the one we have instead...
                                        $remotepluginversionmatch = $pluginman->get_remote_plugin_info($sourceplugin->pluginname,
                                            $sourceplugin->version, true);
                                        if (!empty($remotepluginversionmatch)) {
                                            // See {@link https://moodle.org/plugins/quiz_downloadsubmissions/1.1/17326} - maturity
                                            // is BETA, however, "required code maturity" is set to STABLE. For these kinds of
                                            // scenarios we will just issue a warning notice, but install it anyway.
                                            if ($remotepluginversionmatch->version->maturity == $minmaturity) {
                                                $canbeinstalled[] = $plugindetails;
                                            } else {
                                                $remoteplugindata = $remotepluginversionmatch;
                                                $remotepluginversion = $remoteplugindata->version->version;
                                                $plugindetails['remotepluginrelease'] = $remoteplugindata->version->release;
                                                $plugindetails['conditiontext'] = get_string('conditiontext',
                                                    'tool_optionalplugins');

                                                $stringman = get_string_manager();

                                                if ($stringman->string_exists('validationmsg_maturity', 'core_plugin')) {
                                                    $plugindetails['notice'] = get_string('validationmsg_maturity', 'core_plugin');
                                                }
                                            }
                                        } else {
                                            $plugindetails['notes'] = 'Nothing returned from the plugin directory';
                                            $cannotbeinstalled[] = $plugindetails;
                                        }
                                    }

                                    if (!empty($remoteplugindata)) {
                                        // Var $remotepluginversion is derived from either a near, or exact match...
                                        $plugindetails['remotepluginversion'] = $remotepluginversion;
                                        $plugindetails['versiontobeinstalled'] = $remoteplugindata->version->version;
                                        $plugindetails['maturitylevel'] = $remoteplugindata->version->maturity;
                                        $plugindetails['notes'] = true;
                                        $canbeinstalled[] = $plugindetails;

                                        // Clear these for the next time around...
                                        unset($plugindetails['notes']);
                                        unset($plugindetails['requiredby']);
                                    }
                                }
                            }
                        } else {
                            // No info returned from the plugin repository, but a version is already installed...
                            $alreadyinstalled[] = $plugindetails;
                        }

                    } else {

                        // If the plugin doesn't exist, check that it can be installed into this environment...
                        $pluginisinstallable = $pluginman->is_remote_plugin_installable($sourceplugin->pluginname,
                            $sourceplugin->version, $reason);

                        if ($pluginisinstallable == true) {

                            $remoteplugin = $pluginman->get_remote_plugin_info($sourceplugin->pluginname,
                                $sourceplugin->version, false);

                            if (!empty($remoteplugin)) {

                                // Let's check if the plugin we're trying to install has any dependencies (we're making the
                                // assumption they exist if the plugin has previously been installed, as per the above test).
                                if (isset($sourceplugin->dependencies)) {
                                    foreach ($sourceplugin->dependencies as $dependencyname => $dependencyversion) {
                                        // We've already seen and dealt with this dependency previously, move along...move along...
                                        if (in_array($dependencyname, $alreadyseen)) {
                                            $plugindetails['requiredby'][] = $dependencyname;
                                            $plugindetails['notes'] = true;
                                            continue;
                                        }

                                        // It's already in the list of things to be installed, move along...
                                        if (array_key_exists($dependencyname, $plugindata)) {
                                            $alreadyseen[] = $dependencyname;
                                            continue;
                                        }

                                        // So, it doesn't exist, add it to the list of things to be installed...
                                        $tmp = array('displayname' => $dependencyname, 'pluginname' => $dependencyname,
                                            'version' => $dependencyversion, 'versiontobeinstalled' => '');

                                        $dependencyisinstallable = $pluginman->is_remote_plugin_installable($dependencyname,
                                            $dependencyversion, $reason);

                                        if ($dependencyisinstallable == true) {
                                            $dependencyplugin = $pluginman->get_remote_plugin_info($dependencyname,
                                                $dependencyversion, true);

                                            if (!empty($dependencyplugin)) {
                                                $plugindetails['requiredby'][] = $dependencyname;
                                                $tmp['displayname'] = $dependencyplugin->name;
                                                $tmp['versiontobeinstalled'] = $dependencyplugin->version->version;
                                                $versioninstalltext = (($dependencyplugin->version->release) ?:
                                                    $dependencyplugin->version->version);
                                                $tmp['notes'] = get_string('pluginversioninstall_text', 'tool_optionalplugins',
                                                    $versioninstalltext);
                                                $canbeinstalled[] = $tmp;
                                            } else {
                                                // No info is available...
                                                $tmp['notes'] = get_string('plugindirectory_text', 'tool_optionalplugins');
                                                $cannotbeinstalled[] = $tmp;
                                            }
                                        } else {
                                            // This dependency isn't installable...
                                            $tmp['notes'] = '<small class="text-muted muted">['
                                                . get_string('plugindependency_text', 'tool_optionalplugins')
                                                . $sourceplugin->pluginname . ']</small>';
                                            $cannotbeinstalled[] = $tmp;
                                        }
                                        // So we don't deal with this again...
                                        $alreadyseen[] = $dependencyname;
                                        unset($tmp);
                                    }
                                }

                                // If the version we're trying to install matches the current plugin repository version, all is ok.
                                if ($sourceplugin->version == $remoteplugin->version->version) {
                                    $versioninstalltext = (($sourceplugin->release) ?: $sourceplugin->version);
                                    $plugindetails['notes'] = get_string('pluginversioninstall_text', 'tool_optionalplugins',
                                        $versioninstalltext);
                                    $canbeinstalled[$sourceplugin->pluginname] = $plugindetails;
                                    unset($plugindetails['notes']);
                                    unset($plugindetails['requiredby']);
                                    continue;
                                }

                                // If an update is available however...
                                if ($remoteplugin->version->version > $sourceplugin->version) {

                                    // ... if the config minmaturity level matches, this should be safe to install...
                                    if ($remoteplugin->version->maturity == $minmaturity) {
                                        $remoteplugindata = $remoteplugin;
                                        $remotepluginversion = $remoteplugindata->version->version;
                                        $plugindetails['remotepluginrelease'] = $remoteplugindata->version->release;
                                        $plugindetails['conditiontext'] = get_string('conditiontext', 'tool_optionalplugins');
                                        $plugindetails['checkbox_n'] = true;
                                    }

                                    // ...Looks like a newer version *is* available, but not one matching our current maturity level
                                    if ($remoteplugin->version->maturity < $minmaturity) {

                                        // Go and fetch the level nearest to the one we have instead...
                                        $remotepluginversionmatch = $pluginman->get_remote_plugin_info($sourceplugin->pluginname,
                                            $sourceplugin->version, true);

                                        if (!empty($remotepluginversionmatch)) {
                                            // See {@link https://moodle.org/plugins/quiz_downloadsubmissions/1.1/17326} - maturity
                                            // is BETA, however, "required code maturity" is set to STABLE. For these kinds of
                                            // scenarios we will just issue a warning notice, but install it anyway.
                                            if ($remotepluginversionmatch->version->maturity == $minmaturity) {
                                                $canbeinstalled[$remotepluginversionmatch->pluginname] = $plugindetails;
                                            } else {
                                                $remoteplugindata = $remotepluginversionmatch;
                                                $remotepluginversion = $remoteplugindata->version->version;
                                                $plugindetails['remotepluginrelease'] = $remoteplugindata->version->release;
                                                $plugindetails['conditiontext'] = get_string('conditiontext',
                                                    'tool_optionalplugins');

                                                $stringman = get_string_manager();
                                                if ($stringman->string_exists('validationmsg_maturity', 'core_plugin')) {
                                                    $plugindetails['notice'] = get_string('validationmsg_maturity', 'core_plugin');
                                                }
                                            }
                                        } else {
                                            // No match can be found...
                                            $plugindetails['notes'] = get_string('plugindirectory_text', 'tool_optionalplugins');
                                            $cannotbeinstalled[] = $plugindetails;
                                            continue;
                                        }
                                    }

                                    if (!empty($remoteplugindata)) {
                                        // Var $remotepluginversion is derived from either a near, or exact match...
                                        $plugindetails['remotepluginversion'] = $remotepluginversion;
                                        $plugindetails['versiontobeinstalled'] = $remoteplugindata->version->version;
                                        $plugindetails['maturitylevel'] = $remoteplugindata->version->maturity;
                                        $plugindetails['notes'] = true;
                                        $canbeinstalled[$remoteplugindata->component] = $plugindetails;

                                        // Clear these for the next time around...
                                        unset($plugindetails['notes']);
                                        unset($plugindetails['notice']);
                                        unset($plugindetails['requiredby']);
                                    }
                                }
                            } else {
                                // No info is available...
                                $plugindetails['notes'] = get_string('plugindirectory_text', 'tool_optionalplugins');
                                $cannotbeinstalled[] = $plugindetails;
                            }
                        } else {
                            $reason = remote_plugin_not_installable($reason);
                            $plugindetails['notes'] = $reason;
                            $cannotbeinstalled[] = $plugindetails;
                        }
                    }
                } else {
                    $plugindetails['notes'] = get_string('pluginversionmismatch_text', 'tool_optionalplugins');
                    $cannotbeinstalled[] = $plugindetails;
                }
            }
        }

        // So we can pick these up in pluginpreview.php.
        $SESSION->canbeinstalled = $canbeinstalled;
        $SESSION->alreadyinstalled = $alreadyinstalled;
        $SESSION->cannotbeinstalled = $cannotbeinstalled;

        unset($SESSION->filecontents);

        return true;
    }

    return false;
}

/**
 * This function primarily takes care of triggering the plugin installation mechanism
 *
 * @param array $canbeinstalled
 * @param array $installationchoices
 * @param object $pageurl
 * @return void
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 */
function install_optional_plugins($canbeinstalled, $installationchoices, $pageurl) {

    global $SESSION, $DB, $USER;

    $pluginman = core_plugin_manager::instance();

    foreach ($canbeinstalled as $idx => $plugin) {

        // Check if we are wanting to install the suggested update, or keep the version we have...
        $pluginversiontobeinstalled = $plugin['version'];
        $canbeinstalled[$idx]['versioninstalled'] = $pluginversiontobeinstalled;
        $canbeinstalled[$idx]['remoteinstalled'] = 0;

        if (isset($canbeinstalled[$idx]['notes']) && $canbeinstalled[$idx]['notes'] == true) {
            if (isset($canbeinstalled[$idx]['requiredby'])) {
                // Notes is currently a boolean value which gets appended to unless we do...
                if (is_bool($canbeinstalled[$idx]['notes'])) {
                    $canbeinstalled[$idx]['notes'] = '';
                }

                if (is_array($canbeinstalled[$idx]['requiredby'])) {
                    $requiredby = implode(', ', $canbeinstalled[$idx]['requiredby']);
                } else {
                    $requiredby = $canbeinstalled[$idx]['requiredby'];
                }
                $requirebytext = get_string('pluginrequired_text', 'tool_optionalplugins')
                    . strtolower(get_string('requiredby', 'core_plugin', $requiredby));
                $canbeinstalled[$idx]['notes'] = $requirebytext;
            }
        }

        if (array_key_exists($plugin['pluginname'], $installationchoices)) {
            // Notes is currently a boolean value which gets appended to unless we do...
            if (is_bool($canbeinstalled[$idx]['notes'])) {
                $canbeinstalled[$idx]['notes'] = '';
            }
            if ($installationchoices[$plugin['pluginname']] == 1) {
                $pluginversiontobeinstalled = $plugin['versiontobeinstalled'];
                // Needed for the logging purposes...
                $canbeinstalled[$idx]['versioninstalled'] = $pluginversiontobeinstalled;
                $canbeinstalled[$idx]['remoteinstalled'] = 1;
                if (isset($plugin['release']) && (string)$plugin['release'] !== '') {
                    $releasestring = $plugin['release'];
                } else {
                    $releasestring = $plugin['version'];
                }
                $availablestring = get_string('version', 'core_plugin') . ' ' . $releasestring
                    . get_string('available_string', 'tool_optionalplugins');
                $canbeinstalled[$idx]['notes'] .= $availablestring;

            } else {
                $canbeinstalled[$idx]['notes'] .= $plugin['remotepluginrelease']
                    . get_string('available_string', 'tool_optionalplugins');
            }
        }

        // Now trigger the installation process...
        $installable = array($pluginman->get_remote_plugin_info($plugin['pluginname'], $pluginversiontobeinstalled, true));
        if (!$pluginman->install_plugins($installable, true, true)) {
            throw new moodle_exception('install_plugins_failed', 'core_plugin', $pageurl);
        }
    }

    // Now create a log entry for those plugins installed, already installed, and unable to be installed....
    $pluginsinstalled = json_encode($canbeinstalled);
    $pluginsalreadyinstalled = json_encode($SESSION->alreadyinstalled);
    $pluginsnotinstalled = json_encode($SESSION->cannotbeinstalled);

    $record = new stdclass();
    $record->userid = $USER->id;
    $now = make_timestamp(date('Y'), date('m'), date('d'), date('H'), date('i'), date('s'));;
    $record->timecreated = $now;
    $record->installed = $pluginsinstalled;
    $record->alreadyinstalled = $pluginsalreadyinstalled;
    $record->notinstalled = $pluginsnotinstalled;
    $DB->insert_record('tool_optionalplugins_log', $record);

    // No point in having these cluttering up the session any further...
    unset($SESSION->canbeinstalled);
    unset($SESSION->alreadyinstalled);
    unset($SESSION->cannotbeinstalled);
    unset($SESSION->installationchoice);

    redirect(new moodle_url('/admin/index.php',
        array('cache' => 0, 'confirmplugincheck' => 1, 'confirmupgrade' => 1, 'confirmrelease' => 1)));
}

/**
 * Explain why {@see core_plugin_manager::is_remote_plugin_installable()} returned false.
 *
 * @param string $reason the reason code as returned by the plugin manager
 * @return string
 */
function remote_plugin_not_installable($reason) {

    if ($reason === 'notwritableplugintype' || $reason === 'notwritableplugin') {
        return get_string('notwritable', 'core_plugin');
    }

    if ($reason === 'remoteunavailable') {
        return get_string('packagenotdownloadable', 'tool_optionalplugins');
    }

    return false;
}
