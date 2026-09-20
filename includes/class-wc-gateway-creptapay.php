<?php
/**
 * CreptaPay payment gateway for WooCommerce.
 *
 * Flow:
 *   1. Checkout: create a CreptaPay payment and send the customer to the hosted checkout.
 *   2. CreptaPay posts a signed webhook to /wc-api/creptapay/ when the payment changes.
 *   3. The webhook re-reads the payment with the secret key and completes / holds / fails the order.
 *   4. The thank-you page double-checks in case the webhook hasn't arrived yet.
 *
 * @package CreptaPay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Gateway_CreptaPay extends WC_Payment_Gateway {

	const SUPPORTED_CURRENCIES = array( 'USD', 'EUR' );

	/** Order meta keys. */
	const META_PAYMENT_ID  = '_creptapay_payment_id';
	const META_REFERENCE   = '_creptapay_reference';
	const META_ENVIRONMENT = '_creptapay_environment';
	const META_CHECKOUT    = '_creptapay_checkout_url';

	/** @var bool */
	public $testmode;
	/** @var bool */
	public $debug;
	/** @var WC_Logger|null */
	private static $logger;

	public function __construct() {
		$this->id                 = CREPTAPAY_WC_GATEWAY_ID;
		$this->method_title       = __( 'CreptaPay', 'creptapay-woocommerce' );
		$this->method_description = __( 'Accept USDC, EURC and USDT. Customers pay on the secure CreptaPay checkout and orders are confirmed automatically.', 'creptapay-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->icon               = apply_filters( 'creptapay_wc_icon', '' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );
		$this->testmode    = 'yes' === $this->get_option( 'testmode', 'yes' );
		$this->debug       = 'yes' === $this->get_option( 'debug', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_' . $this->id, array( $this, 'handle_webhook' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_check' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/* ================================================================== */
	/* Settings                                                           */
	/* ================================================================== */

	public function webhook_url() {
		return $this->public_https_url( WC()->api_request_url( $this->id ) );
	}

	/**
	 * CreptaPay rejects http URLs except localhost. TasteWP and other proxied
	 * hosts often store the site URL as http even when the shop is on https.
	 */
	private function public_https_url( $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
			return $url;
		}
		return set_url_scheme( $url, 'https' );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'            => array(
				'title'   => __( 'Enable/Disable', 'creptapay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable CreptaPay', 'creptapay-woocommerce' ),
				'default' => 'no',
			),
			'title'              => array(
				'title'       => __( 'Title', 'creptapay-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'What customers see at checkout.', 'creptapay-woocommerce' ),
				'default'     => __( 'Pay with crypto (USDC, EURC, USDT)', 'creptapay-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'        => array(
				'title'       => __( 'Description', 'creptapay-woocommerce' ),
				'type'        => 'textarea',
				'default'     => __( 'You will be taken to CreptaPay to pay with stablecoins on Base, Polygon or Celo.', 'creptapay-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'Shown under the payment method at checkout.', 'creptapay-woocommerce' ),
			),
			'testmode'           => array(
				'title'       => __( 'Sandbox mode', 'creptapay-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Use sandbox (test) keys', 'creptapay-woocommerce' ),
				'default'     => 'yes',
				'description' => __( 'Tick to take test payments with your sandbox keys. Untick to go live.', 'creptapay-woocommerce' ),
			),
			'sandbox_heading'    => array(
				'title' => __( 'Sandbox keys', 'creptapay-woocommerce' ),
				'type'  => 'title',
			),
			'sandbox_public_key' => array(
				'title'       => __( 'Sandbox public key', 'creptapay-woocommerce' ),
				'type'        => 'text',
				'placeholder' => 'pk_test_…',
			),
			'sandbox_secret_key' => array(
				'title'       => __( 'Sandbox secret key', 'creptapay-woocommerce' ),
				'type'        => 'password',
				'placeholder' => 'sk_test_…',
			),
			'live_heading'       => array(
				'title' => __( 'Live keys', 'creptapay-woocommerce' ),
				'type'  => 'title',
			),
			'live_public_key'    => array(
				'title'       => __( 'Live public key', 'creptapay-woocommerce' ),
				'type'        => 'text',
				'placeholder' => 'pk_live_…',
			),
			'live_secret_key'    => array(
				'title'       => __( 'Live secret key', 'creptapay-woocommerce' ),
				'type'        => 'password',
				'placeholder' => 'sk_live_…',
			),
			'webhook_heading'    => array(
				'title'       => __( 'Webhook', 'creptapay-woocommerce' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: webhook URL */
					__( 'Saving your keys registers this URL with CreptaPay automatically, so paid orders are confirmed without any setup:<br><code>%s</code>', 'creptapay-woocommerce' ),
					esc_html( $this->webhook_url() )
				),
			),
			'advanced_heading'   => array(
				'title' => __( 'Advanced', 'creptapay-woocommerce' ),
				'type'  => 'title',
			),
			'api_url'            => array(
				'title'       => __( 'API URL', 'creptapay-woocommerce' ),
				'type'        => 'text',
				'default'     => CreptaPay_API::DEFAULT_BASE_URL,
				'description' => __( 'Leave as is unless CreptaPay support tells you otherwise.', 'creptapay-woocommerce' ),
				'desc_tip'    => true,
			),
			'debug'              => array(
				'title'       => __( 'Debug log', 'creptapay-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log events to WooCommerce → Status → Logs (source: creptapay)', 'creptapay-woocommerce' ),
				'default'     => 'no',
			),
		);
	}

	/**
	 * Show the webhook status for each environment above the settings form.
	 */
	public function admin_options() {
		$status = get_option( 'creptapay_wc_webhook_status', array() );
		echo '<h2>' . esc_html( $this->get_method_title() ) . '</h2>';
		echo wp_kses_post( wpautop( $this->get_method_description() ) );

		if ( $status ) {
			echo '<table class="widefat" style="max-width:720px;margin:0 0 16px"><thead><tr><th>' .
				esc_html__( 'Environment', 'creptapay-woocommerce' ) . '</th><th>' .
				esc_html__( 'Webhook', 'creptapay-woocommerce' ) . '</th><th>' .
				esc_html__( 'Last checked', 'creptapay-woocommerce' ) . '</th></tr></thead><tbody>';
			foreach ( array( 'sandbox', 'production' ) as $env ) {
				if ( empty( $status[ $env ] ) ) {
					continue;
				}
				$s = $status[ $env ];
				echo '<tr><td>' . esc_html( 'sandbox' === $env ? __( 'Sandbox', 'creptapay-woocommerce' ) : __( 'Live', 'creptapay-woocommerce' ) ) . '</td><td>' .
					( ! empty( $s['ok'] ) ? '<span style="color:#008a20">&#10003; ' : '<span style="color:#d63638">&#10007; ' ) .
					esc_html( $s['message'] ) . '</span></td><td>' .
					esc_html( isset( $s['at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $s['at'] ) : '' ) .
					'</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}

	/**
	 * Save settings, then validate keys and register the webhook for every
	 * environment that has a secret key.
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		$this->init_settings();
		$this->testmode = 'yes' === $this->get_option( 'testmode', 'yes' );

		$status = array();
		foreach ( array( 'sandbox', 'production' ) as $env ) {
			$keys = $this->keys_for( $env );
			if ( '' === $keys['public'] && '' === $keys['secret'] ) {
				continue;
			}

			$problem = $this->key_problem( $env, $keys );
			if ( $problem ) {
				$status[ $env ] = array( 'ok' => false, 'message' => $problem, 'at' => time() );
				WC_Admin_Settings::add_error( $problem );
				continue;
			}

			try {
				$api = $this->api( $env );
				$api->register_webhook( $this->webhook_url(), 'WooCommerce: ' . wp_parse_url( home_url(), PHP_URL_HOST ) );
				$message = __( 'Webhook registered', 'creptapay-woocommerce' );
				$ok      = true;

				// Reachability check. Local sites can't receive webhooks, so only warn.
				try {
					$test = $api->test_webhook( $this->webhook_url() );
					if ( empty( $test['ok'] ) ) {
						$message .= ' — ' . sprintf(
							/* translators: %s: HTTP status or error */
							__( 'but a test delivery failed (%s). Make sure this site is publicly reachable over https.', 'creptapay-woocommerce' ),
							! empty( $test['error'] ) ? $test['error'] : 'HTTP ' . (int) $test['status']
						);
						$ok = false;
					} else {
						$message .= ' ' . __( 'and reachable', 'creptapay-woocommerce' );
					}
				} catch ( CreptaPay_API_Exception $e ) {
					self::log( 'Webhook test failed: ' . $e->getMessage() );
				}

				$status[ $env ] = array( 'ok' => $ok, 'message' => $message, 'at' => time() );
				$label          = 'sandbox' === $env ? __( 'Sandbox', 'creptapay-woocommerce' ) : __( 'Live', 'creptapay-woocommerce' );
				if ( $ok ) {
					WC_Admin_Settings::add_message( $label . ': ' . $message );
				} else {
					WC_Admin_Settings::add_error( $label . ': ' . $message );
				}
			} catch ( CreptaPay_API_Exception $e ) {
				$msg            = sprintf(
					/* translators: 1: environment, 2: error */
					__( '%1$s: could not register the webhook: %2$s', 'creptapay-woocommerce' ),
					'sandbox' === $env ? __( 'Sandbox', 'creptapay-woocommerce' ) : __( 'Live', 'creptapay-woocommerce' ),
					$e->getMessage()
				);
				$status[ $env ] = array( 'ok' => false, 'message' => $e->getMessage(), 'at' => time() );
				WC_Admin_Settings::add_error( $msg );
			}
		}

		update_option( 'creptapay_wc_webhook_status', $status, false );
		return $saved;
	}

	/**
	 * Warn when the gateway is enabled but the active environment isn't ready.
	 */
	public function admin_notices() {
		if ( 'yes' !== $this->enabled || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$env  = $this->environment();
		$keys = $this->keys_for( $env );
		if ( '' === $keys['public'] || '' === $keys['secret'] ) {
			$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $this->id );
			echo '<div class="notice notice-warning"><p>' . wp_kses_post(
				sprintf(
					/* translators: 1: environment, 2: settings URL */
					__( 'CreptaPay is enabled but your %1$s keys are missing, so it is hidden at checkout. <a href="%2$s">Add your keys</a>.', 'creptapay-woocommerce' ),
					'sandbox' === $env ? __( 'sandbox', 'creptapay-woocommerce' ) : __( 'live', 'creptapay-woocommerce' ),
					esc_url( $url )
				)
			) . '</p></div>';
		}
	}

	/* ================================================================== */
	/* Keys & environment                                                 */
	/* ================================================================== */

	/** @return string "sandbox" or "production" */
	public function environment() {
		return $this->testmode ? 'sandbox' : 'production';
	}

	public function keys_for( $env ) {
		$prefix = 'sandbox' === $env ? 'sandbox' : 'live';
		return array(
			'public' => trim( (string) $this->get_option( $prefix . '_public_key' ) ),
			'secret' => trim( (string) $this->get_option( $prefix . '_secret_key' ) ),
		);
	}

	/** Returns an error message if the keys don't look right for $env. */
	private function key_problem( $env, array $keys ) {
		$tag   = 'sandbox' === $env ? 'test' : 'live';
		$label = 'sandbox' === $env ? __( 'Sandbox', 'creptapay-woocommerce' ) : __( 'Live', 'creptapay-woocommerce' );
		if ( 0 !== strpos( $keys['public'], "pk_{$tag}_" ) ) {
			/* translators: 1: environment, 2: expected prefix */
			return sprintf( __( '%1$s public key must start with %2$s', 'creptapay-woocommerce' ), $label, "pk_{$tag}_" );
		}
		if ( 0 !== strpos( $keys['secret'], "sk_{$tag}_" ) ) {
			/* translators: 1: environment, 2: expected prefix */
			return sprintf( __( '%1$s secret key must start with %2$s', 'creptapay-woocommerce' ), $label, "sk_{$tag}_" );
		}
		return '';
	}

	/** Secret keys of every configured environment (for webhook verification). */
	private function all_secrets() {
		$secrets = array();
		foreach ( array( 'sandbox', 'production' ) as $env ) {
			$keys = $this->keys_for( $env );
			if ( '' !== $keys['secret'] ) {
				$secrets[ $env ] = $keys['secret'];
			}
		}
		return $secrets;
	}

	public function api( $env = null ) {
		$env  = $env ? $env : $this->environment();
		$keys = $this->keys_for( $env );
		return new CreptaPay_API( $keys['public'], $keys['secret'], $this->get_option( 'api_url' ) );
	}

	/* ================================================================== */
	/* Checkout                                                           */
	/* ================================================================== */

	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		$keys = $this->keys_for( $this->environment() );
		if ( '' === $keys['public'] || '' === $keys['secret'] ) {
			return false;
		}
		return in_array( get_woocommerce_currency(), self::SUPPORTED_CURRENCIES, true );
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'creptapay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$env    = $this->environment();
		$api    = $this->api( $env );
		$amount = (float) wc_format_decimal( $order->get_total(), wc_get_price_decimals() );
		$email  = sanitize_email( $order->get_billing_email() );

		if ( $amount <= 0 ) {
			wc_add_notice( __( 'This order has no amount to charge.', 'creptapay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		if ( ! is_email( $email ) ) {
			wc_add_notice( __( 'A billing email is required to start a crypto payment.', 'creptapay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Customer came back and retried: reuse the open payment if it still matches.
		$existing = $this->reusable_checkout_url( $order, $api, $env );
		if ( $existing ) {
			return array( 'result' => 'success', 'redirect' => $existing );
		}

		$customer = array(
			'email'      => $email,
			'first_name' => $order->get_billing_first_name() ? $order->get_billing_first_name() : __( 'Customer', 'creptapay-woocommerce' ),
			'last_name'  => $order->get_billing_last_name() ? $order->get_billing_last_name() : '-',
		);
		$phone = trim( (string) $order->get_billing_phone() );
		if ( '' !== $phone ) {
			$customer['phone'] = $phone;
		}

		$body = array(
			'amount'       => $amount,
			'currency'     => strtoupper( $order->get_currency() ),
			'description'  => sprintf(
				/* translators: 1: order number, 2: site name */
				__( 'Order #%1$s at %2$s', 'creptapay-woocommerce' ),
				$order->get_order_number(),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			'customer'     => $customer,
			'redirect_url' => $this->public_https_url( $this->get_return_url( $order ) ),
			'metadata'     => array(
				'order_id'  => (string) $order->get_id(),
				'order_key' => $order->get_order_key(),
				'source'    => 'woocommerce',
				'site'      => $this->public_https_url( home_url() ),
			),
		);

		try {
			$payment = $api->create_payment( $body );
		} catch ( CreptaPay_API_Exception $e ) {
			self::log( 'Create payment failed for order ' . $order_id . ': ' . $e->getMessage(), 'error' );
			wc_add_notice(
				sprintf(
					/* translators: %s: error from CreptaPay */
					__( 'We could not start your crypto payment. %s', 'creptapay-woocommerce' ),
					$e->getMessage()
				),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		if ( empty( $payment['checkout_url'] ) || empty( $payment['reference'] ) ) {
			self::log( 'Create payment returned no checkout_url for order ' . $order_id, 'error' );
			wc_add_notice( __( 'We could not start your crypto payment. Please try again.', 'creptapay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( self::META_PAYMENT_ID, (string) $payment['id'] );
		$order->update_meta_data( self::META_REFERENCE, (string) $payment['reference'] );
		$order->update_meta_data( self::META_ENVIRONMENT, $env );
		$order->update_meta_data( self::META_CHECKOUT, (string) $payment['checkout_url'] );
		$order->add_order_note(
			sprintf(
				/* translators: 1: reference, 2: environment */
				__( 'CreptaPay payment started (reference %1$s, %2$s). Waiting for the customer to pay.', 'creptapay-woocommerce' ),
				$payment['reference'],
				'sandbox' === $env ? 'sandbox' : 'live'
			)
		);
		$order->save();

		self::log( 'Order ' . $order_id . ' -> payment ' . $payment['reference'] );

		return array(
			'result'   => 'success',
			'redirect' => $payment['checkout_url'],
		);
	}

	private function reusable_checkout_url( WC_Order $order, CreptaPay_API $api, $env ) {
		$payment_id = $order->get_meta( self::META_PAYMENT_ID );
		$url        = $order->get_meta( self::META_CHECKOUT );
		if ( ! $payment_id || ! $url || $order->get_meta( self::META_ENVIRONMENT ) !== $env ) {
			return '';
		}
		try {
			$payment = $api->get_payment( $payment_id );
		} catch ( CreptaPay_API_Exception $e ) {
			return '';
		}
		$open       = in_array( $payment['status'] ?? '', array( 'pending', 'confirming', 'underpaid' ), true );
		$same_total = abs( (float) ( $payment['amount'] ?? 0 ) - (float) $order->get_total() ) < 0.00001;
		return $open && $same_total ? $url : '';
	}

	/* ================================================================== */
	/* Webhook                                                            */
	/* ================================================================== */

	public function handle_webhook() {
		$raw    = file_get_contents( 'php://input' );
		$header = isset( $_SERVER['HTTP_X_CREPTAPAY_SIGNATURE_V2'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_CREPTAPAY_SIGNATURE_V2'] ) ) : '';

		$secrets = $this->all_secrets();
		$matched = CreptaPay_Signature::verify( (string) $raw, $header, array_values( $secrets ) );
		if ( false === $matched ) {
			self::log( 'Webhook rejected: invalid or missing signature', 'warning' );
			$this->respond( 401, array( 'message' => 'Invalid signature' ) );
		}
		$env = (string) array_search( $matched, $secrets, true );

		$event = json_decode( (string) $raw, true );
		if ( ! is_array( $event ) || empty( $event['event'] ) ) {
			$this->respond( 400, array( 'message' => 'Malformed payload' ) );
		}

		if ( 'webhook.test' === $event['event'] ) {
			$this->respond( 200, array( 'ok' => true ) );
		}

		$data  = isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : array();
		$order = $this->find_order( $data );
		if ( ! $order ) {
			self::log( 'Webhook ' . $event['event'] . ': no matching order for ' . ( $data['reference'] ?? '?' ) );
			// 200 so CreptaPay doesn't keep retrying something we can't match.
			$this->respond( 200, array( 'ok' => true, 'ignored' => 'no matching order' ) );
		}

		$this->sync_order( $order, $env, 'webhook ' . $event['event'] );
		$this->respond( 200, array( 'ok' => true ) );
	}

	/** Find the order from metadata, then confirm it really belongs to this payment. */
	private function find_order( array $data ) {
		$reference = isset( $data['reference'] ) ? (string) $data['reference'] : '';
		$order_id  = isset( $data['metadata']['order_id'] ) ? absint( $data['metadata']['order_id'] ) : 0;

		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order && $reference ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'meta_key'   => self::META_REFERENCE, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value' => $reference, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			$order  = $orders ? $orders[0] : false;
		}
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return false;
		}
		// The order must be linked to this exact payment.
		if ( $reference && $order->get_meta( self::META_REFERENCE ) !== $reference ) {
			return false;
		}
		return $order;
	}

	/**
	 * Re-read the payment from CreptaPay with the secret key and move the order.
	 * Safe to call repeatedly.
	 */
	public function sync_order( WC_Order $order, $env = '', $via = '' ) {
		$env        = $env ? $env : ( $order->get_meta( self::META_ENVIRONMENT ) ? $order->get_meta( self::META_ENVIRONMENT ) : $this->environment() );
		$payment_id = $order->get_meta( self::META_PAYMENT_ID );
		if ( ! $payment_id ) {
			return;
		}

		try {
			$payment = $this->api( $env )->get_payment( $payment_id );
		} catch ( CreptaPay_API_Exception $e ) {
			self::log( 'Could not fetch payment for order ' . $order->get_id() . ': ' . $e->getMessage(), 'error' );
			return;
		}

		$status = $payment['status'] ?? '';
		$ref    = $payment['reference'] ?? '';
		if ( $ref !== $order->get_meta( self::META_REFERENCE ) ) {
			self::log( 'Reference mismatch for order ' . $order->get_id(), 'warning' );
			return;
		}

		$paid_line = sprintf(
			'%s %s%s',
			wc_format_decimal( $payment['amount_paid'] ?? 0 ),
			$payment['crypto_currency'] ?? '',
			! empty( $payment['network'] ) ? ' on ' . $payment['network'] : ''
		);

		if ( in_array( $status, array( 'paid', 'overpaid' ), true ) ) {
			if ( $order->is_paid() ) {
				return;
			}
			$amount_ok   = (float) ( $payment['amount'] ?? 0 ) + 0.00001 >= (float) $order->get_total();
			$currency_ok = strtoupper( (string) ( $payment['currency'] ?? '' ) ) === strtoupper( $order->get_currency() );
			if ( ! $amount_ok || ! $currency_ok ) {
				$order->update_status(
					'on-hold',
					__( 'CreptaPay reports this payment as paid, but its amount or currency does not match the order. Check it in your CreptaPay dashboard.', 'creptapay-woocommerce' )
				);
				return;
			}
			$order->payment_complete( (string) ( $payment['tx_hash'] ?? $ref ) );
			$order->add_order_note(
				sprintf(
					/* translators: 1: amount paid, 2: reference, 3: source */
					__( 'CreptaPay payment received: %1$s (reference %2$s, via %3$s).', 'creptapay-woocommerce' ),
					$paid_line,
					$ref,
					$via
				) . ( 'overpaid' === $status ? ' ' . __( 'The customer overpaid.', 'creptapay-woocommerce' ) : '' )
			);
			return;
		}

		if ( 'underpaid' === $status && $order->has_status( 'pending' ) ) {
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: 1: amount received, 2: expected */
					__( 'CreptaPay: partial payment received (%1$s of %2$s). Waiting for the rest.', 'creptapay-woocommerce' ),
					$paid_line,
					wc_format_decimal( $payment['crypto_amount'] ?? 0 ) . ' ' . ( $payment['crypto_currency'] ?? '' )
				)
			);
			return;
		}

		if ( 'expired' === $status && ! $order->is_paid() && $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			$order->update_status(
				'failed',
				( (float) ( $payment['amount_paid'] ?? 0 ) > 0 )
					? sprintf(
						/* translators: %s: amount received */
						__( 'CreptaPay payment expired after a partial payment (%s). Refund or settle this with the customer.', 'creptapay-woocommerce' ),
						$paid_line
					)
					: __( 'CreptaPay payment expired without payment.', 'creptapay-woocommerce' )
			);
		}
	}

	/**
	 * Thank-you page: if the webhook hasn't landed yet, check once directly.
	 */
	public function thankyou_check( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && ! $order->is_paid() && $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			$this->sync_order( $order, '', 'thank-you page' );
		}
	}

	/* ================================================================== */

	private function respond( $status, array $body ) {
		status_header( $status );
		nocache_headers();
		wp_send_json( $body, $status );
	}

	public static function log( $message, $level = 'info' ) {
		$settings = get_option( 'woocommerce_' . CREPTAPAY_WC_GATEWAY_ID . '_settings', array() );
		if ( 'error' !== $level && ( empty( $settings['debug'] ) || 'yes' !== $settings['debug'] ) ) {
			return;
		}
		if ( ! self::$logger ) {
			self::$logger = wc_get_logger();
		}
		self::$logger->log( $level, $message, array( 'source' => 'creptapay' ) );
	}
}
