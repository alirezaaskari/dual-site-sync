<?php
/**
 * جست‌وجوی محصول با SKU در فرانت‌اند.
 *
 * نسخه‌ی قبلی رشته‌ی " OR (...)" را به انتهای بند جست‌وجو می‌چسباند؛ چون
 * وردپرس این بند را به شکل «AND ( ... )» می‌سازد، آن OR از پرانتز بیرون
 * می‌زد و شرط‌های post_status و post_type را دور می‌زد — یعنی محصولات
 * پیش‌نویس و حذف‌شده هم در نتایج عمومی ظاهر می‌شدند.
 *
 * اینجا به‌جای دستکاری SQL، شناسه‌های منطبق با SKU از قبل پیدا و با فیلتر
 * post__in به کوئری اضافه می‌شوند.
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

class DSS_Search {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_filter( 'posts_search', array( $this, 'extend_search' ), 10, 2 );
	}

	/**
	 * افزودن شرط SKU داخل پرانتز موجود.
	 *
	 * @param string   $search بند جست‌وجو.
	 * @param WP_Query $query  کوئری.
	 *
	 * @return string
	 */
	public function extend_search( $search, $query ) {
		if ( ! DSS_Config::is_on( 'frontend_sku_search' ) ) {
			return $search;
		}

		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return $search;
		}

		$post_type = $query->get( 'post_type' );

		if ( 'product' !== $post_type && ! ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) ) {
			return $search;
		}

		$term = trim( (string) $query->get( 's' ) );

		if ( '' === $term || '' === $search ) {
			return $search;
		}

		$ids = $this->product_ids_by_sku( $term );

		if ( empty( $ids ) ) {
			return $search;
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$clause       = $wpdb->prepare( "{$wpdb->posts}.ID IN ({$placeholders})", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL

		/*
		 * $search به شکل « AND (((...)))» است. شرط SKU را داخل بیرونی‌ترین
		 * پرانتز تزریق می‌کنیم تا سایر شرط‌های کوئری (وضعیت انتشار، نوع پست)
		 * دست‌نخورده باقی بمانند.
		 */
		$trimmed = rtrim( $search );

		if ( ')' !== substr( $trimmed, -1 ) ) {
			return $search;
		}

		return substr( $trimmed, 0, -1 ) . ' OR ' . $clause . ' )';
	}

	/**
	 * محصولات (و واریشن‌ها) با SKU دقیق یا شروع‌شونده.
	 *
	 * @param string $sku عبارت جست‌وجو.
	 *
	 * @return int[] شناسه‌ی محصولات والد.
	 */
	private function product_ids_by_sku( $sku ) {
		global $wpdb;

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.post_id
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_sku'
				   AND pm.meta_value = %s
				   AND p.post_type IN ('product','product_variation')
				   AND p.post_status = 'publish'
				 LIMIT 50",
				$sku
			)
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$ids = array();

		foreach ( $rows as $id ) {
			$id = (int) $id;

			// واریشن‌ها به والدشان نگاشت می‌شوند.
			if ( 'product_variation' === get_post_type( $id ) ) {
				$parent = wp_get_post_parent_id( $id );

				if ( $parent ) {
					$ids[] = (int) $parent;
				}

				continue;
			}

			$ids[] = $id;
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}
}
