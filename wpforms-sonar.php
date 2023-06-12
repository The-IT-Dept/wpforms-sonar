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

$plugin_path = plugin_dir_path(__FILE__);
$autoload = $plugin_path . '/vendor/autoload.php';

if (is_readable($autoload)) {
    require_once $autoload;
}

use TheITDept\WPSonar\Sonar;

const WPFORMS_SONAR_VER = 'VERSION';

defined('ABSPATH') or die('');

if (!function_exists('is_plugin_active_for_network')) {
    require_once(ABSPATH . '/wp-admin/includes/plugin.php');
}

// Boot the plugin.
Sonar::make()
    ->boot();
