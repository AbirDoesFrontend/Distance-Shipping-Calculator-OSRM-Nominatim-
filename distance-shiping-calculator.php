<?php
/*
Plugin Name: Distance & Shipping Calculator (OSRM/Nominatim) with WooCommerce Product Integration
Plugin URI:  https://example.com/
Description: Calculates driving distance between pickup and drop-off using Nominatim + OSRM, displays map, and adds as a WooCommerce product at checkout, with optional services.
Version:     1.5.2
Author:      Abir Khan
Author URI:  https://example.com/
License:     GPL2
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// Define the WooCommerce product ID used for shipping
if ( ! defined( 'DSC_SHIPPING_PRODUCT_ID' ) ) {
    define( 'DSC_SHIPPING_PRODUCT_ID', 1054 );
}

// Enqueue necessary scripts & styles
add_action( 'wp_enqueue_scripts', 'dsc_enqueue_assets' );
function dsc_enqueue_assets() {
    wp_enqueue_script( 'jquery-ui-autocomplete' );
    wp_enqueue_style( 'dsc-jquery-ui-css',
        'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css',
        [], '1.13.2'
    );
    wp_enqueue_style( 'leaflet-css',
        'https://unpkg.com/leaflet@1.9.3/dist/leaflet.css',
        [], '1.9.3'
    );
    wp_enqueue_script( 'leaflet-js',
        'https://unpkg.com/leaflet@1.9.3/dist/leaflet.js',
        [], '1.9.3', true
    );
}

// Shortcode: [distance_shipping_calculator_osrm]
add_shortcode( 'distance_shipping_calculator_osrm', 'dsc_osrm_render_calculator' );
function dsc_osrm_render_calculator() {
    ob_start(); ?>
    <style>
      /* Simple spinner CSS */
      .dsc-spinner {
        display: inline-block;
        width: 18px;
        height: 18px;
        border: 2px solid #ccc;
        border-top: 2px solid #333;
        border-radius: 50%;
        animation: spin 0.6s linear infinite;
        vertical-align: middle;
        margin-left: 8px;
      }
      @keyframes spin {
        to { transform: rotate(360deg); }
      }
    </style>
    <!-- Wrapper container for the form and map -->
    <div id="dsc-wrapper">
      <div class="form-div">
        <form id="dsc-osrm-form">
          <p>
            <label for="pickup">Pickup Location:</label><br>
            <input type="text" id="pickup" name="pickup" placeholder="Enter pickup address" required style="width:100%; padding:8px;">
          </p>
          <p>
            <label for="dropoff">Drop-off Location (Return Center):</label><br>
            <input type="text" id="dropoff" name="dropoff" placeholder="Enter return center address" required style="width:100%; padding:8px;">
          </p>
          <p>
            <label for="packages">Number of Packages:</label><br>
            <input type="number" id="packages" name="packages" value="1" min="1" required style="width:100%; padding:8px;">
          </p>
          <p>
            <label for="weight">Weight per Package (lbs):</label><br>
            <input type="number" id="weight" name="weight" value="1" min="0" step="any" required style="width:100%; padding:8px;">
          </p>
          <p>
            <label for="distance_miles">Distance (miles) will auto calculate:</label><br>
            <input type="text" id="distance_miles" name="distance_miles" readonly style="width:100%; background:#f9f9f9; padding:8px;">
          </p>
          <hr>
          <div id="additional-services">
            <p><strong>Additional Services</strong></p>
            <p>
              <label>
                <input type="checkbox" id="service_label" name="service_label"> Label Printing (+$1 per label)
              </label>
            </p>
            <p>
              <label>
                <input type="checkbox" id="service_packaging" name="service_packaging"> Packaging Service (+$2 per package)
              </label>
            </p>
            <p>
              <label>
                <input type="checkbox" id="service_rush" name="service_rush"> Rush Service (+$3)
              </label>
            </p>
            <p>
              <label>
                <input type="checkbox" id="service_bulk" name="service_bulk"> Bulk Discount (10% off for 5+ packages)
              </label>
            </p>
          </div>
          <hr>
          <!-- Two buttons: one to calculate and one to checkout -->
          <p>
            <button id="calculate-btn" type="button" style="padding:10px 20px; font-size:16px;">Calculate</button>
            <button id="checkout-btn" type="button" style="padding:10px 20px; font-size:16px; display:none; margin-left:10px;">Checkout</button>
          </p>
        </form>
        <div id="dsc-osrm-result" style="margin-top:20px;"></div>
      </div>
      <div id="dsc-map"></div>
    </div>

    <script type="text/javascript">
    (function($){
      $(function(){
        console.log('DSC plugin script loaded.');

        var params = {
          ajax_url: '<?php echo esc_js( admin_url('admin-ajax.php') ); ?>',
          checkout_url: '<?php echo esc_js( wc_get_checkout_url() ); ?>'
        };
        var map, pickupMarker, dropoffMarker, routeLayer;
        // Global variables to store computed values
        var computedFee = 0, computedMiles = 0, computedPkgCount = 0, computedPkgWeight = 0;

        // Initialize map with default view (centered on Miami)
        map = L.map('dsc-map').setView([25.7617, -80.1918], 12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        function fetchSuggestions(req, resp) {
          $.ajax({
            url: 'https://nominatim.openstreetmap.org/search',
            data: { format: 'json', q: req.term, addressdetails: 1, limit: 5 },
            dataType: 'json',
            success: function(data) {
              resp($.map(data, function(item){
                return {
                  label: item.display_name,
                  value: item.display_name,
                  lat: item.lat,
                  lon: item.lon
                };
              }));
            },
            error: function(){
              console.error('Error fetching suggestions.');
              resp([]);
            }
          });
        }

        $('#pickup,#dropoff').autocomplete({
          source: fetchSuggestions,
          minLength: 3,
          select: function(e, ui){
            $(this).data('lat', ui.item.lat).data('lon', ui.item.lon);
            renderMap();
          }
        });

        function renderMap(){
          var pLat = +$('#pickup').data('lat'),
              pLon = +$('#pickup').data('lon'),
              dLat = +$('#dropoff').data('lat'),
              dLon = +$('#dropoff').data('lon');
          if (pLat && pLon && dLat && dLon) {
            var url = `https://router.project-osrm.org/route/v1/driving/${pLon},${pLat};${dLon},${dLat}?overview=full&geometries=geojson`;
            $.getJSON(url, function(r){
              var coords = r.routes[0].geometry.coordinates.map(function(c){ return [c[1], c[0]]; });
              if(routeLayer) { map.removeLayer(routeLayer); }
              if(pickupMarker) { map.removeLayer(pickupMarker); }
              if(dropoffMarker) { map.removeLayer(dropoffMarker); }
              map.fitBounds(coords);
              pickupMarker = L.marker([pLat, pLon]).addTo(map);
              dropoffMarker = L.marker([dLat, dLon]).addTo(map);
              routeLayer = L.geoJSON(r.routes[0].geometry).addTo(map);
            }).fail(function(jqXHR, textStatus, errorThrown) {
              console.error('Error rendering map route:', textStatus, errorThrown);
            });
          }
        }

        function getRoute(p, d) {
          console.log('Getting route for:', p, d);
          return $.ajax({
            url: `https://router.project-osrm.org/route/v1/driving/${p.lon},${p.lat};${d.lon},${d.lat}?overview=false`,
            dataType: 'json'
          });
        }

        function geocode(q) {
          return $.ajax({
            url: 'https://nominatim.openstreetmap.org/search',
            data: { format:'json', q: q, limit:1 },
            dataType: 'json'
          });
        }

        // Calculate button: perform route calculation and pricing
        $('#calculate-btn').on('click', function(e){
          e.preventDefault();
          console.log('Calculate button clicked.');
          var pkgCount = +$('#packages').val(),
              pkgWeight = +$('#weight').val();

          // Validate the package weight
          if (pkgWeight > 50) {
            $('#dsc-osrm-result').html('<p style="color:red;">Packages over 50 lbs are not accepted.</p>');
            return;
          }

          $('#dsc-osrm-result').html('<p>Calculating...</p>');

          var p = { lat: $('#pickup').data('lat'), lon: $('#pickup').data('lon') },
              d = { lat: $('#dropoff').data('lat'), lon: $('#dropoff').data('lon') };

          function compute(pCoords, dCoords) {
            console.log('Computing route with coords:', pCoords, dCoords);
            $.when(getRoute(pCoords, dCoords)).done(function(r) {
              if (r.routes && r.routes.length) {
                var miles = r.routes[0].distance / 1609.34;
                $('#distance_miles').val(miles.toFixed(2));

                // Dynamic Pricing Model:
                // 1️⃣ Base Fee: $5
                var fee = 5;
                // 2️⃣ Distance-Based Fee: $1.25 per mile
                fee += miles * 1.25;
                // 3️⃣ Multiple Package Fee: first package included, each additional package costs $2
                if (pkgCount > 1) {
                  fee += (pkgCount - 1) * 2;
                }
                // 4️⃣ Weight-Based Fee: if weight is between 26-50 lbs, add $3 per package
                if (pkgWeight > 25 && pkgWeight <= 50) {
                  fee += pkgCount * 3;
                }

                // Save computed values in global variables for later checkout
                computedFee = fee;
                computedMiles = miles;
                computedPkgCount = pkgCount;
                computedPkgWeight = pkgWeight;

                console.log('Route computed: ', { miles: miles.toFixed(2), fee: fee.toFixed(2) });
                $('#dsc-osrm-result').html(
                  `<p><strong>Distance:</strong> ${miles.toFixed(2)} miles</p>
                   <p><strong>Fee:</strong> $${fee.toFixed(2)}</p>`
                );
                // Show the Checkout button once calculation is done
                $('#checkout-btn').fadeIn('fast');
              } else {
                $('#dsc-osrm-result').html('<p>No route found.</p>');
                console.error('No route found in response:', r);
              }
            }).fail(function(jqXHR, textStatus, errorThrown){
              console.error('Routing error:', textStatus, errorThrown);
              $('#dsc-osrm-result').html('<p>Please Enter Correct Address & Try again.</p>');
            });
          }

          if (p.lat && p.lon && d.lat && d.lon) {
            compute(p, d);
          } else {
            $.when(geocode($('#pickup').val()), geocode($('#dropoff').val()))
             .done(function(pa, da){
               compute(
                 { lat: pa[0].lat, lon: pa[0].lon },
                 { lat: da[0].lat, lon: da[0].lon }
               );
             });
          }
        });

        // Checkout button: send data to server and redirect
        $('#checkout-btn').on('click', function(e){
          e.preventDefault();
          console.log('Checkout button clicked.');
          // Use the computed values; additional services are taken from checkbox states
          $.post(params.ajax_url, {
            action:            'dsc_set_shipping_fee',
            shipping_fee:      computedFee.toFixed(2),
            distance:          computedMiles.toFixed(2),
            packages:          computedPkgCount,
            weight:            computedPkgWeight,
            service_label:     $('#service_label').is(':checked') ? 1 : 0,
            service_packaging: $('#service_packaging').is(':checked') ? 1 : 0,
            service_rush:      $('#service_rush').is(':checked') ? 1 : 0,
            service_bulk:      $('#service_bulk').is(':checked') ? 1 : 0
          }).done(function(response) {
            console.log('AJAX success. Response:', response);
            // Hide the wrapper containing the form and map
            $('#dsc-wrapper').fadeOut('fast', function(){
              // Append a full-screen loading overlay
              var loadingHtml = '<div id="dsc-loading" style="position:fixed; top:0; left:0; width:100%; height:100%; display:flex; justify-content:center; align-items:center; background:#fff; z-index:9999;">' +
                '<div><p><strong>Redirecting to checkout...</strong> <span class="dsc-spinner"></span><br><em>Please wait...</em></p></div>' +
                '</div>';
              $('body').append(loadingHtml);
              setTimeout(function() {
                window.location.href = params.checkout_url;
              }, 1000);
            });
          }).fail(function(jqXHR, textStatus, errorThrown) {
            console.error('AJAX post error:', textStatus, errorThrown);
            $('#dsc-osrm-result').html('<p>Error setting shipping fee. Please try again.</p>');
          });
        });

      });
    })(jQuery);
    </script>
    <?php
    return ob_get_clean();
}

