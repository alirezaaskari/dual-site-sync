<?php
/**
 * dss-check.php — ابزار تشخیص مسیر نصب
 *
 * اگر بعد از افزودن خط require خطای «No such file or directory» گرفتید، این
 * فایل را در ریشه‌ی وردپرس (کنار wp-config.php) آپلود کنید و در مرورگر باز کنید:
 *
 *     https://دامنه-شما/dss-check.php
 *
 * خروجی می‌گوید فایل‌ها کجا هستند و مسیر درست چیست.
 *
 * ⚠️ بعد از رفع مشکل، این فایل را از سرور پاک کنید.
 */

require_once __DIR__ . '/wp-load.php';

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'برای دیدن این صفحه باید به‌عنوان مدیر وارد شده باشید.' );
}

header( 'Content-Type: text/html; charset=utf-8' );

$child     = get_stylesheet_directory();
$parent    = get_template_directory();
$expected  = $child . '/inc/dual-site-sync/bootstrap.php';

echo '<pre style="direction:ltr;text-align:left;font:14px/1.7 monospace;padding:20px;">';

echo "پوسته‌ی فعال (child) : {$child}\n";
echo "پوسته‌ی والد (parent): {$parent}\n";
echo "مسیر مورد انتظار     : {$expected}\n";
echo str_repeat( '-', 78 ) . "\n";

if ( file_exists( $expected ) ) {
	echo "✅ bootstrap.php پیدا شد — مسیر درست است.\n";
	echo '   وضعیت ماژول: ' . ( defined( 'DSS_VERSION' ) ? 'بارگذاری شده، نسخه ' . DSS_VERSION : 'بارگذاری نشده (خط require را چک کنید)' ) . "\n";
} else {
	echo "❌ bootstrap.php در مسیر مورد انتظار نیست.\n\n";
	echo "جست‌وجو در کل wp-content برای پیدا کردن محل واقعی فایل...\n\n";

	$found    = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $iterator as $path => $info ) {
		if ( $info->isFile() && 'bootstrap.php' === $info->getFilename() && false !== strpos( $path, 'dual-site-sync' ) ) {
			$found[] = $path;
		}
	}

	if ( empty( $found ) ) {
		echo "هیچ نسخه‌ای از bootstrap.php در wp-content پیدا نشد.\n";
		echo "یعنی فایل‌ها اصلاً آپلود نشده‌اند یا زیپ extract نشده است.\n";
	} else {
		echo "پیدا شد در:\n";
		foreach ( $found as $path ) {
			echo "  {$path}\n";
		}
		echo "\nاین فایل‌ها را طوری جابه‌جا کنید که مسیر نهایی دقیقاً این شود:\n";
		echo "  {$expected}\n";
	}
}

echo str_repeat( '-', 78 ) . "\n";
echo "محتویات پوشه‌ی پوسته‌ی فعال:\n";

foreach ( array_diff( scandir( $child ), array( '.', '..' ) ) as $item ) {
	$type = is_dir( $child . '/' . $item ) ? '[DIR ]' : '[FILE]';
	echo "  {$type} {$item}\n";
}

$inc = $child . '/inc';

if ( is_dir( $inc ) ) {
	echo "\nمحتویات inc/ :\n";
	foreach ( array_diff( scandir( $inc ), array( '.', '..' ) ) as $item ) {
		$type = is_dir( $inc . '/' . $item ) ? '[DIR ]' : '[FILE]';
		echo "  {$type} {$item}\n";
	}
} else {
	echo "\n⚠️ پوشه‌ی inc/ در پوسته‌ی فعال وجود ندارد.\n";
}

echo "\n⚠️ پس از رفع مشکل، این فایل را از سرور پاک کنید.\n";
echo '</pre>';
