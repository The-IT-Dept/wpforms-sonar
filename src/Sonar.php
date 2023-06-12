<?php

namespace TheITDept\WPSonar;

use Exception;
use League\ISO3166\ISO3166;
use TheITDept\WPSonar\API\SonarApi;

class Sonar
{
    public static function make(): self
    {
        return new self();
    }

    public function __construct()
    {
    }

    public function boot(): void
    {
        // hook the wpforms_frontend_js action to remove the geolocation script, and add our own with some updates.
        add_action('wpforms_frontend_js', [$this, 'enqueue_scripts'], 99);

        // Hook the wpforms_ajax_submit_success_response filter to the submit_success_response method, which lets us perform
        // an SQ using the submitted form, and modify the output before it's returned back to the user.
        add_filter('wpforms_ajax_submit_success_response', [$this, 'submit_success_response'], 10, 4);

        // Hook the wpforms settings page, to add our own settings.
        add_filter('wpforms_builder_settings_sections', [$this, 'add_settings_panel'], 10, 1);

        // Hook the settings content for our settings panel, to add our own settings.
        add_filter('wpforms_form_settings_panel_content', [$this, 'settings_content'], 20);

        // Hook the form submission, to send the data to Sonar.
        add_action('wpforms_process_complete', [$this, 'process_complete'], 10, 4);

    }

    public function process_complete($fields, $entry, $form_data, $entry_id)
    {
        // We only want to process here if the Sonar integration is enabled.
        if (!isset($form_data['settings']['sonar_enable']) && $form_data['settings']['sonar_enable'] !== "1") {
            return;
        }

        // Get the Sonar API.
        $api = SonarApi::make(SONAR_API_URL, SONAR_API_KEY);

        try {
            $addressField = $fields[$form_data['settings']['sonar_account_service_address']];
            $address = $this->processAddressField($addressField);

            if ($line2 = isset($form_data['settings']['sonar_account_service_address_line2'])
            && $form_data['settings']['sonar_account_service_address_line2'] !== ""
                ? $fields[$form_data['settings']['sonar_account_service_address_line2']]['value'] : $addressField['address2']) {
                $address['line2'] = $line2;
            }

            $sonarAddressId = $api->createAddress($address);
        } catch (Exception $e) {
            if ($email = $form_data['settings']['sonar_error_report_email'] ?? false) {
                $this->errorEmail($email, $form_data['settings']['form_title'], $entry_id, "Error creating address", $e);
            }
            error_log("Error creating address: " . $e->getMessage());
            return;
        }

        if (!$sonarAddressId) {
            if ($email = $form_data['settings']['sonar_error_report_email'] ?? false) {
                $this->errorEmail($email, $form_data['settings']['form_title'], $entry_id, "Unknown error creating address", $address);
            }
            error_log("No address ID returned from Sonar.");
            return;
        }

        $mailingAddress = $address;
        unset($mailingAddress['network_site_ids']);
        unset($mailingAddress['address_status_id']);
        unset($mailingAddress['latitude']);
        unset($mailingAddress['longitude']);

        // If we make it here with an address, we can create the account.
        $accountCreateInput = [
            'serviceable_address_id' => $sonarAddressId,
            'unset_custom_field_data' => ["2"], // This unsets the DOB field for now
            'custom_field_data' => [['custom_field_id' => '1', 'value' => 'A']],
            'account_group_ids' => [],
            'account_status_id' => $form_data['settings']['sonar_account_status'],
            'account_type_id' => $form_data['settings']['sonar_account_type'],
            'company_id' => $form_data['settings']['sonar_company'],
            'mailing_address' => $mailingAddress,
            'name' => $fields[$form_data['settings']['sonar_account_name']]['value'],
            'primary_contact' => [
                'name' => $fields[$form_data['settings']['sonar_contact_name']]['value'],
                'email_address' => $fields[$form_data['settings']['sonar_contact_email']]['value'],
                'email_category_ids' => ["2", "3", "5", "4"],
                'phone_numbers' => [
                    [
                        'country' => $address['country'],
                        'number' => $fields[$form_data['settings']['sonar_contact_phone']]['value'],
                        'phone_number_type_id' => '4', // Mobile
                    ]
                ]
            ],
        ];

        // Create the account
        try {
            $accountId = $api->createAccount($accountCreateInput);
            if (!$accountId) {
                if ($email = $form_data['settings']['sonar_error_report_email'] ?? false) {
                    $this->errorEmail($email, $form_data['settings']['form_title'], $entry_id, "Unknown error creating account", $accountCreateInput);
                }
            }
        } catch (Exception $e) {
            if ($email = $form_data['settings']['sonar_error_report_email'] ?? false) {
                $this->errorEmail($email, $form_data['settings']['form_title'], $entry_id, "Error Creating Account", $e);
            }
            return;
        }
    }

