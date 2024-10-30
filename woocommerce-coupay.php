<?php
/**
 * Plugin Name: Coupay - Payment Gateway
 * Plugin URI: https://coupay.co.uk/
 * Description: Instant cashback for your shoppers and significantly lower fees for you - the best way to accept payments in UK!
 * Author: Coupay Limited
 * Version: 1.0.3
 */

require_once(plugin_dir_path(__FILE__) . '/lib/autoload.php');

use Coupay\Client as CoupayClient;
use Coupay\Core\CoupayException;
use Coupay\Core\Options;
use Coupay\Payments\QuickPayment;

add_filter('woocommerce_payment_gateways', function ($gateways) {
	$gateways[] = 'WC_Coupay_Gateway';
	return $gateways;
});

add_filter('query_vars', function($qvars) {
	return array_merge($qvars, [
		'transactionRef',
		'order-id',
		'coupay-source'
	]);
});


add_action('plugins_loaded', 'coupay_init_gateway_class');
function coupay_init_gateway_class() {

	if (!class_exists( 'WooCommerce')) {
		add_action('admin_notices', function() {
			echo '<div class="error"><p><strong>Coupay requires <a href="https://woocommerce.com/" target="_blank">WooCommerce</a> to be installed first.</strong></p></div>';
		});
		return false;
	}


    class WC_Coupay_Gateway extends WC_Payment_Gateway {
	    const METADATA_TXN_REF = '_coupay_transaction_uid';

	    /**
	     * These device tokens identify the API as a consumer.
	     * This is unique per SDK/library and not related to a user.
	     */
		const PUBLIC_DEVICE_TOKEN_SANDBOX = '5848cc18-8097-4072-bdad-1f73fc2bc2fe';
		const PUBLIC_DEVICE_TOKEN_PRODUCTION = '97dd870d-4202-4f38-b7e6-e13dcf49d680';

	    /**
	     * @var CoupayClient
	     */
	    private $client;
	    private $private_key;
	    private $device_token;
	    private $webhook_key;

	    public function __construct() {

            $this->id = 'woocommerce-coupay';
		    $this->title = 'Coupay';
            $this->icon = plugins_url( 'resources/images/coupay-icon.png', __FILE__);
            $this->has_fields = true;  // calls $this->payment_fields

            // Details for admin page
            $this->method_title = 'Coupay';
            $this->method_description = 'Coupay - Payments Better';

            // Gateways can support subscriptions, refunds, saved payment methods
            $this->supports = array(
                'products'
           );

            $this->init_form_fields();
            $this->init_settings();
            $this->enabled = $this->get_option('enabled');
			$this->order_button_text = 'Pay now';

			// Customise title on checkout page
		    add_filter('woocommerce_gateway_title', function ($title, $gatewayId)  {
				if ($gatewayId === $this->id && !is_admin()) {
					return 'Coupay - 0.5% instant cashback';
				} else {
					return $title;
				}
		    }, 20, 2);

		    $this->testmode = 'yes' === $this->get_option('testmode');

			if ($this->testmode) {
				$this->device_token = self::PUBLIC_DEVICE_TOKEN_SANDBOX;
				$this->private_key  = $this->get_option('test_private_key');
				$this->webhook_key  = $this->get_option('test_webhook_key');
			} else {
				$this->device_token = self::PUBLIC_DEVICE_TOKEN_PRODUCTION;
				$this->private_key  = $this->get_option('private_key');
				$this->webhook_key  = $this->get_option('webhook_key');
			}

            // Save settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            add_action('woocommerce_api_coupay', array($this, 'webhook'));
		    wp_register_style( 'coupay_styles', plugins_url( 'resources/css/styles.css', __FILE__));
        }


	    public function get_supported_currency() {
		    return apply_filters(
			    'wc_coupay_supported_currencies',
			    [
				    'GBP',
			    ]
		    );
	    }

	    public function is_available() {
		    if ( ! in_array( get_woocommerce_currency(), $this->get_supported_currency() ) ) {
			    return false;
		    }

		    return parent::is_available();
	    }


        public function init_form_fields(){

            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable Coupay Payment Gateway',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'testmode' => array(
                    'title'       => 'Sandbox mode',
                    'label'       => 'Enable sandbox - used for development/testing only.<br />In sandbox mode, no real money transfer will take place and users will be redirected to a "Mock Bank"',
                    'type'        => 'checkbox',
                    'description' => 'When using the "Mock Bank", you\'ll find the test data in the bank\'s login page',
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
                'test_private_key' => array(
                    'title'       => 'Sandbox Private Key',
                    'type'        => 'password',
                ),
                'test_webhook_key' => array(
	                'title'       => 'Sandbox Webhook Key',
	                'type'        => 'password'
                ),
                'private_key' => array(
                    'title'       => 'Live Private Key',
                    'type'        => 'password'
                ),
                'webhook_key' => array(
	                'title'       => 'Live Webhook Key',
	                'type'        => 'password'
                )
           );
        }

        public function payment_fields() {
	        wp_enqueue_style('coupay_styles');

            $description = '<span style="float: right;font-size: 14px;">100 days payment protection <img style="padding-bottom: 4px; padding-left: 5px;" 
				src="'.plugins_url( 'resources/images/shield.jpeg', __FILE__).'" />
			</span>';


            if ($this->testmode) {
                $description = '<b>TEST MODE</b><br />' . $description;
            }

            echo wpautop(wp_kses_post($description));
        }


        public function validate_fields() {
            return true;
        }


        public function process_payment($order_id) {
	        $order = wc_get_order($order_id);
	        $coupay = $this->get_client();

			try {
				$response = $coupay->createQuickPayment((
				new QuickPayment([
					'amount' => $order->get_total(),
					'merchantRef' => 'Order ' . $order_id,
					'customerName' => $order->get_formatted_billing_full_name(),
					'customerEmailAddress' => $order->get_billing_email(),
					'customerPhoneNumber' => $order->get_billing_phone(),
					'suppressNotifications' => true,
					'redirectUri' => add_query_arg( [
						'coupay-source' => 'ob',
						'order-id' => $order_id
					], $this->get_return_url($order))
				])
				));
			} catch (CoupayException $ex) {
				$error = $ex->getMessage();

				if ($ex->getTraceId()) {
					$error .= ' (error reference: '.$ex->getTraceId().')';
				}

				$order->update_status('failed', $error, 'woocommerce-coupay');

				throw $ex;
			}

	        $order->update_meta_data( self::METADATA_TXN_REF, $response->uid);
			$order->save();

            return [
                'result' => 'success',
                'redirect' => $response->customerPaymentUri
            ];

        }


        public function webhook() {
			$headers = $this->get_headers();
			if (isset($headers['x-sha2-signature'])) {
				$body = file_get_contents('php://input');

				if ($this->get_client()->validateWebhookSignature($this->webhook_key, $body, $headers['x-sha2-signature'])) {
					$errors = [];
					try {
						$json = \json_decode($body);
						foreach ($json->payload as $data) {
							$transaction = $data->object;
							$matchingOrders = wc_get_orders( array(
								'orderby'      => 'date',
								'order'        => 'DESC',
								'meta_key'     =>  self::METADATA_TXN_REF,
								'meta_compare' => '=',
								'meta_value'   => 'txn_' . $transaction->transactionRef
							));

							if (count($matchingOrders) === 1) {
								// There can be only one
								$this->completeTransaction($matchingOrders[0], $transaction);
							}
						}
					} catch (\Throwable $t) {
						if (!$t instanceof CoupayException) {
							$errors[] = $t->getMessage();
						}
					}

					header('Content-type: application/json');
					if (count($errors)) {
						echo \json_encode([
							'success' => false,
							'data' => $errors
						]);
					} else {
						echo \json_encode([
							'success' => true
						]);
					}

					exit;
				} else {
					exit("Signature mismatch");
				}
			}
        }

	    public function get_client() {
		    if ($this->client === null) {
			    $this->client = new CoupayClient(new Options([
				    'environment' => $this->testmode ? CoupayClient::ENV_SANDBOX : CoupayClient::ENV_PRODUCTION,
				    'apiKey'      => $this->private_key,
				    'deviceToken' => $this->device_token
			    ]));
		    }

			return $this->client;
	    }

		private function get_headers() {
			$headers = [];
			foreach ( $_SERVER as $name => $value ) {
				if ( 'HTTP_' === substr( $name, 0, 5 ) ) {
					$headers[preg_replace('/[_\-]/', '-', substr(strtolower($name), 5))] = $value;
				}
			}

			return $headers;
		}

	    public function completeTransaction(WC_Abstract_Order $order, $transaction) {

			if (!$order->has_status(['completed', 'processing'])) {
				$transactionRef = $transaction->transactionRef;
				$transactionRefShort = substr($transactionRef, 0, 8);

				switch ($transaction->state) {
					case QuickPayment::STATE_SUCCESS:
						$order->payment_complete($transactionRef);
						$order->add_order_note('Paid via Coupay. Transaction ref: ' . $transactionRefShort);
						break;
					case QuickPayment::STATE_AWAITING_CONFIRMATION:
						$order->update_status( 'on-hold',
							sprintf( __( 'Coupay: Awaiting confirmation from bank, ref: %s.', 'woocommerce-coupay' ), $transactionRefShort)
						);
						break;
					default:
						throw new CoupayException(
							__('Unfortunately, your bank has declined this transaction. Please try again or use another payment method.', 'woocommerce-coupay')
						);
				}
			}
	    }
    }

	// Handle redirects from bank
	add_action( 'wp', function() {
		if (!is_order_received_page() || empty(get_query_var('coupay-source'))|| empty(get_query_var('transactionRef'))) {
			return;
		}

		if (WC()->session->get('order_awaiting_payment')==get_query_var('order-id') && $order = wc_get_order(get_query_var('order-id'))) {
			try {
				if ($order->get_meta(WC_Coupay_Gateway::METADATA_TXN_REF) == 'txn_' . get_query_var('transactionRef')) {
					$coupay = new WC_Coupay_Gateway();
					$transaction = $coupay->get_client()->getTransaction(get_query_var('transactionRef'));
					$coupay->completeTransaction($order, $transaction);
				}
			} catch (CoupayException $e) {
				if (isset($transaction) && in_array($transaction->state, [
						QuickPayment::STATE_FAILED,
						QuickPayment::STATE_INITIALIZED
					])) {
					$order->update_status('failed', __('Rejected by customer\'s bank.', 'woocommerce-coupay'));
				} else {
					$order->update_status('failed', __($e->getMessage(), 'woocommerce-coupay'));
				}


				wc_add_notice($e->getMessage(), 'error');
				wp_safe_redirect( wc_get_checkout_url() );
				exit;
			}
		}
	});
}