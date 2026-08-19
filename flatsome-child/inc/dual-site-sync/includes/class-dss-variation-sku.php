<?php
/**
 * تولید خودکار SKU برای واریشن‌ها (اختیاری، پیش‌فرض خاموش).
 *
 * اگر سایت شما مولد SKU مخصوص خودش را دارد، این قابلیت را روشن نکنید؛
 * DSS در هر حال SKU موجود را عیناً به سایت مقابل منتقل می‌کند و هرگز روی
 * سایت مقصد SKU جدید نمی‌سازد.
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

class DSS_Variation_Sku {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_process_product_meta_variable', array( $this, 'generate' ), 20, 1 );
	}

	/**
	 * تولید SKU واریشن‌های بدون SKU.
	 *
	 * @param int $product_id محصول والد.
	 */
	public function generate( $product_id ) {
		if ( ! DSS_Config::is_on( 'auto_variation_sku' ) ) {
			return;
		}

		// در طول دریافت داده از سایت مقابل، SKUها دست‌نخورده می‌مانند.
		if ( DSS_Context::is_syncing() ) {
			return;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return;
		}

		$parent_sku = $product->get_sku();

		if ( '' === $parent_sku ) {
			DSS_Logger::warning( 'تولید SKU واریشن ممکن نیست: محصول والد SKU ندارد.', array( 'product_id' => $product_id ) );

			return;
		}

		$protect_manual = DSS_Config::is_on( 'protect_manual_variation_sku' );
		$counter        = 1;

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$current = (string) $variation->get_sku();

			if ( '' !== $current ) {
				// SKU دستی حفظ می‌شود (مگر اینکه در تنظیمات غیرفعال شده باشد).
				if ( $protect_manual || 0 === strpos( $current, $parent_sku . '-' ) ) {
					$suffix = substr( $current, strlen( $parent_sku ) + 1 );

					if ( ctype_digit( $suffix ) && (int) $suffix >= $counter ) {
						$counter = (int) $suffix + 1;
					}

					continue;
				}
			}

			$new_sku = $this->next_free_sku( $parent_sku, $counter );

			if ( '' === $new_sku ) {
				continue;
			}

			try {
				$variation->set_sku( $new_sku );
				$variation->save();
			} catch ( Exception $e ) {
				DSS_Logger::warning( 'ثبت SKU واریشن ناموفق بود.', array(
					'variation_id' => $variation_id,
					'sku'          => $new_sku,
					'error'        => $e->getMessage(),
				) );
			}

			$counter++;
		}

		WC_Product_Variable::sync( $product_id );
		wc_delete_product_transients( $product_id );
	}

	/**
	 * اولین شماره‌ی آزاد.
	 *
	 * @param string $parent_sku SKU والد.
	 * @param int    $counter    شمارنده (ارجاعی).
	 *
	 * @return string
	 */
	private function next_free_sku( $parent_sku, &$counter ) {
		$guard = 0;

		while ( $guard < 500 ) {
			$candidate = $parent_sku . '-' . $counter;

			if ( ! wc_get_product_id_by_sku( $candidate ) ) {
				return $candidate;
			}

			$counter++;
			$guard++;
		}

		return '';
	}
}
