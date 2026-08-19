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
 * تشخیص در سطح *سایت* انجام می‌شود، نه در سطح واریشن: اگر افزونه روی سایت
 * مبدأ فعال باشد، کلید برای هر واریشن فرستاده می‌شود — حتی اگر آن واریشن
 * تصویر اضافی نداشته باشد. فهرست خالی در مقصد باعث پاک شدن می‌شود، و همین
 * چیزی است که دو سایت را واقعاً یکسان نگه می‌دارد.
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

class DSS_Variation_Gallery {

	const CACHE_KEY = 'dss_variation_gallery_active_keys';

	/**
	 * کلیدهای شناخته‌شده، شکل ذخیره‌سازی، و نشانه‌های فعال بودن افزونه.
	 *
	 * format  → csv (رشته‌ی جداشده با کاما) یا array
	 * classes → کلاس‌هایی که وجودشان یعنی افزونه فعال است
	 * plugins → پوشه‌ی افزونه در فهرست افزونه‌های فعال
	 *
	 * @return array<string,array>
	 */
	public static function definitions() {
		/*
		 * افزودن کلید سفارشی بدون دست زدن به این فایل — در functions.php:
		 *
		 *   add_filter( 'dss_variation_gallery_definitions', function ( $defs ) {
		 *       $defs['my_plugin_images'] = array(
		 *           'format'  => 'array',   // یا 'csv'
		 *           'classes' => array(),
		 *           'plugins' => array(),
		 *       );
		 *       return $defs;
		 *   } );
		 *
		 * کلیدی که در دیتابیس ردیف داشته باشد، حتی بدون classes/plugins هم
		 * خودکار فعال شمرده می‌شود.
		 */
		return apply_filters(
			'dss_variation_gallery_definitions',
			array(
				'_wc_additional_variation_images' => array(
					'format'  => 'csv',
					'classes' => array( 'WC_Additional_Variation_Images' ),
					'plugins' => array( 'woocommerce-additional-variation-images' ),
				),
				'additional_variation_images'     => array(
					'format'  => 'csv',
					'classes' => array(),
					'plugins' => array(),
				),
				'rtwpvg_images'                   => array(
					'format'  => 'array',
					'classes' => array( 'RTWPVG', 'RTWPVG\\Helper' ),
					'plugins' => array( 'woo-product-variation-gallery', 'woo-product-variation-gallery-pro' ),
				),
				'woo_variation_gallery_images'    => array(
					'format'  => 'array',
					'classes' => array( 'Woo_Variation_Gallery' ),
					'plugins' => array( 'woo-variation-gallery' ),
				),
			)
		);
	}

	/**
	 * تکمیل کلیدهای جاافتاده در تعریف‌هایی که از فیلتر می‌آیند.
	 *
	 * @param array $definitions تعریف‌ها.
	 *
	 * @return array
	 */
	private static function normalize( array $definitions ) {
		$out = array();

		foreach ( $definitions as $key => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$out[ $key ] = array(
				'format'  => isset( $definition['format'] ) && 'array' === $definition['format'] ? 'array' : 'csv',
				'classes' => isset( $definition['classes'] ) ? (array) $definition['classes'] : array(),
				'plugins' => isset( $definition['plugins'] ) ? (array) $definition['plugins'] : array(),
			);
		}

		return $out;
	}

	/**
	 * نگاشت ساده‌ی کلید → فرمت (برای سازگاری و استفاده‌ی داخلی).
	 *
	 * @return array<string,string>
	 */
	public static function meta_keys() {
		$out = array();

		foreach ( self::normalize( self::definitions() ) as $key => $definition ) {
			$out[ $key ] = $definition['format'];
		}

		return $out;
	}

	/**
	 * کلیدهایی که روی *این سایت* واقعاً در جریان‌اند.
	 *
	 * یک کلید فعال شمرده می‌شود اگر افزونه‌اش شناسایی شود (کلاس یا فهرست
	 * افزونه‌های فعال)، یا اگر دست‌کم یک ردیف متا با آن کلید در دیتابیس باشد.
	 *
	 * @param bool $fresh نادیده گرفتن کش.
	 *
	 * @return array<string,string> کلید => فرمت
	 */
	public static function active_keys( $fresh = false ) {
		static $runtime = null;

		if ( ! $fresh && null !== $runtime ) {
			return $runtime;
		}

		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				$runtime = $cached;

				return $runtime;
			}
		}

		$active      = array();
		$definitions = self::normalize( self::definitions() );
		$in_db       = self::keys_present_in_db( array_keys( $definitions ) );

		foreach ( $definitions as $key => $definition ) {
			if ( in_array( $key, $in_db, true ) || self::plugin_detected( $definition ) ) {
				$active[ $key ] = $definition['format'];
			}
		}

		set_transient( self::CACHE_KEY, $active, HOUR_IN_SECONDS );

		$runtime = $active;

		return $runtime;
	}

	/**
	 * پاک کردن کش تشخیص.
	 */
	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * آیا افزونه‌ی مربوط به این کلید شناسایی می‌شود؟
	 *
	 * @param array $definition تعریف کلید.
	 *
	 * @return bool
	 */
	private static function plugin_detected( array $definition ) {
		foreach ( $definition['classes'] as $class ) {
			if ( class_exists( $class ) ) {
				return true;
			}
		}

		if ( empty( $definition['plugins'] ) ) {
			return false;
		}

		$active_plugins = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active_plugins = array_merge( $active_plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		foreach ( $active_plugins as $plugin_file ) {
			$folder = strtok( (string) $plugin_file, '/' );

			if ( in_array( $folder, $definition['plugins'], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * کدام‌یک از کلیدها دست‌کم یک ردیف در جدول متا دارند.
	 *
	 * @param string[] $keys کلیدها.
	 *
	 * @return string[]
	 */
	private static function keys_present_in_db( array $keys ) {
		global $wpdb;

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

	/**
	 * خروجی گرفتن از یک واریشن.
	 *
	 * برای هر کلید فعال روی این سایت یک ورودی تولید می‌شود؛ آرایه‌ی خالی یعنی
	 * این واریشن تصویر اضافی ندارد و مقصد هم باید خالی شود.
	 *
	 * @param int $variation_id شناسه واریشن.
	 *
	 * @return array<string,string[]>
	 */
	public static function export( $variation_id ) {
		$out = array();

		foreach ( self::active_keys() as $meta_key => $format ) {
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
			 * فهرست خالی یعنی واریشن مبدأ تصویر اضافی ندارد؛ پس مقصد هم پاک
			 * می‌شود. همین رفتار است که تصاویر اضافیِ اشتباهاً پرشده را در
			 * همگام‌سازی بعدی تمیز می‌کند.
			 */
			if ( empty( $ids ) ) {
				if ( metadata_exists( 'post', $variation_id, $meta_key ) ) {
					delete_post_meta( $variation_id, $meta_key );
					$changed = true;
				}

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
	 * کلیدهای شناسایی‌شده، برای نمایش در صفحه‌ی تنظیمات.
	 *
	 * @return string[]
	 */
	public static function detected_keys() {
		return array_keys( self::active_keys( true ) );
	}
}
