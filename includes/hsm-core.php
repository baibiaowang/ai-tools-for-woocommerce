<?php
/**
 * Shared framework for the AI Hand / AI Tools / AI Design plugins:
 * ability registry + runner, two-step confirmation, guard rails, rate limit, undo and audit.
 * Loaded once — whichever plugin loads first defines it; the rest reuse it.
 */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'HSM_CORE_LOADED' ) ) {
define( 'HSM_CORE_LOADED', true );

class HSM_Registry {
	public static $abilities = array();
	/** Current plugin's namespace / category / version — set by each plugin's entry before it loads its ability files. */
	public static $ns      = '';
	public static $cat     = '';
	public static $version = '1.0.0';

	public static function add( $name, $args ) {
		self::$abilities[ $name ] = $args;
	}

	public static function register_all() {
		foreach ( self::$abilities as $name => $a ) {
			$cap = $a['cap'] ?? 'manage_woocommerce';
			wp_register_ability( $name, array(
				'label'               => $a['label'],
				'description'         => $a['description'],
				'category'            => $a['_cat'] ?? self::$cat,
				'input_schema'        => array_filter( array(
					'type'       => 'object',
					'properties' => ( $a['properties'] ?? array() ) + ( empty( $a['readonly'] ) ? array( 'confirm_token' => array( 'type' => 'string', 'description' => 'Only when a previous call answered CONFIRMATION_REQUIRED: pass the token it gave, with identical other arguments, after the store owner approved.' ) ) : array() ),
					'required'   => $a['required'] ?? array(),
				) ) + array( 'type' => 'object' ),
				'execute_callback'    => function ( $input = array() ) use ( $a, $name ) {
					return hsm_run_ability( $name, $a, is_array( $input ) ? $input : array() );
				},
				'permission_callback' => function () use ( $cap ) {
					if ( 'manage_woocommerce' === $cap && ! class_exists( 'WooCommerce' ) ) {
						return current_user_can( 'manage_options' );
					}
					return current_user_can( $cap );
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => (bool) ( $a['readonly'] ?? false ),
						'destructive' => (bool) ( $a['destructive'] ?? false ),
						'idempotent'  => (bool) ( $a['idempotent'] ?? false ),
					),
					'mcp'          => array( 'public' => true, 'type' => 'tool' ),
				),
			) );
		}
	}
}
add_action( 'wp_abilities_api_init', array( 'HSM_Registry', 'register_all' ) );

