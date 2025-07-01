<?php
/**
 * Plugin Name: Postmark Error Logger
 * Description: Sends Postmark logs with ErrorCode != 0 to a Google Sheet daily at 3:33 AM.
 * Version: 1.0
 * Author: FizzPop Media
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Postmark_Error_To_Sheet {
	const GOOGLE_SHEET_WEBHOOK_URL = 'https://script.google.com/macros/s/AKfycbzeGXeSCbOcXPZO3_kz3dZg1zjAec9Fchd7xx4dxcj7CC8E3FD_g9Jr7BhT36KKHnkD/exec'; // Replace this
	const LOG_TABLE = 'postmark_log';
	const CRON_HOOK = 'postmark_error_cron_run';

	public function __construct() {
		add_action( 'admin_init', [ $this, 'maybe_run_check' ] );
		add_action( self::CRON_HOOK, [ $this, 'check_errors_and_send' ] );
		add_filter( 'cron_schedules', [ $this, 'custom_cron_schedule' ] );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$this->schedule_daily_at_333();
		}
	}

	public function maybe_run_check() {
		if ( isset( $_GET['run_postmark_error_check'] ) && current_user_can( 'manage_options' ) ) {
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );

			echo "🔍 Running Postmark error check for last 24 hours...\n\n";
			$count = $this->check_errors_and_send( true );
			echo "\n✅ Check complete. Total sent to webhook: $count\n";
			exit;
		}
	}

	public function custom_cron_schedule( $schedules ) {
		// No custom interval needed — we use 'daily'
		return $schedules;
	}

	public function schedule_daily_at_333() {
		$now = current_time( 'timestamp' );
		$target = strtotime( '03:33:00', $now );

		if ( $target <= $now ) {
			$target = strtotime( '+1 day 03:33:00', $now );
		}

		wp_schedule_event( $target, 'daily', self::CRON_HOOK );
	}

	public function check_errors_and_send( $verbose = false ) {
		global $wpdb;
		$table = $wpdb->prefix . self::LOG_TABLE;

		$now   = current_time( 'mysql' );
		$since = date( 'Y-m-d H:i:s', strtotime( '-24 hours', strtotime( $now ) ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, fromaddress, toaddress, subject, response, log_entry_date
				 FROM $table
				 WHERE response LIKE '%\"ErrorCode\":%' AND log_entry_date >= %s
				 ORDER BY log_entry_date DESC
				 LIMIT 50",
				$since
			),
			ARRAY_A
		);

		$sent_count = 0;

		$batch = [];

foreach ( $rows as $row ) {
	$raw = $row['response'];
	if ( $raw && $raw[0] === '"' ) $raw = stripslashes( trim( $raw, '"' ) );
	$parsed = json_decode( $raw, true );
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		if ( $verbose ) echo "❌ ID {$row['id']}: Invalid JSON\n";
		continue;
	}

	$code = $parsed['ErrorCode'] ?? null;
	if ( $code === 0 ) {
		if ( $verbose ) echo "✅ ID {$row['id']}: ErrorCode 0, skipping\n";
		continue;
	}

	$batch[] = [
		'id'        => $row['id'],
		'from'      => $row['fromaddress'],
		'to'        => $row['toaddress'],
		'subject'   => $row['subject'],
		'errorCode' => $code,
		'message'   => $parsed['Message'] ?? '',
		'timestamp' => $row['log_entry_date'],
		'domain'    => home_url(),
	];

	if ( $verbose ) echo "📤 Queued ID {$row['id']}\n";
}

if ( ! empty( $batch ) ) {
	$response = wp_remote_post( self::GOOGLE_SHEET_WEBHOOK_URL, [
		'method'  => 'POST',
		'timeout' => 10,
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body'    => wp_json_encode([ 'entries' => $batch ]),
	]);

	if ( is_wp_error( $response ) ) {
		if ( $verbose ) echo "❌ Webhook error - {$response->get_error_message()}\n";
	} elseif ( wp_remote_retrieve_response_code( $response ) === 200 ) {
		if ( $verbose ) echo "✅ Sent " . count( $batch ) . " entries to webhook\n";
		$sent_count += count( $batch );
	} else {
		if ( $verbose ) echo "❌ Webhook response code " . wp_remote_retrieve_response_code( $response ) . "\n";
	}
}


		return $sent_count;
	}
}

new Postmark_Error_To_Sheet();
