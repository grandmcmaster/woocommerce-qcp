<?php
/*
 * Shop System Plugins - Terms of use This terms of use regulates warranty and liability between Wirecard Central Eastern Europe (subsequently referred to as WDCEE) and it's contractual partners (subsequently referred to as customer or customers) which are related to the use of plugins provided by WDCEE. The Plugin is provided by WDCEE free of charge for it's customers and must be used for the purpose of WDCEE's payment platform integration only. It explicitly is not part of the general contract between WDCEE and it's customer. The plugin has successfully been tested under specific circumstances which are defined as the shopsystem's standard configuration (vendor's delivery state). The Customer is responsible for testing the plugin's functionality before putting it into production enviroment. The customer uses the plugin at own risk. WDCEE does not guarantee it's full functionality neither does WDCEE assume liability for any disadvantage related to the use of this plugin. By installing the plugin into the shopsystem the customer agrees to the terms of use. Please do not use this plugin if you do not agree to the terms of use!
 *
 *  - Support for WooCommerce 2.3 (not backward compatible)
 *  - Removed margin-right of payment type radio button
 *  - Wrapped payment type in div
 *
 */
require_once( WOOCOMMERCE_GATEWAY_WCP_BASEDIR . 'classes/class-woocommerce-wcp-config.php' );
require_once( WOOCOMMERCE_GATEWAY_WCP_BASEDIR . 'classes/class-woocommerce-wcp-payments.php' );

define( 'WOOCOMMERCE_GATEWAY_WCP_NAME', 'Woocommerce2_WirecardCheckoutPage' );
define( 'WOOCOMMERCE_GATEWAY_WCP_VERSION', '1.3.0' );
define( 'WOOCOMMERCE_GATEWAY_WCP_WINDOWNAME', 'WirecardCheckoutPageFrame' );
define( 'WOOCOMMERCE_GATEWAY_WCP_TABLE_NAME', 'woocommerce_wcp_transaction' );
define( 'WOOCOMMERCE_GATEWAY_WCP_INVOICE_INSTALLMENT_MIN_AGE', 18 );

class WC_Gateway_WCP extends WC_Payment_Gateway {

	/**
	 * @var $log WC_Logger
	 */
	protected $log;

	/**
	 * Config Class
	 *
	 * @since 2.2.0
	 * @access protected
	 * @var WC_Gateway_WCP_Config
	 */
	protected $_config;

	/**
     * Payments Class
     *
     * @since 2.2.0
     * @access protected
	 * @var WC_Gateway_WCP_Payments
	 */
	protected $_payments;

	function __construct() {
		$this->id                 = 'wirecard_checkout_page';
		$this->icon               = WOOCOMMERCE_GATEWAY_WCP_URL . 'assets/images/wirecard.png';
		$this->has_fields         = false;
		$this->method_title       = __( 'Wirecard Checkout Page', 'woocommerce-wcp' );
		$this->method_description = __(
			"Wirecard CEE is a popular payment service provider (PSP) and has connections with over 20 national and international currencies. ",
			'woocommerce-wcp'
		);

		// Load the form fields
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		$this->_config = new WC_Gateway_WCP_Config($this->settings);
		$this->_payments = new WC_Gateway_WCP_Payments($this->settings);

		$this->title       = 'Wirecard Checkout Page'; // frontend title
		$this->description = 'Wirecard description to payment maybe picture?'; // frontend description
		$this->debug       = $this->settings['debug'] == 'yes';
		$this->use_iframe  = $this->get_option( 'use_iframe' ) == 'yes';
		$this->enabled = count( $this->get_enabled_paymenttypes(false ) ) > 0 ? "yes" : "no";

		// Hooks
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array(
				$this,
				'process_admin_options'
			)
		); // inherit method
		add_action(
			'woocommerce_thankyou_' . $this->id,
			array(
				$this,
				'thankyou_page_text'
			)
		);

		// iframe only
		if ( $this->use_iframe ) {
			add_action(
				'woocommerce_receipt_' . $this->id,
				array(
					$this,
					'payment_page'
				)
			);
		}

