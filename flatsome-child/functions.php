<?php
/**
 * توابع چایلد تم فلت‌سام.
 *
 * اگر از قبل functions.php دارید، این فایل را جایگزین نکنید؛ فقط بخش
 * «Dual Site Sync» را به انتهای فایل خودتان اضافه کنید.
 *
 * @package FlatsomeChild
 */

defined( 'ABSPATH' ) || exit;

/**
 * بارگذاری استایل والد و چایلد.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		wp_enqueue_style( 'flatsome-child-style', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );
	},
	20
);

/* -------------------------------------------------------------------------
 * Dual Site Sync — همگام‌سازی محصولات بین دو فروشگاه
 *
 * تنظیمات اتصال در wp-config.php تعریف می‌شوند (به docs/wp-config-snippet.php
 * نگاه کنید). صفحه‌ی تنظیمات: ووکامرس ← همگام‌سازی دو سایته
 * ---------------------------------------------------------------------- */

$dss_bootstrap = get_stylesheet_directory() . '/inc/dual-site-sync/bootstrap.php';

if ( file_exists( $dss_bootstrap ) ) {
	require_once $dss_bootstrap;
}

unset( $dss_bootstrap );
