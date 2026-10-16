<?php
/**
 * v1.6 Server-side conversion events (Purchase): Meta Conversions API, TikTok Events API 1.3, GA4 Measurement Protocol.
 *
 * Secrets: wp-config constants HSM_CAPI_META_TOKEN, HSM_CAPI_TIKTOK_TOKEN, HSM_CAPI_GA4_SECRET (optional
 * HSM_CAPI_META_TEST_CODE) or the owner-only admin screen (WooCommerce > Hark Ads, manage_options + nonce; option
 * hsm_capi). No MCP tool reads or writes them; status output only says whether each is set. Pixel/measurement IDs
 * come from hsm_pixels (pixels.php).
 * Endpoints: default to the real APIs; overridable (local mock testing) via constants HSM_CAPI_{META,TIKTOK,GA4}_URL,
 * the owner option keys meta_url / tiktok_url / ga4_url, or the filter `hsm_capi_endpoint` ( $url, $platform, $order ).
 *
 * - Consent: captured from the shopper's own checkout request (cookie hsm_consent + Sec-GPC) onto the order.
 *   Meta/TikTok need ads consent (GPC = no). GA4 needs analytics consent; its consent block carries ad_user_data /
 *   ad_personalization = ads consent, and hashed user_data (Google enhanced conversions via GA4) only with ads consent.
 *   Orders without a captured shopper consent (admin/MCP-created) are never sent.
 * - PII normalised then SHA-256 (email, phone E.164 digits, first/last name, city, state, zip, country, external_id).
 *   IP, user agent, fbp/fbc, ttclid/ttp, GA client_id are sent unhashed, as the APIs require.
 * - event_id = 'o' . order_id, identical to the browser pixel Purchase/CompletePayment eventID -> platform dedupe.
 *   GA4 dedupes on transaction_id (= order number, same as gtag purchase).
 * - Once per order per platform (order meta _hsm_capi = per-platform status), atomic per-order lock.
 * - Failures: network error / 429 / 5xx retried up to HSM_CAPI_MAX_TRIES (3) with backoff via Action Scheduler;
 *   other 4xx are final. Every attempt logged in option hsm_capi_log (no tokens, no PII).
 */
defined( 'ABSPATH' ) || exit;

const HSM_CAPI_MAX_TRIES = 3;

function hsm_capi_config() {
	$o = (array) get_option( 'hsm_capi', array() );
	$c = array();
	foreach ( array( 'meta_token', 'tiktok_token', 'ga4_secret', 'meta_test_code', 'meta_url', 'tiktok_url', 'ga4_url' ) as $k ) {
		$const   = 'HSM_CAPI_' . strtoupper( 'ga4_secret' === $k ? 'GA4_SECRET' : $k );
		$c[ $k ] = defined( $const ) ? (string) constant( $const ) : (string) ( $o[ $k ] ?? '' );
	}
	$c['async']  = ! isset( $o['async'] ) || ! empty( $o['async'] );
	$c['source'] = ( defined( 'HSM_CAPI_META_TOKEN' ) || defined( 'HSM_CAPI_TIKTOK_TOKEN' ) || defined( 'HSM_CAPI_GA4_SECRET' ) ) ? 'wp-config' : ( $o ? 'admin' : 'none' );
	return $c;
}

/** Safe status for MCP/CLI: which platforms are configured, never the secrets. */
function hsm_capi_status() {
	$c = hsm_capi_config();
	$p = function_exists( 'hsm_pixels_settings' ) ? hsm_pixels_settings() : array();
	return array(
		'meta'   => array( 'token_set' => '' !== $c['meta_token'], 'pixel_set' => ! empty( $p['meta'] ) ),
		'tiktok' => array( 'token_set' => '' !== $c['tiktok_token'], 'pixel_set' => ! empty( $p['tiktok'] ) ),
		'ga4'    => array( 'secret_set' => '' !== $c['ga4_secret'], 'measurement_id_set' => ! empty( $p['ga4'] ) ),
		'source' => $c['source'],
		'google_ads_enhanced_conversions' => 'via GA4 user_data (link GA4 to Google Ads and import the purchase conversion); direct Google Ads API upload needs a developer token + OAuth (not built in)',
	);
}

/* ---------- normalisation + hashing ---------- */

