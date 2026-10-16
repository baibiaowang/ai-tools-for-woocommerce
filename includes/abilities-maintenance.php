<?php
/**
 * v1.5 hark-store-maintenance: one tool for the built-in replacement modules (backups, mail, consent,
 * follow-up emails, page cache). Settings and secrets of these modules are owner-only and never exposed here.
 */
defined( 'ABSPATH' ) || exit;

function hsm_maint_actions() {
	return array(
		// action => is_write
		'backup_list'     => false,
		'backup_verify'   => false,
		'backup_run'      => true,
		'mail_status'     => false,
		'mail_log'        => false,
		'mail_test'       => true,
		'consent_status'  => false,
		'followup_status' => false,
		'cache_status'    => false,
		'cache_purge'     => true,
		'ads_status'      => false,
	);
}

hsm_ability( 'maintenance', array(
	'label'       => 'Store maintenance (backups, mail, consent, follow-ups, cache)',
	'description' => 'Built-in modules that replace separate plugins. action=backup_list (backup sets, newest first) | backup_verify (name: checks sha256, gzip/zip integrity, table count) | backup_run (start a database + uploads backup now; needs owner confirmation) | mail_status (which API mailer is active: SES/Mailgun/Postmark; never shows keys) | mail_log (recent send attempts) | mail_test (to: send one test email) | consent_status (cookie banner / CPRA / GPC settings) | followup_status (review-request and abandoned-cart email settings and queue counts) | cache_status | cache_purge | ads_status (pixel IDs, whether server-side conversion tokens are set — never the tokens —, recent server-side send results, catalog feed URLs). Backups cannot be deleted through MCP; schedules, retention, mail API keys and cache on/off are set by the owner in WP-CLI or wp-admin.',
	'cap'         => 'manage_options',
	'properties'  => array(
		'action' => hsm_s( 'string', '', array( 'enum' => array_keys( hsm_maint_actions() ) ) ),
		'name'   => hsm_s( 'string', 'backup_verify: backup set name from backup_list' ),
		'to'     => hsm_s( 'string', 'mail_test: recipient email' ),
		'limit'  => hsm_s( 'integer', 'mail_log: entries to return (max 100)' ),
	),
	'required'    => array( 'action' ),
	'is_write'    => function ( $in ) {
		return ! empty( hsm_maint_actions()[ $in['action'] ?? '' ] );
	},
	'callback'    => function ( $in ) {
		$action = (string) ( $in['action'] ?? '' );
		if ( ! array_key_exists( $action, hsm_maint_actions() ) ) {
			throw new Exception( 'Unknown action. Allowed: ' . implode( ', ', array_keys( hsm_maint_actions() ) ) );
		}
		switch ( $action ) {
			case 'backup_list':
				$s = hsm_backup_settings();
				$n = wp_next_scheduled( 'hsm_backup_cron' );
				return array(
					'settings' => $s,
					'next_run' => $n ? gmdate( 'c', $n ) : null,
					'last'     => get_option( 'hsm_backup_last' ) ?: null,
					'backups'  => array_map( function ( $m ) {
						return array_intersect_key( $m, array_flip( array( 'name', 'created', 'trigger', 'tables', 'rows', 'files', 'total_bytes', 'seconds' ) ) );
					}, hsm_backup_list() ),
				);
			case 'backup_verify':
				return hsm_backup_verify( (string) ( $in['name'] ?? '' ) );
			case 'backup_run':
				hsm_confirm( $in, 'Run a full backup now (database + uploads). It uses disk space and server time; the oldest set beyond the retention limit (' . hsm_backup_settings()['keep'] . ') is removed.', array( 'tables' => count( hsm_backup_tables() ), 'keep' => hsm_backup_settings()['keep'] ) );
				$m = hsm_backup_run( 'mcp' );
				return array( 'action' => 'backup_run', 'name' => $m['name'], 'tables' => $m['tables'], 'rows' => $m['rows'], 'files' => $m['files'], 'pruned' => $m['pruned'], 'seconds' => $m['seconds'] );
		}
		if ( 0 === strpos( $action, 'mail_' ) && function_exists( 'hsm_mail_maint' ) ) {
			return hsm_mail_maint( $action, $in );
		}
		if ( 'consent_status' === $action && function_exists( 'hsm_consent_settings' ) ) {
			$s = hsm_consent_settings();
			return array( 'settings' => $s, 'dns_page' => hsm_consent_dns_url(), 'gpc_honored' => true, 'categories' => array( 'necessary', 'analytics', 'ads' ) );
		}
		if ( 'followup_status' === $action && function_exists( 'hsm_followup_status' ) ) {
			return hsm_followup_status();
		}
		if ( 'ads_status' === $action && function_exists( 'hsm_capi_status' ) ) {
			return array( 'pixels' => hsm_pixels_settings(), 'server_side' => hsm_capi_status(), 'consent_mode' => hsm_consent_settings()['mode'],
				'recent_sends' => array_slice( (array) get_option( 'hsm_capi_log', array() ), 0, 20 ),
				'feeds' => function_exists( 'hsm_feed_url' ) ? array( 'google' => hsm_feed_url( 'google' ), 'meta' => hsm_feed_url( 'meta' ), 'tiktok' => hsm_feed_url( 'tiktok' ) ) : array() );
		}
		if ( 0 === strpos( $action, 'cache_' ) && function_exists( 'hsm_pc_maint' ) ) {
			return hsm_pc_maint( $action );
		}
		throw new Exception( 'This module is not available.' );
	},
) );
