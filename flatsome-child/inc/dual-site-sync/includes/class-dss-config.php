<?php
/**
 * پیکربندی: ثابت‌های wp-config.php + تنظیمات قابل ویرایش در پنل.
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

class DSS_Config {

	const OPTION = 'dss_settings';

	/**
	 * کش تشخیص سایت جاری.
	 *
	 * @var string|null
	 */
	private static $current_key = null;

	/**
	 * مقادیر پیش‌فرض تنظیمات پنل.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// همگام‌سازی.
			'sync_status'                => 'no',   // وضعیت انتشار محصول منتقل شود؟
			'sync_categories'            => 'yes',
			'sync_brands'                => 'yes',
			'sync_variation_images'      => 'yes',
			'create_missing_categories'  => 'yes',
			'sync_swatches'              => 'yes',
			'sync_term_meta'             => 'yes',  // متای ترم‌های سواچ (رنگ/تصویر/تولتیپ).
			'delete_missing_variations'  => 'yes',

			// موجودی.
			'sync_stock_fields'          => 'no',   // فیلدهای موجودی در همگام‌سازی دستی ارسال شوند؟
			'shared_inventory'           => 'no',   // انبار مشترک: پوش خودکار موجودی هنگام تغییر.

			// خودکارسازی.
			'auto_sync_mode'             => 'off',  // off|partial|full_no_images|full

			// SKU.
			'auto_variation_sku'         => 'no',   // ساخت خودکار SKU واریشن‌ها هنگام ذخیره.
			'protect_manual_variation_sku' => 'yes',

			// فرانت‌اند.
			'frontend_sku_search'        => 'yes',

			// عمومی.
			'request_timeout'            => 120,
			'debug_log'                  => 'no',
		);
	}

	/**
	 * خواندن یک تنظیم.
	 *
	 * @param string $key     کلید.
	 * @param mixed  $default مقدار پیش‌فرض جایگزین.
	 *
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$defaults = self::defaults();
		$saved    = get_option( self::OPTION, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$settings = array_merge( $defaults, $saved );

		if ( ! array_key_exists( $key, $settings ) ) {
			return $default;
		}

		$value = $settings[ $key ];

		return apply_filters( 'dss_setting', $value, $key );
	}

	/**
	 * تنظیم بولی.
	 *
	 * @param string $key کلید.
	 *
	 * @return bool
	 */
	public static function is_on( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * همه‌ی تنظیمات.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * سایت‌های تعریف‌شده در wp-config.php.
	 *
	 * @return array<string,array{url:string,label:string}>
	 */
	public static function sites() {
		$sites = array();

		if ( defined( 'DSS_SITE_A_URL' ) && DSS_SITE_A_URL ) {
			$sites['site_a'] = array(
				'url'   => untrailingslashit( DSS_SITE_A_URL ),
				'label' => defined( 'DSS_SITE_A_LABEL' ) ? DSS_SITE_A_LABEL : 'سایت اول',
			);
		}

		if ( defined( 'DSS_SITE_B_URL' ) && DSS_SITE_B_URL ) {
			$sites['site_b'] = array(
				'url'   => untrailingslashit( DSS_SITE_B_URL ),
				'label' => defined( 'DSS_SITE_B_LABEL' ) ? DSS_SITE_B_LABEL : 'سایت دوم',
			);
		}

		return apply_filters( 'dss_sites', $sites );
	}

	/**
	 * کلید رمز مشترک بین دو سایت.
	 *
	 * @return string
	 */
	public static function secret() {
		return defined( 'DSS_SHARED_SECRET' ) ? (string) DSS_SHARED_SECRET : '';
	}

	/**
	 * نرمال‌سازی دامنه برای مقایسه.
	 *
	 * @param string $url آدرس.
	 *
	 * @return string
	 */
	private static function host_of( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host ) {
			return '';
		}

		$host = strtolower( $host );

		return preg_replace( '/^www\./', '', $host );
	}

	/**
	 * کلید سایت جاری (site_a یا site_b). رشته‌ی خالی یعنی تشخیص داده نشد.
	 *
	 * @return string
	 */
	public static function current_key() {
		if ( null !== self::$current_key ) {
			return self::$current_key;
		}

		// امکان تعیین صریح در wp-config.php برای محیط‌های استیجینگ.
		if ( defined( 'DSS_SITE_KEY' ) && array_key_exists( DSS_SITE_KEY, self::sites() ) ) {
			self::$current_key = DSS_SITE_KEY;

			return self::$current_key;
		}

		$current_host = self::host_of( home_url() );

		foreach ( self::sites() as $key => $site ) {
			if ( $current_host && $current_host === self::host_of( $site['url'] ) ) {
				self::$current_key = $key;

				return self::$current_key;
			}
		}

		self::$current_key = '';

		return self::$current_key;
	}

	/**
	 * کلید سایت مقابل.
	 *
	 * @return string
	 */
	public static function target_key() {
		$current = self::current_key();

		if ( ! $current ) {
			return '';
		}

		foreach ( array_keys( self::sites() ) as $key ) {
			if ( $key !== $current ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * اطلاعات سایت مقابل.
	 *
	 * @return array|null
	 */
	public static function target() {
		$key   = self::target_key();
		$sites = self::sites();

		return $key && isset( $sites[ $key ] ) ? $sites[ $key ] : null;
	}

	/**
	 * برچسب یک سایت.
	 *
	 * @param string $key کلید سایت.
	 *
	 * @return string
	 */
	public static function label( $key ) {
		$sites = self::sites();

		return isset( $sites[ $key ] ) ? $sites[ $key ]['label'] : $key;
	}

	/**
	 * خطاهای پیکربندی.
	 *
	 * @return string[]
	 */
	public static function configuration_errors() {
		$errors = array();
		$sites  = self::sites();

		if ( count( $sites ) < 2 ) {
			$errors[] = 'ثابت‌های DSS_SITE_A_URL و DSS_SITE_B_URL باید هر دو در wp-config.php تعریف شوند.';
		}

		$secret = self::secret();

		if ( '' === $secret ) {
			$errors[] = 'ثابت DSS_SHARED_SECRET در wp-config.php تعریف نشده است.';
		} elseif ( strlen( $secret ) < 32 ) {
			$errors[] = 'مقدار DSS_SHARED_SECRET باید حداقل ۳۲ کاراکتر تصادفی باشد.';
		}

		if ( count( $sites ) >= 2 && ! self::current_key() ) {
			$errors[] = sprintf(
				'دامنه‌ی این سایت (%s) با هیچ‌کدام از آدرس‌های تعریف‌شده مطابقت ندارد. آدرس‌ها را اصلاح کنید یا ثابت DSS_SITE_KEY را دستی تعیین کنید.',
				self::host_of( home_url() )
			);
		}

		return $errors;
	}

	/**
	 * آیا همگام‌سازی قابل انجام است؟
	 *
	 * @return bool
	 */
	public static function is_ready() {
		return empty( self::configuration_errors() );
	}
}
