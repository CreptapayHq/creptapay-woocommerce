<?php
/**
 * Makes CreptaPay available in the WooCommerce Checkout block.
 *
 * @package CreptaPay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class CreptaPay_Blocks_Support extends AbstractPaymentMethodType {

	protected $name = 'creptapay';

	/** @var WC_Gateway_CreptaPay|null */
	private $gateway;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_creptapay_settings', array() );
		$gateways       = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$this->gateway  = isset( $gateways[ $this->name ] ) ? $gateways[ $this->name ] : null;
	}

	public function is_active() {
		return $this->gateway && $this->gateway->is_available();
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'creptapay-blocks',
			CREPTAPAY_WC_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			CREPTAPAY_WC_VERSION,
			true
		);
		return array( 'creptapay-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
			'supports'    => $this->gateway ? array_values( array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) ) ) : array( 'products' ),
		);
	}
}
