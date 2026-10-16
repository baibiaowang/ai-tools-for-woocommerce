<?php
if (!defined('ABSPATH')) exit;
// Front-end hardening: version leaks, XML-RPC, user enumeration, login messages.
add_filter('xmlrpc_enabled', '__return_false');
add_filter('xmlrpc_methods', '__return_empty_array');
remove_action('wp_head', 'wp_generator');
add_filter('the_generator', '__return_empty_string');
// Don't leak the WordPress core version through ?ver= on core scripts/styles; use a short hash so caches still bust on upgrade.
function hsm_hide_core_ver( $src ) {
	if ( ! is_string( $src ) || false === strpos( $src, 'ver=' ) ) { return $src; }
	$wpv = get_bloginfo( 'version' );
	if ( $wpv && preg_match( '/[?&]ver=' . preg_quote( $wpv, '/' ) . '(&|$)/', $src ) ) {
		$src = add_query_arg( 'ver', substr( md5( $wpv . wp_salt( 'nonce' ) ), 0, 8 ), $src );
	}
	return $src;
}
add_filter( 'style_loader_src', 'hsm_hide_core_ver', 9999 );
add_filter( 'script_loader_src', 'hsm_hide_core_ver', 9999 );
add_action('init', function () {
    if (function_exists('wc')) { remove_action('wp_head', 'wc_generator_tag'); }
    remove_action('wp_head', 'wc_generator_tag', 99);
}, 99);
add_filter('rest_endpoints', function ($e) {
    if (!is_user_logged_in()) {
        foreach (array_keys($e) as $r) { if (preg_match('#^/wp/v2/users#', $r)) unset($e[$r]); }
    }
    return $e;
});
add_action('template_redirect', function () {
    if (is_author() || (isset($_GET['author']) && !is_admin())) { wp_safe_redirect(home_url('/'), 301); exit; }
});
add_filter('oembed_response_data', function ($d) { unset($d['author_name'], $d['author_url']); return $d; });
add_filter('login_errors', function () { global $errors; if (is_wp_error($errors) && in_array('hsm_locked', $errors->get_error_codes(), true)) { return 'Too many failed login attempts. Try again in 15 minutes.'; } return 'Incorrect login details.'; });
add_filter('wp_sitemaps_add_provider', function ($p, $n) { return $n === 'users' ? false : $p; }, 10, 2);

// Security headers (front end + login)
add_action('send_headers', function () {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    // HSTS only over TLS (never send it on plain HTTP, it would be ignored and is a footgun).
    if (is_ssl()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header_remove('X-Powered-By');
});
// ?author=N must not reveal usernames
add_action('parse_request', function ($wp) {
    if (!is_admin() && isset($_GET['author']) && !is_user_logged_in()) {
        wp_safe_redirect(home_url('/'), 301); exit;
    }
});
// Block XML-RPC at request level (some routers bypass the filter)
add_action('init', function () {
    if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) { status_header(403); exit('XML-RPC disabled'); }
}, 0);

// --- v1.2: no theme/plugin file editor in wp-admin (a stolen admin session cannot drop PHP into the site).
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}

// --- v1.2: login brute-force limit. 5 failures for one username from one IP in 15 minutes lock that
// username from that IP for 15 minutes. Keyed per IP + username so a shared proxy/CDN IP or an attacker
// guessing one account does not lock every other user out
// (wp-login.php, WooCommerce My Account login, XML-RPC and application passwords all count).
function hsm_login_ip() {
	return preg_replace( '/[^0-9a-f:.]/i', '', $_SERVER['REMOTE_ADDR'] ?? '0' );
}
function hsm_login_user( $username = null ) {
	if ( null === $username || '' === $username ) {
		$username = $_SERVER['PHP_AUTH_USER'] ?? ( $_POST['log'] ?? ( $_POST['username'] ?? '' ) );
	}
	return strtolower( trim( (string) $username ) );
}
function hsm_login_key( $username = null ) {
	return 'hsm_lf_' . md5( hsm_login_ip() . '|' . hsm_login_user( $username ) );
}
function hsm_login_limits() {
	$o = (array) get_option( 'hsm_login_limit', array() );
	return array( max( 3, (int) ( $o['max'] ?? 5 ) ), max( 60, (int) ( $o['window'] ?? 900 ) ) );
}
function hsm_login_locked( $username = null ) {
	list( $max ) = hsm_login_limits();
	return (int) get_transient( hsm_login_key( $username ) ) >= $max;
}
function hsm_login_fail( $username = null ) {
	list( , $win ) = hsm_login_limits();
	$k = hsm_login_key( is_string( $username ) ? $username : null );
	set_transient( $k, (int) get_transient( $k ) + 1, $win );
}
add_filter( 'authenticate', function ( $user, $username = '', $password = '' ) {
	if ( '' !== (string) $username && hsm_login_locked( $username ) ) {
		return new WP_Error( 'hsm_locked', 'Too many failed login attempts. Try again in 15 minutes.' );
	}
	return $user;
}, 99, 3 );
add_action( 'wp_login_failed', 'hsm_login_fail' );
add_action( 'application_password_failed_authentication', 'hsm_login_fail' );
add_filter( 'wp_authenticate_application_password_errors', function ( $error ) {
	if ( hsm_login_locked() ) {
		$error->add( 'hsm_locked', 'Too many failed login attempts. Try again in 15 minutes.' );
	}
	return $error;
} );
add_action( 'wp_login', function ( $login ) {
	delete_transient( hsm_login_key( $login ) );
} );

// --- v1.3.1: per-IP limits on anonymous endpoints that cost a full DB query each (search) or write
// sessions (Store API cart). Logged-in shop staff are never limited. Option hsm_public_limits overrides.
function hsm_public_limit( $bucket, $max, $window = 60 ) {
	$o   = (array) get_option( 'hsm_public_limits', array() );
	$max = (int) ( $o[ $bucket ] ?? $max );
	if ( $max <= 0 ) {
		return true;
	}
	$k = 'hsm_rl_' . $bucket . '_' . md5( hsm_login_ip() );
	$n = (int) get_transient( $k ) + 1;
	set_transient( $k, $n, $window );
	return $n <= $max;
}
add_action( 'template_redirect', function () {
	if ( is_search() && ! current_user_can( 'edit_posts' ) && ! hsm_public_limit( 'search', 30 ) ) {
		status_header( 429 );
		header( 'Retry-After: 60' );
		nocache_headers();
		exit( 'Too many searches. Please wait a minute.' );
	}
}, 0 );
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
	if ( null !== $result || current_user_can( 'edit_posts' ) ) {
		return $result;
	}
	$route = $request->get_route();
	if ( 0 === strpos( $route, '/wc/store/' ) && 'GET' !== $request->get_method() && ! hsm_public_limit( 'store_api', 60 ) ) {
		return new WP_Error( 'hsm_rate_limited', 'Too many requests. Please wait a minute.', array( 'status' => 429 ) );
	}
	if ( 0 === strpos( $route, '/wc/store/' ) && 'GET' === $request->get_method() && ! hsm_public_limit( 'store_api_read', 300 ) ) {
		return new WP_Error( 'hsm_rate_limited', 'Too many requests. Please wait a minute.', array( 'status' => 429 ) );
	}
	return $result;
}, 5, 3 );
