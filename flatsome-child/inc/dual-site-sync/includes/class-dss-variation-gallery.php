<?php
/**
 * پل ارتباطی با افزونه‌های «تصاویر اضافی واریشن» (Additional Variation Images).
 *
 * ووکامرس به‌صورت استاندارد برای هر واریشن فقط *یک* تصویر دارد. فیلد
 * «Additional images» زیر هر واریشن از یک افزونه‌ی جانبی می‌آید که فهرست
 * شناسه‌ی پیوست‌ها را در متای خود واریشن ذخیره می‌کند.
 *
 * چون شناسه‌ی پیوست در دو سایت یکسان نیست، مثل بقیه‌ی تصاویر باید به URL
 * ترجمه و در مقصد دوباره به شناسه‌ی محلی برگردانده شود.
 *
 * تشخیص افزونه خودکار است: هر کلید متایی که روی واریشن *وجود داشته باشد*
 * (حتی خالی) منتقل می‌شود. کلیدی که وجود ندارد اصلاً در بسته نمی‌آید و در
 * مقصد دست‌نخورده می‌ماند.
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

class DSS_Variation_Gallery {

	/**
	 * کلیدهای شناخته‌شده و شکل ذخیره‌سازی‌شان.
	 *
	 * csv   → رشته‌ی شناسه‌ها با کاما
	 * array → آرایه‌ی شناسه‌ها
	 *
	 * @return array<string,string>
	 */
	public static function meta_keys() {
		return apply_filters(
			'dss_variation_gallery_meta_keys',
			array(
				'_wc_additional_variation_images' => 'csv',   // WooCommerce Additional Variation Images
				'additional_variation_images'     => 'csv',   // نسخه‌های قدیمی همان افزونه
				'rtwpvg_images'                   => 'array', // RadiusTheme Variation Images Gallery
				'woo_variation_gallery_images'    => 'array', // Variation Images Gallery (GetWooPlugins)
			)
		);
	}

	/**
	 * خروجی گرفتن از یک واریشن.
	 *
	 * @param int $variation_id شناسه واریشن.
	 *
	 * @return array<string,string[]> کلید متا => فهرست آدرس‌ها. آرایه‌ی خالی
	 *                                یعنی افزونه فعال است ولی تصویری ثبت نشده.
	 */
	public static function export( $variation_id ) {
		$out = array();

		foreach ( self::meta_keys() as $meta_key => $format ) {
			// اگر ردیف متا اصلاً وجود ندارد، آن افزونه روی این سایت در کار نیست.
			if ( ! metadata_exists( 'post', $variation_id, $meta_key ) ) {
				continue;
			}

			$ids  = self::parse( get_post_meta( $variation_id, $meta_key, true ), $format );
			$urls = array();

			foreach ( $ids as $attachment_id ) {
				$url = DSS_Media::url( $attachment_id );

				if ( $url ) {
					$urls[] = $url;
				}
			}

			$out[ $meta_key ] = $urls;
		}

		return $out;
	}

	/**
	 * اعمال روی واریشن مقصد.
	 *
	 * @param int   $variation_id شناسه واریشن محلی.
	 * @param array $data         داده‌ی دریافتی (کلید متا => آدرس‌ها).
	 * @param int   $parent_id    محصول والد، برای نسبت دادن پیوست‌ها.
	 *
	 * @return bool آیا چیزی تغییر کرد.
	 */
	public static function import( $variation_id, $data, $parent_id = 0 ) {
		if ( ! is_array( $data ) || empty( $data ) ) {
			return false;
		}

		$known   = self::meta_keys();
		$changed = false;

		foreach ( $data as $meta_key => $urls ) {
			if ( ! isset( $known[ $meta_key ] ) || ! is_array( $urls ) ) {
				continue;
			}

			$ids = DSS_Media::resolve_many( $urls, $parent_id );

			/*
			 * فهرست خالی یعنی واریشن مبدأ هم تصویر اضافی نداشته — پس در مقصد
			 * هم پاک می‌شود. همین رفتار است که تصاویر اضافیِ اشتباهاً پرشده را
			 * در همگام‌سازی بعدی تمیز می‌کند.
			 */
			if ( empty( $ids ) ) {
				delete_post_meta( $variation_id, $meta_key );
				$changed = true;
				continue;
			}

			update_post_meta( $variation_id, $meta_key, self::format( $ids, $known[ $meta_key ] ) );
			$changed = true;
		}

		return $changed;
	}

	/**
	 * تبدیل مقدار ذخیره‌شده به آرایه‌ی شناسه.
	 *
	 * @param mixed  $value  مقدار متا.
	 * @param string $format csv یا array.
	 *
	 * @return int[]
	 */
	private static function parse( $value, $format ) {
		if ( 'csv' === $format ) {
			$value = is_string( $value ) ? explode( ',', $value ) : (array) $value;
		}

		$ids = array_map( 'absint', (array) $value );

		return array_values( array_filter( $ids ) );
	}

	/**
	 * تبدیل آرایه‌ی شناسه به شکل ذخیره‌سازی افزونه.
	 *
	 * @param int[]  $ids    شناسه‌ها.
	 * @param string $format csv یا array.
	 *
	 * @return string|int[]
	 */
	private static function format( array $ids, $format ) {
		return 'csv' === $format ? implode( ',', $ids ) : $ids;
	}

	/**
	 * کلیدهایی که روی این سایت واقعاً استفاده می‌شوند (برای نمایش در تنظیمات).
	 *
	 * @return string[]
	 */
	public static function detected_keys() {
		global $wpdb;

		$keys = array_keys( self::meta_keys() );

		if ( empty( $keys ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
				$keys
			)
		);

		return is_array( $found ) ? $found : array();
	}
}
