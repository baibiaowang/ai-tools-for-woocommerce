<?php
/**
 * v1.8 US compliance & risk — OFF by default.
 *
 * California Prop 65 warnings (per product or per category), shipping restrictions by state / ZIP
 * prefix (fuel, batteries, knives…), an age gate on restricted products, and a fraud blacklist by
 * email / country. Plus two read-only reports: broken links / 404s (from the redirect log) and a
 * basic accessibility check (images without alt text, form controls without a label) — a report, not
 * an overlay widget.
 *
 * Per-product flags are stored in meta and set through hsm-store/compliance action=product_flags.
 */
defined( 'ABSPATH' ) || exit;

function hsm_comp_defaults() {
	return array(
		'prop65'       => array( 'enabled' => false, 'default_warning' => 'WARNING: This product can expose you to chemicals known to the State of California to cause cancer or birth defects. For more information go to www.P65Warnings.ca.gov.' ),
		'ship_restrict' => array( 'enabled' => false, 'blocked_states' => array(), 'blocked_zip_prefixes' => array(), 'message' => 'Sorry, this item cannot be shipped to your location.' ),
		'age'          => array( 'enabled' => false, 'default_min_age' => 18, 'message' => 'This order contains an age-restricted item. Please confirm you meet the age requirement.' ),
		'blacklist'    => array( 'enabled' => false, 'emails' => array(), 'countries' => array(), 'message' => 'We are unable to process this order. Please contact us.' ),
	);
}

function hsm_comp_settings() {
	$o = (array) get_option( 'hsm_compliance', array() );
	$s = array();
	foreach ( hsm_comp_defaults() as $k => $def ) {
		$s[ $k ] = wp_parse_args( is_array( $o[ $k ] ?? null ) ? $o[ $k ] : array(), $def );
	}
	return $s;
}

/** Prop 65 warning for a product ('' = none). */
function hsm_comp_prop65( $product_id ) {
	$s = hsm_comp_settings()['prop65'];
	if ( empty( $s['enabled'] ) ) {
		return '';
	}
	$meta = get_post_meta( $product_id, '_hsm_prop65', true );
	if ( 'none' === $meta ) {
		return '';
	}
	if ( is_string( $meta ) && '' !== trim( $meta ) ) {
		return $meta;
	}
	// Category rule: any product_cat term with a warning.
	$cats = wp_list_pluck( get_the_terms( $product_id, 'product_cat' ) ?: array(), 'slug' );
	foreach ( (array) ( $s['categories'] ?? array() ) as $slug => $w ) {
		if ( in_array( $slug, $cats, true ) && ! empty( $w ) ) {
			return $w;
		}
	}
	return '';
}

function hsm_comp_min_age( $product_id ) {
	$s = hsm_comp_settings()['age'];
	if ( empty( $s['enabled'] ) ) {
		return 0;
	}
	$age = (int) get_post_meta( $product_id, '_hsm_min_age', true );
	return $age > 0 ? $age : (int) $s['default_min_age'];
}

function hsm_comp_restricted_states( $product_id ) {
	$v = get_post_meta( $product_id, '_hsm_restrict_states', true );
	return is_array( $v ) ? array_map( 'strtoupper', $v ) : array();
}

/** Which restrictions the current cart triggers. Returns ['states'=>[], 'zip'=>bool, 'age'=>int, 'prop65'=>[]]. */
function hsm_comp_cart_flags() {
	$s      = hsm_comp_settings();
	$states = array();
	$zip    = false;
	$age    = 0;
	$p65    = array();
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return compact( 'states', 'zip', 'age', 'p65' );
	}
	foreach ( WC()->cart->get_cart() as $item ) {
		$pid = (int) $item['product_id'];
		if ( $s['ship_restrict']['enabled'] ) {
			$states = array_merge( $states, hsm_comp_restricted_states( $pid ) );
		}
		if ( $s['age']['enabled'] ) {
			$age = max( $age, hsm_comp_min_age( $pid ) );
		}
		if ( $s['prop65']['enabled'] ) {
			$w = hsm_comp_prop65( $pid );
			if ( $w ) {
				$p65[ $pid ] = $w;
			}
		}
	}
	return array( 'states' => array_values( array_unique( $states ) ), 'zip' => $zip, 'age' => $age, 'prop65' => $p65 );
}

