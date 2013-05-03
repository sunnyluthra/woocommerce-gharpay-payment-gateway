<?php
/*
Plugin Name: WooCommerce GharPay gateway
Plugin URI: http://www.mrova.com/
Description: Extends WooCommerce with mrova GharPay Payment gateway.
Version: 1.1
Author: mRova
Author URI: http://www.mrova.com/

    Copyright: © 2009-2013 mRova.
    License: GNU General Public License v3.0
    License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

add_action( 'plugins_loaded', 'woocommerce_mrova_gharpay_init', 0 );

function woocommerce_mrova_gharpay_init() {

  if ( !class_exists( 'WC_Payment_Gateway' ) ) return;

  /**
   * Localisation
   */
  load_plugin_textdomain( 'wc-mrova-gharpay', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

  //Include gharpay api class
  require_once dirname( __FILE__ ) . '/gharpay/GharpayAPI.php';



  /**
   * Gateway class
   */
  class WC_Mrova_GharPay extends WC_Payment_Gateway {
    protected $msg = array();
    public function __construct() {
      // Go wild in here
      $this -> id = 'gharpay';
      $this -> method_title = __( 'GharPay', 'mrova' );

      //$this -> icon = WP_PLUGIN_URL . "/" . plugin_basename( dirname( __FILE__ ) ) . '/images/logo.gif';
      $this -> has_fields = false;

      $this -> init_form_fields();
      $this -> init_settings();

      $this -> title        = $this -> get_option('title');
      $this -> description  = $this -> get_option('description');
      $this -> username     = $this -> get_option('username');
      $this -> password     = $this -> get_option('password');
      $this -> instructions = $this -> get_option('instructions');
      $this -> service_url  = 'http://services.gharpay.in';

      //init Gharpay Object
      $this -> gharpay = new GharpayAPI();
      $this -> gharpay -> setUsername( $this->username );
      $this -> gharpay -> setPassword( $this->password );
      $this -> gharpay -> setUrl( $this->service_url );

      add_action( 'init', array( &$this, 'check_gharpay_response' ) );

      //update for woocommerce >2.0
      add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'check_gharpay_response' ) );

      if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
      } else {
        add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
      }
      add_action( 'woocommerce_thankyou_gharpay', array( &$this, 'thankyou_page' ) );
    }

    function init_form_fields() {
      $this -> form_fields = array(
        'enabled' => array(
          'title' => __( 'Enable/Disable', 'mrova' ),
          'type' => 'checkbox',
          'label' => __( 'Enable GhayPay Payment Module.', 'mrova' ),
          'default' => 'no' ),
        'title' => array(
          'title' => __( 'Title:', 'mrova' ),
          'type'=> 'text',
          'description' => __( 'This controls the title which the user sees during checkout.', 'mrova' ),
          'default' => __( 'GharPay - Cash Before Delivery', 'mrova' ) ),
        'description' => array(
          'title' => __( 'Description:', 'mrova' ),
          'type' => 'textarea',
          'description' => __( 'This controls the description which the user sees during checkout.', 'mrova' ),
          'default' => __( 'Gharpay is a doorstep payment network with a human face.', 'mrova' ) ),
        'username' => array(
          'title' => __( 'Api Key', 'mrova' ),
          'type' => 'text',
          'description' => __( 'Enter the Api Key provided by gharpay.in', 'mrova' ) ),
        'password' => array(
          'title' => __( 'Api Secret', 'mrova' ),
          'type' => 'text',
          'description' =>  __( 'Enter your gharpay.in Api Secret.', 'mrova' ),
        ),
        'instructions' => array(
          'title' => __( 'Instructions', 'woocommerce' ),
          'type' => 'textarea',
          'description' => __( 'Instructions that will be added to the thank you page.', 'woocommerce' ),
          'default' => __( 'Pay with cash before delivery.', 'woocommerce' )
        )
      );
    }
    /**
     * Admin Panel Options
     * - Options for bits like 'title' and availability on a country-by-country basis
     * */
    public function admin_options() {
      $ipn_url = add_query_arg( 'wc-api', get_class( $this ), get_site_url() );
      echo '<h3>'.__( 'GharPay Payment Gateway', 'mrova' ).'</h3>';
      echo '<p>'.__( 'Gharpay is a doorstep payment network with a human face.' ).'</p>';
      echo '<p>'.__( 'Please give this url "<b><i>'.$ipn_url.'</i></b>" to gharpay for Realtime Updates.' ).'</p>';
      echo '<table class="form-table">';
      $this -> generate_settings_html();
      echo '</table>';
    }
    /**
     * Right now we are saving the data of the cities to the DB
     * Because i think calling everytime gharpay api when user orders somethin is illogical, resource & time consuming.
     * Right now there is no way to update DB with new cities but in future version there will be. (Lazy..)
     *
     * @return array
     */
    function get_city_list() {
      try{
        $city_list = get_option( 'mrova_gharpay_citylist' );
        if ( !$city_list ) {
          $city_list = serialize( $this -> gharpay -> getCityList() );
          update_option( 'mrova_gharpay_citylist', $city_list );
        }
      }catch( GharpayAPIException $e ) {

      }

      return unserialize( $city_list );
    }
    /**
     * Same as city list
     *
     * @return array
     */
    function get_pincodes() {
      try{
        $pincodes = get_option( 'mrova_pincodes' );
        if ( !$pincodes ) {
          $pincodes = serialize( $this -> gharpay -> getAllPincodes() );
          update_option( 'mrova_pincodes', $pincodes );
        }
      }catch( GharpayAPIException $e ) {

      }
      return unserialize( $pincodes );
    }
    /**
     * Validation for user city, pincode, and country
     *
     * @param int     $order_id
     * @return bool
     */
    function validate_fields( $order_id='' ) {
      global $woocommerce;
      //first check if order id is present or not
      //because user can pay later too via pay page
      //if not check post fields
      if ( !empty( $order_id ) ) {
        $order   = new WC_Order( $order_id );
        $city    = $order -> billing_city;
        $pincode = $order -> billing_postcode;
        $country = $order -> billing_country;
      }else {
        $city    = $_POST['billing_city'];
        $pincode = $_POST['billing_postcode'];
        $country = $_POST['billing_country'];

      }

      $city_list = $this->get_city_list();
      //check if gharpay is available at the user's city
      if ( !in_array( $city, $city_list ) ) {
        $woocommerce->add_error( 'Sorry "'.$this->title.'" is not available in your city '.$city.'.' );
      }
      //check for pincode
      $pincodes = $this -> get_pincodes();
      if ( !in_array( $pincode, $pincodes ) ) {
        $woocommerce->add_error( 'Sorry "'.$this->title.'" is not available at your pincode '.$pincode.'.' );
      }
      //Gharpay support INDIA - IN
      if ( $country!='IN' ) {
        $woocommerce->add_error( 'Sorry "'.$this->title.'" is not available in your country.' );
      }

      if ( $woocommerce->error_count() > 0  ) {
        return false;
      }

      return true;
    }


    /**
     * Process the payment and return the result
     * */
    function process_payment( $order_id ) {
      global $woocommerce;
      $order = new WC_Order( $order_id );

      $formatted_address = str_replace( '<br />', ', ', $order -> get_formatted_billing_address() );
      $customer_details= array(
        'address'   => $formatted_address,
        'contactNo' => $order -> billing_phone,
        'firstName' => $order -> billing_first_name,
        'lastName'  => $order -> billing_last_name,
        'email'     => $order -> billing_email
      );

      //deliver date add 2 days
      $timezone = "Asia/Kolkata";
      if ( function_exists( 'date_default_timezone_set' ) ) date_default_timezone_set( $timezone );
      $delivery_date = date( 'd-m-Y', strtotime( "+2 day" ) );
      $order_details = array(
        'pincode'       => $order -> billing_postcode,
        'clientOrderID' => $order_id,
        'deliveryDate'  => $delivery_date,
        'orderAmount'   => $order -> order_total
      );

      $product_details = array();
      if ( sizeof( $woocommerce->cart->get_cart() ) > 0 ) {
        foreach ( $woocommerce->cart->get_cart() as $cart_item_key => $values ) {
          $_product = $values['data'];
          if ( $_product->exists() && $values['quantity'] > 0 ) {
            $product_price = get_option( 'woocommerce_tax_display_cart' ) == 'excl' ? $_product->get_price_excluding_tax() : $_product->get_price_including_tax();
            $productDetails[] = array (
              'productID'          => $values['product_id'],
              'productQuantity'    => esc_attr( $values['quantity'] ),
              'unitCost'           => $product_price,
              'productDescription' => $_product->get_title()
            );
          }
        }
      }

      try{
        $result = $this->gharpay->createOrder( $customer_details, $order_details, $product_details );
        $order->add_order_note( __( "Gharpay order id is ".$result['gharpayOrderId'], 'woocommerce' ) );
        update_post_meta( $order->id, 'gharpay_transaction_id', $result['gharpayOrderId'] );
      }catch( GharpayAPIException $e ) {
        $order->add_order_note( __( $e->getMessage(), 'woocommerce' ) );
        $mailer = $woocommerce->mailer();
        $message = $mailer->wrap_message(
          __( 'Failed to update gharpay!', 'woocommerce' ),
          sprintf( __( 'Failed to send the notification to gharpay of Order %s - message: %s, Please add the order manually using gharpay dashboard.', 'woocommerce' ), $order->get_order_number(), $e->getMessage() )
        );
        $mailer->send( get_option( 'admin_email' ), sprintf( __( 'Order %s - Failed to update gharpay!', 'woocommerce' ), $order->get_order_number() ), $message );
      }
      // Mark as on-hold (we're awaiting the gharpay response)
      $order->update_status( 'on-hold', __( 'Payment to be made before delivery.', 'woocommerce' ) );

      // Reduce stock levels
      $order->reduce_order_stock();

      // Remove cart
      $woocommerce->cart->empty_cart();

      // Return thankyou redirect
      return array(
        'result'    => 'success',
        'redirect'  => add_query_arg( 'key', $order->order_key, add_query_arg( 'order', $order_id, get_permalink( woocommerce_get_page_id( 'thanks' ) ) ) )
      );
    }
    /**
     * Realtime updates from gharpay
     * */
    function check_gharpay_response() {

      //Example Query - ?order_id=GW-xxx-0000xxx-xxx&time=2011-09-08+13%3A59%3A20&client_order_id=1234A
      $gharpay_order_id = $_REQUEST['order_id'];
      $client_order_id  = $_REQUEST['client_order_id'];
      if ( $gharpay_order_id != '' ) {
        if ( $client_order_id!='' ) {
          $gharpay_transaction_id = get_post_meta( $client_order_id, 'gharpay_transaction_id', true );
          if ( $gharpay_transaction_id==$gharpay_order_id ) {
            try{
              $status = $this -> gharpay -> viewOrderStatus( $gharpay_order_id );
              $order = new WC_Order( $client_order_id );
              if ( !isset( $order->id ) ) {
                exit;
              }
              $status = strtolower( $status['status'] );
              switch ( $status ) {
              case 'delivered':
                $order->add_order_note( __( 'The order has been delivered successfully and Gharpay has collected the payment.', 'woocommerce' ) );
                $order->payment_complete();
                break;
              case 'failed':
                $order->update_status( 'failed', 'The customer has failed to respond despite repeated attempts made by Gharpay’s executives. In this case, the exact reason is mentioned in the order’s comments section[gharpay dashboard].' );
                break;
              case 'cancelled by client':
                $order->update_status( 'failed', 'The order has been cancelled by the client/merchant.' );
                break;
              case 'cancelled by customer':
                $order->update_status( 'failed', 'The customer, when contacted by Gharpay’s executive, expressed the intent to cancel the order.' );
                break;
              case 'deferred by customer':
                $order->update_status( 'failed', 'The end customer, when contacted by Gharpay’s executive, asked for the delivery/pickup to be deferred by a few days.' );
                break;
              case 'invalid':
                $order->update_status( 'failed', 'The customer contact details provided are invalid or the area is not serviceable by Gharpay.' );
                break;

              }
            }catch( GharpayAPIException $e ) {

            }
          }

        }
      }

    }
    /**
     * Output for the order received page.
     *
     * @access public
     * @return void
     */
    function thankyou_page() {
      echo $this->instructions != '' ? wpautop( $this->instructions ) : '';
    }
  }

  /**
   * Add the Gateway to WooCommerce
   * */
  function woocommerce_add_mrova_gharpay_gateway( $methods ) {
     $methods[] = 'WC_Mrova_GharPay';
    return $methods;
  }
 
  add_filter( 'woocommerce_payment_gateways', 'woocommerce_add_mrova_gharpay_gateway' );
}

?>