// AJAX handler: add Distance Shipping product
add_action( 'wp_ajax_dsc_set_shipping_fee', 'dsc_set_shipping_fee' );
add_action( 'wp_ajax_nopriv_dsc_set_shipping_fee', 'dsc_set_shipping_fee' );
function dsc_set_shipping_fee() {
    if ( ! isset( $_POST['shipping_fee'] ) || ! WC()->cart ) {
        wp_send_json_error( 'Invalid request.' );
    }
    $fee      = floatval( $_POST['shipping_fee'] );
    $distance = floatval( $_POST['distance'] ?? 0 );
    $packages = intval( $_POST['packages'] ?? 0 );
    $weight   = floatval( $_POST['weight'] ?? 0 );
    $services = [];
    if ( ! empty( $_POST['service_label'] ) )     { $services['Label Printing']   = $packages * 1; }
    if ( ! empty( $_POST['service_packaging'] ) ) { $services['Packaging Service'] = $packages * 2; }
    if ( ! empty( $_POST['service_rush'] ) )      { $services['Rush Service']      = 3; }
    if ( ! empty( $_POST['service_bulk'] ) )      { $services['Bulk Discount']     = '-'; }

    // Remove existing DSC items
    foreach ( WC()->cart->get_cart() as $key => $item ) {
        if ( ! empty( $item['is_dsc_product'] ) ) {
            WC()->cart->remove_cart_item( $key );
        }
    }

    // Add new DSC item
    $cart_key = WC()->cart->add_to_cart(
        DSC_SHIPPING_PRODUCT_ID,
        1,
        0,
        [],
        [
            'is_dsc_product'  => true,
            'distance_fee'    => $fee,
            'distance_miles'  => $distance,
            'packages'        => $packages,
            'weight'          => $weight,
            'services'        => $services,
        ]
    );

    if ( $cart_key ) {
        WC()->cart->calculate_totals();
        wp_send_json_success( [ 'checkout_url' => wc_get_checkout_url() ] );
    }

    wp_send_json_error( 'Could not add distance-shipping product.' );
}

