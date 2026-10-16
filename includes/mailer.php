<?php
/**
 * v1.5 API mailer: send wp_mail() through Amazon SES (v2 API, SigV4), Mailgun or Postmark over HTTPS,
 * intercepted with the pre_wp_mail filter. If the API call fails, wp_mail() continues with its normal
 * PHPMailer path (fallback) and the failure is logged.
 *
 * Secrets: only from wp-config constants (HSM_MAIL_PROVIDER, HSM_MAIL_KEY, HSM_MAIL_SECRET, HSM_MAIL_REGION,
 * HSM_MAIL_DOMAIN, HSM_MAIL_FROM, HSM_MAIL_FROM_NAME, HSM_MAIL_ENDPOINT) or the owner-only admin screen
 * (option hsm_mail_settings, manage_options + nonce). No MCP tool can read or write them; status output
 * only says whether a key is set.
 */
defined( 'ABSPATH' ) || exit;

function hsm_mail_config() {
	$o = (array) get_option( 'hsm_mail_settings', array() );
	$c = array();
	foreach ( array( 'provider', 'key', 'secret', 'region', 'domain', 'from', 'from_name', 'endpoint' ) as $k ) {
		$const   = 'HSM_MAIL_' . strtoupper( $k );
		$c[ $k ] = defined( $const ) ? (string) constant( $const ) : (string) ( $o[ $k ] ?? '' );
	}
	$c['provider'] = in_array( $c['provider'], array( 'ses', 'mailgun', 'postmark' ), true ) ? $c['provider'] : '';
	$c['region']   = preg_replace( '/[^a-z0-9-]/', '', $c['region'] ?: 'us-east-1' );
	$c['source']   = defined( 'HSM_MAIL_PROVIDER' ) ? 'wp-config' : ( $o ? 'admin' : 'none' );
	return $c;
}

function hsm_mail_ready( $c = null ) {
	$c = $c ?: hsm_mail_config();
	if ( ! $c['provider'] || ! $c['key'] ) {
		return false;
	}
	if ( 'ses' === $c['provider'] && ! $c['secret'] ) {
		return false;
	}
	if ( 'mailgun' === $c['provider'] && ! $c['domain'] ) {
		return false;
	}
	return true;
}

function hsm_mail_log( $entry ) {
	$log   = (array) get_option( 'hsm_mail_log', array() );
	$entry = array( 'time' => gmdate( 'c' ) ) + $entry;
	array_unshift( $log, $entry );
	update_option( 'hsm_mail_log', array_slice( $log, 0, 100 ), false );
}

/** Mask an address for the log: j***@example.com */
function hsm_mail_mask( $addr ) {
	$addr = (string) $addr;
	$at   = strpos( $addr, '@' );
	return false === $at ? '***' : substr( $addr, 0, 1 ) . '***' . substr( $addr, $at );
}

