<?php
/**
 * Plugin Name: AI Tools (Store Infrastructure)
 * Description: Self-contained replacements for the small plugins a store would otherwise install: database + uploads backups, API mail (SES/Mailgun/Postmark), cookie consent + CPRA/GPC, consent-gated ad pixels with server-side Meta CAPI / TikTok Events API / GA4 Measurement Protocol, an optional page cache, a Google Merchant catalog feed, login hardening, email-notification switches and a compliance toolkit (age gate, Prop 65, accessibility) — so no separate backup/mail/consent/pixel/cache/compliance plugins are needed.
 * Version: 1.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author: Hark
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/hsm-core.php';

/** This plugin's MCP namespace (kept on the registry so the three AI plugins can co-exist). */
HSM_Registry::$ns      = 'ai-tools/';
HSM_Registry::$cat     = 'ai-tools';
HSM_Registry::$version = '1.1.0';

$hsm_ns  = HSM_Registry::$ns;
$hsm_pfx = str_replace( '/', '-', $hsm_ns );

require_once __DIR__ . '/includes/hardening.php';
require_once __DIR__ . '/includes/backup.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/consent.php';
require_once __DIR__ . '/includes/pixels.php';
require_once __DIR__ . '/includes/capi.php';
require_once __DIR__ . '/includes/page-cache.php';
require_once __DIR__ . '/includes/compliance.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/abilities-maintenance.php';

/** Ability category. */
add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'ai-tools', array(
		'label'       => 'AI Tools',
		'description' => 'Store infrastructure for AI agents.',
	) );
} );

/** Register a dedicated MCP server exposing only this plugin's abilities. */
add_action( 'mcp_adapter_init', function ( $adapter ) use ( $hsm_ns ) {
	$tools = array_values( array_filter( array_keys( HSM_Registry::$abilities ), function ( $n ) use ( $hsm_ns ) {
		return 0 === strpos( $n, $hsm_ns );
	} ) );
	$adapter->create_server(
		'ai-tools',
		'ai-tools',
		'mcp',
		'AI Tools',
		'Store infrastructure: backups, mail, consent, pixels, cache, compliance.',
		HSM_Registry::$version,
		array( \WP\MCP\Transport\HttpTransport::class ),
		\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
		null,
		$tools,
		array(),
		array(),
		'hsm_transport_permission'
	);
} );

if ( ! function_exists( 'hsm_transport_permission' ) ) {
	/** HTTP transport gate: only staff who can use at least one tool may open an MCP session. */
	function hsm_transport_permission() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		foreach ( array( 'manage_woocommerce', 'manage_options', 'edit_posts', 'upload_files' ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}
		return false;
	}
}

/** tools/list shows only the tools the current user is allowed to call (HTTP and STDIO). */
add_filter( 'mcp_adapter_tools_list', function ( $tools, $server = null ) use ( $hsm_ns, $hsm_pfx ) {
	if ( ! function_exists( 'wp_get_ability' ) ) {
		return $tools;
	}
	return array_values( array_filter( (array) $tools, function ( $t ) use ( $hsm_ns, $hsm_pfx ) {
		$name = is_object( $t ) && method_exists( $t, 'getName' ) ? $t->getName() : ( is_array( $t ) ? ( $t['name'] ?? '' ) : '' );
		if ( 0 !== strpos( $name, $hsm_pfx ) ) {
			return true;
		}
		$ability = wp_get_ability( $hsm_ns . substr( $name, strlen( $hsm_pfx ) ) );
		if ( ! $ability ) {
			return true;
		}
		return true === $ability->check_permissions( array() );
	} ) );
}, 10, 2 );

register_activation_hook( __FILE__, function () {
	hsm_consent_install();
	flush_rewrite_rules();
} );

/** After an upgrade, register new rewrite rules once. */
$hsm_ver = HSM_Registry::$version;
add_action( 'init', function () use ( $hsm_ver ) {
	if ( get_option( 'ai_tools_version' ) !== $hsm_ver ) {
		flush_rewrite_rules( false );
		update_option( 'ai_tools_version', $hsm_ver );
	}
}, 99 );
