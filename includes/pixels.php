<?php
/**
 * v1.6 Ad/analytics pixels: GA4, Google Ads, Meta, TikTok. Owner-only option hsm_pixels
 * (ga4, gads, gads_label, meta, tiktok); not writable via MCP. Nothing loads until the matching consent
 * category is granted (analytics for GA4, ads for the rest) -- JS waits for window.hsmConsent / `hsm:consent`.
 * Standard e-commerce events carry an event_id (view_item: product id; purchase: order id) for server-side dedupe.
 */
defined( 'ABSPATH' ) || exit;

function hsm_pixels_settings() {
	$s = wp_parse_args( (array) get_option( 'hsm_pixels', array() ), array( 'ga4' => '', 'gads' => '', 'gads_label' => '', 'meta' => '', 'tiktok' => '' ) );
	$ok = array(
		'ga4'        => '/^G-[A-Z0-9]{4,20}$/',
		'gads'       => '/^AW-\d{5,15}$/',
		'gads_label' => '/^[A-Za-z0-9_-]{4,40}$/',
		'meta'       => '/^\d{8,20}$/',
		'tiktok'     => '/^[A-Z0-9]{8,30}$/',
	);
	foreach ( $ok as $k => $re ) {
		$s[ $k ] = preg_match( $re, (string) $s[ $k ] ) ? (string) $s[ $k ] : '';
	}
	return $s;
}

/** Event payload for the current page (view_item / purchase), or array(). */
function hsm_pixels_page_event() {
	if ( function_exists( 'is_product' ) && is_product() ) {
		$p = wc_get_product( get_queried_object_id() );
		if ( $p ) {
			return array( 'name' => 'view_item', 'event_id' => 'p' . $p->get_id(), 'currency' => get_woocommerce_currency(), 'value' => (float) $p->get_price(), 'items' => array( array( 'id' => $p->get_sku() ?: (string) $p->get_id(), 'name' => $p->get_name(), 'price' => (float) $p->get_price(), 'quantity' => 1 ) ) );
		}
	}
	if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
		$id = absint( get_query_var( 'order-received' ) );
		$o  = $id ? wc_get_order( $id ) : null;
		$key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( $o && $key && hash_equals( $o->get_order_key(), $key ) ) {
			$items = array();
			foreach ( $o->get_items() as $it ) {
				$pr      = $it->get_product();
				$items[] = array( 'id' => $pr && $pr->get_sku() ? $pr->get_sku() : (string) $it->get_product_id(), 'name' => $it->get_name(), 'price' => (float) $o->get_item_total( $it ), 'quantity' => (int) $it->get_quantity() );
			}
			return array( 'name' => 'purchase', 'event_id' => 'o' . $o->get_id(), 'transaction_id' => (string) $o->get_order_number(), 'currency' => $o->get_currency(), 'value' => (float) $o->get_total(), 'items' => $items );
		}
	}
	return array();
}

add_action( 'wp_head', function () {
	$s = hsm_pixels_settings();
	if ( ! array_filter( $s ) ) {
		return;
	}
	if ( function_exists( 'hsm_consent_gpc' ) && hsm_consent_gpc() ) {
		// Sec-GPC: 1 = opted out of sale/sharing: ad pixels are not even configured (page cache keys on GPC).
		$s = array_merge( $s, array( 'gads' => '', 'gads_label' => '', 'meta' => '', 'tiktok' => '' ) );
		if ( ! array_filter( $s ) ) {
			return;
		}
	}
	$cfg = array( 'ids' => $s, 'event' => hsm_pixels_page_event() );
	?>
<script id="hsm-pixels">
(function(){var C=<?php echo wp_json_encode( $cfg ); ?>,I=C.ids,done={};
function ok(c){return typeof window.hsmConsent==='function'?!!window.hsmConsent(c):false}
function ld(u){var s=document.createElement('script');s.async=1;s.src=u;document.head.appendChild(s)}
function ev(n,e){return e}
function ck(n,v){document.cookie=n+'='+v+';path=/;max-age=7776000;SameSite=Lax'+(location.protocol==='https:'?';Secure':'')}
function clk(){var q=new URLSearchParams(location.search),f=q.get('fbclid'),t=q.get('ttclid');
 if(f&&/^[A-Za-z0-9_-]{8,500}$/.test(f)&&document.cookie.indexOf('_fbc=')<0)ck('_fbc','fb.1.'+Date.now()+'.'+f);
 if(t&&/^[A-Za-z0-9_.-]{8,500}$/.test(t))ck('hsm_ttclid',t)}
function run(){
 if(!done.a&&ok('analytics')&&I.ga4){done.a=1;window.dataLayer=window.dataLayer||[];window.gtag=function(){dataLayer.push(arguments)};gtag('js',new Date());gtag('config',I.ga4);ld('https://www.googletagmanager.com/gtag/js?id='+I.ga4);
  if(C.event.name){gtag('event',C.event.name,{currency:C.event.currency,value:C.event.value,transaction_id:C.event.transaction_id,items:C.event.items.map(function(i){return{item_id:i.id,item_name:i.name,price:i.price,quantity:i.quantity}})})}}
 if(!done.d&&ok('ads')){done.d=1;var e=C.event;clk();
  if(I.gads){window.dataLayer=window.dataLayer||[];if(!window.gtag){window.gtag=function(){dataLayer.push(arguments)};gtag('js',new Date());ld('https://www.googletagmanager.com/gtag/js?id='+I.gads)}gtag('config',I.gads);
   if(e.name==='purchase'&&I.gads_label)gtag('event','conversion',{send_to:I.gads+'/'+I.gads_label,value:e.value,currency:e.currency,transaction_id:e.transaction_id})}
  if(I.meta){!function(f,b,e2,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e2);t.async=!0;t.src=v;s=b.getElementsByTagName(e2)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
   fbq('init',I.meta);fbq('track','PageView');
   if(e.name==='view_item')fbq('track','ViewContent',{content_ids:e.items.map(function(i){return i.id}),content_type:'product',value:e.value,currency:e.currency},{eventID:e.event_id});
   if(e.name==='purchase')fbq('track','Purchase',{content_ids:e.items.map(function(i){return i.id}),content_type:'product',value:e.value,currency:e.currency},{eventID:e.event_id})}
  if(I.tiktok){!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=['page','track','identify'];ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat([].slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.load=function(e){var n='https://analytics.tiktok.com/i18n/pixel/events.js';ttq._i=ttq._i||{};ttq._i[e]=[];ttq._t=ttq._t||{};ttq._t[e]=+new Date;var o=d.createElement('script');o.async=!0;o.src=n+'?sdkid='+e+'&lib='+t;var a=d.getElementsByTagName('script')[0];a.parentNode.insertBefore(o,a)};ttq.load(I.tiktok);ttq.page()}(window,document,'ttq');
   if(e.name==='view_item')ttq.track('ViewContent',{contents:e.items.map(function(i){return{content_id:i.id,quantity:1,price:i.price}}),content_type:'product',value:e.value,currency:e.currency},{event_id:e.event_id});
   if(e.name==='purchase')ttq.track('CompletePayment',{contents:e.items.map(function(i){return{content_id:i.id,quantity:i.quantity,price:i.price}}),content_type:'product',value:e.value,currency:e.currency},{event_id:e.event_id})}}
}
// window.hsmConsent is defined by the consent script in the footer: re-check once the DOM is parsed (returning visitors).
run();document.addEventListener('hsm:consent',run);if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',run);else setTimeout(run,0);
})();
</script>
	<?php
}, 20 );