/** Runs an ability with guard rails, confirmation, audit and undo capture. */
function hsm_run_ability( $name, $a, $input ) {
	// Mixed tools (action=list|get|search|log...) are writes only for their mutating actions.
	$read_actions                   = array( 'list', 'get', 'search', 'log', 'shipping_classes', 'list_missing_alt' );
	$write                          = empty( $a['readonly'] ) && ! ( isset( $input['action'] ) && in_array( $input['action'], $read_actions, true ) );
	// Tools that read unless given certain arguments (store-settings without "set", rest-request GET).
	if ( $write && isset( $a['is_write'] ) && is_callable( $a['is_write'] ) ) {
		$write = (bool) call_user_func( $a['is_write'], $input );
	}
	$GLOBALS['hsm_current_ability'] = $name;
	$GLOBALS['hsm_undo']            = array();
	$GLOBALS['hsm_confirmed']       = null;
	$GLOBALS['hsm_pending_reprice'] = 0;
	$GLOBALS['hsm_audit_seq']       = ( $GLOBALS['hsm_audit_seq'] ?? 0 ) + 1;
	// A long-lived STDIO server must never act on stale data (stock sold on the web, prices edited in
	// wp-admin, guard limits changed by the owner): drop the in-process object cache before every call.
	if ( function_exists( 'wp_cache_flush_runtime' ) && wp_cache_supports( 'flush_runtime' ) ) {
		wp_cache_flush_runtime();
	} elseif ( ! wp_using_ext_object_cache() ) {
		wp_cache_flush();
	}
	try {
		if ( $write ) {
			if ( hsm_guard( 'read_only' ) && empty( $a['allow_in_read_only'] ) ) {
				throw new HSM_Blocked( 'The store is in read-only mode (guard read_only). No changes are allowed.' );
			}
			hsm_rate_limit();
		}
		$result = call_user_func( $a['callback'], $input );
		if ( $write ) {
			$dry = is_array( $result ) && ! empty( $result['dry_run'] );
			if ( ! $dry ) {
				$aid = hsm_audit_write( $name, $input, $result, 'ok' );
				! empty( $GLOBALS['hsm_pending_reprice'] ) && hsm_daily_counter( 'reprice', (int) $GLOBALS['hsm_pending_reprice'] );
				do_action( 'hsm_after_write', $name, $aid ); // e.g. page cache purge
				if ( is_array( $result ) && ( ! $result || array_keys( $result ) !== range( 0, count( $result ) - 1 ) ) ) {
					$result = array( 'audit_id' => $aid, 'undoable' => ! empty( $GLOBALS['hsm_undo'] ) ) + $result;
				}
			}
		}
		return $result;
	} catch ( HSM_Confirm_Required $e ) {
		return new WP_Error( 'hsm_confirmation_required', sprintf(
			"CONFIRMATION_REQUIRED — nothing was changed.\nReason: %s\nPreview: %s\nShow this to the store owner. Only if they approve, call %s again with exactly the same arguments plus \"confirm_token\": \"%s\" (single use, valid %d minutes).",
			$e->getMessage(),
			wp_json_encode( $e->preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			str_replace( '/', '-', $name ),
			$e->token,
			(int) ( hsm_guard( 'token_ttl' ) / 60 )
		), array( 'status' => 409, 'confirm_token' => $e->token, 'preview' => $e->preview ) );
	} catch ( HSM_Blocked $e ) {
		$write && hsm_audit_write( $name, $input, null, 'blocked', $e->getMessage() );
		return new WP_Error( 'hsm_blocked', 'BLOCKED by store safety rules — nothing was changed. ' . $e->getMessage(), array( 'status' => 403 ) );
	} catch ( \Throwable $e ) {
		$write && ! empty( $GLOBALS['hsm_undo'] ) && hsm_audit_write( $name, $input, null, 'error', $e->getMessage() );
		return new WP_Error( 'hsm_error', $e->getMessage(), array( 'status' => 400 ) );
	}
}

function hsm_ability( $name, $args ) {
	$args['_cat'] = HSM_Registry::$cat; // Capture now: registration is deferred, and other plugins may reset the static later.
	HSM_Registry::add( HSM_Registry::$ns . $name, $args );
}

function hsm_require_wc() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		throw new Exception( 'WooCommerce is not active. Activate it with hark-store/site-extensions.' );
	}
}

/** Schema shorthands. */
function hsm_s( $type, $desc = '', $extra = array() ) {
	return array_merge( array( 'type' => $type, 'description' => $desc ), $extra );
}

function hsm_paginate( $in, $default = 20 ) {
	$per  = max( 1, min( 100, (int) ( $in['per_page'] ?? $default ) ) );
	$page = max( 1, (int) ( $in['page'] ?? 1 ) );
	return array( $per, $page );
}

/** Write deny rules (index/.htaccess/web.config) into a directory so its files cannot be listed or served. */
function hsm_backup_protect( $d ) {
	$files = array(
		'index.php'  => "<?php\n// Silence is golden.\nhttp_response_code(404);\n",
		'index.html' => '',
		'.htaccess'  => "# Hark Store: never served over HTTP\nOptions -Indexes\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n",
		'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization><directoryBrowse enabled=\"false\" /></system.webServer></configuration>\n",
	);
	foreach ( $files as $f => $c ) {
		if ( ! file_exists( "$d/$f" ) ) {
			file_put_contents( "$d/$f", $c );
		}
	}
}

