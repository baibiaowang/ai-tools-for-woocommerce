<?php
/**
 * v1.5 Simple full-page cache for anonymous visitors — OFF by default.
 *
 * Purpose: measure whether a self-written page cache is safe with WooCommerce. A page is stored only when it is
 * a plain 200 HTML page for a visitor with no WordPress/WooCommerce cookies, no session started during the
 * request (no Set-Cookie), no no-cache/private headers, not cart/checkout/my-account/search/preview/404, empty
 * cart, and prices not geolocated. It is served only to requests that also carry none of those cookies, use
 * GET/HEAD and have no query string other than ad click ids/utm. The cache key includes the Sec-GPC header.
 * Any content, price, stock, term, menu, theme, option or MCP write purges everything.
 *
 * Owner switches it with `wp hark-store cache on|off` (not via MCP). Host/CDN caching is still preferred.
 */
defined( 'ABSPATH' ) || exit;

function hsm_pc_settings() {
	$s = wp_parse_args( (array) get_option( 'hsm_page_cache', array() ), array( 'enabled' => false, 'ttl' => 600 ) );
	$s['ttl'] = max( 60, min( 86400, (int) $s['ttl'] ) );
	return $s;
}

function hsm_pc_dir() {
	return WP_CONTENT_DIR . '/cache/hsm-page';
}

/** Why this request must not use the cache ('' = cacheable). */
function hsm_pc_request_bypass() {
	$m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
	if ( 'GET' !== $m && 'HEAD' !== $m ) {
		return 'method';
	}
	if ( defined( 'WP_CLI' ) || defined( 'REST_REQUEST' ) || defined( 'DOING_CRON' ) || defined( 'DOING_AJAX' ) || defined( 'XMLRPC_REQUEST' ) || is_admin() ) {
		return 'context';
	}
	if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['PHP_AUTH_USER'] ) ) {
		return 'auth';
	}
	$path = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
	if ( preg_match( '#^/(wp-admin|wp-login\.php|wp-json|xmlrpc\.php|wp-cron\.php|wp-content|wp-includes)|/feed/?$|\.(xml|txt|php)$#', $path ) ) {
		return 'path';
	}
	foreach ( array_keys( $_GET ) as $k ) {
		if ( ! preg_match( '/^(utm_[a-z]+|gclid|gbraid|wbraid|fbclid|msclkid|ttclid|_ga|mc_cid|mc_eid)$/', (string) $k ) ) {
			return 'query';
		}
	}
	foreach ( array_keys( $_COOKIE ) as $c ) {
		if ( preg_match( '/^(wordpress_(?!test_cookie)|wp-postpass_|comment_author_|woocommerce_|wp_woocommerce_session_|wp-settings-|hsm_nocache)/', (string) $c ) ) {
			return 'cookie';
		}
	}
	return '';
}

function hsm_pc_key() {
	$path = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
	$host = strtolower( preg_replace( '/[^a-z0-9.:-]/i', '', $_SERVER['HTTP_HOST'] ?? '' ) );
	$gpc  = ( isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === trim( $_SERVER['HTTP_SEC_GPC'] ) ) ? 'gpc' : '';
	return md5( ( is_ssl() ? 'https' : 'http' ) . '|' . $host . '|' . rawurldecode( $path ) . '|' . $gpc );
}

/* Serve (as early as a plugin can: before WooCommerce sessions, the query and the theme). */
add_action( 'plugins_loaded', function () {
	if ( ! hsm_pc_settings()['enabled'] ) {
		return;
	}
	$why = hsm_pc_request_bypass();
	if ( $why ) {
		if ( 'context' !== $why ) {
			header( 'X-HSM-Cache: BYPASS ' . $why );
		}
		return;
	}
	$f = hsm_pc_dir() . '/' . hsm_pc_key() . '.html';
	if ( is_file( $f ) && ( time() - filemtime( $f ) ) < hsm_pc_settings()['ttl'] ) {
		$html = file_get_contents( $f );
		if ( false !== $html ) {
			header( 'Content-Type: text/html; charset=UTF-8' );
			header( 'X-HSM-Cache: HIT' );
			header( 'Age: ' . ( time() - filemtime( $f ) ) );
			header( 'Cache-Control: no-cache' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'X-Frame-Options: SAMEORIGIN' );
			header( 'Referrer-Policy: strict-origin-when-cross-origin' );
			if ( 'HEAD' !== $_SERVER['REQUEST_METHOD'] ) {
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- stored page.
			}
			exit;
		}
	}
	$GLOBALS['hsm_pc_candidate'] = true;
	header( 'X-HSM-Cache: MISS' );
}, 0 );

