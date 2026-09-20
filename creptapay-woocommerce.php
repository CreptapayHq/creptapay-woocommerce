<?php
/**
 * Plugin Name:       CreptaPay for WooCommerce
 * Plugin URI:        https://creptapay.online
 * Description:       Accept USDC, EURC and USDT in WooCommerce with CreptaPay. Supports sandbox and live keys, and confirms orders automatically through a signed webhook.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            CreptaPay
 * Author URI:        https://creptapay.online
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       creptapay-woocommerce
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   9.3
 *
 * @package CreptaPay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'CREPTAPAY_WC_VERSION', '0.1.0' );
define( 'CREPTAPAY_WC_FILE', __FILE__ );
define( 'CREPTAPAY_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'CREPTAPAY_WC_URL', plugin_dir_url( __FILE__ ) );
define( 'CREPTAPAY_WC_GATEWAY_ID', 'creptapay' );

/**
 * Declare compatibility with WooCommerce order storage (HPOS) and the Checkout block.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Boot once all plugins are loaded, so WooCommerce's classes exist.
 */
add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'creptapay-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'CreptaPay for WooCommerce needs WooCommerce to be installed and active.', 'creptapay-woocommerce' ) .
						'</p></div>';
				}
			);
			return;
		}

		require_once CREPTAPAY_WC_PATH . 'includes/class-creptapay-api.php';
		require_once CREPTAPAY_WC_PATH . 'includes/class-creptapay-signature.php';
		require_once CREPTAPAY_WC_PATH . 'includes/class-wc-gateway-creptapay.php';

		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = 'WC_Gateway_CreptaPay';
				return $gateways;
			}
		);
	}
);

/**
 * Checkout block support.
 */
add_action(
	'woocommerce_blocks_loaded',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}
		require_once CREPTAPAY_WC_PATH . 'includes/class-creptapay-blocks.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new CreptaPay_Blocks_Support() );
			}
		);
	}
);

/**
 * "Settings" link on the Plugins screen.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . CREPTAPAY_WC_GATEWAY_ID );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'creptapay-woocommerce' ) . '</a>' );
		return $links;
	}
);