/** Normalise wp_mail() arguments into a provider-neutral message. */
function hsm_mail_parse( $atts, $c ) {
	$to      = is_array( $atts['to'] ) ? $atts['to'] : explode( ',', (string) $atts['to'] );
	$headers = is_array( $atts['headers'] ) ? $atts['headers'] : preg_split( "/\r?\n/", (string) $atts['headers'] );
	$m       = array( 'to' => array_values( array_filter( array_map( 'trim', $to ) ) ), 'cc' => array(), 'bcc' => array(), 'reply_to' => array(), 'subject' => (string) $atts['subject'],
		'body' => (string) $atts['message'], 'html' => false, 'headers' => array(), 'attachments' => array_filter( (array) $atts['attachments'] ) );
	$from    = $c['from'] ?: get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );
	$name    = $c['from_name'] ?: get_option( 'woocommerce_email_from_name', get_bloginfo( 'name' ) );
	foreach ( $headers as $h ) {
		if ( false === strpos( (string) $h, ':' ) ) {
			continue;
		}
		list( $k, $v ) = array_map( 'trim', explode( ':', $h, 2 ) );
		switch ( strtolower( $k ) ) {
			case 'from':
				if ( preg_match( '/^(.*)<(.+)>$/', $v, $mm ) ) {
					$name = trim( $mm[1], ' "' ) ?: $name;
					$from = trim( $mm[2] );
				} elseif ( is_email( $v ) ) {
					$from = $v;
				}
				break;
			case 'cc':
				$m['cc'] = array_merge( $m['cc'], array_map( 'trim', explode( ',', $v ) ) );
				break;
			case 'bcc':
				$m['bcc'] = array_merge( $m['bcc'], array_map( 'trim', explode( ',', $v ) ) );
				break;
			case 'reply-to':
				$m['reply_to'][] = $v;
				break;
			case 'content-type':
				$m['html'] = false !== stripos( $v, 'text/html' );
				break;
			default:
				if ( preg_match( '/^(list-unsubscribe|list-unsubscribe-post|x-[a-z0-9-]+)$/i', $k ) ) {
					$m['headers'][ $k ] = $v;
				}
		}
	}
	$ct = apply_filters( 'wp_mail_content_type', $m['html'] ? 'text/html' : 'text/plain' );
	$m['html']      = false !== stripos( (string) $ct, 'text/html' );
	$m['from']      = apply_filters( 'wp_mail_from', $from );
	$m['from_name'] = apply_filters( 'wp_mail_from_name', $name );
	$m['from_full'] = $m['from_name'] ? sprintf( '"%s" <%s>', str_replace( '"', "'", $m['from_name'] ), $m['from'] ) : $m['from'];
	return $m;
}