class HSM_Confirm_Required extends Exception {
	public $preview;
	public $token;
	public function __construct( $reason, $preview, $token ) {
		parent::__construct( $reason );
		$this->preview = $preview;
		$this->token   = $token;
	}
}

class HSM_Blocked extends Exception {}

function hsm_guard_defaults() {
	return array(
		'read_only'               => false,  // Block every write.
		'require_confirmation'    => true,   // Two-step confirm for risky actions.
		'min_price'               => 0.01,   // Hard floor for any regular/sale price (0 = allow free).
		'max_price_drop_percent'  => 30,     // Bigger drops need confirmation.
		'max_price_raise_percent' => 100,    // Bigger raises need confirmation.
		'max_bulk_items'          => 200,    // Hard cap per bulk call.
		'confirm_bulk_over'       => 20,     // Bulk calls touching more items need confirmation.
		'max_coupon_percent'      => 30,     // Percent coupons above this need confirmation.
		'max_coupon_fixed'        => 20,     // Fixed coupons above this amount need confirmation.
		'hard_max_coupon_percent' => 90,     // Never allowed above this.
		'max_refund_without_confirm' => 0,   // Every refund needs confirmation by default.
		'hard_max_price_drop_percent' => 0,  // Drops bigger than this are blocked even with confirmation (0 = off).
		'min_margin_percent'      => 0,      // Price must be >= cost x (1 + this/100) when a product has a cost (_hsm_cost). Blocked otherwise.
		'max_refund_per_day'      => 0,      // Hard cap on the total refunded through MCP per day (store currency, 0 = off).
		'max_price_changes_per_day' => 0,    // Hard cap on products/variations re-priced through MCP per day (0 = off).
		'token_ttl'               => 900,    // Seconds.
		'rate_limit_per_minute'   => 60,     // Write calls per user per minute.
		'audit_retention_days'    => 180,
	);
}

function hsm_guard( $key = null ) {
	global $wpdb;
	// Read straight from the database so limits changed by the owner apply immediately, even to a running server.
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'hsm_guard' ) );
	$g   = wp_parse_args( (array) maybe_unserialize( $raw ), hsm_guard_defaults() );
	return null === $key ? $g : ( $g[ $key ] ?? null );
}

/* --------------------------------------------------------------------- */
/* Confirmation tokens                                                     */
/* --------------------------------------------------------------------- */

function hsm_canonical( $v ) {
	if ( is_array( $v ) ) {
		if ( array_keys( $v ) !== range( 0, count( $v ) - 1 ) ) {
			ksort( $v );
		}
		return array_map( 'hsm_canonical', $v );
	}
	return is_bool( $v ) || is_null( $v ) ? $v : (string) $v;
}

function hsm_input_hash( $ability, $input ) {
	unset( $input['confirm_token'] );
	return hash( 'sha256', get_current_user_id() . '|' . $ability . '|' . wp_json_encode( hsm_canonical( $input ) ) );
}

/**
 * Call inside an ability before a risky change. Returns silently when the caller already holds
 * a valid single-use token for exactly these arguments; otherwise throws HSM_Confirm_Required.
 */
function hsm_confirm( $in, $reason, $preview = array() ) {
	if ( ! hsm_guard( 'require_confirmation' ) ) {
		return;
	}
	$ability = $GLOBALS['hsm_current_ability'] ?? 'unknown';
	$hash    = hsm_input_hash( $ability, $in );
	if ( ! empty( $in['confirm_token'] ) ) {
		$tok    = preg_replace( '/[^A-Za-z0-9]/', '', (string) $in['confirm_token'] );
		$stored = get_transient( 'hsm_tok_' . $tok );
		if ( $stored && hash_equals( $stored['hash'], $hash ) && (int) $stored['user'] === get_current_user_id() ) {
			delete_transient( 'hsm_tok_' . $tok ); // Single use.
			$GLOBALS['hsm_confirmed'] = $reason;
			return;
		}
		$reason = 'The confirm_token is invalid, expired, already used, or the arguments changed since it was issued. ' . $reason;
	}
	$tok = wp_generate_password( 12, false );
	set_transient( 'hsm_tok_' . $tok, array( 'hash' => $hash, 'user' => get_current_user_id(), 'ability' => $ability ), (int) hsm_guard( 'token_ttl' ) );
	throw new HSM_Confirm_Required( $reason, $preview, $tok );
}

