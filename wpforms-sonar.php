<?php

/*
Plugin Name: WPForms Sonar
Plugin URI: https://github.com/the-it-dept/wpforms-sonar
Description: Simple Sonar (https://sonar.software) integration for WPForms.
Version: VERSION
Author: Nick Pratley
Author URI: https://theitdept.au
Text Domain: wpforms-sonar
Domain Path: /languages
Documentation: https://github.com/the-it-dept/wpforms-sonar
*/


// Define the plugin version. This will be replaced by the build script.
const WPFORMS_SONAR_VER = 'VERSION';

defined('ABSPATH') or die('');

if (!function_exists('is_plugin_active_for_network')) {
    require_once(ABSPATH . '/wp-admin/includes/plugin.php');
}


// We only want to run the updater if we are in the admin area.
add_action('admin_init', function () {
    if (!class_exists('GithubUpdater')) {
        require_once __DIR__ . '/GithubUpdater.php';
    }

    GithubUpdater::make()
        ->repository('the-it-dept/wpforms-sonar')
        ->asset_name('wpforms-sonar.zip')
        ->readme_url('https://raw.githubusercontent.com/the-it-dept/wpforms-sonar/main/README.md')
        ->boot(__FILE__);
});

require_once __DIR__ . '/Sonar.php';
// Boot the plugin.
Sonar::make()
    ->boot();
