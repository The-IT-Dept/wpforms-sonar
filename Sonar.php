<?php

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
        add_action( 'wpforms_frontend_js', [ $this, 'enqueue_scripts' ], 99 );

        // Hook the wpforms_ajax_submit_success_response filter to the submit_success_response method, which lets us perform
        // an SQ using the submitted form, and modify the output before it's returned back to the user.
        add_filter('wpforms_ajax_submit_success_response', [$this, 'submit_success_response'], 10, 4);

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
        $address = $_REQUEST['wpforms']['fields'][$address_field_id];
        $place_id = $_REQUEST['wpforms']['fields'][$place_id_field_id];

        // If we do not have a place ID, we cannot do the API call.
        if (!$place_id) {
            $response['confirmation'] = $this->sqstyle("Error") . $response['confirmation'];
            return $response;
        }

        // Do the API call
        $sq_response = $this->doSQ($place_id, $address);
        if($sq_response === false) {
            $sq_response = "Error";
        }

        // Store the response in the form entry
        if ($entry_id = $_POST['wpforms']['entry_id'] ?? false){
            $entry = wpforms()->get('entry')->get($entry_id);

            if($fields = json_decode($entry->fields, true)) {
                // Update the response field
                $fields[$response_field_id]['value'] = $sq_response;

                // Save the response back to the entry
                wpforms()->get('entry')->update( $entry_id, array( 'fields' => json_encode($fields) ), '', '', array( 'cap' => false ) );
            }
        }

        // Update the confirmation message to include the SQ response
        $response['confirmation'] = str_replace('address', $address, $response['confirmation']);
        $response['confirmation'] = $this->sqstyle($sq_response) .
            str_replace('place_id', $place_id, $response['confirmation']);

        return  $response;
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
    private function doSQ($place_id, $address)
    {
        // TODO: Make this a setting
        $url = "https://sq.vinenetworks.com.au/api/search";

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                "placeId" => $place_id,
                "autocomplete_search" => $address,
            ]),
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $result = json_decode(wp_remote_retrieve_body($response));

        return $result->status ?? false;
    }
}
