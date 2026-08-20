<?php
/**
 * Dual Site Sync - Bootstrap
 *
 * همگام‌سازی دستی محصولات ووکامرس بین دو فروشگاه.
 * این فایل تنها نقطه‌ی ورود است؛ از functions.php چایلد تم فراخوانی می‌شود:
 *
 *     require_once get_stylesheet_directory() . '/inc/dual-site-sync/bootstrap.php';
 *
 * تنظیمات اتصال در wp-config.php تعریف می‌شوند. نمونه را در
 * docs/wp-config-snippet.php ببینید.
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

define( 'DSS_VERSION', '2.0.0' );
define( 'DSS_PATH', trailingslashit( __DIR__ ) );
define( 'DSS_URL', trailingslashit( get_stylesheet_directory_uri() . '/inc/dual-site-sync' ) );
define( 'DSS_TEXTDOMAIN', 'dual-site-sync' );

/**
 * فایل‌های کلاس‌ها.
 */
foreach ( array(
	'class-dss-logger.php',
	'class-dss-config.php',
	'class-dss-context.php',
	'class-dss-cache.php',
	'class-dss-settings.php',
	'class-dss-media.php',
	'class-dss-swatches.php',
	'class-dss-variation-gallery.php',
	'class-dss-exporter.php',
	'class-dss-importer.php',
	'class-dss-client.php',
	'class-dss-rest.php',
	'class-dss-ajax.php',
	'class-dss-metabox.php',
	'class-dss-search.php',
	'class-dss-stock.php',
	'class-dss-variation-sku.php',
) as $dss_file ) {
	require_once DSS_PATH . 'includes/' . $dss_file;
}
unset( $dss_file );

/**
 * راه‌اندازی ماژول‌ها.
 */
function dss_init_modules() {
	static $done = false;

	if ( $done ) {
		return;
	}

	$done = true;

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'dss_notice_missing_woocommerce' );

		return;
	}

	DSS_Settings::instance();
	DSS_Metabox::instance();
	DSS_Ajax::instance();
	DSS_Rest::instance();
	DSS_Search::instance();
	DSS_Stock::instance();
	DSS_Variation_Sku::instance();

	do_action( 'dss_loaded' );
}

/*
 * انتخاب قلاب راه‌اندازی بر اساس اینکه این فایل از کجا لود شده است.
 *
 * وردپرس در wp-settings.php ابتدا افزونه‌ها را لود و plugins_loaded را شلیک
 * می‌کند، و *بعد* functions.php پوسته را include می‌کند. پس وقتی این فایل از
 * چایلد تم فراخوانی می‌شود، plugins_loaded از قبل اجرا شده و بستن به آن یعنی
 * ماژول هرگز راه نمی‌افتد. در آن حالت after_setup_theme درست‌ترین قلاب است که
 * بلافاصله پس از بارگذاری پوسته اجرا می‌شود و ووکامرس هم تا آن لحظه آماده است.
 *
 * اگر روزی همین فایل از یک افزونه (یا mu-plugin) لود شد، شاخه‌ی دوم اجرا
 * می‌شود و رفتار قبلی حفظ می‌ماند.
 */
if ( did_action( 'plugins_loaded' ) ) {
	add_action( 'after_setup_theme', 'dss_init_modules', 20 );
} else {
	add_action( 'plugins_loaded', 'dss_init_modules', 20 );
}

/**
 * هشدار نبود ووکامرس.
 */
function dss_notice_missing_woocommerce() {
	echo '<div class="notice notice-error"><p><strong>Dual Site Sync:</strong> ووکامرس فعال نیست؛ همگام‌سازی غیرفعال شد.</p></div>';
}

/**
 * هشدار پیکربندی ناقص در wp-config.php.
 */
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$errors = DSS_Config::configuration_errors();

		if ( empty( $errors ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>Dual Site Sync — پیکربندی ناقص است:</strong></p><ul style="list-style:disc;margin-right:20px;">';
		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}
		echo '</ul><p>نمونه‌ی تنظیمات <code>wp-config.php</code> را در فایل <code>docs/wp-config-snippet.php</code> ببینید.</p></div>';
	}
);
