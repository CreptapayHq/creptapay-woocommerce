<?php
/**
 * Remove plugin settings when the plugin is deleted (not on deactivate).
 * Order notes and payment references on orders are kept for your records.
 *
 * @package CreptaPay\WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_creptapay_settings' );
delete_option( 'creptapay_wc_webhook_status' );
