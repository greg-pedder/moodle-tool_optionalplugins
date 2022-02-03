# moodle-tool_optionalplugins
___

#Purpose
This plugin is intended to allow the export and mass installation of additional plugins into Moodle.

Historically, end users had the task of installing additional plugins one at a time when setting up a new Moodle installation. Over time, as this can grow to be well be into the tens, or hundreds of additional plugins, naturally, it becomes quite a time-consuming exercise to have to carry out.

# Installation
___
* Either clone or checkout the files to /your/moodle/admin/tool/
* Visit Site admin => Notifications, follow the upgrade instructions which will install the files and additional report logging table

# Use
___
* To use, go to Site administration > Development > Experimental > Manage optional plugins
* "Export plugin list" will generate a (JSON) list of all additional plugins. Save it to your machine, or another location.
* "Import optional plugins" allows you to select a file containing a list of plugins to import.
* "Upload and preview" displays a list of the plugins that can/will be installed.
* Plugins with upgrades available will offer you the choice to install the upgrade, or to stick with the source version.
* Once the installation has completed, finalise the upgrade process as normal, i.e. save changes at the upgrade settings page. 
* Revisit Site administration > Plugins > Plugins overview > Additional plugins or ...
* There is also a report which can be found under Site administration > Reports > Optional plugin installations - this provides further details as to what was installed.