/* --------------------------------------------------------------------- */
/* Product page: Prop 65 warning                                           */
/* --------------------------------------------------------------------- */

add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( ! $product ) {
		return;
	}
	$w = hsm_comp_prop65( $product->get_id() );
	if ( $w ) {
		echo '<p class="hsm-prop65" style="font-size:13px;color:#8a6d3b;background:#fcf8e3;border:1px solid #faebcc;padding:8px;border-radius:6px">' . esc_html( $w ) . '</p>';
	}
}, 32 );

/* --------------------------------------------------------------------- */
/* Cart / checkout: restrictions, age, blacklist, Prop 65 notice           */
/* --------------------------------------------------------------------- */

add_action( 'woocommerce_check_cart_items', 'hsm_comp_validate_cart' );
add_action( 'woocommerce_store_api_cart_errors', function ( $errors, $cart = null ) {
	$msgs = hsm_comp_validate_cart( true );
	if ( is_object( $errors ) && method_exists( $errors, 'add' ) ) {
		foreach ( $msgs as $m ) {
			$errors->add( 'hsm_compliance', $m );
		}
	}
}, 10, 2 );

/** Returns error messages; also adds Woo notices in the classic flow. */
function hsm_comp_validate_cart( $return = false ) {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return array();
	}
	$s      = hsm_comp_settings();
	$flags  = hsm_comp_cart_flags();
	$msgs   = array();
	$ship   = WC()->customer ? WC()->customer->get_shipping_state() : '';
	$zip    = WC()->customer ? WC()->customer->get_shipping_postcode() : '';
	if ( $s['ship_restrict']['enabled'] && $ship && in_array( strtoupper( $ship ), $flags['states'], true ) ) {
		$msgs[] = $s['ship_restrict']['message'];
	}
	if ( $s['ship_restrict']['enabled'] && $zip ) {
		foreach ( (array) $s['ship_restrict']['blocked_zip_prefixes'] as $pre ) {
			$pre = preg_replace( '/[^0-9A-Za-z]/', '', (string) $pre );
			if ( '' !== $pre && 0 === stripos( preg_replace( '/[^0-9A-Za-z]/', '', $zip ), $pre ) ) {
				$msgs[] = $s['ship_restrict']['message'];
				break;
			}
		}
	}
	if ( $s['prop65']['enabled'] && $flags['prop65'] ) {
		$msgs[] = 'This order includes a product with a California Prop 65 warning.';
	}
	$msgs = array_values( array_unique( $msgs ) );
	if ( ! $return ) {
		foreach ( $msgs as $m ) {
			wc_add_notice( $m, 'error' );
		}
	}
	return $msgs;
}

/** Blacklist by email / country at checkout (classic). */
add_action( 'woocommerce_checkout_process', function () {
	$s = hsm_comp_settings()['blacklist'];
	if ( empty( $s['enabled'] ) ) {
		return;
	}
	$email   = strtolower( sanitize_email( wp_unslash( $_POST['billing_email'] ?? '' ) ) );
	$country = strtoupper( sanitize_text_field( wp_unslash( $_POST['billing_country'] ?? '' ) ) );
	$bl      = array_map( 'strtolower', array_map( 'trim', (array) $s['emails'] ) );
	$bc      = array_map( 'strtoupper', array_map( 'trim', (array) $s['countries'] ) );
	if ( ( $email && in_array( $email, $bl, true ) ) || ( $country && in_array( $country, $bc, true ) ) ) {
		wc_add_notice( $s['message'], 'error' );
	}
} );

/** Blacklist for the block checkout too. */
add_action( 'woocommerce_store_api_checkout_update_order_from_request', function ( $order, $request ) {
	$s = hsm_comp_settings()['blacklist'];
	if ( empty( $s['enabled'] ) ) {
		return;
	}
	$email   = strtolower( (string) $order->get_billing_email() );
	$country = strtoupper( (string) $order->get_billing_country() );
	$bl      = array_map( 'strtolower', array_map( 'trim', (array) $s['emails'] ) );
	$bc      = array_map( 'strtoupper', array_map( 'trim', (array) $s['countries'] ) );
	if ( ( $email && in_array( $email, $bl, true ) ) || ( $country && in_array( $country, $bc, true ) ) ) {
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'hsm_blacklist', esc_html( $s['message'] ), 403 );
	}
}, 10, 2 );