		// Payment listener/API hook
		add_action(
			'woocommerce_api_wc_gateway_wcp',
			array(
				$this,
				'dispatch_callback'
			)
		);
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 *
	 * @access public
	 * @return void
	 */
	function init_form_fields() {
		$countries = WC()->countries->countries;
		$this->countries = array();
		$this->countries['all'] = __('Select all', 'woocommerce-wcp');
		if ( ! empty( $countries ) ) {
			foreach ( $countries as $key => $val ) {
			    $this->countries[$key] = $val;
			}
		}
		$this->currency_code_options = array();
		foreach ( get_woocommerce_currencies() as $code => $name ) {
			$this->currency_code_options[ $code ] = $name . ' (' . get_woocommerce_currency_symbol( $code ) . ')';
		}
		$this->form_fields = include('includes/settings-wcp.php');
	}

	/**
	 * Admin Panel Options
	 *
	 * @access public
	 * @return void
	 */
	function admin_options() {
		?>
		<h3><?php _e( 'Wirecard Checkout Page', 'woocommerce-wcp' ); ?></h3>
		<div class="woo-wcs-settings-header-wrapper" style="min-width: 200px; max-width: 800px;">
			<img src="<?= plugins_url( 'woocommerce-wirecard-checkout-page/assets/images/wirecard-logo.png' ) ?>">
			<p style="text-transform: uppercase;"><?= __( 'Wirecard - Your Full Service Payment Provider - Comprehensive solutions from one single source',
					'woocommerce-wcp' ) ?></p>

			<p><?= __( 'Wirecard is one of the world´s leading providers of outsourcing and white label solutions for electronic payment transactions.',
					'woocommerce-wcp' ) ?></p>

			<p><?= __( 'As independent provider of payment solutions, we accompany our customers along the entire business development. Our payment solutions are perfectly tailored to suit e-Commerce requirements and have made	us Austria´s leading payment service provider. Customization, competence, and commitment.',
					'woocommerce-wcp' ) ?></p>

		</div>
		<hr/>
        <style>
            .form-table td {
                padding:0px;
            }
            .form-table th {
                padding:0px;
            }

        </style>
		<table class="form-table">
			<?php
			// Generate the HTML For the settings form.
			$this->generate_settings_html();
			?>
		</table>
		<?php
	}

	/**
	 * Process the payment and return the result
	 *
	 * @access public
	 *
	 * @param int $order_id
	 *
	 * @return array
	 */
	function process_payment( $order_id ) {
		/**
		 * @global $woocommerce Woocommerce
		 */
		global $woocommerce;

		$order = new WC_Order( $order_id );

		$paymenttype = $_POST['wcp_payment_method'];
		if ( ! $this->is_paymenttype_enabled( $paymenttype ) ) {
			wc_add_notice( __( 'Payment type is not available, please select another payment type.',
			                   'woocommerce-wcp' ), 'error' );

			return false;
		}

		$birthday = null;
		if ( isset( $_POST['wcp_birthday'] ) ) {
			$birthday = $_POST['wcp_birthday'];
		}
		$financial_inst = null;
		if ( $paymenttype == 'eps' ) {
			$financial_inst = $_POST['wcp_eps_financialInstitution'];
		}
		if ( $paymenttype == 'idl' ) {
			$financial_inst = $_POST['wcp_idl_financialInstitution'];
		}

		if ( $this->use_iframe ) {
			WC()->session->wirecard_checkout_page_type = $paymenttype;

			$page_url = version_compare( WC()->version, '2.1.0', '<' )
				? get_permalink( wc_get_page_id( 'pay' ) )
				: $order->get_checkout_payment_url( true );

			$page_url = add_query_arg( 'key', $order->get_order_key(), $page_url );
			$page_url = add_query_arg( 'order-pay', $order_id, $page_url );

			return array(
				'result'   => 'success',
				'redirect' => $page_url
			);
		} else {
			$redirectUrl = $this->initiate_payment( $order, $paymenttype, $birthday, $financial_inst );
			if ( ! $redirectUrl ) {
				return;
			}

			return array(
				'result'   => 'success',
				'redirect' => $redirectUrl
			);
		}
	}

