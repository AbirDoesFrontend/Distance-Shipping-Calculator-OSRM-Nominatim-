<?php
/*
Plugin Name: Distance & Shipping Calculator (OSRM/Nominatim)
Plugin URI:  https://example.com/
Description: Provides a form to calculate driving distance between pickup and drop-off locations using Nominatim for geocoding and OSRM for routing, then computes a final shipping price based on the distance, package count, and weight.
Version:     1.2
Author:      Your Name
Author URI:  https://example.com/
License:     GPL2
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Register the shortcode [distance_shipping_calculator_osrm]
add_shortcode('distance_shipping_calculator_osrm', 'dsc_osrm_render_calculator');

function dsc_osrm_render_calculator() {
    ob_start(); ?>
    <form id="dsc-osrm-form" style="max-width:400px;">
        <p>
            <label for="pickup">Pickup Location:</label><br>
            <input type="text" id="pickup" name="pickup" placeholder="Enter pickup address" required style="width:100%;">
        </p>
        <p>
            <label for="dropoff">Drop-off Location:</label><br>
            <input type="text" id="dropoff" name="dropoff" placeholder="Enter drop-off address" required style="width:100%;">
        </p>
        <p>
            <label for="packages">Number of Packages:</label><br>
            <input type="number" id="packages" name="packages" value="1" min="1" required style="width:100%;">
        </p>
        <p>
            <label for="weight">Weight per Package (lbs):</label><br>
            <input type="number" id="weight" name="weight" value="0" min="0" step="any" required style="width:100%;">
        </p>
        <p>
            <label for="distance_miles">Distance (miles):</label><br>
            <input type="text" id="distance_miles" name="distance_miles" readonly style="width:100%; background:#f9f9f9;">
        </p>
        <p>
            <button type="submit">Calculate Shipping Price</button>
        </p>
    </form>
    <div id="dsc-osrm-result" style="margin-top:20px;"></div>
    
    <!-- Include jQuery and jQuery UI CSS/JS for autocomplete functionality -->
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
    
    <script type="text/javascript">
    (function($) {
        // Function to query suggestions from Nominatim
        function fetchSuggestions(request, responseCallback) {
            $.ajax({
                url: "https://nominatim.openstreetmap.org/search",
                data: {
                    format: "json",
                    q: request.term,
                    addressdetails: 1,
                    limit: 5
                },
                dataType: 'json',
                headers: {
                    'Accept-Language': 'en'
                },
                success: function(data) {
                    var suggestions = $.map(data, function(item) {
                        return {
                            label: item.display_name,
                            value: item.display_name,
                            lat: item.lat,
                            lon: item.lon
                        };
                    });
                    responseCallback(suggestions);
                },
                error: function() {
                    responseCallback([]);
                }
            });
        }
    
        // Initialize autocomplete for pickup and dropoff fields
        $("#pickup, #dropoff").autocomplete({
            source: fetchSuggestions,
            minLength: 3,
            select: function(event, ui) {
                // Store the latitude & longitude values on selection for later use.
                $(this).data("lat", ui.item.lat);
                $(this).data("lon", ui.item.lon);
            }
        });
    
        // Function to geocode an address using Nominatim (fallback when form is submitted)
        function geocodeAddress(address) {
            return $.ajax({
                url: "https://nominatim.openstreetmap.org/search",
                data: {
                    format: "json",
                    q: address,
                    limit: 1 // use the first result
                },
                dataType: 'json',
                headers: {
                    'Accept-Language': 'en'
                }
            });
        }
    
        // Function to get route distance using OSRM
        function getRouteDistance(pickupCoords, dropoffCoords) {
            // OSRM expects coordinates in the order: longitude,latitude
            var url = 'https://router.project-osrm.org/route/v1/driving/' +
                      pickupCoords.lon + ',' + pickupCoords.lat + ';' +
                      dropoffCoords.lon + ',' + dropoffCoords.lat +
                      '?overview=false';
    
            return $.ajax({
                url: url,
                dataType: 'json'
            });
        }
    
        $('#dsc-osrm-form').on('submit', function(e) {
            e.preventDefault();
            var pickup = $('#pickup').val();
            var dropoff = $('#dropoff').val();
            var packages = parseFloat($('#packages').val());
            var weight = parseFloat($('#weight').val());
    
            $('#dsc-osrm-result').html('<p>Calculating...</p>');
    
            // Check if the autocomplete provided latitude/longitude values
            var pickupLat = $('#pickup').data("lat");
            var pickupLon = $('#pickup').data("lon");
            var dropoffLat = $('#dropoff').data("lat");
            var dropoffLon = $('#dropoff').data("lon");
    
            // Function to continue with the calculated coordinates
            function continueWithCoordinates(pickupCoords, dropoffCoords) {
                $.when( getRouteDistance(pickupCoords, dropoffCoords) ).done(function(routeData) {
                    if (routeData.routes && routeData.routes.length > 0) {
                        var distanceMeters = routeData.routes[0].distance;
                        var miles = distanceMeters / 1609.34;
    
                        // Update read-only input field with calculated miles
                        $('#distance_miles').val(miles.toFixed(2));
    
                        // Example pricing formula:
                        // Base rate: $1 per mile
                        // Surcharge: $2 per package
                        // Weight factor: $0.50 per lb per package
                        var price = (miles * 1) + (packages * 2) + (packages * weight * 0.5);
    
                        $('#dsc-osrm-result').html(
                            '<p><strong>Distance:</strong> ' + miles.toFixed(2) + ' miles</p>' +
                            '<p><strong>Final Price:</strong> $' + price.toFixed(2) + '</p>'
                        );
                    } else {
                        $('#dsc-osrm-result').html('<p>Could not calculate the route distance. Please try again.</p>');
                    }
                }).fail(function() {
                    $('#dsc-osrm-result').html('<p>Error retrieving the route from OSRM.</p>');
                });
            }
    
            // Use the stored latitude/longitude if available; otherwise, fallback to geocoding
            if (pickupLat && pickupLon && dropoffLat && dropoffLon) {
                var pickupCoords = { lat: pickupLat, lon: pickupLon };
                var dropoffCoords = { lat: dropoffLat, lon: dropoffLon };
                continueWithCoordinates(pickupCoords, dropoffCoords);
            } else {
                $.when( geocodeAddress(pickup), geocodeAddress(dropoff) ).done(function(pickupData, dropoffData) {
                    if (!pickupData[0] || !dropoffData[0] || pickupData[0].length === 0 || dropoffData[0].length === 0) {
                        $('#dsc-osrm-result').html('<p>Could not geocode one or both addresses. Please check your input.</p>');
                        return;
                    }
    
                    var pickupCoords = {
                        lat: pickupData[0][0].lat,
                        lon: pickupData[0][0].lon
                    };
                    var dropoffCoords = {
                        lat: dropoffData[0][0].lat,
                        lon: dropoffData[0][0].lon
                    };
    
                    continueWithCoordinates(pickupCoords, dropoffCoords);
                }).fail(function() {
                    $('#dsc-osrm-result').html('<p>Error geocoding the addresses.</p>');
                });
            }
        });
    })(jQuery);
    </script>
    <?php
    return ob_get_clean();
}
?>