function hsm_capi_sha( $v ) {
	return '' === (string) $v ? '' : hash( 'sha256', (string) $v );
}
function hsm_capi_norm_email( $v ) {
	$v = strtolower( trim( (string) $v ) );
	return is_email( $v ) ? $v : '';
}
/** Digits only, with country calling code (US/CA 10-digit numbers get a leading 1). Meta wants no '+'. */
function hsm_capi_norm_phone( $v, $country = 'US' ) {
	$d = preg_replace( '/\D/', '', (string) $v );
	if ( '' === $d ) {
		return '';
	}
	$d = ltrim( $d, '0' ) === $d ? $d : ( 0 === strpos( $d, '00' ) ? substr( $d, 2 ) : $d );
	if ( in_array( strtoupper( (string) $country ), array( 'US', 'CA', '' ), true ) && 10 === strlen( $d ) ) {
		$d = '1' . $d;
	}
	return strlen( $d ) >= 8 && strlen( $d ) <= 15 ? $d : '';
}
function hsm_capi_norm_name( $v ) {
	$v = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $v ), 'UTF-8' ) : strtolower( trim( (string) $v ) );
	return preg_replace( '/[^\p{L}\p{N}]/u', '', $v );
}
function hsm_capi_norm_zip( $v, $country = 'US' ) {
	$v = strtolower( preg_replace( '/\s+/', '', (string) $v ) );
	return 'US' === strtoupper( (string) $country ) ? substr( preg_replace( '/[^0-9]/', '', $v ), 0, 5 ) : $v;
}

/** Normalised (unhashed) user fields for an order. */
function hsm_capi_user( $order ) {
	$cc = (string) $order->get_billing_country();
	return array_filter( array(
		'em'          => hsm_capi_norm_email( $order->get_billing_email() ),
		'ph'          => hsm_capi_norm_phone( $order->get_billing_phone(), $cc ),
		'fn'          => hsm_capi_norm_name( $order->get_billing_first_name() ),
		'ln'          => hsm_capi_norm_name( $order->get_billing_last_name() ),
		'ct'          => hsm_capi_norm_name( $order->get_billing_city() ),
		'st'          => strtolower( preg_replace( '/[^A-Za-z]/', '', (string) $order->get_billing_state() ) ),
		'zp'          => hsm_capi_norm_zip( $order->get_billing_postcode(), $cc ),
		'country'     => strtolower( substr( preg_replace( '/[^A-Za-z]/', '', $cc ), 0, 2 ) ),
		'external_id' => $order->get_customer_id() ? (string) $order->get_customer_id() : '',
	) );
}

/* ---------- capture shopper context at checkout (the only request that carries the shopper's cookies) ---------- */

function hsm_capi_cookie( $name, $re ) {
	$v = isset( $_COOKIE[ $name ] ) ? (string) wp_unslash( $_COOKIE[ $name ] ) : ''; // phpcs:ignore
	return preg_match( $re, $v ) ? $v : '';
}

function hsm_capi_capture( $order ) {
	if ( ! $order instanceof WC_Order || $order->get_meta( '_hsm_ads_consent' ) ) {
		return;
	}
	$ads = function_exists( 'hsm_consent' ) && hsm_consent( 'ads' );
	$ana = function_exists( 'hsm_consent' ) && hsm_consent( 'analytics' );
	$order->update_meta_data( '_hsm_ads_consent', $ads ? 'yes' : 'no' );
	$order->update_meta_data( '_hsm_analytics_consent', $ana ? 'yes' : 'no' );
	$ga = hsm_capi_cookie( '_ga', '/^GA\d\.\d\.\d{1,12}\.\d{1,12}$/' );
	$ref = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
	$ctx = array(
		'ip'        => WC_Geolocation::get_ip_address(),
		'ua'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 400 ) : '',
		'url'       => ( $ref && 0 === strpos( $ref, home_url() ) ) ? $ref : ( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' ) ),
		'gpc'       => function_exists( 'hsm_consent_gpc' ) && hsm_consent_gpc(),
		'ga_cid'    => $ga ? implode( '.', array_slice( explode( '.', $ga ), 2 ) ) : '',
	);
	if ( $ads ) { // ad identifiers are only kept when the shopper allowed ads/sharing
		$ctx['fbp']    = hsm_capi_cookie( '_fbp', '/^fb\.\d\.\d{10,14}\.\d{1,25}$/' );
		$ctx['fbc']    = hsm_capi_cookie( '_fbc', '/^fb\.\d\.\d{10,14}\.[A-Za-z0-9_-]{8,500}$/' );
		$ctx['ttclid'] = hsm_capi_cookie( 'hsm_ttclid', '/^[A-Za-z0-9_.-]{8,500}$/' ) ?: hsm_capi_cookie( 'ttclid', '/^[A-Za-z0-9_.-]{8,500}$/' );
		$ctx['ttp']    = hsm_capi_cookie( '_ttp', '/^[A-Za-z0-9_.-]{8,200}$/' );
	}
	$order->update_meta_data( '_hsm_ads_ctx', array_filter( $ctx ) );
}
add_action( 'woocommerce_checkout_order_created', function ( $order ) { // classic checkout
	hsm_capi_capture( $order );
	$order->save();
} );
add_action( 'woocommerce_store_api_checkout_update_order_from_request', function ( $order ) { // block checkout / Store API (saved by WC)
	hsm_capi_capture( $order );
} );

