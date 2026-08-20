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
	const MAX_STATE_ITEMS = 50;

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

		register_rest_route(
			self::NAMESPACE_,
			'/product-state',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_product_state' ),
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
	 * وضعیت فعلی چند محصول را برمی‌گرداند — فقط خواندنی.
	 *
	 * تا پیش از این، افزونه فقط مسیر نوشتن داشت و سایت مبدأ هیچ راهی
	 * نداشت بفهمد داده‌ی سایت مقابل با خودش می‌خواند یا نه. این مسیر
	 * همان شکاف را پر می‌کند: قیمت و موجودی را برمی‌گرداند تا بشود
	 * ناهماهنگی را تشخیص داد، بدون اینکه چیزی تغییر کند.
	 *
	 * بدنه‌ی درخواست:
	 *   { "ids": [12, 34] }        شناسه‌های محلیِ سایت مقابل
	 *   { "skus": ["0715"] }       یا بر اساس SKU
	 *
	 * حداکثر ۵۰ مورد در هر درخواست.
	 *
	 * @param WP_REST_Request $request درخواست.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_product_state( $request ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'dss_no_woocommerce', 'ووکامرس روی این سایت فعال نیست.', array( 'status' => 503 ) );
		}

		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'dss_bad_payload', 'بدنه‌ی درخواست JSON معتبر نیست.', array( 'status' => 400 ) );
		}

		$ids  = isset( $payload['ids'] ) && is_array( $payload['ids'] ) ? array_map( 'absint', $payload['ids'] ) : array();
		$skus = isset( $payload['skus'] ) && is_array( $payload['skus'] ) ? array_map( 'sanitize_text_field', $payload['skus'] ) : array();

		foreach ( $skus as $sku ) {
			if ( '' === $sku ) {
				continue;
			}

			$found = wc_get_product_id_by_sku( $sku );

			if ( $found ) {
				$ids[] = (int) $found;
			}
		}

		$ids = array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, self::MAX_STATE_ITEMS );

		if ( empty( $ids ) ) {
			return new WP_REST_Response( array( 'products' => array() ), 200 );
		}

		$products = array();

		foreach ( $ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$products[] = self::product_state( $product );
		}

		return new WP_REST_Response(
			array(
				'site'     => DSS_Config::current_key(),
				'products' => $products,
			),
			200
		);
	}

	/**
	 * وضعیت یک محصول به شکلی که برای مقایسه کافی باشد.
	 *
	 * عمداً کوچک نگه داشته شده: فقط چیزهایی که ممکن است بین دو سایت
	 * از هم دور بیفتند. توضیحات و تصاویر اینجا نمی‌آیند.
	 *
	 * @param WC_Product $product محصول.
	 *
	 * @return array
	 */
	private static function product_state( $product ) {
		$state = array(
			'id'             => $product->get_id(),
			'sku'            => $product->get_sku(),
			'name'           => $product->get_name(),
			'type'           => $product->get_type(),
			'status'         => $product->get_status(),
			'regular_price'  => (string) $product->get_regular_price(),
			'sale_price'     => (string) $product->get_sale_price(),
			'stock_status'   => $product->get_stock_status(),
			'manage_stock'   => (bool) $product->get_manage_stock(),
			'stock_quantity' => $product->get_manage_stock() ? $product->get_stock_quantity() : null,
			'modified'       => $product->get_date_modified() ? $product->get_date_modified()->date( 'Y-m-d H:i:s' ) : '',
			'variations'     => array(),
		);

		if ( ! $product->is_type( 'variable' ) ) {
			return $state;
		}

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation ) {
				continue;
			}

			$state['variations'][] = array(
				'id'             => $variation->get_id(),
				'sku'            => $variation->get_sku(),
				'attributes'     => $variation->get_attributes(),
				'regular_price'  => (string) $variation->get_regular_price(),
				'sale_price'     => (string) $variation->get_sale_price(),
				'stock_status'   => $variation->get_stock_status(),
				'manage_stock'   => (bool) $variation->get_manage_stock(),
				'stock_quantity' => $variation->get_manage_stock() ? $variation->get_stock_quantity() : null,
			);
		}

		return $state;
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
