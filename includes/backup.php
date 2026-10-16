<?php
/**
 * v1.5 Backups: database (every table with this site's prefix, gzip SQL) + uploads (zip), on WP-Cron
 * (daily by default), keeping the newest N sets (default 7).
 *
 * Stored in wp-content/hsm-backups/<random 32-hex dir>/ with index.php, .htaccess and web.config deny
 * rules so the files cannot be listed or downloaded from the web. Settings (enabled, frequency, keep)
 * are owner-only (WP-CLI `wp hark-store backup ...` or the hsm_backup_settings option) — not MCP.
 * Through MCP an agent may list, verify and (with a confirmation token) run a backup. There is no MCP
 * action that deletes backups; only retention pruning removes old sets and it always keeps at least one.
 */
defined( 'ABSPATH' ) || exit;

function hsm_backup_settings() {
	$o = (array) get_option( 'hsm_backup_settings', array() );
	$s = wp_parse_args( $o, array( 'enabled' => true, 'frequency' => 'daily', 'keep' => 7, 'uploads' => true ) );
	$s['keep']      = max( 1, min( 60, (int) $s['keep'] ) );
	$s['frequency'] = in_array( $s['frequency'], array( 'hourly', 'twicedaily', 'daily', 'weekly' ), true ) ? $s['frequency'] : 'daily';
	$s['enabled']   = (bool) $s['enabled'];
	return apply_filters( 'hsm_backup_settings', $s );
}

/** Root (wp-content/hsm-backups) and the random subdirectory that actually holds the files. */
function hsm_backup_dir( $create = true ) {
	$root = WP_CONTENT_DIR . '/hsm-backups';
	$key  = get_option( 'hsm_backup_dir_key' );
	if ( ! $key || ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
		if ( ! $create ) {
			return null;
		}
		$key = bin2hex( random_bytes( 16 ) );
		update_option( 'hsm_backup_dir_key', $key, false );
	}
	$dir = $root . '/' . $key;
	if ( $create ) {
		foreach ( array( $root, $dir ) as $d ) {
			if ( ! is_dir( $d ) ) {
				wp_mkdir_p( $d );
			}
			hsm_backup_protect( $d );
		}
	}
	return $dir;
}

/* hsm_backup_protect() now lives in the shared hsm-core.php framework. */

/** Tables that belong to this site (prefix match, so another site in the same database is never touched). */
function hsm_backup_tables() {
	global $wpdb;
	$like = $wpdb->esc_like( $wpdb->prefix ) . '%';
	return $wpdb->get_col( $wpdb->prepare( 'SHOW FULL TABLES WHERE `Tables_in_' . DB_NAME . '` LIKE %s AND Table_type = %s', $like, 'BASE TABLE' ) ) ?: array();
}

function hsm_backup_quote( $v ) {
	global $wpdb;
	if ( null === $v ) {
		return 'NULL';
	}
	// mysqli escaping directly: esc_sql() would add WordPress placeholder escapes to every '%'.
	if ( $wpdb->dbh instanceof mysqli ) {
		return "'" . mysqli_real_escape_string( $wpdb->dbh, (string) $v ) . "'";
	}
	return "'" . addslashes( (string) $v ) . "'";
}

/** Dump the site's tables to a gzip SQL file. Returns [tables, rows]. */
function hsm_backup_dump_db( $file ) {
	global $wpdb;
	$gz = gzopen( $file, 'wb6' );
	if ( ! $gz ) {
		throw new Exception( 'Cannot write ' . basename( $file ) );
	}
	gzwrite( $gz, "-- Hark Store MCP backup\n-- Site: " . home_url() . "\n-- Date: " . gmdate( 'c' ) . "\n-- Prefix: {$wpdb->prefix}\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n" );
	$tables = hsm_backup_tables();
	$rows   = 0;
	foreach ( $tables as $t ) {
		$create = $wpdb->get_row( "SHOW CREATE TABLE `$t`", ARRAY_N );
		if ( empty( $create[1] ) ) {
			continue;
		}
		gzwrite( $gz, "DROP TABLE IF EXISTS `$t`;\n" . $create[1] . ";\n" );
		$offset = 0;
		do {
			$batch = $wpdb->get_results( "SELECT * FROM `$t` LIMIT $offset, 500", ARRAY_N );
			if ( $batch ) {
				$vals = array();
				foreach ( $batch as $r ) {
					$vals[] = '(' . implode( ',', array_map( 'hsm_backup_quote', $r ) ) . ')';
				}
				gzwrite( $gz, "INSERT INTO `$t` VALUES\n" . implode( ",\n", $vals ) . ";\n" );
				$rows += count( $batch );
			}
			$offset += 500;
		} while ( $batch && count( $batch ) === 500 );
		gzwrite( $gz, "\n" );
	}
	gzwrite( $gz, "SET FOREIGN_KEY_CHECKS=1;\n-- end of backup\n" );
	gzclose( $gz );
	return array( count( $tables ), $rows );
}