/* ---------- payloads ---------- */

function hsm_capi_endpoint( $platform, $default, $order ) {
	$c   = hsm_capi_config();
	$url = $c[ $platform . '_url' ] ?: $default;
	return (string) apply_filters( 'hsm_capi_endpoint', $url, $platform, $order );
}

function hsm_capi_items( $order ) {
	$items = array();
	foreach ( $order->get_items() as $it ) {
		$pr      = $it->get_product();
		$items[] = array( 'id' => $pr && $pr->get_sku() ? $pr->get_sku() : (string) $it->get_product_id(), 'name' => $it->get_name(), 'quantity' => (int) $it->get_quantity(), 'price' => round( (float) $order->get_item_total( $it ), 2 ) );
	}
	return $items;
}

/** Requests to send for an order, keyed by platform, honouring consent. Each: url, headers, body. */
function hsm_capi_requests( $order ) {
	$c   = hsm_capi_config();
	$p   = function_exists( 'hsm_pixels_settings' ) ? hsm_pixels_settings() : array();
	$ads = 'yes' === $order->get_meta( '_hsm_ads_consent' );
	$ana = 'yes' === $order->get_meta( '_hsm_analytics_consent' );
	$ctx = (array) $order->get_meta( '_hsm_ads_ctx' );
	$created = $order->get_date_paid() ?: $order->get_date_created();
	$t     = $created ? $created->getTimestamp() : time();
	$t     = max( $t, time() - 6 * DAY_IN_SECONDS ); // Meta rejects events older than 7 days
	$eid   = 'o' . $order->get_id();
	$items = hsm_capi_items( $order );
	$value = round( (float) $order->get_total(), 2 );
	$cur   = $order->get_currency();
	$u     = hsm_capi_user( $order );
	$h     = array_map( 'hsm_capi_sha', $u );
	$r     = array();

	if ( $ads && $c['meta_token'] && ! empty( $p['meta'] ) ) {
		$ud = array();
		foreach ( array( 'em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country', 'external_id' ) as $k ) {
			isset( $h[ $k ] ) && $ud[ $k ] = array( $h[ $k ] );
		}
		$ud += array_filter( array( 'client_ip_address' => $ctx['ip'] ?? '', 'client_user_agent' => $ctx['ua'] ?? '', 'fbp' => $ctx['fbp'] ?? '', 'fbc' => $ctx['fbc'] ?? '' ) );
		$body = array(
			'data' => array( array(
				'event_name'       => 'Purchase',
				'event_time'       => $t,
				'event_id'         => $eid,
				'action_source'    => 'website',
				'event_source_url' => $ctx['url'] ?? home_url( '/' ),
				'user_data'        => $ud,
				'custom_data'      => array( 'currency' => $cur, 'value' => $value, 'content_type' => 'product', 'content_ids' => array_column( $items, 'id' ),
					'contents' => array_map( function ( $i ) { return array( 'id' => $i['id'], 'quantity' => $i['quantity'], 'item_price' => $i['price'] ); }, $items ),
					'num_items' => array_sum( array_column( $items, 'quantity' ) ), 'order_id' => (string) $order->get_order_number() ),
			) ),
			'access_token' => $c['meta_token'],
		);
		$c['meta_test_code'] && $body['test_event_code'] = $c['meta_test_code'];
		$r['meta'] = array( 'url' => hsm_capi_endpoint( 'meta', 'https://graph.facebook.com/' . apply_filters( 'hsm_capi_meta_version', 'v21.0' ) . '/' . $p['meta'] . '/events', $order ), 'headers' => array(), 'body' => $body );
	}

	if ( $ads && $c['tiktok_token'] && ! empty( $p['tiktok'] ) ) {
		$user = array_filter( array( 'email' => $h['em'] ?? '', 'phone' => isset( $u['ph'] ) ? hsm_capi_sha( '+' . $u['ph'] ) : '', 'external_id' => $h['external_id'] ?? '',
			'ttclid' => $ctx['ttclid'] ?? '', 'ttp' => $ctx['ttp'] ?? '', 'ip' => $ctx['ip'] ?? '', 'user_agent' => $ctx['ua'] ?? '' ) );
		$r['tiktok'] = array( 'url' => hsm_capi_endpoint( 'tiktok', 'https://business-api.tiktok.com/open_api/v1.3/event/track/', $order ), 'headers' => array( 'Access-Token' => $c['tiktok_token'] ), 'body' => array(
			'event_source'    => 'web',
			'event_source_id' => $p['tiktok'],
			'data'            => array( array(
				'event'      => 'CompletePayment',
				'event_time' => $t,
				'event_id'   => $eid,
				'user'       => $user,
				'page'       => array( 'url' => $ctx['url'] ?? home_url( '/' ) ),
				'properties' => array( 'currency' => $cur, 'value' => $value, 'content_type' => 'product', 'order_id' => (string) $order->get_order_number(),
					'contents' => array_map( function ( $i ) { return array( 'content_id' => $i['id'], 'content_name' => $i['name'], 'quantity' => $i['quantity'], 'price' => $i['price'] ); }, $items ) ),
			) ),
		) );
	}

	if ( $ana && $c['ga4_secret'] && ! empty( $p['ga4'] ) ) {
		$body = array(
			'client_id'        => ! empty( $ctx['ga_cid'] ) ? $ctx['ga_cid'] : ( abs( crc32( $order->get_order_key() ) ) . '.' . $t ),
			'timestamp_micros' => $t * 1000000,
			'consent'          => array( 'ad_user_data' => $ads ? 'GRANTED' : 'DENIED', 'ad_personalization' => $ads ? 'GRANTED' : 'DENIED' ),
			'events'           => array( array( 'name' => 'purchase', 'params' => array( 'transaction_id' => (string) $order->get_order_number(), 'currency' => $cur, 'value' => $value,
				'tax' => round( (float) $order->get_total_tax(), 2 ), 'shipping' => round( (float) $order->get_shipping_total(), 2 ), 'engagement_time_msec' => 1,
				'items' => array_map( function ( $i ) { return array( 'item_id' => $i['id'], 'item_name' => $i['name'], 'quantity' => $i['quantity'], 'price' => $i['price'] ); }, $items ) ) ) ),
		);
		$order->get_customer_id() && $body['user_id'] = (string) $order->get_customer_id();
		if ( $ads ) { // user-provided data for Google enhanced conversions (GA4 -> Google Ads)
			$addr = array_filter( array( 'sha256_first_name' => $h['fn'] ?? '', 'sha256_last_name' => $h['ln'] ?? '', 'sha256_city' => $h['ct'] ?? '',
				'region' => $u['st'] ?? '', 'postal_code' => $u['zp'] ?? '', 'country' => isset( $u['country'] ) ? strtoupper( $u['country'] ) : '' ) );
			$body['user_data'] = array_filter( array( 'sha256_email_address' => isset( $h['em'] ) ? array( $h['em'] ) : array(), 'sha256_phone_number' => isset( $u['ph'] ) ? array( hsm_capi_sha( '+' . $u['ph'] ) ) : array(), 'address' => $addr ? array( $addr ) : array() ) );
		}
		$def = add_query_arg( array( 'measurement_id' => $p['ga4'], 'api_secret' => $c['ga4_secret'] ), 'https://www.google-analytics.com/mp/collect' );
		$url = hsm_capi_endpoint( 'ga4', $def, $order );
		if ( $url !== $def ) {
			$url = add_query_arg( array( 'measurement_id' => $p['ga4'], 'api_secret' => $c['ga4_secret'] ), $url );
		}
		$r['ga4'] = array( 'url' => $url, 'headers' => array(), 'body' => $body );
	}
	return $r;
}