	/**
	 * Payment iframe
	 *
	 * @param
	 *            $order_id
	 */
	function payment_page( $order_id ) {
		$order = new WC_Order( $order_id );

		$birthday = null;
		if ( isset( $_POST['wcp_birthday'] ) ) {
			$birthday = $_POST['wcp_birthday'];
		}
		$financial_inst = null;
		if ( WC()->session->wirecard_checkout_page_type == 'eps' ) {
			$financial_inst = $_POST['wcp_eps_financialInstitution'];
		}
		if ( WC()->session->wirecard_checkout_page_type == 'idl' ) {
			$financial_inst = $_POST['wcp_idl_financialInstitution'];
		}

		$iframeUrl = $this->initiate_payment( $order, WC()->session->wirecard_checkout_page_type, $birthday,
			$financial_inst );
		?>
		<iframe src="<?php echo $iframeUrl ?>"
		        name="<?php echo WOOCOMMERCE_GATEWAY_WCP_WINDOWNAME ?>" width="100%"
		        height="700px" border="0" frameborder="0">
			<p>Your browser does not support iframes.</p>
		</iframe>
		<?php
	}

	/**
	 * Dispatch callback, invoked twice, first server-to-server, second browser redirect
	 * Do iframe breakout, if needed
	 */
	function dispatch_callback() {
		// if session data is available assume browser redirect, otherwise server-to-server request
		if ( isset( WC()->session->chosen_payment_method ) ) {

			// do iframe breakout, if needed and not already done
			if ( $this->use_iframe && ! array_key_exists( 'redirected', $_REQUEST ) ) {
				$url = add_query_arg( 'wc-api', 'WC_Gateway_WCP', home_url( '/', is_ssl() ? 'https' : 'http' ) );
				wc_get_template(
					'templates/iframebreakout.php',
					array(
						'url' => $url
					),
					WOOCOMMERCE_GATEWAY_WCP_BASEDIR,
					WOOCOMMERCE_GATEWAY_WCP_BASEDIR
				);
				die();
			}

			$redirectUrl = $this->return_request();
			header( 'Location: ' . $redirectUrl );
		} else {
			print $this->confirm_request();
		}

		die();
	}

	/**
	 * handle browser return
	 *
	 * @return string
	 */
	function return_request() {
		$this->log( 'return_request:' . print_r( $_REQUEST, true ), 'notice' );

		$redirectUrl = $this->get_return_url();
		if ( ! isset( $_REQUEST['wooOrderId'] ) || ! strlen( $_REQUEST['wooOrderId'] ) ) {
			wc_add_notice( __( 'Panic: Order-Id missing', 'woocommerce-wcp' ), 'error' );

			return $redirectUrl;
		}
		$order_id = $_REQUEST['wooOrderId'];
		$order    = new WC_Order( $order_id );
		if ( ! $order->get_id() ) {
			wc_add_notice( __( 'Panic: Order-Id missing', 'woocommerce-wcp' ), 'error' );

			return $redirectUrl;
		}

		$paymentState = $_REQUEST['paymentState'];
		switch ( $paymentState ) {
			case WirecardCEE_QPay_ReturnFactory::STATE_SUCCESS:
			case WirecardCEE_QPay_ReturnFactory::STATE_PENDING:
				return $this->get_return_url( $order );

			case WirecardCEE_QPay_ReturnFactory::STATE_CANCEL:
				wc_add_notice( __( 'Payment has been cancelled.', 'woocommerce-wcp' ), 'error' );
				unset( WC()->session->wirecard_checkout_page_redirect_url );

				return $order->get_cancel_endpoint();

			case WirecardCEE_QPay_ReturnFactory::STATE_FAILURE:
				if ( array_key_exists( 'consumerMessage', $_REQUEST ) ) {
					wc_add_notice( $_REQUEST['consumerMessage'], 'error' );
				} else {
					wc_add_notice( __( 'Payment has failed.', 'woocommerce-wcp' ), 'error' );
				}

				return $order->get_cancel_endpoint();

			default:
				break;
		}

		return $this->get_return_url( $order );
	}