/** Zip wp-content/uploads (skipping caches and our own backups). Returns file count. */
function hsm_backup_zip_uploads( $file ) {
	$base = wp_get_upload_dir()['basedir'];
	if ( ! class_exists( 'ZipArchive' ) || ! is_dir( $base ) ) {
		return null;
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		throw new Exception( 'Cannot write ' . basename( $file ) );
	}
	$n  = 0;
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$path = $f->getPathname();
		$rel  = ltrim( substr( $path, strlen( $base ) ), '/' );
		if ( ! $f->isFile() || preg_match( '#^(cache|hsm-backups|wc-logs|wpo-cache)/#', $rel ) ) {
			continue;
		}
		$zip->addFile( $path, 'uploads/' . $rel );
		$zip->setCompressionName( 'uploads/' . $rel, preg_match( '/\.(jpe?g|png|webp|gif|zip|gz|mp4|pdf)$/i', $rel ) ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE );
		++$n;
	}
	if ( 0 === $n ) {
		$zip->addFromString( 'uploads/.empty', '' );
	}
	$zip->close();
	return $n;
}

/** Run one backup set now. */
function hsm_backup_run( $trigger = 'manual' ) {
	if ( get_transient( 'hsm_backup_lock' ) ) {
		throw new Exception( 'A backup is already running.' );
	}
	set_transient( 'hsm_backup_lock', 1, 30 * MINUTE_IN_SECONDS );
	try {
		@set_time_limit( 0 );
		$dir  = hsm_backup_dir();
		$name = 'backup-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false ) );
		$t0   = microtime( true );
		$db   = "$dir/$name-db.sql.gz";
		list( $tables, $rows ) = hsm_backup_dump_db( $db );
		$m = array( 'name' => $name, 'created' => gmdate( 'c' ), 'trigger' => $trigger, 'tables' => $tables, 'rows' => $rows,
			'site' => home_url(), 'prefix' => $GLOBALS['wpdb']->prefix, 'version' => HSM_VERSION,
			'files' => array( 'db' => array( 'file' => basename( $db ), 'bytes' => filesize( $db ), 'sha256' => hash_file( 'sha256', $db ) ) ) );
		if ( hsm_backup_settings()['uploads'] ) {
			$up = "$dir/$name-uploads.zip";
			$n  = hsm_backup_zip_uploads( $up );
			if ( null !== $n ) {
				$m['files']['uploads'] = array( 'file' => basename( $up ), 'bytes' => filesize( $up ), 'sha256' => hash_file( 'sha256', $up ), 'count' => $n );
			}
		}
		$m['seconds'] = round( microtime( true ) - $t0, 1 );
		file_put_contents( "$dir/$name.json", wp_json_encode( $m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$m['pruned'] = hsm_backup_prune();
		update_option( 'hsm_backup_last', array( 'name' => $name, 'time' => time(), 'ok' => true ), false );
		return $m;
	} catch ( \Throwable $e ) {
		update_option( 'hsm_backup_last', array( 'time' => time(), 'ok' => false, 'error' => $e->getMessage() ), false );
		throw $e;
	} finally {
		delete_transient( 'hsm_backup_lock' );
	}
}

/** Backup sets, newest first. */
function hsm_backup_list() {
	$dir = hsm_backup_dir( false );
	if ( ! $dir || ! is_dir( $dir ) ) {
		return array();
	}
	$out = array();
	foreach ( glob( "$dir/backup-*.json" ) ?: array() as $f ) {
		$m = json_decode( (string) file_get_contents( $f ), true );
		if ( ! is_array( $m ) || empty( $m['name'] ) ) {
			continue;
		}
		$m['total_bytes'] = 0;
		foreach ( $m['files'] as $k => $x ) {
			$m['files'][ $k ]['present'] = file_exists( "$dir/{$x['file']}" );
			$m['total_bytes']           += (int) $x['bytes'];
		}
		$out[] = $m;
	}
	usort( $out, function ( $a, $b ) {
		return strcmp( $b['name'], $a['name'] );
	} );
	return $out;
}

/** Keep the newest N sets; never deletes the last remaining one. */
function hsm_backup_prune() {
	$keep = hsm_backup_settings()['keep'];
	$dir  = hsm_backup_dir( false );
	$gone = array();
	foreach ( array_slice( hsm_backup_list(), max( 1, $keep ) ) as $m ) {
		if ( ! preg_match( '/^backup-[0-9]{8}-[0-9]{6}-[a-z0-9]{6}$/', $m['name'] ) ) {
			continue;
		}
		foreach ( glob( "$dir/{$m['name']}*" ) ?: array() as $f ) {
			@unlink( $f );
		}
		$gone[] = $m['name'];
	}
	return $gone;
}

/** Integrity check: sha256 matches, gzip stream decodes to the end, SQL ends with the end marker, zip passes CHECKCONS. */
function hsm_backup_verify( $name ) {
	$dir = hsm_backup_dir( false );
	if ( ! $dir || ! preg_match( '/^backup-[0-9]{8}-[0-9]{6}-[a-z0-9]{6}$/', (string) $name ) || ! file_exists( "$dir/$name.json" ) ) {
		throw new Exception( 'Backup not found.' );
	}
	$m   = json_decode( file_get_contents( "$dir/$name.json" ), true );
	$res = array( 'name' => $name, 'ok' => true, 'checks' => array() );
	foreach ( $m['files'] as $k => $x ) {
		$f  = "$dir/{$x['file']}";
		$ok = file_exists( $f ) && hash_equals( $x['sha256'], hash_file( 'sha256', $f ) );
		$res['checks'][ "$k sha256" ] = $ok;
		if ( 'db' === $k && $ok ) {
			$gz = gzopen( $f, 'rb' );
			$creates = 0;
			$tail    = '';
			while ( $gz && ! gzeof( $gz ) ) {
				$line = gzgets( $gz, 1 << 20 );
				if ( false === $line ) {
					$ok = false;
					break;
				}
				0 === strpos( $line, 'CREATE TABLE' ) && ++$creates;
				$tail = $line;
			}
			$gz && gzclose( $gz );
			$res['checks']['db gzip readable'] = $ok;
			$res['checks']['db complete (end marker)'] = false !== strpos( $tail, 'end of backup' );
			$res['checks']['db table count'] = $creates === (int) $m['tables'];
		}
		if ( 'uploads' === $k && $ok && class_exists( 'ZipArchive' ) ) {
			$z = new ZipArchive();
			$res['checks']['uploads zip consistent'] = true === $z->open( $f, ZipArchive::CHECKCONS );
			true === $res['checks']['uploads zip consistent'] && $z->close();
		}
	}
	$res['ok'] = ! in_array( false, $res['checks'], true );
	return $res;
}

/* Scheduling ----------------------------------------------------------- */

function hsm_backup_schedule() {
	$s    = hsm_backup_settings();
	$next = wp_get_scheduled_event( 'hsm_backup_cron' );
	if ( ! $s['enabled'] ) {
		$next && wp_clear_scheduled_hook( 'hsm_backup_cron' );
		return;
	}
	if ( $next && $next->schedule !== $s['frequency'] ) {
		wp_clear_scheduled_hook( 'hsm_backup_cron' );
		$next = false;
	}
	if ( ! $next ) {
		// First run in the small hours (site time) to stay out of shopping peaks.
		$start = strtotime( 'tomorrow 03:17', current_time( 'timestamp' ) ) - ( current_time( 'timestamp' ) - time() );
		wp_schedule_event( 'hourly' === $s['frequency'] ? time() + 600 : $start, $s['frequency'], 'hsm_backup_cron' );
	}
}
add_action( 'init', 'hsm_backup_schedule' );
add_action( 'hsm_backup_cron', function () {
	try {
		hsm_backup_run( 'cron' );
	} catch ( \Throwable $e ) {
		error_log( 'Hark Store backup failed: ' . $e->getMessage() );
	}
} );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'hark-store backup', function ( $args ) {
		$cmd = $args[0] ?? 'list';
		if ( 'run' === $cmd ) {
			$m = hsm_backup_run( 'cli' );
			WP_CLI::success( "{$m['name']}: {$m['tables']} tables, {$m['rows']} rows" );
		} elseif ( 'list' === $cmd ) {
			foreach ( hsm_backup_list() as $m ) {
				WP_CLI::line( $m['name'] . '  ' . size_format( $m['total_bytes'] ) . '  ' . $m['trigger'] );
			}
		} elseif ( 'verify' === $cmd && isset( $args[1] ) ) {
			WP_CLI::line( wp_json_encode( hsm_backup_verify( $args[1] ) ) );
		} elseif ( 'path' === $cmd ) {
			WP_CLI::line( hsm_backup_dir() );
		} elseif ( 'set' === $cmd && isset( $args[1], $args[2] ) && in_array( $args[1], array( 'enabled', 'frequency', 'keep', 'uploads' ), true ) ) {
			$o             = (array) get_option( 'hsm_backup_settings', array() );
			$o[ $args[1] ] = in_array( $args[2], array( 'true', 'false' ), true ) ? 'true' === $args[2] : ( is_numeric( $args[2] ) ? (int) $args[2] : $args[2] );
			update_option( 'hsm_backup_settings', $o, false );
			hsm_backup_schedule();
			WP_CLI::success( wp_json_encode( hsm_backup_settings() ) );
		} else {
			WP_CLI::error( 'Usage: wp hark-store backup [list|run|verify <name>|path|set <enabled|frequency|keep|uploads> <value>]' );
		}
	} );
}