// Override price & title before totals
add_action( 'woocommerce_before_calculate_totals', 'dsc_set_custom_price', 20 );
function dsc_set_custom_price( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    foreach ( $cart->get_cart() as $item ) {
        if ( ! empty( $item['is_dsc_product'] ) ) {
            $item['data']->set_price( $item['distance_fee'] );
            $item['data']->set_name(
                sprintf( '%s (%.2f miles)', $item['data']->get_name(), $item['distance_miles'] )
            );
        }
    }
}

// Display item data in cart & checkout
add_filter( 'woocommerce_get_item_data', 'dsc_display_item_data', 10, 2 );
function dsc_display_item_data( $data, $item ) {
    if ( ! empty( $item['is_dsc_product'] ) ) {
        $data[] = [ 'key' => 'Distance', 'value' => $item['distance_miles'] . ' miles' ];
        $data[] = [ 'key' => 'Packages', 'value' => $item['packages'] ];
        $data[] = [ 'key' => 'Weight per package', 'value' => $item['weight'] . ' lbs' ];
        if ( ! empty( $item['services'] ) ) {
            foreach ( $item['services'] as $svc => $amt ) {
                $value = ( $amt === '-' ) ? '10% off applied' : '$' . number_format( $amt, 2 );
                $data[] = [ 'key' => $svc, 'value' => $value ];
            }
        }
    }
    return $data;
}

// Save item meta to order
add_action( 'woocommerce_checkout_create_order_line_item', 'dsc_add_order_item_meta', 10, 4 );
function dsc_add_order_item_meta( $item, $cart_key, $values, $order ) {
    if ( ! empty( $values['is_dsc_product'] ) ) {
        $item->add_meta_data( 'Distance',           $values['distance_miles'] . ' miles', true );
        $item->add_meta_data( 'Packages',           $values['packages'],               true );
        $item->add_meta_data( 'Weight per package', $values['weight']   . ' lbs',      true );
        if ( ! empty( $values['services'] ) ) {
            foreach ( $values['services'] as $svc => $amt ) {
                $label = ( $amt === '-' ) ? '10% off applied' : '$' . number_format( $amt, 2 );
                $item->add_meta_data( $svc, $label, true );
            }
        }
    }
}