	/**
	 * Server to server request
	 *
	 * @return string
	 */
	function confirm_request() {
		$this->log( 'confirm_request:' . print_r( $_REQUEST, true ), 'notice' );

		$message = null;
		if ( ! isset( $_REQUEST['wooOrderId'] ) || ! strlen( $_REQUEST['wooOrderId'] ) ) {
			$message = 'order-id missing';
			$this->log( $message, 'error' );

			return WirecardCEE_QPay_ReturnFactory::generateConfirmResponseString( $message );
		}
		$order_id = $_REQUEST['wooOrderId'];
		$order    = new WC_Order( $order_id );
		if ( ! $order->get_id() ) {
			$message = "order with id `$order->get_id()` not found";
			$this->log( $message, 'error' );

			return WirecardCEE_QPay_ReturnFactory::generateConfirmResponseString( $message );
		}

		if ( $order->get_status() == "processing" || $order->get_status() == "completed" ) {
			$message = "cannot change the order with id `$order->get_id()`";
			$this->log( $message, 'error' );

			return WirecardCEE_QPay_ReturnFactory::generateConfirmResponseString( $message );
		}

		$str = '';
		foreach ( $_POST as $k => $v ) {
			$str .= "$k:$v\n";
		}
		$str = trim( $str );

		update_post_meta( $order->get_id(), 'wcp_data', $str );

		$message = null;
		try {
			$return = WirecardCEE_QPay_ReturnFactory::getInstance( $_POST, $this->get_option( 'secret' ) );
			if ( ! $return->validate() ) {
				$message = __( 'Validation error: invalid response', 'woocommerce-wcp' );
				$order->update_status( 'failed', $message );

				return WirecardCEE_QPay_ReturnFactory::generateConfirmResponseString( $message );
			}

			/**
			 * @var $return WirecardCEE_Stdlib_Return_ReturnAbstract
			 */
			update_post_meta( $order->get_id(), 'wcp_payment_state', $return->getPaymentState() );

			switch ( $return->getPaymentState() ) {
				case WirecardCEE_QPay_ReturnFactory::STATE_SUCCESS:
					update_post_meta( $order->get_id(), 'wcp_gateway_reference_number',
					                  $return->getGatewayReferenceNumber() );
					update_post_meta( $order->get_id(), 'wcp_order_number', $return->getOrderNumber() );
					$order->payment_complete();
					break;

				case WirecardCEE_QPay_ReturnFactory::STATE_PENDING:
					/**
					 * @var $return WirecardCEE_QPay_Return_Pending
					 */
					$order->update_status(
						'on-hold',
						__( 'Awaiting payment notification from 3rd party.', 'woocommerce-wcp' )
					);
					break;

				case WirecardCEE_QPay_ReturnFactory::STATE_CANCEL:
					/**
					 * @var $return WirecardCEE_QPay_Return_Cancel
					 */
					$order->update_status( 'pending', __( 'Payment cancelled.', 'woocommerce-wcp' ) );
					break;

				case WirecardCEE_QPay_ReturnFactory::STATE_FAILURE:
					/**
					 * @var $return WirecardCEE_QPay_Return_Failure
					 */
					$str_errors = '';
					foreach ( $return->getErrors() as $error ) {
						$errors[] = $error->getConsumerMessage();
						wc_add_notice( __( "Request failed! Error: {$error->getConsumerMessage()}",
						                   'woocommerce-wcp' ),
						               'error' );
						$this->log( $error->getConsumerMessage(), 'error' );
						$str_errors += $error->getConsumerMessage();
					}
					$order->update_status( 'failed', $str_errors );
					break;

				default:
					break;
			}
		} catch ( Exception $e ) {
			$this->log( __FUNCTION__ . $e->getMessage(), 'error' );
			$order->update_status( 'failed', $e->getMessage() );
			$message = $e->getMessage();
		}

		return WirecardCEE_QPay_ReturnFactory::generateConfirmResponseString( $message );
	}