/** Age confirmation at checkout when the cart needs it (classic checkout). */
add_action( 'woocommerce_after_order_notes', function ( $checkout ) {
	$flags = hsm_comp_cart_flags();
	if ( $flags['age'] <= 0 ) {
		return;
	}
	woocommerce_form_field( 'hsm_age_confirm', array(
		'type'     => 'checkbox',
		'label'    => sprintf( 'I confirm I am %d or older.', (int) $flags['age'] ),
		'required' => true,
	), $checkout->get_value( 'hsm_age_confirm' ) );
} );
add_action( 'woocommerce_checkout_process', function () {
	$flags = hsm_comp_cart_flags();
	if ( $flags['age'] > 0 && empty( $_POST['hsm_age_confirm'] ) ) {
		wc_add_notice( hsm_comp_settings()['age']['message'], 'error' );
	}
} );
add_action( 'woocommerce_checkout_create_order', function ( $order, $data ) {
	$flags = hsm_comp_cart_flags();
	if ( $flags['age'] > 0 ) {
		$order->update_meta_data( '_hsm_age_confirmed', ! empty( $data['hsm_age_confirm'] ) ? 'yes' : 'no' );
	}
}, 10, 2 );

/* Age confirmation on block checkout (Store API). The classic hooks above do not run there, so the
   gate needs its own field + validation or a block-checkout order skips it entirely. */
add_action( 'woocommerce_init', function () {
	if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
		return;
	}
	$s = hsm_comp_settings()['age'];
	if ( empty( $s['enabled'] ) ) {
		return;
	}
	woocommerce_register_additional_checkout_field( array(
		'id'       => 'hsm/age-confirm',
		'label'    => sprintf( 'I confirm I am %d or older.', (int) ( $s['default_min_age'] ?: 18 ) ),
		'location' => 'order',
		'type'     => 'checkbox',
		'required' => false,
	) );
} );

add_action( 'woocommerce_store_api_checkout_update_order_from_request', function ( $order, $request ) {
	if ( empty( hsm_comp_settings()['age']['enabled'] ) ) {
		return;
	}
	$age = 0;
	foreach ( $order->get_items() as $item ) {
		$age = max( $age, hsm_comp_min_age( $item->get_product_id() ) );
	}
	if ( $age <= 0 ) {
		return;
	}
	$fields = isset( $request['additional_fields'] ) ? (array) $request['additional_fields'] : array();
	$ok     = ! empty( $fields['hsm/age-confirm'] ) || '1' === (string) $order->get_meta( '_wc_other/hsm/age-confirm' );
	$order->update_meta_data( '_hsm_age_confirmed', $ok ? 'yes' : 'no' );
	if ( ! $ok ) {
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'hsm_age_required', esc_html( hsm_comp_settings()['age']['message'] ), 403 );
	}
}, 20, 2 );

/* --------------------------------------------------------------------- */
/* Reports                                                                 */
/* --------------------------------------------------------------------- */

/** Top 404s / broken links from the redirect log. */
function hsm_comp_broken_links( $limit = 25 ) {
	$log = (array) get_option( 'hsm_404_log', array() );
	uasort( $log, function ( $a, $b ) {
		return ( $b['hits'] ?? 0 ) <=> ( $a['hits'] ?? 0 );
	} );
	$out = array();
	foreach ( array_slice( $log, 0, max( 1, min( 200, $limit ) ), true ) as $path => $e ) {
		$out[] = array( 'path' => $path, 'hits' => (int) ( $e['hits'] ?? 0 ), 'last' => $e['last'] ?? '', 'referer' => $e['referer'] ?? '' );
	}
	return array( 'tracked_paths' => count( $log ), 'top' => $out );
}

