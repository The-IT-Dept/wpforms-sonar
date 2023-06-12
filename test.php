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

$autoload = __DIR__ . '/vendor/autoload.php';
require_once $autoload;


use GraphQL\Mutation;
use GraphQL\Variable;
use TheITDept\WPSonar\API\SonarApi;

$client = SonarApi::make("", "");

$cra = (new Mutation("createServiceableAddress"))
    ->setVariables([new Variable('input', 'CreateServiceableAddressMutationInput', true)])
    ->setArguments(['input' => '$input'])
    ->setSelectionSet(['id']);

$address = [
    'line1' => '40 Woolana Avenue',
    'line2' => 'Test1',
    'city' => 'Budgewoi',
    'subdivision' => 'AU_NSW',
    'zip' => '2262',
    'country' => 'AU',
    'address_status_id' => '1',
    'latitude' => '-33.229156',
    'longitude' => '151.548848',
    'network_site_ids' => []
];