	/**
	 *
	 * @param $order_id WC_Order
	 */
	function thankyou_page_text( $order_id ) {
		$order = new WC_Order( $order_id );
		if ( $order->get_status() == 'on-hold' ) {
			printf(
				'<p>%s</p>',
				__(
					'Your order will be processed, as soon as we get the payment notification from your bank institute',
					'woocommerce-wcp'
				)
			);
		}
	}

	/**
	 * Display the list of enabled paymenttypes on the checkout page
	 *
	 * @access public
	 * @return void
	 */
	function payment_fields() {
		?>
        <input id="payment_method_wcp" type="hidden" value="woocommerce_wirecard_checkout_page"
               name="wcp_payment_method"/>
        <script type="text/javascript">
            function changeWCPPayment(code) {
                var changer = document.getElementById('payment_method_wcp');
                changer.value = code;
            }
        </script>
        <link rel="stylesheet" type="text/css" href="<?= WOOCOMMERCE_GATEWAY_WCP_URL ?>assets/styles/payment.css">
		<?php
		foreach ( $this->get_enabled_paymenttypes() as $type ) {
			?>
            </div></li>
        <li class="wc_payment_method payment_method_wirecard_checkout_page_<?php echo $type->code ?>">
            <input
                    id="payment_method_wirecard_checkout_page_<?php echo $type->code ?>"
                    type="radio"
                    class="input-radio"
                    value="wirecard_checkout_page"
                    onclick="changeWCPPayment('<?php echo $type->code ?>');"
                    name="payment_method"
                    data-order_button_text>
            <label for="payment_method_wirecard_checkout_page_<?php echo $type->code ?>">
				<?php
				echo $type->label;
				echo "<img src='{$this->_payments->get_payment_icon($type->code)}' alt='Wirecard {$type->label}'>";
				?>
            </label>
            <div class="payment_box payment_method_wirecard_checkout_page_<?= ( $this->_payments->has_payment_fields($type->code) ) ? $type->code : "" ?>" style="display:none;">
			<?php
            echo $this->_payments->get_payment_fields($type->code);
		}
	}

	/**
     * Basic validation for payment methods
     *
     * @since 2.2.0
     *
	 * @return bool|void
	 */
	public function validate_fields() {
		$args         = $this->get_post_data();
		$payment_type = $args['wcp_payment_method'];
		$validation   = $this->_payments->validate_payment( $payment_type, $args );
		if ( $validation === true ) {
			return true;
		} else {
			wc_add_notice( $validation, 'error' );

			return;
		}
	}

	/*
	 * Protected Methods
	 */

	/**
	 * List of enables paymenttypes
	 *
	 * @return array
	 */
	protected function get_enabled_paymenttypes($is_on_payment = true) {
		$types = array();
		foreach ( $this->settings as $k => $v ) {
			if ( preg_match( '/^pt_(.+)$/', $k, $parts ) ) {
				if ( $v == 'yes' ) {
					$type        = new stdClass();
					$type->code  = $parts[1];
					$type->label = $this->get_paymenttype_name( $type->code );

					if ( method_exists( $this->_payments, 'get_risk' ) && $is_on_payment ) {
						$riskvalue = $this->_payments->get_risk( $type->code );
						if ( ! $riskvalue ) {
							continue;
						}
					}
					$types[] = $type;
				}
			}
		}

		return $types;
	}

