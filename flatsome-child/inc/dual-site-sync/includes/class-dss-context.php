<?php
/**
 * زمینه‌ی اجرای همگام‌سازی.
 *
 * یکتایی SKU فقط در طول یک عملیات همگام‌سازی موقتاً برداشته می‌شود، نه به‌صورت
 * سراسری. سایر بخش‌های کد می‌توانند با is_syncing() بفهمند که در حال دریافت
 * داده از سایت مقابل هستیم (مثلاً برای غیرفعال کردن تولید خودکار SKU).
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

class DSS_Context {

	/**
	 * عمق تودرتویی.
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * ورود به زمینه‌ی همگام‌سازی.
	 */
	public static function enter() {
		if ( 0 === self::$depth ) {
			add_filter( 'wc_product_has_unique_sku', '__return_false', 9999 );
			do_action( 'dss_sync_started' );
		}

		self::$depth++;
	}

	/**
	 * خروج از زمینه‌ی همگام‌سازی.
	 */
	public static function leave() {
		self::$depth--;

		if ( self::$depth <= 0 ) {
			self::$depth = 0;
			remove_filter( 'wc_product_has_unique_sku', '__return_false', 9999 );
			do_action( 'dss_sync_finished' );
		}
	}

	/**
	 * آیا هم‌اکنون در حال همگام‌سازی هستیم؟
	 *
	 * @return bool
	 */
	public static function is_syncing() {
		return self::$depth > 0;
	}

	/**
	 * اجرای یک تابع داخل زمینه‌ی همگام‌سازی، با تضمین خروج.
	 *
	 * @param callable $callback تابع.
	 *
	 * @return mixed
	 * @throws Throwable در صورت خطا در callback.
	 */
	public static function run( callable $callback ) {
		self::enter();

		try {
			return $callback();
		} finally {
			self::leave();
		}
	}
}
