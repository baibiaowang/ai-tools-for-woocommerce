<?php
/**
 * v1.5 Cookie consent + CPRA "Do Not Sell or Share My Personal Information" + Global Privacy Control.
 *
 * - Consent lives only in the visitor's cookie `hsm_consent` (format v1.a{0|1}.d{0|1}.t{unix}; a = analytics, d = ads/sale-share).
 * - A browser sending Sec-GPC: 1 (or navigator.globalPrivacyControl) is treated as opted out of ads/sale/share,
 *   whatever the cookie says; the banner then offers no ads choice.
 * - hsm_consent( 'necessary'|'analytics'|'ads' ) in PHP and window.hsmConsent( cat ) in JS are the single source of
 *   truth for later tracking/ads modules; JS fires a `hsm:consent` event on document when the choice changes.
 * - The banner markup never depends on the cookie (JS decides whether to show it), so pages stay cacheable.
 * Settings (option hsm_consent_settings): enabled, mode opt_in (default: nothing optional runs before consent)
 * or opt_out (US style: on until the visitor opts out), dns_page (page id). Owner-only; not writable via MCP.
 */
defined( 'ABSPATH' ) || exit;

function hsm_consent_settings() {
	$s = wp_parse_args( (array) get_option( 'hsm_consent_settings', array() ), array( 'enabled' => true, 'mode' => 'opt_in', 'dns_page' => 0 ) );
	$s['mode'] = 'opt_out' === $s['mode'] ? 'opt_out' : 'opt_in';
	return $s;
}

function hsm_consent_gpc() {
	return isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === trim( (string) $_SERVER['HTTP_SEC_GPC'] );
}

/** Parsed cookie: array( analytics => bool, ads => bool, t => int ) or null when no choice was made. */
function hsm_consent_cookie() {
	$v = isset( $_COOKIE['hsm_consent'] ) ? (string) $_COOKIE['hsm_consent'] : '';
	if ( ! preg_match( '/^v1\.a([01])\.d([01])\.t(\d{1,12})$/', $v, $m ) ) {
		return null;
	}
	return array( 'analytics' => '1' === $m[1], 'ads' => '1' === $m[2], 't' => (int) $m[3] );
}

/**
 * May this category run for the current visitor?
 * necessary: always. analytics: cookie choice, else mode default. ads (incl. sale/sharing for cross-context
 * behavioural advertising): false under GPC, else cookie choice, else mode default.
 */
function hsm_consent( $category = 'ads' ) {
	$category = (string) $category;
	if ( 'necessary' === $category ) {
		return true;
	}
	if ( ! in_array( $category, array( 'analytics', 'ads' ), true ) ) {
		return (bool) apply_filters( 'hsm_consent', false, $category );
	}
	$s = hsm_consent_settings();
	if ( 'ads' === $category && hsm_consent_gpc() ) {
		$v = false;
	} else {
		$c = hsm_consent_cookie();
		$v = $c ? $c[ $category ] : ( 'opt_out' === $s['mode'] );
	}
	return (bool) apply_filters( 'hsm_consent', $v, $category );
}

function hsm_consent_dns_url() {
	$id = (int) hsm_consent_settings()['dns_page'];
	return $id && 'publish' === get_post_status( $id ) ? get_permalink( $id ) : '';
}

/** Create (or adopt) the "Do Not Sell or Share" page. */
function hsm_consent_install() {
	$s = (array) get_option( 'hsm_consent_settings', array() );
	if ( ! empty( $s['dns_page'] ) && 'publish' === get_post_status( (int) $s['dns_page'] ) ) {
		return (int) $s['dns_page'];
	}
	$p  = get_page_by_path( 'do-not-sell-or-share' );
	$id = $p ? $p->ID : wp_insert_post( array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Do Not Sell or Share My Personal Information',
		'post_name'    => 'do-not-sell-or-share',
		'post_content' => "<!-- wp:paragraph --><p>Under the California Consumer Privacy Act as amended by the CPRA (and similar state laws), you can opt out of the \"sale\" or \"sharing\" of your personal information, including for cross-context behavioral advertising. We honor Global Privacy Control (GPC) browser signals as a valid opt-out.</p><!-- /wp:paragraph -->\n<!-- wp:shortcode -->[hsm_privacy_choices]<!-- /wp:shortcode -->\n<!-- wp:paragraph --><p>Your choice is saved in a cookie on this browser and device. If you clear cookies or use another browser, please opt out again (or turn on GPC). You can also contact us to opt out; see our Privacy Policy.</p><!-- /wp:paragraph -->",
	) );
	if ( $p && false === strpos( $p->post_content, '[hsm_privacy_choices]' ) ) {
		wp_update_post( array( 'ID' => $p->ID, 'post_content' => $p->post_content . "\n<!-- wp:shortcode -->[hsm_privacy_choices]<!-- /wp:shortcode -->" ) );
	}
	$s['dns_page'] = (int) $id;
	update_option( 'hsm_consent_settings', $s );
	return (int) $id;
}

