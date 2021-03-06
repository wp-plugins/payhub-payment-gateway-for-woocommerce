<?php
/*
Plugin Name: PayHub Gateway Plugin for WooCommerce
Plugin URI: http://developer.payhub.com/
Description: This plugin allows you to accept credit card payments through PayHub in your WooCommerce storefront.
Version: 1.0.11
Author: PayHub
*/


add_action('plugins_loaded', 'woocommerce_payhub_init', 0);

function woocommerce_payhub_init() {

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) { return; }

	//require_once(WP_PLUGIN_DIR . "/" . plugin_basename( dirname(__FILE__)) . '/class/payhubTransaction.class.php');

	/**
 	 * Gateway class
 	 **/
	class WC_PayHub_Gateway extends WC_Payment_Gateway {
	
		var $avaiable_countries = array(
			'GB' => array(
				'Visa',
				'MasterCard',
				'Discover',
				'American Express'
			),
			'US' => array(
				'Visa',
				'MasterCard',
				'Discover',
				'American Express'
			),
			'CA' => array(
				'Visa',
				'MasterCard',
				'Discover',
				'American Express'
			)
		);
		var $api_username;
		var $api_password;
		var $orgid;
		var $demo;
		var $terminal_id;
		var $card_data;
		var $card_cvv;
		var $card_exp_month;
		var $card_exp_year;
		var $response;

		function __construct() { 				
			$this->id	= 'payhub';
			$this->method_title 	= __('PayHub', 'woothemes');
			$this->icon = WP_PLUGIN_URL . "/" . plugin_basename( dirname(__FILE__)) . '/PoweredbyPayHubCards.png';
			$this->has_fields = true;
			
			// Load the form fields
			$this->init_form_fields();
			
			// Load the settings.
			$this->init_settings();
			
			// Get setting values
			$this->title 			= $this->settings['title'];
			$this->description 		= $this->settings['description'];
			$this->demo = $this->settings['demo'];
			$this->enabled 			= $this->settings['enabled'];
			$this->api_username 	= $this->settings['api_username'];
			$this->api_password 	= $this->settings['api_password'];
			$this->orgid 	= $this->settings['orgid'];
			$this->tid 	= $this->settings['terminal_id'];
			

			// Hooks
			add_action( 'admin_notices', array( &$this, 'ssl_check') );
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options') );
			add_action( 'woocommerce_thankyou_cheque', array(&$this, 'thankyou_page' ));
		}

		/**
	 	 * Check if SSL is enabled and notify the user if SSL is not enabled
	 	 **/

		function ssl_check() {		
			if (get_option('woocommerce_force_ssl_checkout') == 'no' && $this->enabled == 'yes') {
				echo '<div class="error"><p>'.sprintf(__('PayHub is enabled, but the <a href="%s">force SSL option</a> is disabled; your checkout is not secure! Please enable SSL and ensure your server has a valid SSL certificate.', 'woothemes'), admin_url('admin.php?page=woocommerce')).'</p></div>';
			}
		}

		/*
		 * Check to see if a specific WC feature is supported.
		 */			
		function supports( $feature ) {
			return apply_filters( 'woocommerce_payment_gateway_supports', in_array( $feature, $this->supports) ? true : false, $feature, $this);
		}

		/**
		 * Check for version 2.1 or greater.  We only support 2.x so if this is false 
		 * then the version should be 2.0.x
		 */
		function isWcVersionTwoPointOneOrGreater() {
			global $woocommerce;
			$newer_version_threshold = "2.1.0";

			if (version_compare($woocommerce->version, $newer_version_threshold, ">=" )) return true;

			return false;
		}

		/**
     	 * Initialize Gateway Settings Form Fields
     	 */
	    function init_form_fields() {		    
	    	$this->form_fields = array(
				'title' => array(
					'title' => __( 'Title', 'woothemes' ), 
					'type' => 'text', 
					'description' => __( 'This controls the title which the user sees during checkout.', 'woothemes' ), 
					'default' => __( 'PayHub, Inc', 'woothemes' ),
					), 
				'enabled' => array(
					'title' => __( 'Enable/Disable', 'woothemes' ), 
					'label' => __( 'Enable PayHub', 'woothemes' ), 
					'type' => 'checkbox', 
					'description' => '', 
					'default' => 'no'
					),
				'demo' => array(
					'title' => __( 'PayHub Demo', 'woothemes' ), 
					'label' => __( 'Enable Demo Mode', 'woothemes' ), 
					'type' => 'checkbox',  
					'description' => __('This turns on Demo Mode, where all transactions will go to our demo server.  While this mode is on, you can use any credit card number, but must use the following CVVs for the following card types.  VISA = 999, Mastercard = 998, AMEX = 9997, and Discover/Diners = 996', 'woothemes'), 
					'default' => 'no'
					),
				'description' => array(
					'title' => __( 'Description', 'woothemes' ), 
					'type' => 'text', 
					'description' => __( 'This controls the description which the user sees during checkout.', 'woothemes' ), 
					'default' => 'We accept Visa, Mastercard, & Discover'
					),  
				'api_username' => array(
					'title' => __( 'API Username', 'woothemes' ), 
					'type' => 'text', 
					'description' => __( 'Get your API Login from PayHub.', 'woothemes' ), 
					'default' => ''
					), 
				'api_password' => array(
					'title' => __( 'API Password', 'woothemes' ), 
					'type' => 'text', 
					'description' => __( 'Get your API Password from PayHub.', 'woothemes' ), 
					'default' => ''
					),
				'orgid' => array(
					'title' => __( 'OrgID', 'woothemes' ),
					'type' => 'text',
					'description' => __( 'This is your organization ID', 'woothemes' ),
					'default' => '00000'
					),
				'terminal_id' => array(
					'title' => __( 'Terminal ID', 'woothemes' ),
					'type' => 'text',
					'description' => __( 'Get your terminal ID from PayHub.', 'woothemes' ),
					'default' => '0000'
					)
				);
	    }
	  
	    /**
		 * Admin Panel Options 
		 * - Options for bits like 'title' and availability on a country-by-country basis
		*/
		function admin_options() {
	    	?>
	    	<h3><?php _e( 'PayHub', 'woothemes' ); ?></h3>
	    	<p><?php _e( 'Payhub works by adding credit card fields on the checkout and then sending the details to our webservice for verification. You must first have a PayHub Account to accept credit card and debit card payments. Please contact x to setup an account. If you have any questions you can contact us at (415) 306-9476 M-F from 8am - 5 pm PST or email us at wecare@payhub.com</a> anytime.  ', 'woothemes' ); ?></p>
	    	<table class="form-table">
	    		<?php $this->generate_settings_html(); ?>
			</table><!--/.form-table-->
	    	<?php
	    }
		
		/**
	     * Payment form on checkout page
	     */
		function payment_fields() {
			if ( $description = $this->get_description() )
				echo wpautop( wptexturize( $description ) );
			if ( $this->supports( 'default_credit_card_form' ) )
				echo $this->credit_card_form();

			global $woocommerce;

	        $month_select = "";
	        for ($i=0; $i < 12; $i++) {
	            $month = sprintf('%02d', $i+1);
	            if($month == date('m'))
	                $select = 'selected ';
	            else
	                $select = '';
	            $month_select .= "<option value='" . $month . "' " . $select . ">" . $month . "</option>\n";
	        }    
        
	        // create options for valid from and expires on years
	        $year_now = date('y');
	        $year_select = "";

	        for($y = $year_now; $y < $year_now + 15; $y++) {
	            $year = sprintf('%02d', $y);
	            $year_select .= "<option value='" . $year . "' " . $select . ">" . $year . "</option>\n";
	        }
        	
        	?>

				<fieldset>
					<p class="form-row">
						<label for="card_number"><?php echo __("Credit Card number", 'woocommerce') ?> <span class="required">*</span></label>
						<input type="text" class="input-text" name="card_number" />
					</p>
					<div class="clear"></div>
					<p class="form-row">
						<label for="cc_exp_month"><?php echo __("Expiration date", 'woocommerce') ?> <span class="required">*</span></label>
					</p>
					<p class="form-row form-row-first">
						<select name="card_exp_month" id="cc_exp_month">
							<?php echo $month_select; ?>
						</select>
					</p>
					<p class="form-row form-row-last">	
						<select name="card_exp_year" id="cc_exp_year">
							<?php echo $year_select; ?>
						</select>
					</p>
					<p class="form-row">
						<label for="card_cvv"><?php _e("Card security code", 'woocommerce') ?> <span class="required">*</span></label>
						<input type="text" class="input-text" id="cc_cvv" name="card_cvv" maxlength="4" style="width:45px" />
						<span class="help payhub_card_cvv_description"></span>
					</p>
					<div class="clear"></div>
				</fieldset>

			<?php
		}


		/**
	     * Validate the payment form
	     */
		function validate_fields() {		
			#$card_data 		= isset($_POST['payjunction_card_type']) ? $_POST['payjunction_card_type'] : '';
			$card_data 			= isset($_POST['card_number']) ? $_POST['card_number'] : '';
			$card_cvv 			= isset($_POST['card_cvv']) ? $_POST['card_cvv'] : '';
			$card_exp_month		= isset($_POST['card_exp_month']) ? $_POST['card_exp_month'] : '';
			$card_exp_year 		= isset($_POST['card_exp_year']) ? $_POST['card_exp_year'] : '';
			
			// Check card security code
			/*
			if(!ctype_digit($card_cvv)) {
				$woocommerce->add_error(__('Card security code is invalid (only digits are allowed)', 'woothemes'));
				return false;
			}
	
			
			if((strlen($card_cvv) != 3 && in_array($card_type, array('Visa', 'MasterCard', 'Discover'))) || (strlen($card_csc) != 4 && $card_type == 'American Express')) {
				$woocommerce->add_error(__('Card security code is invalid (wrong length)', 'woothemes'));
				return false;
			}
			
	
			// Check card expiration data
			if(!ctype_digit($card_exp_month) || !ctype_digit($card_exp_year) ||
				 $card_exp_month > 12 ||
				 $card_exp_month < 1 ||
				 $card_exp_year < date('Y') ||
				 $card_exp_year > date('Y') + 20
			) {
				$woocommerce->add_error(__('Card expiration date is invalid', 'woothemes'));
				return false;
			}
	
			// Check card number
			$card_number = str_replace(array(' ', '-'), '', $card_number);
	
			if(empty($card_number) || !ctype_digit($card_number)) {
				$woocommerce->add_error(__('Card number is invalid', 'woothemes'));
				return false;
			}
			*/

			return true;
		}

		/**
	 	 * Add the Gateway to WooCommerce
	 	 **/
		function process_payment( $order_id ) {
			global $woocommerce;
			$order = new WC_Order( $order_id );

			$mode = $this->demo;
			$post_url = "https://checkout.payhub.com/invoice/transaction";

			if ($mode == "yes") {
				$mode = "demo";
			} 
		    else {
				$mode = "live";
			}

			$post_data = array(
				'mode' => $mode,
				'orgid' => $this->orgid,
				'username' => $this->api_username,
				'password' => $this->api_password,
				'tid' => $this->tid,
				'first_name' => $order->billing_first_name,
				'last_name' => $order->billing_last_name,
				'phone' => preg_replace('/[^0-9]/', '', $order->billing_phone),
				'email' => $order->billing_email,
				'address1' => $order->billing_address_1,
				'address2' => $order->billing_address_2,
				'city' => $order->billing_city,
				'state' => $order->billing_state,
				'zip' => $order->billing_postcode,
				'note' => $order_id . ", " . $order->user_id,
				'cc' => $_POST['card_number'],
				'month' => $_POST['card_exp_month'],
				'year' => $_POST['card_exp_year'],
				'cvv' => $_POST['card_cvv'],
				'amount' => $order->order_total,
				'ship_to_name' => $order->billing_first_name . $order->billing_last_name,
				'ship_address1' => $order->shipping_address_1,
				'ship_address2' => $order->shipping_address_2,
				'ship_city' => $order->shipping_city,
				'ship_state' => $order->shipping_state,
				'ship_zip' => $order->shipping_postcode
				);

			$post_fields = json_encode($post_data);
			//var_dump($submit_data);
			//var_dump($post_url);
			// Setup the cURL request.
			$ch = curl_init();
			$c_opts = array(
				CURLOPT_URL => $post_url,
                CURLOPT_VERBOSE => 0,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $post_fields
                );

			curl_setopt_array($ch, $c_opts);
			$raw = curl_exec($ch);

			curl_close($ch);

			$payhub_response = json_decode($raw, true);

			$ph_transaction_id = $payhub_response['TRANSACTION_ID'];
			$ph_response_code = $payhub_response['RESPONSE_CODE'];
			$ph_response_text = $payhub_response['RESPONSE_TEXT'];

			if ($ph_response_code == "00") {
				$order->add_order_note( __('Transaction completed', 'woothemes') . ' (PayHub Transaction ID: ' . $ph_transaction_id);
				
				//$order->payment_complete();
				$order->payment_complete();
				
				// Remove cart
				
				//$woocommerce->cart->empty_cart();
				// Empty awaiting payment session
				unset($_SESSION);

				// Return thank you page redirect
				return array(
					'result' 	=> 'success',
					#'redirect'	=> add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(get_option('woocommerce_thanks_page_id'))))
					'redirect' => $this->get_return_url($order)
					);
			} 
			else {
				$error_msg = __('Payment Error:  ', 'woothemes') . "$ph_response_text ( $ph_response_code )";
				
				# We support WC 2.x and
				# WooCommerce::add_error was removed in WC 2.3 and
				# wc_add_notice was added in WC 2.1
				if ($this->isWcVersionTwoPointOneOrGreater()) {
					wc_add_notice($error_msg, 'error');
				}
				else {
					$woocommerce->add_error($error_msg);
				}

				$order->update_status('failed');

				$order_note = 'PayHub ' . __('Transaction Failed:', 'woothemes') . "\n\n";
				$order_note .= "Response Code: $ph_response_code\n";
				$order_note .= "Response Text: $ph_response_text\n";
				$order_note .= "Response Transaction ID: $ph_transaction_id\n";
				$order->add_order_note($order_note);
				
				return;
			}
		}
	}
}

function woocommerce_add_payhub_gateway( $methods ) {
	$methods[] = 'WC_PayHub_Gateway';
	return $methods;
}

add_filter('woocommerce_payment_gateways', 'woocommerce_add_payhub_gateway');