/* ---------- sending, once per platform, with limited retries ---------- */

function hsm_capi_log( $entry ) {
	$log = (array) get_option( 'hsm_capi_log', array() );
	array_unshift( $log, array( 'time' => gmdate( 'c' ) ) + $entry );
	update_option( 'hsm_capi_log', array_slice( $log, 0, 200 ), false );
}

/** Secrets never reach the log: strip tokens and query strings from messages. */
function hsm_capi_scrub( $msg ) {
	$c = hsm_capi_config();
	foreach ( array( 'meta_token', 'tiktok_token', 'ga4_secret' ) as $k ) {
		'' !== $c[ $k ] && $msg = str_replace( $c[ $k ], '***', $msg );
	}
	return substr( preg_replace( '/(api_secret|access_token)=[^&\s"]+/i', '$1=***', (string) $msg ), 0, 300 );
}

function hsm_capi_lock( $id, $release = false ) {
	$k = 'hsm_capi_lock_' . (int) $id;
	if ( $release ) {
		return delete_option( $k );
	}
	if ( add_option( $k, time(), '', 'no' ) ) {
		return true;
	}
	if ( time() - (int) get_option( $k ) > 120 ) { // stale lock from a crashed request
		delete_option( $k );
		return add_option( $k, time(), '', 'no' );
	}
	return false;
}