/** Why the generated page must not be stored ('' = store). */
function hsm_pc_response_bypass( $html ) {
	if ( 200 !== http_response_code() ) {
		return 'status';
	}
	if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
		return 'donotcachepage';
	}
	if ( is_user_logged_in() ) {
		return 'logged_in';
	}
	if ( is_404() || is_search() || is_preview() || is_feed() || is_robots() || is_trackback() || post_password_required() ) {
		return 'page_type';
	}
	if ( function_exists( 'is_woocommerce' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
		return 'woo_page';
	}
	if ( function_exists( 'WC' ) ) {
		if ( WC()->cart && ! WC()->cart->is_empty() ) {
			return 'cart';
		}
		if ( WC()->session && WC()->session->has_session() ) {
			return 'session';
		}
		if ( 'geolocation' === get_option( 'woocommerce_default_customer_address' ) ) {
			return 'geolocated_prices';
		}
	}
	foreach ( headers_list() as $h ) {
		if ( 0 === stripos( $h, 'Set-Cookie:' ) ) {
			return 'set_cookie';
		}
		if ( 0 === stripos( $h, 'Cache-Control:' ) && preg_match( '/no-store|no-cache|private/i', $h ) ) {
			return 'no_cache_header';
		}
		if ( 0 === stripos( $h, 'Content-Type:' ) && false === stripos( $h, 'text/html' ) ) {
			return 'not_html';
		}
	}
	if ( strlen( $html ) < 255 || false === stripos( $html, '</html>' ) ) {
		return 'incomplete';
	}
	return (string) apply_filters( 'hsm_pc_bypass', '', $html );
}

add_action( 'template_redirect', function () {
	if ( empty( $GLOBALS['hsm_pc_candidate'] ) ) {
		return;
	}
	ob_start( function ( $html ) {
		$why = hsm_pc_response_bypass( $html );
		if ( $why ) {
			if ( ! headers_sent() ) {
				header( 'X-HSM-Cache: MISS no-store ' . $why );
			}
			return $html;
		}
		$dir = hsm_pc_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( "$dir/index.php", "<?php\nhttp_response_code(404);\n" );
		}
		$tmp = "$dir/." . hsm_pc_key() . '.' . getmypid() . '.tmp';
		if ( false !== file_put_contents( $tmp, $html . "\n<!-- hsm-page-cache " . gmdate( 'c' ) . " -->" ) ) {
			rename( $tmp, "$dir/" . hsm_pc_key() . '.html' );
		}
		return $html;
	} );
}, PHP_INT_MAX );

function hsm_pc_purge() {
	$n = 0;
	foreach ( glob( hsm_pc_dir() . '/*.html' ) ?: array() as $f ) {
		@unlink( $f ) && ++$n;
	}
	return $n;
}

// Purge everything on any change that can show on a page.
foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status',
	'woocommerce_variation_set_stock_status', 'woocommerce_update_product', 'woocommerce_update_product_variation', 'created_term', 'edited_term', 'delete_term',
	'comment_post', 'wp_set_comment_status', 'edit_comment', 'switch_theme', 'customize_save_after', 'wp_update_nav_menu', 'hsm_after_write', 'woocommerce_reduce_order_stock',
	'woocommerce_restore_order_stock', 'hsm_pc_purge' ) as $hook ) {
	// Purge immediately every time (no per-request de-duplication: the MCP STDIO server is one long-lived
	// process whose "request" never ends, and a product save fires save_post before its new price is stored —
	// woocommerce_update_product afterwards purges again, closing that race).
	add_action( $hook, function () {
		if ( is_dir( hsm_pc_dir() ) ) {
			hsm_pc_purge();
		}
	}, 99 );
}
add_action( 'updated_option', function ( $name ) {
	if ( preg_match( '/^(blogname|blogdescription|woocommerce_|hsm_seo|hsm_consent|hsm_redirects|sidebars_widgets|theme_mods_|page_on_front|show_on_front|permalink_structure)/', $name ) && is_dir( hsm_pc_dir() ) ) {
		hsm_pc_purge();
	}
} );

function hsm_pc_maint( $action ) {
	if ( 'cache_purge' === $action ) {
		return array( 'action' => 'cache_purge', 'purged' => hsm_pc_purge() );
	}
	$s = hsm_pc_settings();
	return array( 'enabled' => $s['enabled'], 'ttl' => $s['ttl'], 'cached_pages' => count( glob( hsm_pc_dir() . '/*.html' ) ?: array() ),
		'note' => 'Off by default; host/CDN page caching with WooCommerce bypass rules is preferred. The owner switches it with `wp hark-store cache on|off`.' );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'hark-store cache', function ( $args ) {
		$cmd = $args[0] ?? 'status';
		$o   = (array) get_option( 'hsm_page_cache', array() );
		if ( in_array( $cmd, array( 'on', 'off' ), true ) ) {
			$o['enabled'] = 'on' === $cmd;
			update_option( 'hsm_page_cache', $o );
			hsm_pc_purge();
			WP_CLI::success( 'Page cache ' . $cmd );
		} elseif ( 'ttl' === $cmd && isset( $args[1] ) ) {
			$o['ttl'] = (int) $args[1];
			update_option( 'hsm_page_cache', $o );
			WP_CLI::success( 'ttl ' . hsm_pc_settings()['ttl'] );
		} elseif ( 'purge' === $cmd ) {
			WP_CLI::success( hsm_pc_purge() . ' pages purged' );
		} else {
			WP_CLI::line( wp_json_encode( hsm_pc_maint( 'cache_status' ) ) );
		}
	} );
}