    public function errorEmail($to, $form, $entryId, $subject, ...$args)
    {
        ob_start();
        echo "Form: $form\n";
        echo "Entry ID: $entryId\n";
        echo "Error: $subject\n\n";

        echo "Debug Info:\n";
        foreach ($args as $arg) {
            print_r($arg);
        }
        $content = ob_get_clean();

        wp_mail($to, "[Sonar Automation Error / $form] Entry $entryId - $subject", $content);
    }

    /**
     * @throws Exception
     */
    private function processAddressField($addressField): array
    {
        // if this is a string, assume it a base64 encoded json string, of the google maps api response.
        try {
            $addressField = json_decode(base64_decode($addressField['value']), true);
            // Process as a google maps api response.
            $components = $addressField['full']['address_components'];

            $line1 = "";
            $state = "";
            $country = "";
            $city = "";
            $postcode = "";
            foreach ($components as $component) {
                if (in_array('street_number', $component['types'])) {
                    $line1 .= $component['long_name'] . " ";
                }
                if (in_array('route', $component['types'])) {
                    $line1 .= $component['long_name'];
                }
                if (in_array('locality', $component['types'])) {
                    $city = $component['long_name'];
                }
                if (in_array('country', $component['types'])) {
                    $country = $component['short_name'];
                }
                if (in_array('administrative_area_level_1', $component['types'])) {
                    $state = $component['short_name'];
                }
                if (in_array('postal_code', $component['types'])) {
                    $postcode = $component['long_name'];
                }
            }

            return [
                'line1' => $line1,
                'city' => $city,
                'subdivision' => $country . "_" . $state,
                'zip' => $postcode,
                'country' => $country,
                'latitude' => (string)$addressField['full']['geometry']['location']['lat'],
                'longitude' => (string)$addressField['full']['geometry']['location']['lng'],
                'address_status_id' => '1', // Ready for service
                'network_site_ids' => []
            ];
        } catch (Exception $e) {
            // do nothing, it's not a base64 encoded json string.
        }

        throw new Exception("Address field is not a base64 encoded json string.");
    }

    public function add_settings_panel($sections)
    {
        $sections['tid_sonar'] = __('Sonar Integration', 'integrate_sonar_wpforms');
        return $sections;
    }