function hsm_rate_limit() {
	$limit = (int) hsm_guard( 'rate_limit_per_minute' );
	if ( $limit <= 0 ) {
		return;
	}
	$key = 'hsm_rl_' . get_current_user_id() . '_' . gmdate( 'YmdHi' );
	$n   = (int) get_transient( $key ) + 1;
	set_transient( $key, $n, 120 );
	if ( $n > $limit ) {
		throw new HSM_Blocked( "Rate limit: more than $limit write calls in one minute. Slow down." );
	}
}

/* --------------------------------------------------------------------- */
/* Audit log + undo                                                        */
/* --------------------------------------------------------------------- */

add_action( 'init', function () {
	register_post_type( 'hsm_audit', array( 'public' => false, 'show_ui' => false, 'rewrite' => false, 'query_var' => false, 'supports' => array( 'title' ) ) );
} );

function hsm_undo_add( $entry ) {
	$GLOBALS['hsm_undo'][] = $entry;
}

function hsm_apply_undo( $entries ) {
	$log = array();
	foreach ( array_reverse( $entries ) as $e ) {
		switch ( $e['type'] ) {
			case 'product':
				$log[] = hsm_restore_product( $e['state'] );
				break;
			case 'product_created':
				$p = wc_get_product( $e['id'] );
				if ( $p ) {
					if ( $p->is_type( 'variable' ) ) {
						foreach ( $p->get_children() as $c ) {
							wc_get_product( $c ) && wc_get_product( $c )->delete( true );
						}
					}
					$p->delete( false );
				}
				$log[] = 'trashed created product ' . $e['id'];
				break;
			case 'option':
				null === $e['old'] ? delete_option( $e['name'] ) : update_option( $e['name'], $e['old'] );
				'permalink_structure' === $e['name'] && flush_rewrite_rules();
				$log[] = 'restored option ' . $e['name'];
				break;
			case 'post_meta':
				null === $e['old'] ? delete_post_meta( $e['id'], $e['key'] ) : update_post_meta( $e['id'], $e['key'], $e['old'] );
				$log[] = "restored meta {$e['key']} on {$e['id']}";
				break;
			case 'term_meta':
				'' === $e['old'] ? delete_term_meta( $e['id'], $e['key'] ) : update_term_meta( $e['id'], $e['key'], $e['old'] );
				$log[] = "restored term meta {$e['key']} on {$e['id']}";
				break;
			case 'user_meta':
				null === $e['old'] ? delete_user_meta( $e['id'], $e['key'] ) : update_user_meta( $e['id'], $e['key'], $e['old'] );
				$log[] = "restored user meta {$e['key']} on {$e['id']}";
				break;
			case 'coupon':
				if ( empty( $e['state'] ) ) {
					wp_delete_post( $e['id'], true );
					$log[] = 'deleted created coupon ' . $e['id'];
				} else {
					$c = new WC_Coupon( $e['id'] );
					$c->set_props( $e['state'] );
					$c->save();
					$log[] = 'restored coupon ' . $e['id'];
				}
				break;
			case 'coupon_deleted':
				$c = new WC_Coupon();
				$c->set_props( $e['state'] );
				$c->save();
				$log[] = 'recreated deleted coupon ' . $e['state']['code'];
				break;
			case 'comment':
				if ( ! empty( $e['created'] ) ) {
					$pc = get_comment( $e['id'] );
					wp_delete_comment( $e['id'], true );
					$pc && class_exists( 'WC_Comments' ) && WC_Comments::clear_transients( $pc->comment_post_ID );
					$log[] = 'deleted created review ' . $e['id'];
				} else {
					$map = array( 'approved' => 'approve', 'unapproved' => 'hold', 'spam' => 'spam', 'trash' => 'trash' );
					wp_set_comment_status( $e['id'], $map[ $e['old_status'] ] ?? 'hold' );
					$log[] = 'restored review status ' . $e['id'];
				}
				break;
			case 'theme':
				switch_theme( $e['old'] );
				$log[] = 'switched theme back to ' . $e['old'];
				break;
			case 'post':
				if ( ! empty( $e['created'] ) ) {
					wp_trash_post( $e['id'] );
					$log[] = 'trashed created post ' . $e['id'];
				} else {
					wp_update_post( wp_slash( $e['state'] ) );
					$log[] = 'restored post ' . $e['id'];
				}
				break;
		}
	}
	return $log;
}

