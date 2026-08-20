<?php
/**
 * پاک کردن کش بعد از واردات.
 *
 * محصول در دیتابیس به‌روز می‌شود، ولی صفحه‌ای که مشتری می‌بیند از کش
 * می‌آید — پس همگام‌سازی «انجام نشده» به نظر می‌رسد. اینجا کشِ همان یک
 * محصول در همه‌ی لایه‌ها پاک می‌شود، نه کل سایت: پاک کردن کل کش روی هر
 * همگام‌سازی، سایت را برای دقایقی کند می‌کند.
 *
 * هیچ افزونه‌ای الزامی نیست؛ هر کدام نصب باشد صدا زده می‌شود.
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

class DSS_Cache {

	/**
	 * پاک کردن کشِ یک محصول.
	 *
	 * @param int $product_id شناسه‌ی محصول.
	 *
	 * @return string[] نام لایه‌هایی که پاک شدند.
	 */
	public static function purge_product( $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return array();
		}

		$purged = array();

		/* ---- وردپرس و ووکامرس ---- */

		clean_post_cache( $product_id );
		$purged[] = 'WordPress';

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
			$purged[] = 'WooCommerce';
		}

		if ( class_exists( 'WC_Cache_Helper' ) && method_exists( 'WC_Cache_Helper', 'get_transient_version' ) ) {
			WC_Cache_Helper::get_transient_version( 'product', true );
		}

		/* ---- افزونه‌های کش صفحه ---- */

		if ( defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed\Core' ) || class_exists( 'LiteSpeed_Cache' ) ) {
			do_action( 'litespeed_purge_post', $product_id );
			$purged[] = 'LiteSpeed';
		}

		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $product_id );
			$purged[] = 'WP Rocket';
		}

		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $product_id );
			$purged[] = 'W3 Total Cache';
		}

		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $product_id );
			$purged[] = 'WP Super Cache';
		}

		if ( isset( $GLOBALS['wp_fastest_cache'] )
			&& method_exists( $GLOBALS['wp_fastest_cache'], 'singleDeleteCache' ) ) {
			$GLOBALS['wp_fastest_cache']->singleDeleteCache( false, $product_id );
			$purged[] = 'WP Fastest Cache';
		}

		if ( class_exists( 'Cache_Enabler' ) && method_exists( 'Cache_Enabler', 'clear_page_cache_by_post_id' ) ) {
			Cache_Enabler::clear_page_cache_by_post_id( $product_id );
			$purged[] = 'Cache Enabler';
		}

		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache( get_permalink( $product_id ) );
			$purged[] = 'SG Optimizer';
		}

		if ( class_exists( 'Breeze_PurgeCache' ) && method_exists( 'Breeze_PurgeCache', 'breeze_cache_flush' ) ) {
			Breeze_PurgeCache::breeze_cache_flush();
			$purged[] = 'Breeze';
		}

		if ( class_exists( 'Swift_Performance_Cache' ) && method_exists( 'Swift_Performance_Cache', 'clear_post_cache' ) ) {
			Swift_Performance_Cache::clear_post_cache( $product_id );
			$purged[] = 'Swift Performance';
		}

		if ( has_action( 'rt_nginx_helper_purge_url' ) ) {
			do_action( 'rt_nginx_helper_purge_url', get_permalink( $product_id ) );
			$purged[] = 'Nginx Helper';
		}

		/**
		 * برای هر لایه‌ی دیگر — CDN، کش قالب، یا هر چیز اختصاصی.
		 *
		 * @param int $product_id محصول.
		 */
		do_action( 'dss_purge_cache', $product_id );

		$purged = array_values( array_unique( apply_filters( 'dss_purged_cache_layers', $purged, $product_id ) ) );

		DSS_Logger::log( 'success', 'کش محصول پاک شد.', array( 'product_id' => $product_id, 'layers' => $purged ) );

		return $purged;
	}

	/**
	 * پاک کردن کش محصول و والدش — واریشن به تنهایی صفحه‌ای ندارد.
	 *
	 * @param int $product_id شناسه.
	 *
	 * @return string[]
	 */
	public static function purge_product_tree( $product_id ) {
		$product_id = absint( $product_id );
		$purged     = self::purge_product( $product_id );

		if ( function_exists( 'wp_get_post_parent_id' ) ) {
			$parent_id = absint( wp_get_post_parent_id( $product_id ) );

			if ( $parent_id && $parent_id !== $product_id ) {
				$purged = array_merge( $purged, self::purge_product( $parent_id ) );
			}
		}

		return array_values( array_unique( $purged ) );
	}
}
