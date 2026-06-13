<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wpcf7_init', 'mxp_slp_register_form_tag', 10 );

function mxp_slp_register_form_tag(): void {
	wpcf7_add_form_tag(
		'shopline_payment',
		'mxp_slp_form_tag_handler',
		[
			'display-block' => true,
			'singular'      => true,
		]
	);
}

function mxp_slp_form_tag_handler( $tag ): string {
	$contact_form = WPCF7_ContactForm::get_current();
	$form_id = $contact_form ? $contact_form->id() : 0;
	$settings = MXP_SLP_Request_Builder::normalize_settings( get_post_meta( $form_id, '_slp_payment_settings', true ) ?: [] );

	$amount = $settings['amount'] ?? 0;
	$button_text = $settings['button_text'] ?? '';
	$methods = array_values( array_filter(
		(array) ( $settings['payment_methods'] ?? [ 'CreditCard' ] ),
		fn( $method ) => in_array( $method, [ 'CreditCard', 'ApplePay', 'LinePay', 'VirtualAccount', 'JKOPay', 'ChaileaseBNPL' ], true )
	) );
	if ( empty( $methods ) ) {
		$methods = [ 'CreditCard' ];
	}

	// 按鈕文字：tag values > 設定 > 預設
	if ( ! empty( $tag->values[0] ) ) {
		$button_text = trim( $tag->values[0] );
	}
	if ( 'fixed' !== $settings['amount_mode'] && preg_match( '/NT\\$|元/', $button_text ) ) {
		$button_text = '';
	}
	if ( '' === $button_text ) {
		$button_text = __( '前往付款', 'mxp-cf7-slp' );
	}

	// 付款方式標籤
	$method_labels = array_map(
		[ 'MXP_SLP_Request_Builder', 'get_method_label' ],
		$methods
	);

	$amount_display = '';
	if ( 'fixed' === $settings['amount_mode'] && $amount > 0 ) {
		$amount_display = 'NT$' . number_format( $amount );
	} elseif ( 'user_input' === $settings['amount_mode'] ) {
		$amount_display = sprintf(
			/* translators: 1: minimum amount, 2: maximum amount */
			__( '請輸入 NT$%1$s - NT$%2$s', 'mxp-cf7-slp' ),
			number_format( $settings['amount_min'] ),
			number_format( $settings['amount_max'] )
		);
	} elseif ( 'field_mapping' === $settings['amount_mode'] ) {
		$amount_display = __( '付款金額將依表單內容計算', 'mxp-cf7-slp' );
	}

	$has_sdk_methods = 'fixed' === $settings['amount_mode'] ? array_intersect( $methods, [ 'CreditCard', 'ApplePay' ] ) : [];
	$mode = $has_sdk_methods ? 'hybrid' : 'redirect';
	$sdk_installments = array_values( array_filter(
		(array) $settings['cc_installments'],
		fn( $installment ) => preg_match( '/^(0|3|6|9|12|18|24)$/', (string) $installment )
	) );

	$html = '<div class="wpcf7-shopline-payment" data-mode="' . esc_attr( $mode ) . '" data-form-id="' . esc_attr( $form_id ) . '" data-amount-mode="' . esc_attr( $settings['amount_mode'] ) . '" data-amount-min="' . esc_attr( $settings['amount_min'] ) . '" data-amount-max="' . esc_attr( $settings['amount_max'] ) . '" data-sdk-amount="' . esc_attr( $amount * 100 ) . '" data-cc-installments="' . esc_attr( wp_json_encode( $sdk_installments ) ) . '">';

	// SDK 容器（僅 hybrid 模式）
	if ( $has_sdk_methods ) {
		$html .= '<div class="slp-sdk-container" id="slp-sdk-' . esc_attr( $form_id ) . '" style="display:none;"></div>';
	}

	if ( $amount_display ) {
		$html .= sprintf(
			'<div class="slp-product-summary"><span class="slp-amount-display">%s</span></div>',
			esc_html( $amount_display )
		);
	}

	if ( 'user_input' === $settings['amount_mode'] ) {
		$html .= '<div class="slp-custom-amount">';
		$html .= '<label class="slp-custom-amount-label" for="slp-amount-' . esc_attr( $form_id ) . '">' . esc_html__( '付款金額', 'mxp-cf7-slp' ) . '</label>';
		$html .= '<div class="slp-custom-amount-control"><span class="slp-currency-prefix">NT$</span><input id="slp-amount-' . esc_attr( $form_id ) . '" type="number" inputmode="numeric" pattern="[0-9]*" name="slp_amount" min="' . esc_attr( $settings['amount_min'] ) . '" max="' . esc_attr( $settings['amount_max'] ) . '" step="1" placeholder="' . esc_attr( number_format( $settings['amount_min'] ) ) . '" autocomplete="off"></div>';
		if ( ! empty( $settings['suggested_amounts'] ) ) {
			$html .= '<div class="slp-suggested-amounts" aria-label="' . esc_attr__( '建議金額', 'mxp-cf7-slp' ) . '">';
			foreach ( $settings['suggested_amounts'] as $suggested_amount ) {
				$html .= '<button type="button" class="slp-suggested-amount" data-amount="' . esc_attr( $suggested_amount ) . '" aria-pressed="false">NT$' . esc_html( number_format( $suggested_amount ) ) . '</button>';
			}
			$html .= '</div>';
		}
		$html .= '<p class="slp-amount-help">' . esc_html( sprintf(
			/* translators: 1: minimum amount, 2: maximum amount */
			__( '可付款金額：NT$%1$s - NT$%2$s', 'mxp-cf7-slp' ),
			number_format( $settings['amount_min'] ),
			number_format( $settings['amount_max'] )
		) ) . '</p>';
		$html .= '</div>';
	} elseif ( 'field_mapping' === $settings['amount_mode'] && $settings['amount_field'] ) {
		$html .= '<p class="slp-amount-help">' . esc_html( sprintf(
			/* translators: %s: CF7 field name */
			__( '付款金額取自欄位：%s', 'mxp-cf7-slp' ),
			$settings['amount_field']
		) ) . '</p>';
	}

	$html .= sprintf(
		'<button type="submit" class="wpcf7-form-control wpcf7-submit slp-submit-btn">'
		. '<span class="slp-btn-text">%s</span>'
		. '<span class="slp-btn-spinner"><span class="slp-spinner-icon"></span> %s</span>'
		. '</button>',
		esc_html( $button_text ),
		esc_html__( '處理中...', 'mxp-cf7-slp' )
	);

	if ( $method_labels ) {
		$html .= '<div class="slp-payment-methods-preview">';
		foreach ( $method_labels as $label ) {
			$html .= sprintf( '<span class="slp-method">%s</span>', esc_html( $label ) );
		}
		$html .= '</div>';
	}

	$html .= '<input type="hidden" name="_slp_form_payment" value="1" />';
	$html .= '</div>';

	return $html;
}