/** WCAG relative luminance of a #hex colour, or null. */
function hsm_comp_luminance( $hex ) {
	$hex = ltrim( (string) $hex, '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
		return null;
	}
	$c = array();
	foreach ( array( 0, 2, 4 ) as $i ) {
		$v   = hexdec( substr( $hex, $i, 2 ) ) / 255;
		$c[] = $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
	}
	return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

/** WCAG contrast ratio between two #hex colours, or null. */
function hsm_comp_contrast_ratio( $a, $b ) {
	$la = hsm_comp_luminance( $a );
	$lb = hsm_comp_luminance( $b );
	if ( null === $la || null === $lb ) {
		return null;
	}
	return round( ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 ), 2 );
}

/** Contrast check for the store's configured colours (WCAG AA: 4.5 normal, 3.0 large). */
function hsm_comp_contrast_report() {
	$gs   = function_exists( 'wp_get_global_styles' ) ? wp_get_global_styles() : array();
	$get  = function ( $path ) use ( $gs ) {
		$v = $gs;
		foreach ( $path as $k ) {
			if ( ! is_array( $v ) || ! isset( $v[ $k ] ) ) {
				return '';
			}
			$v = $v[ $k ];
		}
		return is_string( $v ) ? $v : '';
	};
	$text  = $get( array( 'color', 'text' ) );
	$bg    = $get( array( 'color', 'background' ) );
	$link  = $get( array( 'elements', 'link', 'color', 'text' ) );
	$btnbg = $get( array( 'elements', 'button', 'color', 'background' ) );
	$btnfg = $get( array( 'elements', 'button', 'color', 'text' ) );
	$pairs = array();
	if ( $text && $bg ) {
		$pairs[] = array( 'body text on page background', $text, $bg );
	}
	if ( $link && $bg ) {
		$pairs[] = array( 'link on page background', $link, $bg );
	}
	if ( $btnfg && $btnbg ) {
		$pairs[] = array( 'button label on button', $btnfg, $btnbg );
	}
	$out = array();
	foreach ( $pairs as $p ) {
		$r     = hsm_comp_contrast_ratio( $p[1], $p[2] );
		$out[] = array(
			'pair'          => $p[0],
			'fg'            => $p[1],
			'bg'            => $p[2],
			'ratio'         => $r,
			'pass_aa_normal' => null !== $r && $r >= 4.5,
			'pass_aa_large'  => null !== $r && $r >= 3.0,
		);
	}
	if ( ! $out ) {
		$out[] = array( 'note' => 'No explicit text/background colours are set in the theme global styles, so the theme defaults apply (not checked here).' );
	}
	return $out;
}

/** Basic accessibility scan: images without alt text, form controls without a label. */
function hsm_comp_accessibility( $limit = 30 ) {
	$no_alt   = array();
	$no_label = array();
	$posts    = get_posts( array( 'post_type' => array( 'post', 'page', 'product' ), 'numberposts' => 200, 'post_status' => 'publish' ) );
	foreach ( $posts as $p ) {
		if ( preg_match_all( '/<img\b[^>]*>/i', $p->post_content, $m ) ) {
			foreach ( $m[0] as $img ) {
				if ( ! preg_match( '/\balt\s*=\s*["\'][^"\']+["\']/i', $img ) ) {
					$no_alt[] = array( 'post' => $p->post_title, 'id' => $p->ID, 'snippet' => substr( $img, 0, 120 ) );
					break;
				}
			}
		}
		if ( preg_match( '/<(input|select|textarea)\b(?![^>]*type=["\'](hidden|submit|button|checkbox|radio)["\'])/i', $p->post_content ) ) {
			if ( ! preg_match( '/<label\b/i', $p->post_content ) ) {
				$no_label[] = array( 'post' => $p->post_title, 'id' => $p->ID );
			}
		}
	}
	$attachments = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'numberposts' => 500, 'post_status' => 'inherit', 'fields' => 'ids' ) );
	$att_no_alt  = 0;
	foreach ( $attachments as $aid ) {
		if ( '' === trim( (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ) ) ) {
			$att_no_alt++;
		}
	}
	return array(
		'content_images_missing_alt' => array_slice( $no_alt, 0, $limit ),
		'media_missing_alt'          => $att_no_alt,
		'media_total'                => count( $attachments ),
		'content_forms_without_label' => array_slice( $no_label, 0, $limit ),
		'contrast'                   => hsm_comp_contrast_report(),
		'note'                       => 'Report only — fixes are content edits, not an overlay widget.',
	);
}

