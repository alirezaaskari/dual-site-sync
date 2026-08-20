<?php
/**
 * ارسال درخواست به سایت مقابل.
 *
 * احراز هویت با امضای HMAC-SHA256 روی بدنه‌ی درخواست انجام می‌شود؛ هیچ کلید
 * رمزی روی خط منتقل نمی‌شود و درخواست‌های تکراری (replay) رد می‌شوند.
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

class DSS_Client {

	const ENDPOINT = '/wp-json/dss/v1/sync-product';
	const PING     = '/wp-json/dss/v1/ping';
	const STATE    = '/wp-json/dss/v1/product-state';

	/**
	 * ارسال بسته‌ی همگام‌سازی.
	 *
	 * @param array $payload بسته.
	 *
	 * @return array{success:bool,message:string,id:int}
	 */
	public static function send( array $payload ) {
		$target = DSS_Config::target();

		if ( ! $target ) {
			return array(
				'success' => false,
				'message' => 'سایت مقابل پیکربندی نشده است.',
				'id'      => 0,
			);
		}

		$response = self::request( $target['url'] . self::ENDPOINT, $payload );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => 'خطا در ارتباط با سایت مقابل: ' . $response->get_error_message(),
				'id'      => 0,
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 200 !== $code ) {
			$remote_message = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : wp_strip_all_tags( substr( $body, 0, 300 ) );

			return array(
				'success' => false,
				'message' => sprintf( 'سایت مقابل خطا داد (کد %d): %s', $code, $remote_message ),
				'id'      => 0,
			);
		}

		if ( ! is_array( $data ) ) {
			return array(
				'success' => false,
				'message' => 'پاسخ سایت مقابل قابل خواندن نبود.',
				'id'      => 0,
			);
		}

		return array(
			'success' => ! empty( $data['success'] ),
			'message' => isset( $data['message'] ) ? $data['message'] : '',
			'id'      => isset( $data['id'] ) ? absint( $data['id'] ) : 0,
		);
	}

	/**
	 * خواندن وضعیت چند محصول از سایت مقابل — بدون تغییر دادن چیزی.
	 *
	 * برای تشخیص ناهماهنگی: قیمت و موجودی آن طرف را می‌گیرد تا بشود
	 * با این طرف مقایسه کرد.
	 *
	 * @param int[]    $ids  شناسه‌های محصول در سایت مقابل.
	 * @param string[] $skus یا SKU ها، اگر شناسه‌ی مقابل را نمی‌دانید.
	 *
	 * @return array{success:bool,message:string,products:array}
	 */
	public static function fetch_state( array $ids = array(), array $skus = array() ) {
		$target = DSS_Config::target();

		if ( ! $target ) {
			return array( 'success' => false, 'message' => 'سایت مقابل پیکربندی نشده است.', 'products' => array() );
		}

		if ( empty( $ids ) && empty( $skus ) ) {
			return array( 'success' => true, 'message' => '', 'products' => array() );
		}

		$response = self::request(
			$target['url'] . self::STATE,
			array(
				'ids'  => array_values( array_map( 'absint', $ids ) ),
				'skus' => array_values( $skus ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success'  => false,
				'message'  => 'خطا در ارتباط با سایت مقابل: ' . $response->get_error_message(),
				'products' => array(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 200 !== $code ) {
			$remote_message = is_array( $data ) && isset( $data['message'] )
				? $data['message']
				: wp_strip_all_tags( substr( $body, 0, 300 ) );

			return array(
				'success'  => false,
				'message'  => sprintf( 'سایت مقابل خطا داد (کد %d): %s', $code, $remote_message ),
				'products' => array(),
			);
		}

		if ( ! is_array( $data ) || ! isset( $data['products'] ) ) {
			return array(
				'success'  => false,
				'message'  => 'پاسخ سایت مقابل قابل خواندن نبود. شاید نسخه‌ی افزونه آنجا قدیمی است.',
				'products' => array(),
			);
		}

		return array( 'success' => true, 'message' => '', 'products' => $data['products'] );
	}

	/**
	 * آزمایش اتصال.
	 *
	 * @return array{success:bool,message:string}
	 */
	public static function ping() {
		$target = DSS_Config::target();

		if ( ! $target ) {
			return array( 'success' => false, 'message' => 'سایت مقابل پیکربندی نشده است.' );
		}

		$response = self::request( $target['url'] . self::PING, array( 'ping' => time() ) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && is_array( $data ) && ! empty( $data['pong'] ) ) {
			return array(
				'success' => true,
				'message' => sprintf(
					'اتصال برقرار است. سایت مقابل: %s — نسخه‌ی افزونه: %s',
					isset( $data['site'] ) ? $data['site'] : '؟',
					isset( $data['version'] ) ? $data['version'] : '؟'
				),
			);
		}

		if ( 401 === $code || 403 === $code ) {
			return array( 'success' => false, 'message' => 'امضا پذیرفته نشد. مقدار DSS_SHARED_SECRET باید در هر دو سایت یکسان باشد.' );
		}

		return array( 'success' => false, 'message' => sprintf( 'پاسخ غیرمنتظره (کد %d).', $code ) );
	}

	/**
	 * اجرای درخواست امضاشده.
	 *
	 * @param string $url     آدرس.
	 * @param array  $payload بدنه.
	 *
	 * @return array|WP_Error
	 */
	private static function request( $url, array $payload ) {
		$body      = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
		$timestamp = (string) time();
		$nonce     = wp_generate_password( 20, false, false );
		$signature = self::sign( $timestamp, $nonce, $body );

		$args = array(
			'body'        => $body,
			'timeout'     => max( 30, absint( DSS_Config::get( 'request_timeout' ) ) ),
			'redirection' => 0,
			'headers'     => array(
				'Content-Type'      => 'application/json; charset=utf-8',
				'Accept'            => 'application/json',
				'X-DSS-Site'        => DSS_Config::current_key(),
				'X-DSS-Timestamp'   => $timestamp,
				'X-DSS-Nonce'       => $nonce,
				'X-DSS-Signature'   => $signature,
				'X-DSS-Version'     => DSS_VERSION,
			),
		);

		return wp_remote_post( $url, apply_filters( 'dss_request_args', $args, $url, $payload ) );
	}

	/**
	 * محاسبه‌ی امضا.
	 *
	 * @param string $timestamp زمان.
	 * @param string $nonce     نانس.
	 * @param string $body      بدنه.
	 *
	 * @return string
	 */
	public static function sign( $timestamp, $nonce, $body ) {
		return hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $body, DSS_Config::secret() );
	}
}
