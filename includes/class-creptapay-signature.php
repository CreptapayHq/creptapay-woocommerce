<?php
/**
 * Verifies CreptaPay webhook signatures.
 *
 * Header: X-CreptaPay-Signature-V2: t=<unix ms>,v1=<hex>
 * v1 = HMAC-SHA256( secret key, "<t>.<raw request body>" )
 *
 * @package CreptaPay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class CreptaPay_Signature {
	/** Reject deliveries older/newer than this (replay protection). */
	const TOLERANCE_SECONDS = 300;

	/**
	 * @param string   $raw_body Exact request body bytes.
	 * @param string   $header   Value of X-CreptaPay-Signature-V2.
	 * @param string[] $secrets  Secret keys to try (sandbox and/or live).
	 * @param int|null $now_ms   Current time in ms (for tests).
	 * @return string|false The secret that matched, or false.
	 */
	public static function verify( $raw_body, $header, array $secrets, $now_ms = null ) {
		$parts = self::parse_header( $header );
		if ( ! $parts ) {
			return false;
		}

		$now_ms = null === $now_ms ? (int) round( microtime( true ) * 1000 ) : (int) $now_ms;
		if ( abs( $now_ms - $parts['t'] ) > self::TOLERANCE_SECONDS * 1000 ) {
			return false;
		}

		$signed = $parts['t'] . '.' . $raw_body;
		foreach ( $secrets as $secret ) {
			if ( ! is_string( $secret ) || '' === $secret ) {
				continue;
			}
			$expected = hash_hmac( 'sha256', $signed, $secret );
			foreach ( $parts['v1'] as $candidate ) {
				if ( hash_equals( $expected, $candidate ) ) {
					return $secret;
				}
			}
		}
		return false;
	}

	/**
	 * "t=123,v1=abc" -> array( 't' => 123, 'v1' => array( 'abc' ) ).
	 */
	public static function parse_header( $header ) {
		if ( ! is_string( $header ) || '' === $header ) {
			return null;
		}
		$t  = null;
		$v1 = array();
		foreach ( explode( ',', $header ) as $pair ) {
			$kv = explode( '=', trim( $pair ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] && ctype_digit( $kv[1] ) ) {
				$t = (int) $kv[1];
			} elseif ( 'v1' === $kv[0] && preg_match( '/^[a-f0-9]{64}$/', $kv[1] ) ) {
				$v1[] = $kv[1];
			}
		}
		return ( null === $t || ! $v1 ) ? null : array( 't' => $t, 'v1' => $v1 );
	}
}