add_action( 'wpcf7_enqueue_scripts', 'mxp_slp_enqueue_frontend_assets' );

function mxp_slp_enqueue_frontend_assets(): void {
	// SLP SDK CDN（Phase 2 內嵌式）
	wp_register_script(
		'shopline-payments-sdk',
		'https://cdn.shoplinepayments.com/sdk/v1/payment-web.js',
		[],
		null,
		[ 'in_footer' => true ]
	);

	wp_enqueue_style(
		'mxp-cf7-slp-frontend',
		MXP_SLP_PLUGIN_URL . '/assets/css/frontend.css',
		[],
		MXP_SLP_VERSION
	);

	wp_enqueue_script(
		'mxp-cf7-slp-frontend',
		MXP_SLP_PLUGIN_URL . '/assets/js/frontend.js',
		[ 'contact-form-7' ],
		MXP_SLP_VERSION,
		[ 'in_footer' => true ]
	);

	// SDK 設定（Phase 2 內嵌式需要）
	$api = MXP_SLP_API::get_instance();
	$client_key = $api->get_client_key();
	if ( $client_key ) {
		wp_enqueue_script( 'shopline-payments-sdk' );
		wp_localize_script( 'mxp-cf7-slp-frontend', 'mxpSlpSettings', [
			'clientKey'  => $client_key,
			'merchantId' => $api->get_merchant_id_public(),
			'env'        => $api->get_environment(),
			'amount'     => 0, // 由前端從 DOM 讀取
			'apiRoot'    => esc_url_raw( rest_url() ),
		] );
	}
}
