/* global wpforms_geolocation_settings, google */

'use strict';

/**
 * WPForms Geolocation Google Places API.
 *
 * @since 2.0.0
 */
var WPFormsGeolocationGooglePlacesAPI = window.WPFormsGeolocationGooglePlacesAPI || (function (document, window) {

    /**
     * List of fields with autocomplete feature.
     *
     * @type {Array}
     */
    var fieldsPlaces = [],

        /**
         * Geocoder from Geolocation API which help to detect current place by latitude and longitude.
         *
         * @type {object}
         */
        geocoder;

    var app = {

        /**
         * Start the engine.
         *
         * @since 2.0.0
         */
        init: function () {

            app.getFields();
            if (!fieldsPlaces.length) {
                return;
            }
            app.initGeocoder();
            fieldsPlaces.forEach(function (currentFieldPlace) {

                app.initMap(currentFieldPlace);
                app.initAutocomplete(currentFieldPlace);
            });
            app.detectGeolocation();
            app.bindFormEvents();
        },

        /**
         * Bind form events.
         *
         * @since 2.3.0
         */
        bindFormEvents: function () {

            const enableButton = [];

            document.querySelectorAll('.wpforms-form').forEach(function (form) {

                if (!form.querySelector('[data-autocomplete]')) {
                    return;
                }

                const formID = form.getAttribute('data-formid');

                // We should prevent a form submission on Enter keyboard key.
                // Enable/disable a form button when the suggestions popup is close/open.
                const observer = new MutationObserver(function (MutationRecords) {

                    MutationRecords.forEach(function (MutationRecord) {

                        if (!MutationRecord.target.classList.contains('pac-container') || MutationRecord.type !== 'attributes' || MutationRecord.attributeName !== 'style') {
                            return;
                        }

                        const formButton = form.querySelector('.wpforms-submit');

                        if (MutationRecord.target.style.display !== 'none') {
                            formButton.disabled = true;
                            delete enableButton[formID];

                            return;
                        }

                        enableButton[formID] = setTimeout(function () {

                            formButton.disabled = false;
                        }, 300);
                    });
                });

                observer.observe(document.querySelector('body'), {attributes: true, subtree: true});
            });
        },

        /**
         * Show debug message.
         *
         * @since 2.0.0
         *
         * @param {string|object} message Debug message.
         */
        showDebugMessage: function (message) {

            if (!window.location.hash || '#wpformsdebug' !== window.location.hash) {
                return;
            }

            console.log(message);
        },

        /**
         * Closest function.
         *
         * @param {Element} el Element.
         * @param {string} selector Parent selector.
         *
         * @returns {Element|undefined} Parent.
         */
        closest: function (el, selector) {

            var matchesSelector = el.matches || el.webkitMatchesSelector || el.mozMatchesSelector || el.msMatchesSelector;

            while (el) {
                if (matchesSelector.call(el, selector)) {
                    break;
                }
                el = el.parentElement;
            }
            return el;
        },

        /**
         * Get all fields for geolocation.
         *
         * @since 2.0.0
         */
        getFields: function () {

            var fields = Array.prototype.slice.call(document.querySelectorAll('.wpforms-form .wpforms-field input[type="text"][data-autocomplete="1"]'));

            fields.forEach(function (el) {

                var wrapper = app.closest(el, '.wpforms-field'),
                    mapField = el.hasAttribute('data-display-map') ? wrapper.querySelector('.wpforms-geolocation-map') : null,
                    type = wrapper.classList[1] ? wrapper.classList[1].replace('wpforms-field-', '') : 'text',
                    additionalFields = {};

                if ('address' === type) {
                    var country = wrapper.querySelector('.wpforms-field-address-country');
                    additionalFields = {
                        locality: {
                            el: wrapper.querySelector('.wpforms-field-address-city'),
                            type: 'long_name',
                        },
                        // eslint-disable-next-line camelcase
                        postal_town: {
                            el: wrapper.querySelector('.wpforms-field-address-city'),
                            type: 'long_name',
                        },
                        political: {
                            el: wrapper.querySelector('.wpforms-field-address-state'),
                            type: country ? 'long_name' : 'short_name',
                        },
                        administrative_area_level_1: { // eslint-disable-line camelcase
                            el: wrapper.querySelector('.wpforms-field-address-state'),
                            type: country ? 'long_name' : 'short_name',
                        },
                        postal_code: { // eslint-disable-line camelcase
                            el: wrapper.querySelector('.wpforms-field-address-postal'),
                            type: 'long_name',
                        },
                        country: {
                            el: country,
                            type: 'short_name',
                        },
                    };
                }

                app.showDebugMessage("type: " + type)

                // Nick P - 2023-05-25 - Add support for place_id hidden field
                if ('text' === type) {
                    // find the hidden field with the custom_place_id_field class and add it to the additionalFields array
                    const place_field = document.querySelector(".wpforms-form .custom_place_id_field > input[type=hidden]");
                    app.showDebugMessage("place_id: " + place_field)
                    if (place_field) {
                        additionalFields = {
                            place_id: {
                                el: place_field,
                                type: 'place_id',
                            }
                        }
                    }
                }

                fieldsPlaces.push({
                    'searchField': el,
                    'mapField': mapField,
                    'type': type,
                    'additionalFields': additionalFields,
                    'settings': app.getFieldSettings(el),
                });
            });
        },

        /**
         * Get the field settings.
         *
         * @since 2.3.0
         *
         * @param {Element} el Element.
         *
         * @returns {object} The field settings.
         */
        getFieldSettings: function (el) {

            return {
                autocompleteSettings: app.getFieldAutocompleteSettings(el),
                mapSettings: app.getFieldMapSettings(el),
                markerSettings: app.getFieldMarkerSettings(el),
            };
        },

        /**
         * Get the autocomplete field settings.
         *
         * @since 2.3.0
         *
         * @param {Element} el Element.
         *
         * @returns {object} The field autocomplete settings.
         */
        getFieldAutocompleteSettings: function (el) {

            var fieldSettingsName = el.getAttribute('id').replaceAll('-', '_');

            return Object.assign(
                {
                    types: ['geocode'],
                },
                wpforms_geolocation_settings.autocompleteSettings.common ? wpforms_geolocation_settings.autocompleteSettings.common : {},
                wpforms_geolocation_settings.autocompleteSettings[fieldSettingsName] ? wpforms_geolocation_settings.autocompleteSettings[fieldSettingsName] : {},
            );
        },

        /**
         * Get the map field settings.
         *
         * @since 2.3.0
         *
         * @param {Element} el Element.
         *
         * @returns {object} The field map settings.
         */
        getFieldMapSettings: function (el) {

            var fieldSettingsName = el.getAttribute('id').replaceAll('-', '_');

            return Object.assign(
                {

                    // Backward compatibility with older versions.
                    zoom: wpforms_geolocation_settings.zoom ? wpforms_geolocation_settings.zoom : 9,
                    center: wpforms_geolocation_settings.default_location ? wpforms_geolocation_settings.default_location : {},
                },
                wpforms_geolocation_settings.mapSettings.common ? wpforms_geolocation_settings.mapSettings.common : {},
                wpforms_geolocation_settings.mapSettings[fieldSettingsName] ? wpforms_geolocation_settings.mapSettings[fieldSettingsName] : {},
            );
        },

        /**
         * Get the marker field settings.
         *
         * @since 2.3.0
         *
         * @param {Element} el Element.
         *
         * @returns {object} The field marker settings.
         */
        getFieldMarkerSettings: function (el) {

            var fieldSettingsName = el.getAttribute('id').replaceAll('-', '_');

            return Object.assign(
                {
                    draggable: true,
                },
                wpforms_geolocation_settings.markerSettings.common ? wpforms_geolocation_settings.markerSettings.common : {},
                wpforms_geolocation_settings.markerSettings[fieldSettingsName] ? wpforms_geolocation_settings.markerSettings[fieldSettingsName] : {},
            );
        },

        /**
         * Init Google Map.
         *
         * @since 2.0.0
         *
         * @param {object} currentFieldPlace Current group field with places API.
         */
        initMap: function (currentFieldPlace) {

            if (!currentFieldPlace.mapField) {
                return;
            }

            currentFieldPlace.map = new google.maps.Map(
                currentFieldPlace.mapField, currentFieldPlace.settings.mapSettings
            );
            currentFieldPlace.marker = new google.maps.Marker(
                Object.assign(
                    {
                        map: currentFieldPlace.map,
                        position: currentFieldPlace.settings.mapSettings.center ? currentFieldPlace.settings.mapSettings.center : {},
                    },
                    currentFieldPlace.settings.markerSettings
                )
            );

            currentFieldPlace.marker.addListener('dragend', app.markerDragend);
        },

        /**
         * Init Google Geocoder.
         *
         * @since 2.0.0
         */
        initGeocoder: function () {

            geocoder = new google.maps.Geocoder;
        },

        /**
         * Action after marker was dragend.
         *
         * @since 2.0.0
         *
         * @param {object} marker Google Marker.
         */
        markerDragend: function (marker) {

            var currentFieldPlace = app.findFieldPlaceByMarker(this);

            if (!currentFieldPlace) {
                return;
            }

            app.detectPlaceByCoordinates(marker.latLng, function (place) {

                app.updateFields(currentFieldPlace, place);
                currentFieldPlace.map.setCenter(marker.latLng);
            });
        },

        /**
         * Detect Place by latitude and longitude.
         *
         * @since 2.0.0
         * @deprecated 2.3.0
         *
         * @param {object} currentFieldPlace Current group field with places API.
         * @param {object} latLng Latitude and longitude.
         */
        detectByCoordinates: function (currentFieldPlace, latLng) {

            console.warn('The WPFormsGeolocationGooglePlacesAPI.detectByCoordinates() is deprecated since version 2.3.0! Use the WPFormsGeolocationGooglePlacesAPI.detectPlaceByCoordinates() instead.');

            if (!geocoder) {
                return;
            }

            geocoder.geocode({'location': latLng}, function (results, status) {

                if (status !== 'OK') {
                    app.showDebugMessage('Geocode was wrong');
                    app.showDebugMessage(results);
                    return;
                }
                if (!results[0]) {
                    return;
                }
                app.updateFields(currentFieldPlace, results[0]);
            });
        },

        /**
         * Detect a place by latitude and longitude.
         *
         * @since 2.3.0
         *
         * @param {object} latLng Latitude and longitude.
         * @param {Function} callback Success callback.
         */
        detectPlaceByCoordinates: function (latLng, callback) {

            if (!geocoder) {
                return;
            }

            geocoder.geocode({'location': latLng}, function (results, status) {

                if (status !== 'OK') {
                    app.showDebugMessage('Geocode was wrong');
                    app.showDebugMessage(results);
                    return;
                }
                if (!results[0]) {
                    return;
                }

                callback(results[0]);
            });
        },

        /**
         * Update map.
         *
         * @since 2.0.0
         *
         * @param {object} currentFieldPlace Current group field with places API.
         * @param {object} latLng Latitude and longitude.
         */
        updateMap: function (currentFieldPlace, latLng) {

            if (!currentFieldPlace.map) {
                return;
            }

            currentFieldPlace.marker.setPosition(latLng);
            currentFieldPlace.map.setCenter(latLng);
        },

        /**
         * Find current group field with places API by Google marker.
         *
         * @since 2.0.0
         *
         * @param {object} marker Google marker.
         *
         * @returns {object|null} currentFieldPlace Current group field with places API.
         */
        findFieldPlaceByMarker: function (marker) {

            var currentFieldPlace = null;

            fieldsPlaces.forEach(function (el) {

                if (el.marker !== marker) {
                    return;
                }
                currentFieldPlace = el;
            });

            return currentFieldPlace;
        },

        /**
         * Find current group field with places API by Google Autocomplete.
         *
         * @since 2.0.0
         *
         * @param {object} autocomplete Google Autocomplete.
         *
         * @returns {object|null} currentFieldPlace Current group field with places API.
         */
        findFieldPlaceByAutocomplete: function (autocomplete) {

            var currentFieldPlace = null;

            fieldsPlaces.forEach(function (el) {

                if (el.autocomplete !== autocomplete) {
                    return;
                }
                currentFieldPlace = el;
            });

            return currentFieldPlace;
        },

        /**
         * Find current group field with places API by country field element.
         *
         * @since 2.0.0
         *
         * @param {object} countryEl Country field element.
         *
         * @returns {object|null} currentFieldPlace Current group field with places API.
         */
        findFieldPlaceByCountry: function (countryEl) {

            var currentFieldPlace = null;

            fieldsPlaces.forEach(function (el) {

                if (!el.additionalFields || !el.additionalFields.country || el.additionalFields.country.el !== countryEl) {
                    return;
                }

                currentFieldPlace = el;
            });

            return currentFieldPlace;
        },

        /**
         * Find current group field with places API by state field element.
         *
         * @since 2.0.0
         *
         * @param {object} politicalEl State field element.
         *
         * @returns {object|null} currentFieldPlace Current group field with places API.
         */
        findFieldPlaceByPolitical: function (politicalEl) {

            var currentFieldPlace = null;

            fieldsPlaces.forEach(function (el) {

                if (!el.additionalFields || !el.additionalFields.political || el.additionalFields.political.el !== politicalEl) {
                    return;
                }

                currentFieldPlace = el;
            });

            return currentFieldPlace;
        },

        /**
         * Init Google Autocomplete.
         *
         * @since 2.0.0
         *
         * @param {object} currentFieldPlace Current group field with places API.
         */
        initAutocomplete: function (currentFieldPlace) {

            currentFieldPlace.autocomplete = new google.maps.places.Autocomplete(
                currentFieldPlace.searchField,
                currentFieldPlace.settings.autocompleteSettings
            );

            currentFieldPlace.autocomplete.addListener('place_changed', app.updateFieldPlace);

            if ('address' === currentFieldPlace.type) {
                app.initAutocompleteAddress(currentFieldPlace);
            }

            if (currentFieldPlace.settings.autocompleteSettings.strict) {
                currentFieldPlace.autocomplete.setComponentRestrictions({
                    'country': currentFieldPlace.settings.autocompleteSettings.strict,
                });

                app.showDebugMessage('The #' + currentFieldPlace.searchField.getAttribute('id') + ' autocomplete field restrict to the ' + currentFieldPlace.settings.autocompleteSettings.strict.join(', ') + ' counties');
            }
        },

        /**
         * Init address field autocomplete features.
         *
         * @since 2.0.0
         *
         * @param {object} currentFieldPlace Current group field with places API.
         */
        initAutocompleteAddress: function (currentFieldPlace) {

            app.disableBrowserAutocomplete(currentFieldPlace.searchField);

            if (currentFieldPlace.additionalFields.country.el) {
                currentFieldPlace.additionalFields.country.el.addEventListener('change', app.updateCountry);
            }

            if (currentFieldPlace.additionalFields.political.el) {
                currentFieldPlace.additionalFields.political.el.addEventListener('change', app.updateArea);
            }
        },

        /**
         * Disable Chrome browser autocomplete.
         *
         * @since 2.0.0
         *
         * @param {Element} searchField Search field.
         */
        disableBrowserAutocomplete: function (searchField) {

            if (navigator.userAgent.indexOf('Chrome') === -1) {
                return;
            }

            var observerHack = new MutationObserver(function () {
                observerHack.disconnect();
                searchField.setAttribute('autocomplete', 'chrome-off');
            });

            observerHack.observe(searchField, {
                attributes: true,
                attributeFilter: ['autocomplete'],
            });
        },

        /**
         * Update field place when Google Autocomplete field fill.
         *
         * @since 2.0.0
         */
        updateFieldPlace: function () {

            var currentFieldPlace = app.findFieldPlaceByAutocomplete(this);

            if (!currentFieldPlace || !currentFieldPlace.autocomplete) {
                return;
            }

            var place = currentFieldPlace.autocomplete.getPlace();

            if (!place.geometry || !place.geometry.location) {
                return;
            }

            app.updateMap(currentFieldPlace, place.geometry.location);
            app.updateFields(currentFieldPlace, place);
        },

        /**
         * Update fields at specified place.
         *
         * @since 2.0.0
         *
         * @param {object} currentFieldPlace Current group field with places API.
         * @param {object} place Current place.
         */
        updateFields: function (currentFieldPlace, place) {

            if (!Object.prototype.hasOwnProperty.call(place, 'formatted_address')) {
                return;
            }

            if ('text' === currentFieldPlace.type) {
                app.updateTextField(currentFieldPlace, place);
            } else if ('address' === currentFieldPlace.type) {
                app.updateAddressFields(currentFieldPlace, place);
            }

            app.triggerEvent(currentFieldPlace.searchField, 'change');

            app.showDebugMessage('Fields was updated');
            app.showDebugMessage(currentFieldPlace);
            app.showDebugMessage(place);
        },

        /**
         * Update text field at specified place.
         *
         * @since 2.0.0
         *
         * @param {object} currentFieldPlace Current group field with places API.
         * @param {object} place Current place.
         */
        updateTextField: function (currentFieldPlace, place) {
            // currentFieldPlace.searchField.value = place.formatted_address;

            const res = {
                "lat": place.geometry.location.lat(),
                "lng": place.geometry.location.lng(),
                "autocomplete_search": currentFieldPlace.searchField.value,
                "placeId": place.place_id
            };

            // Nick P - 2023-05-25 - added to update the hidden field with the place_id
            if (currentFieldPlace.additionalFields.place_id.el) {
                currentFieldPlace.additionalFields.place_id.el.value = btoa(JSON.stringify(res));
            }
        },

        /**
         * Trigger JS event.
         *
         * @since 2.0.0
         *
         * @param {Element} el Element.
         * @param {string} eventName Event name.
         */
        triggerEvent: function (el, eventName) {

            var e = document.createEvent('HTMLEvents');

            e.initEvent(eventName, true, true);
            el.dispatchEvent(e);
        },

        /**
         * Update address fields at specified place.
         *
         * @since 2.0.0
         *
         * @param {object} currentFieldPlace Current group field with places API.
         * @param {object} place Current place.
         */
        updateAddressFields: function (currentFieldPlace, place) {

            var street = '',
                streetNumber = '';

            app.clearAdditionalFields(currentFieldPlace);

            for (var i = 0; i < place.address_components.length; i++) {
                var addressType = place.address_components[i].types[0];
                if ('route' === addressType) {
                    street = place.address_components[i]['short_name'];

                    continue;
                }

                if ('street_number' === addressType) {
                    streetNumber = place.address_components[i]['short_name'];

                    continue;
                }

                if (currentFieldPlace.additionalFields[addressType] && currentFieldPlace.additionalFields[addressType].el) {
                    app.updateAddressField(
                        currentFieldPlace.additionalFields[addressType].el,
                        place.address_components[i][currentFieldPlace.additionalFields[addressType].type]
                    );
                }
            }

            currentFieldPlace.searchField.value = app.formatAddressField(place, streetNumber, street);
        },

        /**
         * Update specified address field.
         *
         * @since 2.3.0
         *
         * @param {Element} field Field.
         * @param {string} value Value.
         */
        updateAddressField: function (field, value) {

            if (app.isConversationalSelect(field)) {
                app.updateConversationalSelect(field, value);

                return;
            }

            field.value = value;
        },

        /**
         * Is conversational forms select.
         *
         * @since 2.3.0
         *
         * @param {Element} field Field.
         *
         * @returns {boolean} Is the field is conversational select format?
         */
        isConversationalSelect: function (field) {

            if (field.tagName !== 'SELECT') {
                return false;
            }

            return Boolean(field.closest('.wpforms-conversational-select'));
        },

        /**
         * Update conversational forms select.
         *
         * @since 2.3.0
         *
         * @param {Element} field Field.
         * @param {string} value Value.
         */
        updateConversationalSelect: function (field, value) {

            const select = field.closest('.wpforms-conversational-select'),
                selectedOption = field.querySelector('option[value="' + value + '"]'),
                input = select.querySelector('.wpforms-conversational-form-dropdown-input input');

            field.value = value;

            if (selectedOption && input) {
                input.value = selectedOption.innerText;
            }
        },

        /**
         * Clear additional fields.
         *
         * @since 2.1.0
         *
         * @param {object} currentFieldPlace Current group field with places API.
         */
        clearAdditionalFields: function (currentFieldPlace) {

            Object.values(currentFieldPlace.additionalFields).forEach(function (field) {

                if (!field.el) {
                    return;
                }

                field.el.value = '';
            });
        },

        /**
         * Get formatted address.
         *
         * @since 2.0.0
         *
         * @param {object} place Current place.
         * @param {string} streetNumber Street number.
         * @param {string} street Street name.
         *
         * @returns {string} Formatted address.
         */
        formatAddressField: function (place, streetNumber, street) {

            var address = 0 === place.formatted_address.indexOf(streetNumber) ?
                streetNumber + ' ' + street : // US format.
                street + ', ' + streetNumber; // EU format.

            // Remove spaces and commas at the start or end of the string.
            return address.trim().replace(/,$|^,/g, '');
        },

        /**
         * Update country for address field. Conditional strict. Start work after CUSTOMER change a country field.
         *
         * @since 2.0.0
         */
        updateCountry: function () {

            var currentFieldPlace = app.findFieldPlaceByCountry(this),
                countryCode = this.value.toString().toLocaleLowerCase();

            if (!currentFieldPlace || !currentFieldPlace.autocomplete) {
                return;
            }

            currentFieldPlace.autocomplete.setComponentRestrictions({
                'country': [countryCode],
            });

            app.showDebugMessage('Autocomplete field restrict to the ' + countryCode + ' country');
        },

        /**
         * Update state for address field. Conditional not strict. Start work after CUSTOMER change a state field.
         *
         * @since 2.0.0
         */
        updateArea: function () {

            var currentFieldPlace = app.findFieldPlaceByPolitical(this),
                stateCode = this.value.toString().toUpperCase();

            if (!currentFieldPlace || !currentFieldPlace.autocomplete) {
                return;
            }

            app.showDebugMessage('Autocomplete field try to find the ' + stateCode + ' state');

            var latLng = app.findStateCoordinates(currentFieldPlace, stateCode);

            if (!latLng) {
                app.showDebugMessage('Autocomplete field doesn\'t restrict to the ' + stateCode + ' state');

                return;
            }

            currentFieldPlace.autocomplete.setBounds(new google.maps.LatLngBounds(latLng));

            app.showDebugMessage('Autocomplete field restrict to the ' + stateCode + ' state');
        },

        /**
         * Find the state coordinates.
         *
         * @since 2.3.0
         *
         * @param {object} currentFieldPlace Current group field with places API.
         * @param {string} stateCode A state code.
         *
         * @returns {object|undefined} Latitude and Longitude for the current state.
         */
        findStateCoordinates: function (currentFieldPlace, stateCode) {

            if (!currentFieldPlace.settings.autocompleteSettings.strict) {
                return;
            }

            var latLng;

            currentFieldPlace.settings.autocompleteSettings.strict.forEach(function (countryCode) {

                if (wpforms_geolocation_settings.states[countryCode] && wpforms_geolocation_settings.states[countryCode][stateCode]) {
                    latLng = {
                        'lat': wpforms_geolocation_settings.states[countryCode][stateCode].lat,
                        'lng': wpforms_geolocation_settings.states[countryCode][stateCode].lng,
                    };

                    return false;
                }
            });

            return latLng;
        },

        /**
         * Detect customer geolocation.
         *
         * @since 2.0.0
         */
        detectGeolocation: function () {

            if (!wpforms_geolocation_settings.current_location || !navigator.geolocation || !fieldsPlaces) {
                return;
            }

            var geolocation = {};

            navigator.geolocation.getCurrentPosition(function (position) {

                geolocation = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude,
                };

                app.detectPlaceByCoordinates(geolocation, function (place) {

                    fieldsPlaces.forEach(function (currentFieldPlace) {

                        const container = currentFieldPlace.searchField.closest('.wpforms-field');

                        if (container.classList.contains('wpforms-conditional-hide')) {
                            return;
                        }

                        app.updateMap(currentFieldPlace, geolocation);
                        app.updateFields(currentFieldPlace, place);
                    });
                });
            });
        },
    };

    // Provide access to public functions/properties.
    return app;

}(document, window));

// Load script if google geolocation library was included from another theme or plugin.
window.addEventListener('load', WPFormsGeolocationGooglePlacesAPI.init);

/**
 * Use function callback for running throw Google JS API.
 *
 * @since 2.0.0
 */
function WPFormsGeolocationInitGooglePlacesAPI() { // eslint-disable-line no-unused-vars

    window.removeEventListener('load', WPFormsGeolocationGooglePlacesAPI.init);
    WPFormsGeolocationGooglePlacesAPI.init();
}