    public function settings_content($instance)
    {
        echo '<div class="wpforms-panel-content-section wpforms-panel-content-section-tid_sonar">';
        echo '<div class="wpforms-panel-content-section-title">' . __('Sonar Integration', 'wpforms-sonar') . '</div>';

        $api = SonarApi::make(SONAR_API_URL, SONAR_API_KEY);

        $general = [
            wpforms_panel_field(
                'toggle',
                'settings',
                'sonar_enable',
                $instance->form_data,
                "Enable Sonar Integration",
                [],
                false
            ),

            wpforms_panel_field(
                'text',
                'settings',
                'sonar_error_report_email',
                $instance->form_data,
                "Error Report Email",
                [
                    'required' => true,
                    'placeholder' => 'help@brokenautomation.com',
                    'tooltip' => 'If set, this email will be notified if there is an error with the Sonar integration.',
                ],
                false
            ),

            wpforms_panel_field(
                "select",
                "settings",
                "sonar_company",
                $instance->form_data,
                "Sonar Company",
                [
                    'required' => true,
                    'options' => $api->getCompanies(),
                    'placeholder' => 'Select a Sonar Company',
                ],
                false,
            ),

            wpforms_panel_field(
                "select",
                "settings",
                "sonar_account_status",
                $instance->form_data,
                "Sonar Account Status",
                [
                    'required' => true,
                    'options' => $api->getAccountStatuses(),
                    'placeholder' => 'Select an Account Status',
                ],
                false,
            ),

            wpforms_panel_field(
                "select",
                "settings",
                "sonar_account_type",
                $instance->form_data,
                "Sonar Account Type",
                [
                    'required' => true,
                    'options' => $api->getAccountTypes(),
                    'placeholder' => 'Select an Account Type',
                ],
                false,
            ),
        ];

        wpforms_panel_fields_group(implode($general), [
            'title' => 'General Settings',
            'description' => "If you would like to use this form to process signups within Sonar, you need to fill the following details in.",
        ]);


        $mapping = [
            wpforms_panel_field(
                "select",
                "settings",
                "sonar_account_name",
                $instance->form_data,
                "Sonar Account Name",
                [
                    'required' => true,
                    'field_map' => ['text', 'name'],
                    'placeholder' => 'Select a Field',
                    'tooltip' => 'This is the name of the account that will be created in Sonar. This is usually the name of the person who is signing up, or the business name.',
                ],
                false,
            ),

            wpforms_panel_field(
                "select",
                "settings",
                "sonar_account_service_address",
                $instance->form_data,
                "Sonar Account Service Address (WARNING: YOU MUST CREATE A HIDDEN FIELD WITH THE CLASS 'custom_place_id_field', AND HAVE A GOOGLE PLACES ADDRESS LOOKUP FIELD FOR THIS TO WORK)! Select the HIDDEN FIELD here.",
                [
                    'required' => true,
                    'field_map' => ['text', 'address', 'hidden'],
                    'placeholder' => 'Select a Field',
                    'tooltip' => 'This is the address of the account that will be created in Sonar. This is usually the address of the person who is signing up, or the business address.',
                ],
                false,
            ),

            wpforms_panel_field(
                "select",
                "settings",
                "sonar_account_service_address_line2",
                $instance->form_data,
                "Sonar Account Service Address Line 2",
                [
                    'field_map' => ['text', 'address'],
                    'placeholder' => 'Select a Field',
                    'tooltip' => 'If you need to add a second line to the address, you can do so here. This could be an apartment number, or a suite number etc.',
                ],
                false,
            ),

            wpforms_panel_field(
                "select",
                "settings",
                "sonar_contact_name",
                $instance->form_data,
                "Sonar Contact Name",
                [
                    'required' => true,
                    'field_map' => ['text', 'name'],
                    'placeholder' => 'Select a Field',
                    'tooltip' => 'This is the name of the contact that will be created in Sonar. This is usually the name of the person who is signing up, or the name of a representative of the business.',
                ],
                false,
            ),

            wpforms_panel_field(
                "select",
                "settings",
                "sonar_contact_email",
                $instance->form_data,
                "Sonar Contact Email",
                [
                    'required' => true,
                    'field_map' => ['text', 'email'],
                    'placeholder' => 'Select a Field',
                    'tooltip' => 'This is the email address of the contact that will be created in Sonar. This is usually the email address of the person who is signing up, or the email address of a representative of the business.',
                ],
                false,
            ),

            wpforms_panel_field(
                "select",
                "settings",
                "sonar_contact_phone",
                $instance->form_data,
                "Sonar Contact Phone",
                [
                    'required' => true,
                    'field_map' => ['text', 'phone'],
                    'placeholder' => 'Select a Field',
                    'tooltip' => 'This is the phone number of the contact that will be created in Sonar. This is usually the phone number of the person who is signing up, or the phone number of a representative of the business.',
                ],
                false,
            ),

        ];

        wpforms_panel_fields_group(implode($mapping), [
            'title' => 'Attribute Mappings',
            'description' => "Select the fields you would like to map to Sonar.",
        ]);


        echo "</div>";
    }

