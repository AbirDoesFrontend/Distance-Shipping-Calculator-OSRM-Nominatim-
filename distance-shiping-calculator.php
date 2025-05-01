<?php
/*
Plugin Name: Distance & Shipping Calculator (Google Maps) with WooCommerce Product Integration
Plugin URI:  https://example.com/
Description: Calculates driving distance between pickup and drop-off using Google Maps API, displays responsive map, and adds as a WooCommerce product at checkout, with optional services.
Version:     1.6.3
Author:      Abir Khan
Author URI:  https://example.com/
License:     GPL2
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// Define the WooCommerce product ID used for shipping
if ( ! defined( 'DSC_SHIPPING_PRODUCT_ID' ) ) {
    define( 'DSC_SHIPPING_PRODUCT_ID', 1054 );
}

// Define your Google Maps API Key
if ( ! defined( 'DSC_GOOGLE_MAPS_API_KEY' ) ) {
    define( 'DSC_GOOGLE_MAPS_API_KEY', 'YOUR_GOOGLE_MAPS_API_KEY' );
}

// Enqueue necessary scripts & styles
add_action( 'wp_enqueue_scripts', 'dsc_enqueue_assets' );
function dsc_enqueue_assets() {
    wp_enqueue_script(
        'google-maps',
        "https://maps.googleapis.com/maps/api/js?key=" . DSC_GOOGLE_MAPS_API_KEY . "&libraries=places&callback=initMap",
        array(),
        null,
        true
    );
}

// Shortcode to render calculator
add_shortcode( 'distance_shipping_calculator_google', 'dsc_google_render_calculator' );
function dsc_google_render_calculator() {
    ob_start(); ?>
    <style>
    /* Container: two columns on desktop, single on mobile */
    #dsc-wrapper {
      display: flex;
      flex-wrap: wrap;
      margin: 0 -10px;
    }
    .dsc-col {
      padding: 0 10px;
      box-sizing: border-box;
    }
    .dsc-form-col {
      flex: 1 1 400px;
      max-width: 600px;
    }
    .dsc-map-col {
      flex: 1 1 300px;
      max-width: 100%;
      margin-top: 20px;
    }
    #dsc-map {
      width: 100%;
      height: 400px;
    }
    /* Form fields */
    .form-group {
      margin-bottom: 1rem;
    }
    .form-group label {
      display: block;
      font-weight: 600;
      margin-bottom: .5rem;
    }
    .form-group input[type="text"],
    .form-group input[type="number"] {
      width: 100%;
      padding: .5rem;
      border: 1px solid #ccc;
      border-radius: 4px;
    }
    /* Two‐column row inside form */
    .dsc-form-row {
      display: flex;
      flex-wrap: wrap;
      margin: 0 -5px;
    }
    .dsc-form-row > .form-group {
      flex: 1 1 45%;
      margin: 0 5px 1rem;
    }
    /* Buttons */
    #dsc-google-form .btn {
      display: inline-block;
      padding: .5rem 1rem;
      margin-bottom: .5rem;
      border: none;
      border-radius: 4px;
      cursor: pointer;
    }
    #calculate-btn { background: #007bff; color: #fff; margin-right: 4%; }
    #checkout-btn { background: #28a745; color: #fff; }
    @media (max-width: 767px) {
      #dsc-wrapper { flex-direction: column; }
      .dsc-form-col, .dsc-map-col { max-width: 100%; margin-top: 0; }
      #dsc-map { margin-top: 20px; height: 300px; }
      #dsc-google-form .btn { width: 100%; margin-right: 0; }
    }
    /* Spinner */
    .dsc-spinner {
      display: inline-block; width: 18px; height: 18px;
      border: 2px solid #ccc; border-top: 2px solid #333; border-radius: 50%;
      animation: spin .6s linear infinite; vertical-align: middle; margin-left: 8px;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    </style>

    <div id="dsc-wrapper">
      <div class="dsc-col dsc-form-col">
        <form id="dsc-google-form">
          <div class="form-group">
            <label for="pickup">Pickup Location</label>
            <input type="text" id="pickup" name="pickup" placeholder="Enter pickup address" required>
          </div>
          <div class="form-group">
            <label for="dropoff">Drop-off Location (Return Center)</label>
            <input type="text" id="dropoff" name="dropoff" placeholder="Enter return center address" required>
          </div>
          <div class="dsc-form-row">
            <div class="form-group">
              <label for="packages"># Packages</label>
              <input type="number" id="packages" name="packages" value="1" min="1" required>
            </div>
            <div class="form-group">
              <label for="weight">Weight per pkg (lbs)</label>
              <input type="number" id="weight" name="weight" value="1" min="0" step="any" required>
            </div>
          </div>
          <div class="form-group">
            <label for="distance_miles">Distance (miles)</label>
            <input type="text" id="distance_miles" name="distance_miles" readonly>
          </div>

          <hr>
          <p><strong>Additional Services</strong></p>
          <div class="form-group">
            <input type="checkbox" id="service_label" name="service_label">
            <label for="service_label" style="display:inline;font-weight:normal;">Label Printing (+$1/label)</label>
          </div>
          <div class="form-group">
            <input type="checkbox" id="service_packaging" name="service_packaging">
            <label for="service_packaging" style="display:inline;font-weight:normal;">Packaging (+$2/pkg)</label>
          </div>
          <div class="form-group">
            <input type="checkbox" id="service_rush" name="service_rush">
            <label for="service_rush" style="display:inline;font-weight:normal;">Rush (+$3)</label>
          </div>
          <div class="form-group">
            <input type="checkbox" id="service_bulk" name="service_bulk">
            <label for="service_bulk" style="display:inline;font-weight:normal;">Bulk (10% off ≥5 pkgs)</label>
          </div>

          <button id="calculate-btn" type="button" class="btn">Calculate</button>
          <button id="checkout-btn" type="button" class="btn" style="display:none;">Checkout</button>
        </form>
        <div id="dsc-osrm-result" style="margin-top:1rem;"></div>
      </div>

      <div class="dsc-col dsc-map-col">
        <div id="dsc-map"></div>
      </div>
    </div>

    <script>
    var map, directionsService, directionsRenderer;
    var computedFee=0, computedMiles=0, computedPkgCount=0, computedPkgWeight=0;
    function initMap() {
      map = new google.maps.Map(document.getElementById('dsc-map'), { center:{lat:25.7617,lng:-80.1918},zoom:12 });
      directionsService = new google.maps.DirectionsService();
      directionsRenderer = new google.maps.DirectionsRenderer({map:map});
      var pickupAC=new google.maps.places.Autocomplete(document.getElementById('pickup'),{componentRestrictions:{country:'us'}});
      var dropoffAC=new google.maps.places.Autocomplete(document.getElementById('dropoff'),{componentRestrictions:{country:'us'}});
      document.getElementById('calculate-btn').addEventListener('click',function(e){
        e.preventDefault();
        var pc=parseInt(document.getElementById('packages').value), pw=parseFloat(document.getElementById('weight').value);
        if(pw>50){ document.getElementById('dsc-osrm-result').innerHTML='<p style="color:red;">Packages over 50 lbs are not accepted.</p>'; return; }
        var pp=pickupAC.getPlace(), dp=dropoffAC.getPlace();
        if(!pp||!pp.geometry||!dp||!dp.geometry){ document.getElementById('dsc-osrm-result').innerHTML='<p style="color:red;">Select valid addresses.</p>'; return; }
        directionsService.route({ origin:pp.geometry.location, destination:dp.geometry.location, travelMode:'DRIVING' }, function(res,status){
          if(status==='OK'){
            directionsRenderer.setDirections(res);
            var leg=res.routes[0].legs[0], mi=leg.distance.value/1609.34;
            document.getElementById('distance_miles').value=mi.toFixed(2);
            var fee=5+mi*1.25; if(pc>1)fee+=(pc-1)*2; if(pw>25&&pw<=50)fee+=pc*3;
            computedFee=fee; computedMiles=mi; computedPkgCount=pc; computedPkgWeight=pw;
            document.getElementById('dsc-osrm-result').innerHTML='<p><strong>Distance:</strong> '+mi.toFixed(2)+' miles</p><p><strong>Fee:</strong> $'+fee.toFixed(2)+'</p>';
            document.getElementById('checkout-btn').style.display='inline-block';
          } else {
            document.getElementById('dsc-osrm-result').innerHTML='<p>No route found.</p>';
          }
        });
      });
      document.getElementById('checkout-btn').addEventListener('click',function(e){
        e.preventDefault();
        var data={ action:'dsc_set_shipping_fee', shipping_fee:computedFee.toFixed(2), distance:computedMiles.toFixed(2), packages:computedPkgCount, weight:computedPkgWeight, service_label:document.getElementById('service_label').checked?1:0, service_packaging:document.getElementById('service_packaging').checked?1:0, service_rush:document.getElementById('service_rush').checked?1:0, service_bulk:document.getElementById('service_bulk').checked?1:0 };
        jQuery.post('<?php echo esc_js(admin_url('admin-ajax.php'));?>', data).done(function(){
          jQuery('#dsc-wrapper').fadeOut('fast', function(){
            jQuery('body').append('<div style="position:fixed;top:0;left:0;width:100%;height:100%;display:flex;justify-content:center;align-items:center;background:#fff;z-index:9999;"><div><p><strong>Redirecting to checkout...</strong> <span class="dsc-spinner"></span><br><em>Please wait...</em></p></div></div>');
            setTimeout(function(){ window.location.href='<?php echo esc_js(wc_get_checkout_url());?>'; }, 1000);
          });
        });
      });
    }
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
?>
