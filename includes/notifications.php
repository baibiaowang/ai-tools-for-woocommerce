<?php
/**
 * v1.8 Email notifications control — OFF by default.
 *
 * Turn individual WooCommerce notification emails on or off (a noisy store can silence "new account"
 * or "on-hold" without touching the important ones), and read the outgoing mail log the mailer module
 * keeps. Owner-facing only: these are delivery switches, not content.
 */
defined( 'ABSPATH' ) || exit;

function hsm_notif_settings() {
	$s = wp_parse_args( (array) get_option( 'hsm_notifications', array() ), array( 'disabled' => array() ) );
	$s['disabled'] = array_values( array_filter( array_map( 'sanitize_key', (array) $s['disabled'] ) ) );
	return $s;
}

/** Every WooCommerce email id => its title, for the settings screen and the tool. */
function hsm_notif_email_list() {
	if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
		return array();
	}
	$out = array();
	foreach ( WC()->mailer()->get_emails() as $email ) {
		// get_emails() is keyed by class name; the real filter id is the email's own ->id.
		if ( is_object( $email ) && ! empty( $email->id ) ) {
			$out[ $email->id ] = $email->get_title();
		}
	}
	return $out;
}

add_action( 'woocommerce_init', function () {
	if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
		return;
	}
	$disabled = hsm_notif_settings()['disabled'];
	if ( ! $disabled ) {
		return;
	}
	foreach ( hsm_notif_email_list() as $id => $title ) {
		if ( in_array( $id, $disabled, true ) ) {
			add_filter( 'woocommerce_email_enabled_' . $id, '__return_false', 99 );
		}
	}
} );

/* --------------------------------------------------------------------- */
/* MCP tool: hark-store/notifications                                      */
/* --------------------------------------------------------------------- */

hsm_ability( 'notifications', array(
	'label'       => 'Email notification switches',
	'description' => 'Turn individual WooCommerce notification emails on or off, and read the outgoing mail log. action=list (every email id with its title and current on/off state) | get_settings | set_settings (disabled:[email ids to silence]) | mail_log (recent outgoing send attempts from the mailer module). Only the listed ids are silenced; the rest keep sending.',
	'properties'  => array(
		'action'   => hsm_s( 'string', '', array( 'enum' => array( 'list', 'get_settings', 'set_settings', 'mail_log' ) ) ),
		'settings' => hsm_s( 'object' ),
		'limit'    => hsm_s( 'integer' ),
	),
	'is_write'    => function ( $in ) {
		return 'set_settings' === ( $in['action'] ?? '' );
	},
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$action = (string) ( $in['action'] ?? 'list' );
		if ( ! in_array( $action, array( 'list', 'get_settings', 'set_settings', 'mail_log' ), true ) ) {
			throw new Exception( 'Unknown action. Allowed: list, get_settings, set_settings, mail_log.' );
		}
		if ( 'get_settings' === $action ) {
			return hsm_notif_settings();
		}
		if ( 'mail_log' === $action ) {
			$log = array_slice( (array) get_option( 'hsm_mail_log', array() ), 0, max( 1, min( 100, (int) ( $in['limit'] ?? 20 ) ) ) );
			return array( 'entries' => $log );
		}
		if ( 'set_settings' === $action ) {
			$incoming = (array) ( $in['settings'] ?? array() );
			if ( ! array_key_exists( 'disabled', $incoming ) ) {
				throw new Exception( 'settings must contain "disabled" (an array of email ids).' );
			}
			$known = hsm_notif_email_list();
			foreach ( (array) $incoming['disabled'] as $id ) {
				$id = sanitize_key( (string) $id );
				if ( '' !== $id && $known && ! isset( $known[ $id ] ) ) {
					throw new Exception( 'Unknown email id: ' . $id . '. Use action=list.' );
				}
			}
			$before = get_option( 'hsm_notifications', array() );
			hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_notifications', 'old' => $before ) );
			update_option( 'hsm_notifications', array( 'disabled' => array_values( array_map( 'sanitize_key', (array) $incoming['disabled'] ) ) ) );
			return array( 'action' => 'set_settings', 'settings' => hsm_notif_settings() );
		}
		// list
		$disabled = hsm_notif_settings()['disabled'];
		$out      = array();
		foreach ( hsm_notif_email_list() as $id => $title ) {
			$out[] = array( 'id' => $id, 'title' => $title, 'enabled' => ! in_array( $id, $disabled, true ) );
		}
		return array( 'emails' => $out, 'disabled' => $disabled );
	},
) );