/** Send whatever is still due for this order. Returns per-platform result codes of this run. */
function hsm_capi_process( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) || ! $order->get_meta( '_hsm_ads_consent' ) ) {
		return array();
	}
	if ( ! hsm_capi_lock( $order->get_id() ) ) {
		return array( 'locked' => true );
	}
	$out = array();
	try {
		$order  = wc_get_order( $order_id ); // fresh copy after taking the lock
		$status = (array) $order->get_meta( '_hsm_capi' );
		$retry  = false;
		foreach ( hsm_capi_requests( $order ) as $k => $q ) {
			$s = (array) ( $status[ $k ] ?? array() ) + array( 'state' => '', 'tries' => 0 );
			if ( in_array( $s['state'], array( 'sent', 'failed' ), true ) || $s['tries'] >= HSM_CAPI_MAX_TRIES ) {
				continue;
			}
			$res  = wp_remote_post( $q['url'], array( 'timeout' => (int) apply_filters( 'hsm_capi_timeout', 5 ), 'redirection' => 0, 'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $q['headers'] ), 'body' => wp_json_encode( $q['body'] ) ) );
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			$s['tries']++;
			$s['code'] = $code;
			$s['time'] = time();
			if ( $code >= 200 && $code < 300 ) {
				$s['state'] = 'sent';
			} elseif ( ( 0 === $code || 429 === $code || $code >= 500 ) && $s['tries'] < HSM_CAPI_MAX_TRIES ) {
				$s['state'] = 'retry';
				$retry      = true;
			} else {
				$s['state'] = 'failed';
			}
			$msg = is_wp_error( $res ) ? $res->get_error_message() : ( $code >= 300 ? wp_remote_retrieve_body( $res ) : '' );
			hsm_capi_log( array( 'order' => $order->get_id(), 'platform' => $k, 'code' => $code, 'try' => $s['tries'], 'state' => $s['state'], 'msg' => hsm_capi_scrub( $msg ) ) );
			$status[ $k ] = $s;
			$out[ $k ]    = $code;
		}
		$order->update_meta_data( '_hsm_capi', $status );
		$order->save_meta_data();
		if ( $retry ) {
			$tries = max( array_map( function ( $s ) { return (int) ( $s['tries'] ?? 0 ); }, $status ) );
			$delay = 60 * ( 5 ** max( 0, $tries - 1 ) ); // 60s, 300s
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + $delay, 'hsm_capi_send', array( $order->get_id() ), 'hsm-capi' );
			} else {
				wp_schedule_single_event( time() + $delay, 'hsm_capi_send', array( $order->get_id() ) );
			}
		}
	} finally {
		hsm_capi_lock( $order_id, true );
	}
	return $out;
}
add_action( 'hsm_capi_send', 'hsm_capi_process' );

/** Paid: queue the send (async keeps checkout fast); inline when Action Scheduler is missing or async is off. */
function hsm_capi_queue( $order_id ) {
	static $seen = array();
	if ( isset( $seen[ $order_id ] ) ) {
		return;
	}
	$seen[ $order_id ] = 1;
	$order = wc_get_order( $order_id );
	if ( ! $order || 'yes' !== $order->get_meta( '_hsm_ads_consent' ) && 'yes' !== $order->get_meta( '_hsm_analytics_consent' ) ) {
		return; // nothing may be sent for this order
	}
	if ( hsm_capi_config()['async'] && function_exists( 'as_enqueue_async_action' ) ) {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( 'hsm_capi_send', array( (int) $order_id ), 'hsm-capi' ) ) {
			as_enqueue_async_action( 'hsm_capi_send', array( (int) $order_id ), 'hsm-capi' );
		}
	} else {
		hsm_capi_process( $order_id );
	}
}
add_action( 'woocommerce_payment_complete', 'hsm_capi_queue' );
add_action( 'woocommerce_order_status_processing', 'hsm_capi_queue' );
add_action( 'woocommerce_order_status_completed', 'hsm_capi_queue' );

