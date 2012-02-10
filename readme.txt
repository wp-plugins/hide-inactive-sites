=== Hide Inactive Sites ===
Contributors: ericjuden
Tags: wordpress, multisite, wpmu, blogs, sites, activity, inactive, hide
Requires at least: 3.2
Tested up to: 3.3.1
Stable tag: trunk

== Description ==
Changes visibility of a blog after it has had no activity for a specified amount of time. It is only for WordPress Multisite.

This plugin uses WP_Cron to update the sites. I've created a filter in the plugin to edit the query of sites that are returned. There are also hooks for adding additional custom options to each of the settings.  

== Installation ==

1. Copy the plugin files to <code>wp-content/plugins/</code>

2. Network Activate plugin from Network Plugins page

3. From Network Admin dashboard, go to Settings -> Hide Inactive Sites and adjust settings.

== Screenshots ==

1. The Network Admin dashboard screen.

== Changelog ==

= 1.0 =
* Initial release