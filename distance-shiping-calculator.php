<?php
/*
Plugin Name: Distance & Shipping Calculator (OSRM/Nominatim) with WooCommerce Integration
Plugin URI:  https://example.com/
Description: Provides a form to calculate driving distance between pickup and drop-off locations using Nominatim for geocoding and OSRM for routing, then computes a final shipping price. Also integrates as a custom WooCommerce shipping method.
Version:     1.1.2
Author:      Abir Khan
Author URI:  https://example.com/
License:     GPL2
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/* ---------------------------------------------------------------------------
   Section 1: Shortcode for Frontend Distance & Shipping Calculator
--------------------------------------------------------------------------- */
// Register the shortcode [distance_shipping_calculator_osrm]
add_shortcode('distance_shipping_calculator_osrm', 'dsc_osrm_render_calculator');

function dsc_osrm_render_calculator()
{
    ob_start(); ?>
    <form id="dsc-osrm-form" style="max-width:400px;">
        <p>
            <label for="pickup">Pickup Location:</label><br>
            <input type="text" id="pickup" name="pickup" placeholder="Enter pickup address" required style="width:100%;">
        </p>
        <p>
            <label for="dropoff">Drop-off Location:</label><br>
            <input type="text" id="dropoff" name="dropoff" placeholder="Enter drop-off address" required
                style="width:100%;">
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
        (function ($) {
            // Function to query suggestions from Nominatim (restricted to USA)
            function fetchSuggestions(request, responseCallback) {
                $.ajax({
                    url: "https://nominatim.openstreetmap.org/search",
                    data: {
                        format: "json",
                        q: request.term,
                        addressdetails: 1,
                        limit: 5,
                        countrycodes: "us" // restrict to USA
                    },
                    dataType: 'json',
                    headers: {
                        'Accept-Language': 'en'
                    },
                    success: function (data) {
                        var suggestions = $.map(data, function (item) {
                            return {
                                label: item.display_name,
                                value: item.display_name,
                                lat: item.lat,
                                lon: item.lon
                            };
                        });
                        responseCallback(suggestions);
                    },
                    error: function () {
                        responseCallback([]);
                    }
                });
            }

            // Initialize autocomplete for pickup and dropoff fields
            $("#pickup, #dropoff").autocomplete({
                source: fetchSuggestions,
                minLength: 3,
                select: function (event, ui) {
                    // Store the latitude & longitude values on selection for later use.
                    $(this).data("lat", ui.item.lat);
                    $(this).data("lon", ui.item.lon);
                }
            });

            // Function to geocode an address using Nominatim (fallback when form is submitted, restricted to USA)
            function geocodeAddress(address) {
                return $.ajax({
                    url: "https://nominatim.openstreetmap.org/search",
                    data: {
                        format: "json",
                        q: address,
                        limit: 1, // use the first result
                        countrycodes: "us" // restrict to USA
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

            $('#dsc-osrm-form').on('submit', function (e) {
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
                    $.when(getRouteDistance(pickupCoords, dropoffCoords)).done(function (routeData) {
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
                    }).fail(function () {
                        $('#dsc-osrm-result').html('<p>Error retrieving the route from OSRM.</p>');
                    });
                }

                // Use the stored latitude/longitude if available; otherwise, fallback to geocoding
                if (pickupLat && pickupLon && dropoffLat && dropoffLon) {
                    var pickupCoords = { lat: pickupLat, lon: pickupLon };
                    var dropoffCoords = { lat: dropoffLat, lon: dropoffLon };
                    continueWithCoordinates(pickupCoords, dropoffCoords);
                } else {
                    $.when(geocodeAddress(pickup), geocodeAddress(dropoff)).done(function (pickupData, dropoffData) {
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
                    }).fail(function () {
                        $('#dsc-osrm-result').html('<p>Error geocoding the addresses.</p>');
                    });
                }
            });
        })(jQuery);
    </script>
    <?php
    return ob_get_clean();
}

/* ---------------------------------------------------------------------------
   Section 2: WooCommerce Shipping Method Integration
--------------------------------------------------------------------------- */
// Ensure WooCommerce is active before adding our shipping method.
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    // Initialize our custom shipping method after WooCommerce has loaded.
    add_action('woocommerce_shipping_init', 'dsc_osrm_wc_shipping_method_init');
    function dsc_osrm_wc_shipping_method_init()
    {
        if (!class_exists('WC_Distance_Shipping_Method')) {
            class WC_Distance_Shipping_Method extends WC_Shipping_Method
            {

                /**
                 * Constructor.
                 *
                 * @param int $instance_id Instance id.
                 */
                public function __construct($instance_id = 0)
                {
                    $this->id = 'distance_shipping';
                    $this->instance_id = absint($instance_id);
                    $this->method_title = __('Distance Shipping', 'text-domain');
                    $this->method_description = __('Calculates shipping cost based on driving distance using OSRM/Nominatim.', 'text-domain');

                    // Limit this method to US addresses.
                    $this->availability = 'including';
                    $this->countries = array('US');

                    // Initialize settings.
                    $this->init();
                }

                /**
                 * Initialize settings form fields and settings.
                 */
                public function init()
                {
                    // Define settings fields.
                    $this->init_form_fields();
                    $this->init_settings();

                    // Load settings.
                    $this->title = $this->get_option('title');
                    $this->origin_address = $this->get_option('origin_address');
                    $this->base_rate = floatval($this->get_option('base_rate', 1));
                    $this->package_surcharge = floatval($this->get_option('package_surcharge', 2));
                    $this->weight_factor = floatval($this->get_option('weight_factor', 0.5));

                    // Save settings.
                    add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
                }

                /**
                 * Define settings fields for this shipping method.
                 */
                public function init_form_fields()
                {
                    $this->form_fields = array(
                        'title' => array(
                            'title' => __('Method Title', 'text-domain'),
                            'type' => 'text',
                            'description' => __('Title to be displayed on the checkout page.', 'text-domain'),
                            'default' => __('Distance Shipping', 'text-domain'),
                        ),
                        'origin_address' => array(
                            'title' => __('Origin Address', 'text-domain'),
                            'type' => 'text',
                            'description' => __('Enter the shipping origin address (USA only).', 'text-domain'),
                            'default' => '',
                        ),
                        'base_rate' => array(
                            'title' => __('Base Rate per Mile', 'text-domain'),
                            'type' => 'number',
                            'description' => __('Cost per mile.', 'text-domain'),
                            'default' => '1',
                            'desc_tip' => true,
                        ),
                        'package_surcharge' => array(
                            'title' => __('Surcharge per Package', 'text-domain'),
                            'type' => 'number',
                            'description' => __('Additional cost per package.', 'text-domain'),
                            'default' => '2',
                            'desc_tip' => true,
                        ),
                        'weight_factor' => array(
                            'title' => __('Cost per lb', 'text-domain'),
                            'type' => 'number',
                            'description' => __('Cost per pound of the total package weight.', 'text-domain'),
                            'default' => '0.5',
                            'desc_tip' => true,
                        ),
                    );
                }

                /**
                 * Calculate shipping cost.
                 *
                 * @param array $package Package details.
                 */
                public function calculate_shipping($package = array())
                {
                    // Retrieve the destination address from $package.
                    $destination = $package['destination'];
                    $shipping_to = trim($destination['address'] . ' ' . $destination['city'] . ' ' . $destination['state'] . ' ' . $destination['postcode'] . ' ' . $destination['country']);

                    // Check if origin address and destination are provided.
                    if (empty($this->origin_address) || empty($shipping_to)) {
                        return;
                    }

                    // Geocode the origin and destination addresses.
                    $origin_coords = $this->geocode_address($this->origin_address);
                    $dest_coords = $this->geocode_address($shipping_to);

                    if (!$origin_coords || !$dest_coords) {
                        return;
                    }

                    // Retrieve the route distance in meters using OSRM.
                    $distance_meters = $this->get_route_distance($origin_coords, $dest_coords);
                    if (!$distance_meters) {
                        return;
                    }

                    // Convert distance to miles.
                    $miles = $distance_meters / 1609.34;

                    // Determine package count and total weight from the package contents.
                    $num_packages = count($package['contents']);
                    $total_weight = 0;
                    foreach ($package['contents'] as $item) {
                        $total_weight += $item['data']->get_weight() * $item['quantity'];
                    }

                    // Calculate shipping cost.
                    // Formula: (miles * base_rate) + (num_packages * package_surcharge) + (total_weight * weight_factor)
                    $cost = ($miles * $this->base_rate) + ($num_packages * $this->package_surcharge) + ($total_weight * $this->weight_factor);

                    // Register the rate with WooCommerce.
                    $rate = array(
                        'id' => $this->id,
                        'label' => $this->title,
                        'cost' => $cost,
                        'calc_tax' => 'per_item',
                    );

                    $this->add_rate($rate);
                }

                /**
                 * Geocode an address using Nominatim (USA only).
                 *
                 * @param string $address The address to geocode.
                 * @return array|false Returns an array with 'lat' and 'lon' or false on failure.
                 */
                private function geocode_address($address)
                {
                    $url = add_query_arg(array(
                        'format' => 'json',
                        'q' => $address,
                        'limit' => 1,
                        'countrycodes' => 'us', // Restrict results to USA.
                    ), 'https://nominatim.openstreetmap.org/search');

                    $response = wp_remote_get($url);
                    if (is_wp_error($response)) {
                        return false;
                    }
                    $body = wp_remote_retrieve_body($response);
                    $data = json_decode($body, true);
                    if (isset($data[0])) {
                        return array(
                            'lat' => $data[0]['lat'],
                            'lon' => $data[0]['lon'],
                        );
                    }
                    return false;
                }

                /**
                 * Get route distance between two coordinates using OSRM.
                 *
                 * @param array $origin_coords Array containing 'lat' and 'lon' for origin.
                 * @param array $dest_coords   Array containing 'lat' and 'lon' for destination.
                 * @return float|false Returns the distance in meters or false on failure.
                 */
                private function get_route_distance($origin_coords, $dest_coords)
                {
                    $url = 'https://router.project-osrm.org/route/v1/driving/' .
                        $origin_coords['lon'] . ',' . $origin_coords['lat'] . ';' .
                        $dest_coords['lon'] . ',' . $dest_coords['lat'] .
                        '?overview=false';

                    $response = wp_remote_get($url);
                    if (is_wp_error($response)) {
                        return false;
                    }
                    $body = wp_remote_retrieve_body($response);
                    $data = json_decode($body, true);
                    if (isset($data['routes'][0])) {
                        return floatval($data['routes'][0]['distance']);
                    }
                    return false;
                }
            }
        }
    }

    /**
     * Register the custom shipping method with WooCommerce.
     */
    add_filter('woocommerce_shipping_methods', 'dsc_osrm_add_shipping_method');
    function dsc_osrm_add_shipping_method($methods)
    {
        $methods['distance_shipping'] = 'WC_Distance_Shipping_Method';
        return $methods;
    }
}
?>