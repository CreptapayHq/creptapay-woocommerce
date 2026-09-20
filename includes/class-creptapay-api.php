<?php
/**
 * Minimal CreptaPay REST client built on the WordPress HTTP API.
 *
 * @package CreptaPay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thrown for any failed CreptaPay API call.
 */
class CreptaPay_API_Exception extends Exception {
	/** @var int HTTP status (0 = network error). */
	public $status;

	public function __construct( $message, $status = 0 ) {
		parent::__construct( $message );
		$this->status = (int) $status;
	}
}

class CreptaPay_API {
	const DEFAULT_BASE_URL = 'https://api.creptapay.online/v1';

	/** @var string */
	private $base_url;
	/** @var string */
	private $public_key;
	/** @var string */
	private $secret_key;

	public function __construct( $public_key, $secret_key, $base_url = '' ) {
		$this->public_key = (string) $public_key;
		$this->secret_key = (string) $secret_key;
		$this->base_url   = untrailingslashit( $base_url ? $base_url : self::DEFAULT_BASE_URL );
	}

	/* ------------------------------------------------------------------ */
	/* Payments                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Create a payment (public key).
	 *
	 * @param array $body See POST /payment.
	 * @return array Payment data (id, reference, checkout_url, status, ...).
	 */
	public function create_payment( array $body ) {
		return $this->request( 'POST', '/payment', $body, $this->public_key );
	}

	/**
	 * Fetch a payment by id with the secret key. Authoritative for fulfilment.
	 */
	public function get_payment( $payment_id ) {
		return $this->request( 'GET', '/payment/' . rawurlencode( $payment_id ), null, $this->secret_key );
	}

	/* ------------------------------------------------------------------ */
	/* Webhooks (secret key)                                              */
	/* ------------------------------------------------------------------ */

	public function register_webhook( $url, $description = '' ) {
		return $this->request(
			'POST',
			'/webhooks',
			array(
				'url'         => $url,
				'events'      => array( 'payment.paid', 'payment.underpaid', 'payment.expired' ),
				'description' => $description,
			),
			$this->secret_key
		);
	}

	public function test_webhook( $url ) {
		return $this->request( 'POST', '/webhooks/test', array( 'url' => $url ), $this->secret_key );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * @return array The `data` field of the response.
	 * @throws CreptaPay_API_Exception On network or API errors.
	 */
	private function request( $method, $path, $body, $key ) {
		if ( '' === $key ) {
			throw new CreptaPay_API_Exception( __( 'API key is missing.', 'creptapay-woocommerce' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
				'x-api-key'    => $key,
				'User-Agent'   => 'CreptaPay-WooCommerce/' . CREPTAPAY_WC_VERSION . '; ' . home_url(),
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base_url . $path, $args );

		if ( is_wp_error( $response ) ) {
			throw new CreptaPay_API_Exception(
				/* translators: %s: error message */
				sprintf( __( 'Could not reach CreptaPay: %s', 'creptapay-woocommerce' ), $response->get_error_message() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $json ) && ! empty( $json['message'] )
				? $json['message']
				/* translators: %d: HTTP status */
				: sprintf( __( 'CreptaPay returned HTTP %d', 'creptapay-woocommerce' ), $status );
			throw new CreptaPay_API_Exception( $message, $status );
		}

		return is_array( $json ) && isset( $json['data'] ) ? $json['data'] : array();
	}
}
