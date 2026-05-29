<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MXP_SLP_Mail_Handler {

	public static function send_payment_confirmation( string $order_token ): bool {
		$order_id = MXP_SLP_Order::find_by_token( $order_token );
		if ( ! $order_id ) {
			return false;
		}

		// 冪等：用 add_post_meta unique=true 做原子鎖
		// 如果已存在 _slp_mail_lock 則 add 會回傳 false（不重複發送）
		$lock = add_post_meta( $order_id, '_slp_mail_lock', time(), true );
		if ( ! $lock ) {
			return false; // 另一個 process 已在處理
		}

		// 二次確認
		$mail_sent = get_post_meta( $order_id, '_slp_mail_sent', true );
		if ( $mail_sent ) {
			delete_post_meta( $order_id, '_slp_mail_lock' );
			return false;
		}

		$form_id     = (int) get_post_meta( $order_id, '_slp_form_id', true );
		$posted_data = get_post_meta( $order_id, '_slp_posted_data', true );

		if ( ! $form_id || ! is_array( $posted_data ) ) {
			delete_post_meta( $order_id, '_slp_mail_lock' );
			return false;
		}

		$contact_form = wpcf7_contact_form( $form_id );
		if ( ! $contact_form ) {
			delete_post_meta( $order_id, '_slp_mail_lock' );
			return false;
		}

		// 準備 SLP 相關資料供 special mail tags 使用
		$slp_data = [
			'amount'         => get_post_meta( $order_id, '_slp_amount', true ),
			'order_number'   => get_the_title( $order_id ),
			'payment_method' => get_post_meta( $order_id, '_slp_payment_method', true ),
			'session_id'     => get_post_meta( $order_id, '_slp_session_id', true ),
			'trade_order_id' => get_post_meta( $order_id, '_slp_trade_order_id', true ),
		];

		// 註冊 filter 注入 posted_data
		$tag_filter = function( $replaced, $submitted, $html, $mail_tag ) use ( $posted_data ) {
			if ( null === $replaced || '' === $replaced ) {
				$field_name = $mail_tag->field_name();
				if ( isset( $posted_data[ $field_name ] ) ) {
					$value = $posted_data[ $field_name ];
					if ( is_array( $value ) ) {
						$value = implode( ', ', $value );
					}
					return $html ? esc_html( $value ) : $value;
				}
			}
			return $replaced;
		};

		// 註冊 special mail tags filter
		$special_filter = function( $output, $name, $html, $mail_tag ) use ( $slp_data ) {
			return match ( $name ) {
				'_slp_amount'         => 'NT$ ' . number_format( (int) $slp_data['amount'] ),
				'_slp_order_number'   => $slp_data['order_number'],
				'_slp_payment_method' => MXP_SLP_Request_Builder::get_method_label( $slp_data['payment_method'] ),
				'_slp_session_id'     => $slp_data['session_id'],
				'_slp_trade_order_id' => $slp_data['trade_order_id'],
				default               => $output,
			};
		};

		add_filter( 'wpcf7_mail_tag_replaced', $tag_filter, 10, 4 );
		add_filter( 'wpcf7_special_mail_tags', $special_filter, 20, 4 );

		// 發送 mail
		$mail_prop = $contact_form->prop( 'mail' );
		$sent = false;

		if ( $mail_prop ) {
			$sent = WPCF7_Mail::send( $mail_prop, 'mail' );
		}

		// 發送 mail_2（如果啟用）
		$mail_2 = $contact_form->prop( 'mail_2' );
		if ( $mail_2 && ! empty( $mail_2['active'] ) ) {
			WPCF7_Mail::send( $mail_2, 'mail_2' );
		}

		// 移除 filters
		remove_filter( 'wpcf7_mail_tag_replaced', $tag_filter, 10 );
		remove_filter( 'wpcf7_special_mail_tags', $special_filter, 20 );

		// Flamingo 整合
		if ( class_exists( 'Flamingo_Inbound_Message' ) ) {
			$contact_form_obj = wpcf7_contact_form( $form_id );
			Flamingo_Inbound_Message::add( [
				'channel' => 'contact-form-7',
				'subject' => $contact_form_obj ? $contact_form_obj->title() : '',
				'from'    => $posted_data['your-email'] ?? $posted_data['email'] ?? '',
				'fields'  => $posted_data,
				'meta'    => [
					'slp_order_number'   => get_the_title( $order_id ),
					'slp_amount'         => $slp_data['amount'],
					'slp_payment_method' => $slp_data['payment_method'],
					'slp_status'         => 'SUCCEEDED',
				],
			] );
		}

		// 觸發 action
		do_action( 'mxp_slp_payment_confirmed', $order_token, $order_id );

		if ( $sent ) {
			update_post_meta( $order_id, '_slp_mail_sent', time() );
		} else {
			delete_post_meta( $order_id, '_slp_mail_lock' );
		}

		return $sent;
	}
}