/** Build the HTTP request for one provider: [url, args]. */
function hsm_mail_request( $m, $c ) {
	$ep = rtrim( $c['endpoint'], '/' );
	if ( 'postmark' === $c['provider'] ) {
		$body = array( 'From' => $m['from_full'], 'To' => implode( ',', $m['to'] ), 'Subject' => $m['subject'], 'MessageStream' => 'outbound' );
		$m['cc'] && $body['Cc'] = implode( ',', $m['cc'] );
		$m['bcc'] && $body['Bcc'] = implode( ',', $m['bcc'] );
		$m['reply_to'] && $body['ReplyTo'] = implode( ',', $m['reply_to'] );
		$body[ $m['html'] ? 'HtmlBody' : 'TextBody' ] = $m['body'];
		foreach ( $m['headers'] as $k => $v ) {
			$body['Headers'][] = array( 'Name' => $k, 'Value' => $v );
		}
		foreach ( $m['attachments'] as $f ) {
			if ( is_readable( $f ) ) {
				$body['Attachments'][] = array( 'Name' => basename( $f ), 'Content' => base64_encode( file_get_contents( $f ) ), 'ContentType' => wp_check_filetype( $f )['type'] ?: 'application/octet-stream' );
			}
		}
		return array( ( $ep ?: 'https://api.postmarkapp.com' ) . '/email', array(
			'headers' => array( 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-Postmark-Server-Token' => $c['key'] ),
			'body'    => wp_json_encode( $body ),
		) );
	}
	if ( 'mailgun' === $c['provider'] ) {
		$fields = array( 'from' => $m['from_full'], 'to' => implode( ',', $m['to'] ), 'subject' => $m['subject'], ( $m['html'] ? 'html' : 'text' ) => $m['body'] );
		$m['cc'] && $fields['cc'] = implode( ',', $m['cc'] );
		$m['bcc'] && $fields['bcc'] = implode( ',', $m['bcc'] );
		$m['reply_to'] && $fields['h:Reply-To'] = implode( ',', $m['reply_to'] );
		foreach ( $m['headers'] as $k => $v ) {
			$fields[ 'h:' . $k ] = $v;
		}
		$boundary = 'hsm' . wp_generate_password( 24, false );
		$payload  = '';
		foreach ( $fields as $k => $v ) {
			$payload .= "--$boundary\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
		}
		foreach ( $m['attachments'] as $f ) {
			if ( is_readable( $f ) ) {
				$payload .= "--$boundary\r\nContent-Disposition: form-data; name=\"attachment\"; filename=\"" . basename( $f ) . "\"\r\nContent-Type: application/octet-stream\r\n\r\n" . file_get_contents( $f ) . "\r\n";
			}
		}
		$payload .= "--$boundary--\r\n";
		$base     = $ep ?: ( 'eu' === $c['region'] ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net' );
		return array( $base . '/v3/' . rawurlencode( $c['domain'] ) . '/messages', array(
			'headers' => array( 'Authorization' => 'Basic ' . base64_encode( 'api:' . $c['key'] ), 'Content-Type' => "multipart/form-data; boundary=$boundary" ),
			'body'    => $payload,
		) );
	}
	// Amazon SES v2 SendEmail (Simple content; with attachments the Raw content type is used).
	$body = array( 'FromEmailAddress' => $m['from_full'], 'Destination' => array_filter( array( 'ToAddresses' => $m['to'], 'CcAddresses' => $m['cc'], 'BccAddresses' => $m['bcc'] ) ) );
	$m['reply_to'] && $body['ReplyToAddresses'] = $m['reply_to'];
	if ( $m['attachments'] || $m['headers'] ) {
		$body['Content'] = array( 'Raw' => array( 'Data' => base64_encode( hsm_mail_mime( $m ) ) ) );
	} else {
		$body['Content'] = array( 'Simple' => array( 'Subject' => array( 'Data' => $m['subject'], 'Charset' => 'UTF-8' ), 'Body' => array( ( $m['html'] ? 'Html' : 'Text' ) => array( 'Data' => $m['body'], 'Charset' => 'UTF-8' ) ) ) );
	}
	$json = wp_json_encode( $body );
	$host = 'email.' . $c['region'] . '.amazonaws.com';
	$url  = ( $ep ?: 'https://' . $host ) . '/v2/email/outbound-emails';
	return array( $url, array( 'headers' => hsm_sigv4( 'POST', $url, $json, $c, 'ses', $host ), 'body' => $json ) );
}

/** Minimal MIME message for SES Raw (multipart/mixed with attachments). */
function hsm_mail_mime( $m ) {
	$b   = 'hsm' . wp_generate_password( 20, false );
	$enc = function ( $s ) {
		return '=?UTF-8?B?' . base64_encode( $s ) . '?=';
	};
	$h   = 'From: ' . $m['from_full'] . "\r\nTo: " . implode( ', ', $m['to'] ) . "\r\n" . ( $m['cc'] ? 'Cc: ' . implode( ', ', $m['cc'] ) . "\r\n" : '' ) . 'Subject: ' . $enc( $m['subject'] ) . "\r\nMIME-Version: 1.0\r\n";
	foreach ( $m['headers'] as $k => $v ) {
		$h .= "$k: $v\r\n";
	}
	$h .= "Content-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n";
	$h .= "--$b\r\nContent-Type: " . ( $m['html'] ? 'text/html' : 'text/plain' ) . "; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split( base64_encode( $m['body'] ) );
	foreach ( $m['attachments'] as $f ) {
		if ( is_readable( $f ) ) {
			$h .= "--$b\r\nContent-Type: application/octet-stream; name=\"" . basename( $f ) . "\"\r\nContent-Disposition: attachment; filename=\"" . basename( $f ) . "\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split( base64_encode( file_get_contents( $f ) ) );
		}
	}
	return $h . "--$b--\r\n";
}

/** AWS Signature Version 4 headers. */
function hsm_sigv4( $method, $url, $payload, $c, $service, $host ) {
	$u      = wp_parse_url( $url );
	$now    = time();
	$amz    = gmdate( 'Ymd\THis\Z', $now );
	$date   = gmdate( 'Ymd', $now );
	$hash   = hash( 'sha256', $payload );
	$canon  = "$method\n" . ( $u['path'] ?? '/' ) . "\n\ncontent-type:application/json\nhost:$host\nx-amz-content-sha256:$hash\nx-amz-date:$amz\n\ncontent-type;host;x-amz-content-sha256;x-amz-date\n$hash";
	$scope  = "$date/{$c['region']}/$service/aws4_request";
	$sts    = "AWS4-HMAC-SHA256\n$amz\n$scope\n" . hash( 'sha256', $canon );
	$k      = hash_hmac( 'sha256', 'aws4_request', hash_hmac( 'sha256', $service, hash_hmac( 'sha256', $c['region'], hash_hmac( 'sha256', $date, 'AWS4' . $c['secret'], true ), true ), true ), true );
	$sig    = hash_hmac( 'sha256', $sts, $k );
	return array(
		'Content-Type'         => 'application/json',
		'Host'                 => $host,
		'X-Amz-Date'           => $amz,
		'X-Amz-Content-Sha256' => $hash,
		'Authorization'        => "AWS4-HMAC-SHA256 Credential={$c['key']}/$scope, SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date, Signature=$sig",
	);
}

/** pre_wp_mail: send through the API; return null to let wp_mail() fall back to PHPMailer. */
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	if ( null !== $null ) {
		return $null;
	}
	$c = hsm_mail_config();
	if ( ! hsm_mail_ready( $c ) ) {
		return $null;
	}
	$m = hsm_mail_parse( $atts, $c );
	if ( ! $m['to'] ) {
		return $null;
	}
	list( $url, $args ) = hsm_mail_request( $m, $c );
	$args['timeout']    = 15;
	$args['redirection'] = 0;
	$t0  = microtime( true );
	$res = wp_remote_post( $url, $args );
	$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	$entry = array( 'provider' => $c['provider'], 'to' => array_map( 'hsm_mail_mask', $m['to'] ), 'subject' => mb_substr( $m['subject'], 0, 120 ), 'http' => $code, 'ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ) );
	if ( $code >= 200 && $code < 300 ) {
		$j = json_decode( wp_remote_retrieve_body( $res ), true );
		$entry['status']     = 'sent';
		$entry['message_id'] = is_array( $j ) ? (string) ( $j['MessageId'] ?? $j['MessageID'] ?? $j['id'] ?? '' ) : '';
		hsm_mail_log( $entry );
		do_action( 'wp_mail_succeeded', array( 'to' => $m['to'], 'subject' => $m['subject'], 'message' => $m['body'], 'headers' => $atts['headers'], 'attachments' => $m['attachments'] ) );
		return true;
	}
	$err = is_wp_error( $res ) ? $res->get_error_message() : mb_substr( wp_strip_all_tags( wp_remote_retrieve_body( $res ) ), 0, 200 );
	$entry['status'] = 'api_failed_fallback';
	$entry['error']  = str_replace( array( $c['key'], $c['secret'] ?: "\0" ), '[redacted]', $err );
	hsm_mail_log( $entry );
	$GLOBALS['hsm_mail_fallback'] = true;
	return null; // wp_mail() continues with PHPMailer.
}, 10, 2 );

/** Log the outcome of the PHPMailer fallback too. */
add_action( 'wp_mail_failed', function ( $err ) {
	if ( empty( $GLOBALS['hsm_mail_fallback'] ) ) {
		return;
	}
	$GLOBALS['hsm_mail_fallback'] = false;
	hsm_mail_log( array( 'provider' => 'phpmailer', 'status' => 'fallback_failed', 'error' => mb_substr( $err->get_error_message(), 0, 200 ) ) );
} );
add_action( 'wp_mail_succeeded', function () {
	if ( ! empty( $GLOBALS['hsm_mail_fallback'] ) ) {
		$GLOBALS['hsm_mail_fallback'] = false;
		hsm_mail_log( array( 'provider' => 'phpmailer', 'status' => 'fallback_sent' ) );
	}
} );

/** MCP maintenance actions (no secrets ever returned). */
function hsm_mail_maint( $action, $in ) {
	$c = hsm_mail_config();
	if ( 'mail_status' === $action ) {
		return array( 'provider' => $c['provider'] ?: 'none (PHP mail)', 'ready' => hsm_mail_ready( $c ), 'configured_in' => $c['source'], 'key_set' => '' !== $c['key'], 'secret_set' => '' !== $c['secret'],
			'region' => 'ses' === $c['provider'] || 'mailgun' === $c['provider'] ? $c['region'] : null, 'domain' => 'mailgun' === $c['provider'] ? $c['domain'] : null, 'from' => $c['from'] ?: get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ),
			'note' => 'API keys are set by the owner in wp-config.php (HSM_MAIL_*) or WooCommerce > Hark Mail; they cannot be read or changed through MCP.' );
	}
	if ( 'mail_log' === $action ) {
		$n = max( 1, min( 100, (int) ( $in['limit'] ?? 20 ) ) );
		return array( 'entries' => array_slice( (array) get_option( 'hsm_mail_log', array() ), 0, $n ) );
	}
	// mail_test
	$to = sanitize_email( (string) ( $in['to'] ?? '' ) );
	if ( ! is_email( $to ) ) {
		throw new Exception( 'to must be a valid email address.' );
	}
	$ok   = wp_mail( $to, '[' . get_bloginfo( 'name' ) . '] Test email', "This is a test email from your store's mail settings.\n" );
	$last = ( (array) get_option( 'hsm_mail_log', array() ) )[0] ?? null;
	return array( 'action' => 'mail_test', 'sent' => (bool) $ok, 'last_log' => $last );
}