    // enqueue_scripts is called when the wpforms_frontend_js action is fired, it removes the geolocation script, and adds
    // our own version, that fills a place_id field, as we need it for the SQ API.
    public function enqueue_scripts()
    {
        wp_scripts()->remove('wpforms-geolocation-google-places');
        wp_scripts()->add(
            'wpforms-geolocation-google-places',
            plugin_dir_url(__FILE__) . 'assets/js/wpforms-geolocation-google-api.js',
            [],
            WPFORMS_SONAR_VER
        );
    }

    // submit_success_response is called when a form is submitted successfully, it checks to see if the form is an SQ form,
    // and if so, it calls the SQ API and stores the response in the form entry. It then returns the response to the user.
    public function submit_success_response($response, $form_id, $form_data)
    {
        // We want to make sure this is the SQ form
        if (!stristr($form_data['settings']['form_title'] ?? '', 'SQ')) {
            return $response;
        }

        // Find the field ID for the "Response" field - use the magic css class. We will store the SQ response here.
        $address_field_id = null;
        $place_id_field_id = null;
        $response_field_id = null;
        foreach ($form_data['fields'] as $field) {
            if (stristr($field['css'], 'custom_address_field')) {
                $address_field_id = $field['id'];
            }
            if (stristr($field['css'], 'custom_place_id_field')) {
                $place_id_field_id = $field['id'];
            }
            if (stristr($field['css'], 'custom_response_field')) {
                $response_field_id = $field['id'];
            }
        }

        // We can now run the SQ API call to get the address details, and store them in the form.
        $place_id = $_REQUEST['wpforms']['fields'][$place_id_field_id];


        // If we do not have a place ID, we cannot do the API call.
        if (!$place_id) {
            $response['confirmation'] = $this->sqstyle("Error") . $response['confirmation'];
            return $response;
        }

        // Do the API call
        $place_id = json_decode(base64_decode($place_id), true);
        $sq_response = $this->doSQ($place_id);
        if ($sq_response === false) {
            $sq_response = "Error";
        }

        // Store the response in the form entry
        if ($entry_id = $_POST['wpforms']['entry_id'] ?? false) {
            $entry = wpforms()->get('entry')->get($entry_id);

            if ($fields = json_decode($entry->fields, true)) {
                // Update the response field
                $fields[$response_field_id]['value'] = $sq_response;

                // Save the response back to the entry
                wpforms()->get('entry')->update($entry_id, array('fields' => json_encode($fields)), '', '', array('cap' => false));
            }
        }

        // Update the confirmation message to include the SQ response
        $response['confirmation'] = str_replace('address', $place_id['autocomplete_search'], $response['confirmation']);

        $response['confirmation'] = $this->sqstyle($sq_response) .
            str_replace('place_id', $place_id['placeId'], $response['confirmation']);

        return $response;
    }

    // sqstyle takes a response from the SQ API and returns a string of CSS to hide the other responses
    private function sqstyle($res): string
    {
        $responses = [
            'Error',
            'OnNetwork',
            'ExpressionOfInterest',
        ];

        // remove the response from the array
        $responses = array_diff($responses, [$res]);

        // hide the other responses
        $style = "<style>";
        foreach ($responses as $response) {
            $style .= ".sq-{$response} {display: none!important;}";
        }
        $style .= "</style>";

        return $style;
    }


    // doSQ takes a Google Place ID and returns the response from the SQ API
    private function doSQ($place_id)
    {
        // TODO: Make this a setting
        $url = "https://sq.vinenetworks.com.au/api/search";

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($place_id),
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $result = json_decode(wp_remote_retrieve_body($response));

        return $result->status ?? false;
    }
}
