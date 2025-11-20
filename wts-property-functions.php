<?php
/**
 * Plugin Name: Property Functions
 * Description: Various MLS Property Functions.
 * Version: 1.3.5
 * Author: Thomas Rainer
 * Author URI: https://wtsks.com
 * Plugin URI: https://github.com/wts-thomas/wts-property-functions
 * License: GPL2
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

// ======================================
// INCLUDE CORE FUNCTIONS
// ======================================

require_once plugin_dir_path(__FILE__) . 'includes/community-helpers.php';
require_once plugin_dir_path(__FILE__) . 'includes/community-selection.php';
require_once plugin_dir_path(__FILE__) . 'includes/community-shortcodes.php';
require_once plugin_dir_path(__FILE__) . 'includes/mls-display-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/community-sync-tool.php';

require_once plugin_dir_path(__FILE__) . 'includes/builder-shortcodes.php';
require_once plugin_dir_path(__FILE__) . 'includes/builder-selection.php';
require_once plugin_dir_path(__FILE__) . 'includes/builder-sync-tool.php';


// ======================================
// PLUGIN UPDATE CHECKER (GitHub Integration)
// ======================================

require 'vendor/plugin-update-checker/plugin-update-checker.php';
$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
	'https://github.com/wts-thomas/wts-property-functions/',
	__FILE__,
	'wts-property-functions'
);

//Set the branch that contains the stable release.
$myUpdateChecker->setBranch('main');