/* --------------------------------------------------------------------- */
/* MCP tool: hark-store/compliance                                         */
/* --------------------------------------------------------------------- */

function hsm_comp_actions() {
	return array( 'get_settings' => false, 'product_flags' => false, 'broken_links' => false, 'accessibility' => false, 'contrast' => false, 'set_settings' => true, 'set_product_flags' => true );
}

hsm_ability( 'compliance', array(
	'label'       => 'Compliance & risk',
	'description' => 'US compliance and fraud controls. action=get_settings | set_settings (merge a subset of: prop65 {enabled, default_warning, categories:{slug: warning}} | ship_restrict {enabled, blocked_states:[], blocked_zip_prefixes:[], message} | age {enabled, default_min_age, message} | blacklist {enabled, emails:[], countries:[], message}) | product_flags (product_id → its Prop 65 text, restricted states, min age) | set_product_flags (product_id, prop65: text|"none", restrict_states:[], min_age: 0|18|21) | broken_links (top 404s from the redirect log) | accessibility (images without alt, forms without labels, plus a WCAG contrast check of the store colours) | contrast (fg, bg → ratio and WCAG AA pass/fail; omit both to check the store\'s configured colours). Shipping restrictions, the age gate and the blacklist are enforced on both classic and block checkout.',
	'properties'  => array(
		'action'          => hsm_s( 'string', '', array( 'enum' => array_keys( hsm_comp_actions() ) ) ),
		'settings'        => hsm_s( 'object' ),
		'product_id'      => hsm_s( 'integer' ),
		'prop65'          => hsm_s( 'string', 'Warning text, or "none" to clear.' ),
		'restrict_states' => hsm_s( 'array', 'Two-letter state codes.' ),
		'min_age'         => hsm_s( 'integer', '0, 18 or 21.' ),
		'fg'              => hsm_s( 'string', 'contrast: foreground #hex (with bg) for an ad-hoc check.' ),
		'bg'              => hsm_s( 'string', 'contrast: background #hex.' ),
		'limit'           => hsm_s( 'integer' ),
	),
	'is_write'    => function ( $in ) {
		return ! empty( hsm_comp_actions()[ $in['action'] ?? '' ] );
	},
	'callback'    => function ( $in ) {
		$action = (string) ( $in['action'] ?? 'get_settings' );
		if ( ! array_key_exists( $action, hsm_comp_actions() ) ) {
			throw new Exception( 'Unknown action. Allowed: ' . implode( ', ', array_keys( hsm_comp_actions() ) ) );
		}
		if ( 'get_settings' === $action ) {
			return hsm_comp_settings();
		}
		if ( 'broken_links' === $action ) {
			return hsm_comp_broken_links( (int) ( $in['limit'] ?? 25 ) );
		}
		if ( 'accessibility' === $action ) {
			return hsm_comp_accessibility( (int) ( $in['limit'] ?? 30 ) );
		}
		if ( 'contrast' === $action ) {
			// Explicit pair, or the store's own configured colours.
			if ( ! empty( $in['fg'] ) && ! empty( $in['bg'] ) ) {
				$r = hsm_comp_contrast_ratio( (string) $in['fg'], (string) $in['bg'] );
				return array(
					'fg'   => (string) $in['fg'],
					'bg'   => (string) $in['bg'],
					'ratio' => $r,
					'pass_aa_normal' => null !== $r && $r >= 4.5,
					'pass_aa_large'  => null !== $r && $r >= 3.0,
					'note' => 'WCAG AA needs 4.5:1 for normal text and 3:1 for large text (18pt+/14pt bold).',
				);
			}
			return array( 'checks' => hsm_comp_contrast_report() );
		}
		if ( 'product_flags' === $action ) {
			$pid = (int) ( $in['product_id'] ?? 0 );
			if ( ! wc_get_product( $pid ) ) {
				throw new Exception( 'Product not found.' );
			}
			return array(
				'product_id'      => $pid,
				'prop65'          => get_post_meta( $pid, '_hsm_prop65', true ) ?: null,
				'restrict_states' => hsm_comp_restricted_states( $pid ),
				'min_age'         => (int) get_post_meta( $pid, '_hsm_min_age', true ),
			);
		}
		if ( 'set_product_flags' === $action ) {
			$pid = (int) ( $in['product_id'] ?? 0 );
			if ( ! wc_get_product( $pid ) ) {
				throw new Exception( 'Product not found.' );
			}
			if ( array_key_exists( 'prop65', $in ) ) {
				$v = wp_kses_post( (string) $in['prop65'] );
				hsm_undo_add( array( 'type' => 'post_meta', 'id' => $pid, 'key' => '_hsm_prop65', 'old' => get_post_meta( $pid, '_hsm_prop65', true ) ) );
				'' === trim( $v ) ? delete_post_meta( $pid, '_hsm_prop65' ) : update_post_meta( $pid, '_hsm_prop65', $v );
			}
			if ( array_key_exists( 'restrict_states', $in ) ) {
				$states = array_values( array_filter( array_map( function ( $s ) {
					$s = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $s ) );
					return strlen( $s ) === 2 ? $s : '';
				}, (array) $in['restrict_states'] ) ) );
				hsm_undo_add( array( 'type' => 'post_meta', 'id' => $pid, 'key' => '_hsm_restrict_states', 'old' => get_post_meta( $pid, '_hsm_restrict_states', true ) ) );
				$states ? update_post_meta( $pid, '_hsm_restrict_states', $states ) : delete_post_meta( $pid, '_hsm_restrict_states' );
			}
			if ( array_key_exists( 'min_age', $in ) ) {
				$age = in_array( (int) $in['min_age'], array( 18, 21 ), true ) ? (int) $in['min_age'] : 0;
				hsm_undo_add( array( 'type' => 'post_meta', 'id' => $pid, 'key' => '_hsm_min_age', 'old' => get_post_meta( $pid, '_hsm_min_age', true ) ) );
				$age ? update_post_meta( $pid, '_hsm_min_age', $age ) : delete_post_meta( $pid, '_hsm_min_age' );
			}
			return array( 'action' => 'set_product_flags', 'product_id' => $pid, 'flags' => array(
				'prop65'          => get_post_meta( $pid, '_hsm_prop65', true ) ?: null,
				'restrict_states' => hsm_comp_restricted_states( $pid ),
				'min_age'         => (int) get_post_meta( $pid, '_hsm_min_age', true ),
			) );
		}
		// set_settings
		$incoming = (array) ( $in['settings'] ?? array() );
		if ( ! $incoming ) {
			throw new Exception( 'settings must be a non-empty object.' );
		}
		$before = get_option( 'hsm_compliance', array() );
		$new    = $before;
		foreach ( $incoming as $key => $val ) {
			if ( ! isset( hsm_comp_defaults()[ $key ] ) ) {
				throw new Exception( 'Unknown settings key: ' . $key );
			}
			$out = array();
			foreach ( hsm_comp_defaults()[ $key ] as $k => $d ) {
				$v = array_key_exists( $k, (array) $val ) ? $val[ $k ] : $d;
				if ( is_bool( $d ) ) {
					$out[ $k ] = (bool) $v;
				} elseif ( is_int( $d ) ) {
					$out[ $k ] = max( 0, (int) $v );
				} elseif ( in_array( $k, array( 'blocked_states', 'emails', 'countries' ), true ) ) {
					$out[ $k ] = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'trim', (array) $v ) ) ) );
				} elseif ( in_array( $k, array( 'blocked_zip_prefixes' ), true ) ) {
					$out[ $k ] = array_values( array_filter( array_map( function ( $x ) {
						return preg_replace( '/[^0-9A-Za-z]/', '', (string) $x );
					}, (array) $v ) ) );
				} elseif ( 'categories' === $k ) {
					$out[ $k ] = array();
					foreach ( (array) $v as $slug => $w ) {
						$out[ $k ][ sanitize_title( $slug ) ] = wp_kses_post( (string) $w );
					}
				} else {
					$out[ $k ] = wp_kses_post( (string) $v );
				}
			}
			$new[ $key ] = $out;
		}
		hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_compliance', 'old' => $before ) );
		update_option( 'hsm_compliance', $new );
		return array( 'action' => 'set_settings', 'changed' => array_keys( $incoming ), 'settings' => hsm_comp_settings() );
	},
) );
