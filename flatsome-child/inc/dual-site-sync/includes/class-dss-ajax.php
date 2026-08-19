<?php
/**
 * هندلر AJAX پنل مدیریت (یک نقطه برای همه‌ی حالت‌ها).
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

class DSS_Ajax {

	const NONCE = 'dss_sync';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_dss_sync', array( $this, 'handle_sync' ) );
		add_action( 'wp_ajax_dss_ping', array( $this, 'handle_ping' ) );
	}

	/**
	 * اجرای یک همگام‌سازی.
	 */
	public function handle_sync() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$mode       = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';

		if ( ! $product_id || ! current_user_can( 'edit_post', $product_id ) ) {
			wp_send_json_error( 'برای این محصول دسترسی ویرایش ندارید.' );
		}

		if ( ! in_array( $mode, DSS_Exporter::MODES, true ) ) {
			wp_send_json_error( 'حالت همگام‌سازی نامعتبر است.' );
		}

		$result = self::run( $product_id, $mode );

		if ( $result['success'] ) {
			wp_send_json_success( $result['message'] );
		}

		wp_send_json_error( $result['message'] );
	}

	/**
	 * آزمایش اتصال از صفحه‌ی تنظیمات.
	 */
	public function handle_ping() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'دسترسی ندارید.' );
		}

		$result = DSS_Client::ping();

		if ( $result['success'] ) {
			wp_send_json_success( $result['message'] );
		}

		wp_send_json_error( $result['message'] );
	}

	/**
	 * اجرای همگام‌سازی یک محصول (قابل استفاده از سایر بخش‌ها هم هست).
	 *
	 * @param int    $product_id محصول.
	 * @param string $mode       حالت.
	 *
	 * @return array{success:bool,message:string,id:int}
	 */
	public static function run( $product_id, $mode ) {
		$errors = DSS_Config::configuration_errors();

		if ( ! empty( $errors ) ) {
			return array( 'success' => false, 'message' => 'پیکربندی ناقص است: ' . implode( ' / ', $errors ), 'id' => 0 );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return array( 'success' => false, 'message' => 'محصول پیدا نشد.', 'id' => 0 );
		}

		// جلوگیری از ایجاد تکراری.
		if ( 'create' === $mode ) {
			$remote_id = absint( get_post_meta( $product_id, DSS_Importer::META_REMOTE_ID, true ) );

			if ( $remote_id ) {
				return array(
					'success' => false,
					'message' => sprintf( 'این محصول از قبل به محصول %d در سایت مقابل متصل است؛ به‌جای ایجاد، از دکمه‌های همگام‌سازی استفاده کنید.', $remote_id ),
					'id'      => $remote_id,
				);
			}
		}

		try {
			$payload = DSS_Exporter::build( $product, $mode );
		} catch ( Throwable $e ) {
			DSS_Logger::error( 'ساخت بسته‌ی ارسالی ناموفق بود.', array( 'product_id' => $product_id, 'error' => $e->getMessage() ) );

			return array( 'success' => false, 'message' => 'خطا در آماده‌سازی داده: ' . $e->getMessage(), 'id' => 0 );
		}

		$result = DSS_Client::send( $payload );

		// ثبت پیوند در همین سایت تا دفعه‌ی بعد بدانیم محصول قبلاً ساخته شده.
		if ( $result['success'] && $result['id'] ) {
			DSS_Importer::link( $product_id, $result['id'], DSS_Config::target_key() );

			if ( 'create' === $mode ) {
				update_post_meta( $product_id, DSS_Importer::META_CREATED_BY, DSS_Config::current_key() );
			}
		}

		DSS_Logger::log(
			$result['success'] ? 'success' : 'error',
			sprintf( 'ارسال (%s): %s', DSS_Exporter::mode_label( $mode ), $result['message'] ),
			array( 'product_id' => $product_id, 'mode' => $mode, 'remote_id' => $result['id'] )
		);

		return $result;
	}
}
