<?php
/**
 * خودکارسازی: همگام‌سازی خودکار پس از ذخیره، و پوش موجودی در حالت انبار مشترک.
 *
 * هر دو قابلیت به‌صورت پیش‌فرض خاموش‌اند و از صفحه‌ی تنظیمات روشن می‌شوند.
 * ارسال‌ها در انتهای درخواست و به‌صورت یکجا انجام می‌شود تا ذخیره‌ی محصول کند نشود.
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

class DSS_Stock {

	/**
	 * صفِ ارسال: product_id => mode.
	 *
	 * @var array<int,string>
	 */
	private static $queue = array();

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		// انبار مشترک: هر تغییر موجودی (از جمله فروش) به سایت مقابل پوش شود.
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_change' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_stock_change' ) );

		// همگام‌سازی خودکار پس از ذخیره‌ی محصول.
		add_action( 'woocommerce_update_product', array( $this, 'on_product_saved' ), 20 );

		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	/**
	 * تغییر موجودی.
	 *
	 * @param WC_Product $product محصول یا واریشن.
	 */
	public function on_stock_change( $product ) {
		if ( ! DSS_Config::is_on( 'shared_inventory' ) || DSS_Context::is_syncing() ) {
			return;
		}

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		if ( $parent_id ) {
			self::enqueue( $parent_id, 'stock' );
		}
	}

	/**
	 * ذخیره‌ی محصول در پنل.
	 *
	 * @param int $product_id محصول.
	 */
	public function on_product_saved( $product_id ) {
		$mode = DSS_Config::get( 'auto_sync_mode' );

		if ( 'off' === $mode || DSS_Context::is_syncing() ) {
			return;
		}

		if ( ! in_array( $mode, DSS_Exporter::MODES, true ) || 'create' === $mode ) {
			return;
		}

		// همگام‌سازی خودکار فقط برای محصولاتی که از قبل متصل‌اند.
		$remote_id = absint( get_post_meta( $product_id, DSS_Importer::META_REMOTE_ID, true ) );

		if ( ! $remote_id ) {
			return;
		}

		self::enqueue( $product_id, $mode );
	}

	/**
	 * افزودن به صف. حالت «کامل‌تر» جایگزین حالت سبک‌تر می‌شود.
	 *
	 * @param int    $product_id محصول.
	 * @param string $mode       حالت.
	 */
	private static function enqueue( $product_id, $mode ) {
		$weight = array( 'stock' => 1, 'partial' => 2, 'full_no_images' => 3, 'full' => 4 );

		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return;
		}

		if ( isset( self::$queue[ $product_id ] ) ) {
			$current = self::$queue[ $product_id ];

			if ( ( $weight[ $current ] ?? 0 ) >= ( $weight[ $mode ] ?? 0 ) ) {
				return;
			}
		}

		self::$queue[ $product_id ] = $mode;
	}

	/**
	 * ارسال صف در پایان درخواست.
	 */
	public function flush() {
		if ( empty( self::$queue ) ) {
			return;
		}

		$queue        = self::$queue;
		self::$queue  = array();

		foreach ( $queue as $product_id => $mode ) {
			$result = DSS_Ajax::run( $product_id, $mode );

			if ( ! $result['success'] ) {
				DSS_Logger::warning( 'همگام‌سازی خودکار ناموفق بود.', array(
					'product_id' => $product_id,
					'mode'       => $mode,
					'message'    => $result['message'],
				) );
			}
		}
	}
}