	/**
	 * Check whether the given payment type is enabled/available or not
	 *
	 * @param
	 *            $code
	 *
	 * @return bool
	 */
	protected function is_paymenttype_enabled( $code ) {
		foreach ( $this->get_enabled_paymenttypes() as $pt ) {
			if ( $pt->code == $code ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Basic check if address is empty
	 *
	 * @since 1.2.2
	 *
	 * @param $address
	 *
	 * @return bool
	 */
	function address_empty( $address ) {

		foreach ( $address as $key => $value ) {
			if ( ! empty( $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param $order
	 * @param $paymenttype
	 *
	 * @return string
	 * @throws Exception
	 */
	protected function initiate_payment( $order, $paymenttype, $birthday, $financial_inst ) {
		if ( isset( WC()->session->wirecard_checkout_page_redirect_url ) && WC()->session->wirecard_checkout_page_redirect_url['id'] == $order->get_id() ) {
			return WC()->session->wirecard_checkout_page_redirect_url['url'];
		}

		$paymenttype = strtoupper( $paymenttype );
		try {
		    $config = $this->_config->get_client_config();
			$client = new WirecardCEE_QPay_FrontendClient( $config );

			// consumer data (IP and User aget) are mandatory!
			$consumerData = new WirecardCEE_Stdlib_ConsumerData();
			$consumerData->setUserAgent( $_SERVER['HTTP_USER_AGENT'] )->setIpAddress( $_SERVER['REMOTE_ADDR'] );

			if ( $birthday !== null ) {
				$date = DateTime::createFromFormat( 'Y-m-d', $birthday );
				$consumerData->setBirthDate( $date );
			}
			$consumerData->setEmail( $order->get_billing_email() );

			if ( $this->get_option( 'send_consumer_shipping' ) == 'yes' ||
			     in_array( $paymenttype,
				     Array( WirecardCEE_QPay_PaymentType::INVOICE, WirecardCEE_QPay_PaymentType::INSTALLMENT ) )
			) {
				$consumerData->addAddressInformation( $this->get_consumer_data( $order, 'shipping' ) );
			}
			if ( $this->get_option( 'send_consumer_billing' ) == 'yes' ||
			     in_array( $paymenttype,
				     Array( WirecardCEE_QPay_PaymentType::INVOICE, WirecardCEE_QPay_PaymentType::INSTALLMENT ) )
			) {
				$consumerData->addAddressInformation( $this->get_consumer_data( $order, 'billing' ) );
			}

			$returnUrl = add_query_arg( 'wc-api', 'WC_Gateway_WCP', home_url( '/', is_ssl() ? 'https' : 'http' ) );

			$version = WirecardCEE_QPay_FrontendClient::generatePluginVersion(
				$this->get_vendor(),
				WC()->version,
				WOOCOMMERCE_GATEWAY_WCP_NAME,
				WOOCOMMERCE_GATEWAY_WCP_VERSION
			);

			$client->setAmount( $order->get_total() )
			       ->setCurrency( get_woocommerce_currency() )
			       ->setPaymentType( $paymenttype )
			       ->setOrderDescription( $this->get_order_description( $order ) )
			       ->setPluginVersion( $version )
			       ->setSuccessUrl( $returnUrl )
			       ->setPendingUrl( $returnUrl )
			       ->setCancelUrl( $returnUrl )
			       ->setFailureUrl( $returnUrl )
			       ->setConfirmUrl( $returnUrl )
			       ->setServiceUrl( $this->get_option( 'service_url' ) )
			       ->setImageUrl( $this->get_option( 'image_url' ) )
			       ->setConsumerData( $consumerData )
			       ->setDisplayText( $this->get_option( 'display_text' ) )
			       ->setCustomerStatement( $this->get_customer_statement( $order ) )
			       ->setDuplicateRequestCheck( false )
			       ->setMaxRetries( $this->get_option( 'max_retries' ) )
			       ->createConsumerMerchantCrmId( $order->get_billing_email() )
			       ->setWindowName( WOOCOMMERCE_GATEWAY_WCP_WINDOWNAME );

			if ( $financial_inst !== null ) {
				$client->setFinancialInstitution( $financial_inst );
			}
			if ( ( $this->get_option( 'auto_deposit' ) == 'yes' ) ) {
				$client->setAutoDeposit( (bool) ( $this->get_option( 'auto_deposit' ) == 'yes' ) );
			}

			$client->wooOrderId = $order->get_id();
			$response           = $client->initiate();
			if ( $response->hasFailed() ) {
				wc_add_notice(
					__( "Response failed! Error: {$response->getError()->getMessage()}", 'woocommerce-wcp' ),
					'error'
				);
				// throw new \Exception("Response failed! Error: {$response->getError()->getMessage()}", 500);
			}
		} catch ( Exception $e ) {
			throw ( $e );
		}

		WC()->session->wirecard_checkout_page_redirect_url = array(
			'id'  => $order->get_id(),
			'url' => $response->getRedirectUrl()
		);

		return $response->getRedirectUrl();
	}

	/**
     * Get billing/shipping address
     *
     * @since 2.2.0
     *
	 * @param $order
	 * @param string $address
	 *
	 * @return WirecardCEE_Stdlib_ConsumerData_Address
	 */
	private function get_consumer_data( $order, $address = 'billing' ) {
		$consumer_address = 'billing';
		$type             = WirecardCEE_Stdlib_ConsumerData_Address::TYPE_BILLING;
		$cart             = new WC_Cart();
		$cart->get_cart_from_session();

		//check if shipping address is different
		if ( $cart->needs_shipping_address() && $address == 'shipping' ) {
			$consumer_address = 'shipping';
			$type             = WirecardCEE_Stdlib_ConsumerData_Address::TYPE_SHIPPING;
		}
		switch ( $consumer_address ) {
			case 'shipping':
				$shippingAddress = new WirecardCEE_Stdlib_ConsumerData_Address( $type );

				$shippingAddress->setFirstname( $order->get_shipping_first_name() )
				                ->setLastname( $order->get_shipping_last_name() )
				                ->setAddress1( $order->get_shipping_address_1() )
				                ->setAddress2( $order->get_shipping_address_2() )
				                ->setCity( $order->get_shipping_city() )
				                ->setZipCode( $order->get_shipping_postcode() )
				                ->setCountry( $order->get_shipping_country() )
				                ->setState( $order->get_shipping_state() );

				return $shippingAddress;
			case 'billing':
			default:
				$billing_address = new WirecardCEE_Stdlib_ConsumerData_Address( $type );

				$billing_address->setFirstname( $order->get_billing_first_name() )
				                ->setLastname( $order->get_billing_last_name() )
				                ->setAddress1( $order->get_billing_address_1() )
				                ->setAddress2( $order->get_billing_address_2() )
				                ->setCity( $order->get_billing_city() )
				                ->setZipCode( $order->get_billing_postcode() )
				                ->setCountry( $order->get_billing_country() )
				                ->setPhone( $order->get_billing_phone() )
				                ->setState( $order->get_billing_state() );

				return $billing_address;
		}

	}

	/**
	 * Return the translated name of the paymenttype
	 *
	 * @param
	 *            $code
	 *
	 * @return string void
	 */
	protected function get_paymenttype_name( $code ) {
		return __( $code, 'woocommerce-wcp' );
	}

	/**
	 * Extract the language code from the locale settings
	 *
	 * @return mixed
	 */
	protected function get_language_code() {
		$locale = get_locale();
		$parts  = explode( '_', $locale );

		return $parts[0];
	}

	/**
	 *
	 * @param $order WC_Order
	 *
	 * @return string
	 */
	protected function get_order_description( $order ) {
		return sprintf( 'user_id:%s order_id:%s', $order->get_user_id(), $order->get_id() );
	}

	/**
	 *
	 * @param $order WC_Order
	 *
	 * @return string
	 */
	protected function get_customer_statement( $order ) {
		return sprintf( '%s #%06s', $this->get_vendor(), $order->get_order_number() );
	}

	/**
	 * Get vendor info, i.e.
	 * shopname
	 *
	 * @return mixed void
	 */
	protected function get_vendor() {
		return get_option( 'blogname' );
	}

	/**
	 * Log to file, if enabled
	 *
	 * @param
	 *            $str
	 */
	protected function log( $str, $level = 'notice' ) {
		if ( $this->debug ) {
			if ( empty( $this->log ) ) {
				$this->log = new WC_Logger();
			}
			$this->log->log( $level, 'WirecardCheckoutPage: ' . $str );
		}
	}
}
