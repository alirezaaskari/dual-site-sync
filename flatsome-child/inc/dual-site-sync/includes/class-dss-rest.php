<?php
/**
 * نقطه‌ی دریافت درخواست از سایت مقابل.
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

class DSS_Rest {

	const NAMESPACE_ = 'dss/v1';
	const MAX_SKEW   = 300; // ثانیه

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_,
			'/sync-product',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_sync' ),
				'permission_callback' => array( $this, 'verify_request' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/ping',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_ping' ),
				'permission_callback' => array( $this, 'verify_request' ),
			)
		);

		// مسیر نسخه‌ی قدیمی، برای دوره‌ی گذار.
		register_rest_route(
			self::NAMESPACE_,
			'/sync-product/(?P<source_id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_legacy' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * بررسی امضای درخواست.
	 *
	 * @param WP_REST_Request $request درخواست.
	 *
	 * @return true|WP_Error
	 */
	public function verify_request( $request ) {
		$secret = DSS_Config::secret();

		if ( '' === $secret ) {
			return new WP_Error( 'dss_not_configured', 'همگام‌سازی روی این سایت پیکربندی نشده است.', array( 'status' => 503 ) );
		}

		$site      = (string) $request->get_header( 'x-dss-site' );
		$timestamp = (string) $request->get_header( 'x-dss-timestamp' );
		$nonce     = (string) $request->get_header( 'x-dss-nonce' );
		$signature = (string) $request->get_header( 'x-dss-signature' );

		if ( ! $site || ! $timestamp || ! $nonce || ! $signature ) {
			return new WP_Error( 'dss_missing_headers', 'هدرهای احراز هویت ناقص است.', array( 'status' => 401 ) );
		}

		// فقط سایت مقابل مجاز است؛ کلید خودِ این سایت پذیرفته نمی‌شود.
		if ( $site !== DSS_Config::target_key() ) {
			return new WP_Error( 'dss_unknown_site', 'سایت فرستنده شناسایی نشد.', array( 'status' => 403 ) );
		}

		if ( abs( time() - (int) $timestamp ) > self::MAX_SKEW ) {
			return new WP_Error( 'dss_stale_request', 'زمان درخواست معتبر نیست (اختلاف ساعت سرورها بیش از ۵ دقیقه است).', array( 'status' => 401 ) );
		}

		$expected = DSS_Client::sign( $timestamp, $nonce, $request->get_body() );

		if ( ! hash_equals( $expected, $signature ) ) {
			DSS_Logger::warning( 'درخواست با امضای نامعتبر رد شد.', array( 'site' => $site ) );

			return new WP_Error( 'dss_bad_signature', 'امضای درخواست معتبر نیست.', array( 'status' => 403 ) );
		}

		// جلوگیری از بازپخش درخواست.
		$nonce_key = 'dss_nonce_' . md5( $site . '|' . $nonce );

		if ( get_transient( $nonce_key ) ) {
			return new WP_Error( 'dss_replay', 'این درخواست قبلاً پردازش شده است.', array( 'status' => 409 ) );
		}

		set_transient( $nonce_key, 1, 2 * self::MAX_SKEW );

		return true;
	}

	/**
	 * پردازش بسته.
	 *
	 * @param WP_REST_Request $request درخواست.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_sync( $request ) {
		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'dss_bad_payload', 'بدنه‌ی درخواست JSON معتبر نیست.', array( 'status' => 400 ) );
		}

		try {
			$result = DSS_Importer::handle( $payload );

			DSS_Logger::log(
				$result['success'] ? 'success' : 'warning',
				'دریافت: ' . $result['message'],
				array(
					'mode'      => isset( $payload['mode'] ) ? $payload['mode'] : '',
					'source_id' => isset( $payload['source_id'] ) ? $payload['source_id'] : 0,
					'local_id'  => $result['id'],
				)
			);

			return new WP_REST_Response( $result, 200 );
		} catch ( Throwable $e ) {
			DSS_Logger::error( 'خطای پردازش بسته‌ی دریافتی.', array(
				'error' => $e->getMessage(),
				'file'  => $e->getFile() . ':' . $e->getLine(),
			) );

			return new WP_Error( 'dss_import_failed', 'خطای داخلی در سایت مقصد: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * پاسخ آزمایش اتصال.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_ping() {
		return new WP_REST_Response(
			array(
				'pong'     => true,
				'site'     => DSS_Config::label( DSS_Config::current_key() ),
				'site_key' => DSS_Config::current_key(),
				'version'  => DSS_VERSION,
				'swatches' => array(
					'active' => DSS_Swatches::is_active(),
					'pro'    => DSS_Swatches::is_pro(),
				),
			),
			200
		);
	}

	/**
	 * مسیر قدیمی: صراحتاً رد می‌شود تا کسی با کلیدهای لو رفته‌ی قبلی چیزی ننویسد.
	 *
	 * @return WP_Error
	 */
	public function handle_legacy() {
		return new WP_Error(
			'dss_legacy_endpoint',
			'این مسیر منسوخ شده است. نسخه‌ی جدید Dual Site Sync را روی هر دو سایت نصب کنید.',
			array( 'status' => 410 )
		);
	}
}