function hsm_audit_write( $ability, $input, $result, $status, $message = '' ) {
	unset( $input['confirm_token'] );
	$summary = is_array( $result ) ? array_intersect_key( $result, array_flip( array( 'action', 'id', 'count', 'refund_id', 'amount', 'deleted', 'changed', 'added', 'dry_run' ) ) ) : array();
	$id      = wp_insert_post( array(
		'post_type'    => 'hsm_audit',
		'post_status'  => 'publish',
		'post_title'   => $ability . ' — ' . $status,
		'post_author'  => get_current_user_id(),
		'post_content' => wp_slash( wp_json_encode( array(
			'ability'   => $ability,
			'status'    => $status,
			'message'   => $message,
			'input'     => $input,
			'summary'   => $summary,
			'confirmed' => $GLOBALS['hsm_confirmed'] ?? null,
			'undo'      => $GLOBALS['hsm_undo'] ?? array(),
			'undone'    => false,
			'via'       => defined( 'WP_CLI' ) && WP_CLI ? 'stdio' : 'http',
		), JSON_UNESCAPED_UNICODE ) ),
	) );
	return $id;
}

function hsm_audit_read( $id ) {
	$p = get_post( (int) $id );
	if ( ! $p || 'hsm_audit' !== $p->post_type ) {
		return null;
	}
	$d               = json_decode( $p->post_content, true ) ?: array();
	$d['id']         = $p->ID;
	$d['time']       = get_post_time( 'Y-m-d H:i:s', false, $p );
	$d['user']       = get_the_author_meta( 'user_login', $p->post_author );
	$d['undoable']   = ! empty( $d['undo'] ) && empty( $d['undone'] ) && 'ok' === ( $d['status'] ?? '' );
	return $d;
}

/** Purge old audit entries once a day. */
add_action( 'hsm_daily', function () {
	$days = (int) hsm_guard( 'audit_retention_days' );
	$old  = get_posts( array( 'post_type' => 'hsm_audit', 'numberposts' => 500, 'fields' => 'ids', 'date_query' => array( array( 'before' => "$days days ago" ) ) ) );
	foreach ( $old as $id ) {
		wp_delete_post( $id, true );
	}
} );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'hsm_daily' ) ) {
		wp_schedule_event( time() + 3600, 'daily', 'hsm_daily' );
	}
} );

/* --------------------------------------------------------------------- */
/* Input sanitizing                                                        */
/* --------------------------------------------------------------------- */

/**
 * HTML written by an agent is always filtered to post-safe HTML (no <script>, <iframe>, event handlers),
 * even for administrators: a prompt-injected agent must not be able to plant scripts on the storefront.
 * Opt out with add_filter( 'hsm_allow_unfiltered_html', '__return_true' ).
 */