/* ---------- owner-only admin screen (pixel IDs + server-side secrets) ---------- */

add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Hark Ads', 'Hark Ads', 'manage_options', 'hsm-ads', function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$secret = array( 'meta_token' => 'Meta CAPI access token', 'tiktok_token' => 'TikTok Events API access token', 'ga4_secret' => 'GA4 Measurement Protocol API secret' );
		$plain  = array( 'meta_test_code' => 'Meta test_event_code (optional)' );
		$pix    = array( 'ga4' => 'GA4 measurement ID (G-…)', 'gads' => 'Google Ads ID (AW-…)', 'gads_label' => 'Google Ads purchase label', 'meta' => 'Meta pixel ID', 'tiktok' => 'TikTok pixel code' );
		if ( isset( $_POST['hsm_ads'] ) && check_admin_referer( 'hsm_ads' ) ) {
			$in = wp_unslash( (array) $_POST['hsm_ads'] );
			$o  = (array) get_option( 'hsm_capi', array() );
			foreach ( $secret as $k => $l ) {
				'' !== trim( (string) ( $in[ $k ] ?? '' ) ) && $o[ $k ] = sanitize_text_field( $in[ $k ] );
				! empty( $in[ $k . '_clear' ] ) && $o[ $k ] = '';
			}
			$o['meta_test_code'] = sanitize_text_field( $in['meta_test_code'] ?? '' );
			update_option( 'hsm_capi', $o, false );
			$px = array();
			foreach ( $pix as $k => $l ) {
				$px[ $k ] = sanitize_text_field( $in[ 'px_' . $k ] ?? '' );
			}
			update_option( 'hsm_pixels', $px );
			echo '<div class="updated"><p>Saved.</p></div>';
		}
		$o  = hsm_capi_config();
		$px = function_exists( 'hsm_pixels_settings' ) ? hsm_pixels_settings() : array();
		echo '<div class="wrap"><h1>Hark Ads</h1><p>Browser pixels load only with consent; server-side events are sent only for orders whose shopper consented (GPC = no ads). Secrets in wp-config constants override this screen.</p><form method="post">';
		wp_nonce_field( 'hsm_ads' );
		echo '<table class="form-table">';
		foreach ( $pix as $k => $l ) {
			echo '<tr><th>' . esc_html( $l ) . '</th><td><input type="text" name="hsm_ads[px_' . esc_attr( $k ) . ']" value="' . esc_attr( $px[ $k ] ?? '' ) . '" class="regular-text"></td></tr>';
		}
		foreach ( $secret as $k => $l ) {
			echo '<tr><th>' . esc_html( $l ) . '</th><td><input type="password" autocomplete="new-password" name="hsm_ads[' . esc_attr( $k ) . ']" value="" placeholder="' . ( '' !== $o[ $k ] ? '(saved — leave blank to keep)' : '' ) . '" class="regular-text"> <label><input type="checkbox" name="hsm_ads[' . esc_attr( $k ) . '_clear]" value="1"> clear</label></td></tr>';
		}
		foreach ( $plain as $k => $l ) {
			echo '<tr><th>' . esc_html( $l ) . '</th><td><input type="text" name="hsm_ads[' . esc_attr( $k ) . ']" value="' . esc_attr( $o[ $k ] ) . '" class="regular-text"></td></tr>';
		}
		echo '</table>';
		submit_button();
		echo '</form><h2>Recent server-side sends</h2><pre style="max-height:300px;overflow:auto">' . esc_html( wp_json_encode( array_slice( (array) get_option( 'hsm_capi_log', array() ), 0, 30 ), JSON_PRETTY_PRINT ) ) . '</pre></div>';
	} );
} );

add_action( 'init', function () {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::add_command( 'hark-store capi', function ( $args ) {
			$cmd = $args[0] ?? 'status';
			if ( 'log' === $cmd ) {
				WP_CLI::line( wp_json_encode( array_slice( (array) get_option( 'hsm_capi_log', array() ), 0, 20 ) ) );
			} elseif ( 'send' === $cmd && ! empty( $args[1] ) ) {
				WP_CLI::line( wp_json_encode( hsm_capi_process( (int) $args[1] ) ) );
			} else {
				WP_CLI::line( wp_json_encode( hsm_capi_status() ) );
			}
		} );
	}
} );