/* Owner-only admin screen ------------------------------------------------ */
add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Hark Mail', 'Hark Mail', 'manage_options', 'hsm-mail', function () {
		if ( defined( 'HSM_MAIL_PROVIDER' ) ) {
			echo '<div class="wrap"><h1>Hark Mail</h1><p>Configured in wp-config.php (HSM_MAIL_*). Provider: ' . esc_html( HSM_MAIL_PROVIDER ) . '</p></div>';
			return;
		}
		$o = (array) get_option( 'hsm_mail_settings', array() );
		if ( isset( $_POST['hsm_mail'] ) && check_admin_referer( 'hsm_mail' ) ) {
			$in = wp_unslash( (array) $_POST['hsm_mail'] );
			foreach ( array( 'provider', 'region', 'domain', 'from', 'from_name', 'endpoint' ) as $k ) {
				$o[ $k ] = sanitize_text_field( $in[ $k ] ?? '' );
			}
			foreach ( array( 'key', 'secret' ) as $k ) {
				if ( '' !== ( $in[ $k ] ?? '' ) ) { // Blank = keep the saved secret.
					$o[ $k ] = trim( $in[ $k ] );
				}
			}
			update_option( 'hsm_mail_settings', $o, false );
			echo '<div class="updated"><p>Saved.</p></div>';
		}
		echo '<div class="wrap"><h1>Hark Mail</h1><p>Send store email through Amazon SES, Mailgun or Postmark. AI agents cannot see or change these settings. For best security put them in wp-config.php instead (HSM_MAIL_PROVIDER, HSM_MAIL_KEY, ...).</p><form method="post">';
		wp_nonce_field( 'hsm_mail' );
		echo '<table class="form-table"><tr><th>Provider</th><td><select name="hsm_mail[provider]">';
		foreach ( array( '' => 'Off (PHP mail)', 'ses' => 'Amazon SES', 'mailgun' => 'Mailgun', 'postmark' => 'Postmark' ) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $o['provider'] ?? '', $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></td></tr>';
		foreach ( array( 'key' => 'API key / SES access key ID / Postmark server token', 'secret' => 'SES secret access key', 'region' => 'Region (SES e.g. us-east-1; Mailgun "eu" for EU)', 'domain' => 'Mailgun sending domain', 'from' => 'From address', 'from_name' => 'From name' ) as $k => $l ) {
			$secret = in_array( $k, array( 'key', 'secret' ), true );
			echo '<tr><th>' . esc_html( $l ) . '</th><td><input type="' . ( $secret ? 'password' : 'text' ) . '" name="hsm_mail[' . esc_attr( $k ) . ']" value="' . ( $secret ? '' : esc_attr( $o[ $k ] ?? '' ) ) . '" placeholder="' . ( $secret && ! empty( $o[ $k ] ) ? '(saved — leave blank to keep)' : '' ) . '" class="regular-text"></td></tr>';
		}
		echo '</table>';
		submit_button();
		echo '</form></div>';
	} );
} );