function hsm_html( $html ) {
	return ( apply_filters( 'hsm_allow_unfiltered_html', false ) && current_user_can( 'unfiltered_html' ) ) ? (string) $html : wp_kses_post( (string) $html );
}

/** Is this path inside one of the allowed import directories? */
function hsm_path_allowed( $path ) {
	$real = realpath( $path );
	if ( ! $real || ! is_file( $real ) ) {
		return false;
	}
	$dirs = array( wp_get_upload_dir()['basedir'], WP_CONTENT_DIR . '/hsm-import' );
	$dirs = apply_filters( 'hsm_allowed_import_dirs', array_merge( $dirs, (array) get_option( 'hsm_import_dirs', array() ) ) );
	foreach ( $dirs as $d ) {
		$rd = realpath( $d );
		if ( $rd && 0 === strpos( $real, rtrim( $rd, '/' ) . '/' ) ) {
			return true;
		}
	}
	return false;
}

/* --------------------------------------------------------------------- */
/* WP-CLI: the only way (besides wp-admin) to change guard settings        */
/* --------------------------------------------------------------------- */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'hark-store guard', function ( $args ) {
		$g = hsm_guard();
		if ( empty( $args ) || 'list' === $args[0] ) {
			foreach ( $g as $k => $v ) {
				WP_CLI::line( str_pad( $k, 28 ) . wp_json_encode( $v ) );
			}
			return;
		}
		if ( 'set' === $args[0] && isset( $args[1], $args[2] ) ) {
			if ( ! array_key_exists( $args[1], hsm_guard_defaults() ) ) {
				WP_CLI::error( 'Unknown guard key.' );
			}
			$cur              = (array) get_option( 'hsm_guard', array() );
			$v                = $args[2];
			$cur[ $args[1] ]  = in_array( $v, array( 'true', 'false' ), true ) ? 'true' === $v : ( is_numeric( $v ) ? $v + 0 : $v );
			update_option( 'hsm_guard', $cur );
			WP_CLI::success( "{$args[1]} = " . wp_json_encode( $cur[ $args[1] ] ) );
			return;
		}
		if ( 'reset' === $args[0] ) {
			delete_option( 'hsm_guard' );
			WP_CLI::success( 'Guard reset to defaults.' );
			return;
		}
		WP_CLI::error( 'Usage: wp hark-store guard [list|set <key> <value>|reset]' );
	} );
}

/* Simple admin screen to view/change guard settings (humans only). */
add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Hark Store Safety', 'Hark Store Safety', 'manage_options', 'hsm-safety', function () {
		if ( isset( $_POST['hsm_guard'] ) && check_admin_referer( 'hsm_guard' ) ) {
			$new = array();
			foreach ( hsm_guard_defaults() as $k => $def ) {
				$v         = wp_unslash( $_POST['hsm_guard'][ $k ] ?? '' );
				$new[ $k ] = is_bool( $def ) ? ! empty( $v ) : ( is_numeric( $v ) ? $v + 0 : $def );
			}
			update_option( 'hsm_guard', $new );
			echo '<div class="updated"><p>Saved.</p></div>';
		}
		echo '<div class="wrap"><h1>Hark Store Safety</h1><p>Limits that AI agents cannot change.</p><form method="post">';
		wp_nonce_field( 'hsm_guard' );
		echo '<table class="form-table">';
		foreach ( hsm_guard() as $k => $v ) {
			$def = hsm_guard_defaults()[ $k ];
			echo '<tr><th>' . esc_html( $k ) . '</th><td>';
			if ( is_bool( $def ) ) {
				echo '<input type="checkbox" name="hsm_guard[' . esc_attr( $k ) . ']" value="1" ' . checked( $v, true, false ) . '>';
			} else {
				echo '<input type="text" name="hsm_guard[' . esc_attr( $k ) . ']" value="' . esc_attr( $v ) . '">';
			}
			echo '</td></tr>';
		}
		echo '</table>';
		submit_button();
		echo '</form></div>';
	} );
} );

}