function hsm_consent_frontend() {
	if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_customize_preview() ) {
		return false;
	}
	return (bool) hsm_consent_settings()['enabled'];
}

/** [hsm_privacy_choices] — opt-out control on the Do Not Sell or Share page. */
add_shortcode( 'hsm_privacy_choices', function () {
	return '<div class="hsm-privacy-choices" id="hsm-privacy-choices">'
		. '<p class="hsm-gpc-on" hidden><strong>Global Privacy Control detected:</strong> your browser has already opted you out. We will not sell or share your personal information.</p>'
		. '<p class="hsm-dns-status" aria-live="polite"></p>'
		. '<button type="button" class="wp-element-button" id="hsm-dns-optout">Opt out of sale / sharing</button></div>';
} );

add_action( 'wp_footer', function () {
	if ( ! hsm_consent_frontend() ) {
		return;
	}
	$s    = hsm_consent_settings();
	$dns  = hsm_consent_dns_url();
	$priv = get_privacy_policy_url();
	$cfg  = array( 'mode' => $s['mode'], 'secure' => is_ssl(), 'path' => COOKIEPATH ?: '/', 'serverGpc' => hsm_consent_gpc() );
	?>
<div class="hsm-privacy-links" style="text-align:center;font-size:13px;padding:12px 8px">
	<?php if ( $dns ) : ?><a href="<?php echo esc_url( $dns ); ?>" id="hsm-dns-link">Do Not Sell or Share My Personal Information</a> &middot; <?php endif; ?>
	<a href="#" id="hsm-consent-open">Cookie settings</a><?php if ( $priv ) : ?> &middot; <a href="<?php echo esc_url( $priv ); ?>">Privacy Policy</a><?php endif; ?>
</div>
<div id="hsm-consent" role="dialog" aria-live="polite" aria-label="Cookie consent" hidden style="position:fixed;left:16px;right:16px;bottom:16px;z-index:99999;max-width:640px;margin:0 auto;background:#fff;color:#111;border:1px solid #ccc;border-radius:8px;box-shadow:0 4px 24px rgba(0,0,0,.18);padding:16px 18px;font-size:14px;line-height:1.45">
	<p style="margin:0 0 10px">We use cookies that are needed for the store to work, plus optional analytics and advertising cookies. <?php if ( $priv ) : ?><a href="<?php echo esc_url( $priv ); ?>">Learn more</a><?php endif; ?></p>
	<p class="hsm-gpc-note" hidden style="margin:0 0 10px;font-size:13px"><strong>Global Privacy Control detected.</strong> We honor it: we won't sell or share your personal information or use advertising cookies.</p>
	<div class="hsm-consent-opts" hidden style="margin:0 0 10px">
		<label style="display:block"><input type="checkbox" checked disabled> Necessary (always on)</label>
		<label style="display:block"><input type="checkbox" id="hsm-c-analytics"> Analytics</label>
		<label style="display:block" class="hsm-ads-row"><input type="checkbox" id="hsm-c-ads"> Advertising &amp; personalized ads (sale/sharing)</label>
	</div>
	<div style="display:flex;gap:8px;flex-wrap:wrap">
		<button type="button" class="wp-element-button" id="hsm-consent-accept">Accept all</button>
		<button type="button" class="wp-element-button" id="hsm-consent-reject">Reject non-essential</button>
		<button type="button" class="wp-element-button" id="hsm-consent-settings">Settings</button>
		<button type="button" class="wp-element-button" id="hsm-consent-save" hidden>Save choices</button>
	</div>
</div>
<script id="hsm-consent-js">
(function(){
var C=<?php echo wp_json_encode( $cfg ); ?>,d=document;
function gpc(){return C.serverGpc||navigator.globalPrivacyControl===true;}
function read(){var m=d.cookie.match(/(?:^|;\s*)hsm_consent=v1\.a([01])\.d([01])\.t(\d+)/);return m?{analytics:m[1]==='1',ads:m[2]==='1',t:+m[3]}:null;}
function write(a,ads){if(gpc())ads=false;var v='v1.a'+(a?1:0)+'.d'+(ads?1:0)+'.t'+Math.floor(Date.now()/1000);
 d.cookie='hsm_consent='+v+';path='+C.path+';max-age='+31536000+';SameSite=Lax'+(C.secure?';Secure':'');
 try{d.dispatchEvent(new CustomEvent('hsm:consent',{detail:{necessary:true,analytics:!!a,ads:!!ads,gpc:gpc()}}));}catch(e){}
 window.dataLayer=window.dataLayer||[];window.dataLayer.push({event:'hsm_consent_update',analytics:!!a,ads:!!ads});}
window.hsmConsent=function(cat){if(cat==='necessary')return true;if(cat!=='analytics'&&cat!=='ads')return false;
 if(cat==='ads'&&gpc())return false;var c=read();return c?c[cat]:C.mode==='opt_out';};
window.hsmConsentSet=function(o){o=o||{};write(!!o.analytics,!!o.ads);};
var b=d.getElementById('hsm-consent');if(!b)return;
function $(id){return d.getElementById(id);}
function show(opts){b.hidden=false;var g=gpc();b.querySelector('.hsm-gpc-note').hidden=!g;
 var row=b.querySelector('.hsm-ads-row');if(g&&row){row.parentNode.removeChild(row);}
 var c=read();if($('hsm-c-analytics'))$('hsm-c-analytics').checked=c?c.analytics:false;if($('hsm-c-ads'))$('hsm-c-ads').checked=c?c.ads:false;
 b.querySelector('.hsm-consent-opts').hidden=!opts;$('hsm-consent-save').hidden=!opts;$('hsm-consent-settings').hidden=!!opts;}
function hide(){b.hidden=true;}
$('hsm-consent-accept').onclick=function(){write(true,true);hide();};
$('hsm-consent-reject').onclick=function(){write(false,false);hide();};
$('hsm-consent-settings').onclick=function(){show(true);};
$('hsm-consent-save').onclick=function(){write($('hsm-c-analytics').checked,$('hsm-c-ads')?$('hsm-c-ads').checked:false);hide();};
var o=$('hsm-consent-open');if(o)o.onclick=function(e){e.preventDefault();show(true);};
var pc=$('hsm-privacy-choices');
if(pc){var st=pc.querySelector('.hsm-dns-status');pc.querySelector('.hsm-gpc-on').hidden=!gpc();
 function upd(){var c=read();st.textContent=(gpc()||(c&&!c.ads))?'You are opted out of the sale and sharing of your personal information on this browser.':'You have not opted out on this browser.';}
 $('hsm-dns-optout').onclick=function(){var c=read();write(c?c.analytics:false,false);hide();upd();};upd();}
if(!read())show(false);
})();
</script>
	<?php
}, 50 );

add_action( 'init', function () {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::add_command( 'hark-store consent', function ( $args ) {
			$cmd = $args[0] ?? 'status';
			if ( 'install' === $cmd ) {
				WP_CLI::success( 'Do Not Sell page id ' . hsm_consent_install() );
			} elseif ( 'set' === $cmd && isset( $args[1], $args[2] ) && in_array( $args[1], array( 'enabled', 'mode', 'dns_page' ), true ) ) {
				$s             = (array) get_option( 'hsm_consent_settings', array() );
				$s[ $args[1] ] = 'enabled' === $args[1] ? 'true' === $args[2] : ( 'dns_page' === $args[1] ? (int) $args[2] : $args[2] );
				update_option( 'hsm_consent_settings', $s );
				WP_CLI::success( wp_json_encode( hsm_consent_settings() ) );
			} else {
				WP_CLI::line( wp_json_encode( hsm_consent_settings() ) );
			}
		} );
	}
} );
