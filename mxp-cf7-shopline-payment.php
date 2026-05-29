<?php
/**
 * Plugin Name: MXP CF7 Shopline Payment
 * Plugin URI: https://github.com/nczz/mxp-cf7-shopline-payment
 * Description: 讓 Contact Form 7 表單具備 SHOPLINE Payments 收款能力，支援信用卡、LINE Pay、街口支付、ATM 轉帳、Apple Pay、中租零卡分期。
 * Version: 1.1.1
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Requires Plugins: contact-form-7
 * Author: Jeremie Chiang (MXP)
 * Author URI: https://mxp.tw
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mxp-cf7-slp
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MXP_SLP_VERSION', '1.1.1' );
define( 'MXP_SLP_PLUGIN_FILE', __FILE__ );
define( 'MXP_SLP_PLUGIN_DIR', __DIR__ );
define( 'MXP_SLP_PLUGIN_URL', plugins_url( '', __FILE__ ) );
define( 'MXP_SLP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once MXP_SLP_PLUGIN_DIR . '/includes/class-loader.php';
