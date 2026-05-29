<?php
/**
 * Uninstall MXP CF7 Shopline Payment
 *
 * 移除所有外掛資料（選項、訂單、return page）
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 刪除全域設定
delete_option( 'mxp_slp_settings' );
delete_option( '_slp_order_counter' );
delete_option( '_slp_webhook_last_received' );

// 刪除所有訂單 CPT
$orders = get_posts( [
	'post_type'   => 'slp_order',
	'numberposts' => -1,
	'fields'      => 'ids',
	'post_status' => 'any',
] );

foreach ( $orders as $order_id ) {
	wp_delete_post( $order_id, true );
}

// 刪除 return page
$return_page = get_page_by_path( 'slp-payment-return' );
if ( $return_page ) {
	wp_delete_post( $return_page->ID, true );
}

// 刪除所有表單的付款設定 meta
global $wpdb;
$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_slp_payment_settings' ] );

// 刪除 event id 去重記錄
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_slp_evt_%'" );

// 刪除 rate limit transients
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_slp_rate_%'" );

// 清除 cron
wp_clear_scheduled_hook( 'mxp_slp_cleanup_events' );
