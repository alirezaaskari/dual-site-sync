<?php
/**
 * dss-check.php — ابزار تشخیص Dual Site Sync
 *
 * این فایل را در ریشه‌ی وردپرس (کنار wp-config.php) آپلود کنید و در مرورگر
 * باز کنید. باید به‌عنوان مدیر وارد شده باشید.
 *
 *   dss-check.php                 → بررسی مسیر نصب + شناسایی افزونه‌ها
 *   dss-check.php?product=1026    → بازرسی یک محصول (شناسه‌ی پست یا SKU)
 *   dss-check.php?all=1           → نمایش فهرست کامل افزونه‌های فعال
 *
 * فقط می‌خواند؛ هیچ‌چیزی را تغییر نمی‌دهد.
 *
 * ⚠️ پس از رفع مشکل، این فایل را از سرور پاک کنید.
 */

require_once __DIR__ . '/wp-load.php';

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'برای دیدن این صفحه باید به‌عنوان مدیر وارد شده باشید.' );
}

header( 'Content-Type: text/html; charset=utf-8' );

echo '<pre style="direction:ltr;text-align:left;font:13px/1.7 monospace;padding:20px;white-space:pre-wrap;">';

$line = str_repeat( '-', 78 ) . "\n";

/* =====================================================================
 * ۱. مسیر نصب
 * ================================================================== */

$child    = get_stylesheet_directory();
$expected = $child . '/inc/dual-site-sync/bootstrap.php';

echo "[1] مسیر نصب\n{$line}";
echo "پوسته‌ی فعال : {$child}\n";
echo "مسیر مورد انتظار: {$expected}\n";

if ( file_exists( $expected ) ) {
	echo '✅ پیدا شد — وضعیت ماژول: '
		. ( defined( 'DSS_VERSION' ) ? 'بارگذاری شده، نسخه ' . DSS_VERSION : '❌ بارگذاری نشده (خط require را چک کنید)' )
		. "\n";
} else {
	echo "❌ bootstrap.php در این مسیر نیست.\n";

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

	echo empty( $found )
		? "هیچ نسخه‌ای از bootstrap.php در wp-content پیدا نشد.\n"
		: "پیدا شد در:\n  " . implode( "\n  ", $found ) . "\n";
}

/* =====================================================================
 * ۲. همه‌ی کلیدهای متایی که روی واریشن‌ها استفاده می‌شوند
 *
 * این بخش مهم‌ترین قسمت است: بدون هیچ حدسی، مستقیم از دیتابیس می‌پرسد که
 * افزونه‌ها چه چیزی روی واریشن‌ها ذخیره می‌کنند.
 * ================================================================== */

global $wpdb;

echo "\n[2] کلیدهای متای واریشن‌ها در کل سایت\n{$line}";

$rows = $wpdb->get_results(
	"SELECT pm.meta_key, COUNT(*) AS total
	 FROM {$wpdb->postmeta} pm
	 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
	 WHERE p.post_type = 'product_variation'
	 GROUP BY pm.meta_key
	 ORDER BY pm.meta_key"
);

$core = array(
	'_variation_description', '_regular_price', '_sale_price', '_price', '_sku',
	'_stock', '_stock_status', '_manage_stock', '_backorders', '_low_stock_amount',
	'_weight', '_length', '_width', '_height', '_thumbnail_id', '_virtual',
	'_downloadable', '_download_limit', '_download_expiry', '_downloadable_files',
	'_tax_class', '_tax_status', '_sale_price_dates_from', '_sale_price_dates_to',
	'_product_version', '_wp_old_slug', '_edit_lock', '_edit_last',
);

if ( empty( $rows ) ) {
	echo "هیچ واریشنی در این سایت وجود ندارد.\n";
} else {
	echo sprintf( "%-45s %8s   %s\n", 'کلید متا', 'تعداد', 'وضعیت' );
	echo "{$line}";

	foreach ( $rows as $row ) {
		$key       = $row->meta_key;
		$is_core   = in_array( $key, $core, true ) || 0 === strpos( $key, 'attribute_' );
		$is_image  = preg_match( '/(image|gallery|img|photo|thumb)/i', $key );

		if ( $is_core ) {
			$note = '';
		} elseif ( $is_image ) {
			$note = '  ⬅⬅ احتمالاً همین است (تصویری)';
		} else {
			$note = '  (افزونه)';
		}

		echo sprintf( "%-45s %8d%s\n", $key, (int) $row->total, $note );
	}
}

/* =====================================================================
 * ۳. آنچه DSS شناسایی کرده
 * ================================================================== */

echo "\n[3] تشخیص DSS\n{$line}";

if ( class_exists( 'DSS_Variation_Gallery' ) ) {
	$active = DSS_Variation_Gallery::active_keys( true );

	echo 'کلیدهای شناخته‌شده : ' . implode( ', ', array_keys( DSS_Variation_Gallery::meta_keys() ) ) . "\n";
	echo 'کلیدهای فعال      : ' . ( empty( $active ) ? '(هیچ‌کدام)' : implode( ', ', array_keys( $active ) ) ) . "\n";

	if ( empty( $active ) ) {
		echo "\n⚠️ اگر در بخش [2] کلیدی با علامت ⬅⬅ می‌بینید، آن کلید در فهرست\n";
		echo "   شناخته‌شده‌ی DSS نیست. نام آن را بفرستید تا اضافه شود.\n";
	}
} else {
	echo "کلاس DSS_Variation_Gallery در دسترس نیست (ماژول لود نشده؟).\n";
}

if ( class_exists( 'DSS_Exporter' ) ) {
	$brands = DSS_Exporter::brand_taxonomies();
	echo 'تاکسونومی برند   : ' . ( empty( $brands ) ? '(هیچ‌کدام)' : implode( ', ', $brands ) ) . "\n";
}

/* =====================================================================
 * ۴. افزونه‌های فعال مرتبط
 * ================================================================== */

echo "\n[4] افزونه‌های فعال\n{$line}";

if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$all_plugins = get_plugins();
$show_all    = ! empty( $_GET['all'] );
$printed     = 0;

foreach ( (array) get_option( 'active_plugins', array() ) as $plugin_file ) {
	$name = isset( $all_plugins[ $plugin_file ]['Name'] ) ? $all_plugins[ $plugin_file ]['Name'] : $plugin_file;

	$relevant = preg_match( '/(variation|gallery|image|swatch|brand)/i', $name . ' ' . $plugin_file );

	if ( ! $show_all && ! $relevant ) {
		continue;
	}

	echo sprintf( "  %-55s %s\n", $name, $plugin_file );
	$printed++;
}

if ( ! $printed ) {
	echo "  (افزونه‌ی مرتبطی پیدا نشد — با ?all=1 فهرست کامل را ببینید)\n";
} elseif ( ! $show_all ) {
	echo "\n  فقط افزونه‌های مرتبط نمایش داده شدند. فهرست کامل: ?all=1\n";
}

/* =====================================================================
 * ۵. بازرسی یک محصول
 * ================================================================== */

$requested = isset( $_GET['product'] ) ? sanitize_text_field( wp_unslash( $_GET['product'] ) ) : '';

echo "\n[5] بازرسی محصول\n{$line}";

if ( '' === $requested ) {
	echo "برای بازرسی یک محصول، شناسه‌ی پست یا SKU را به آدرس اضافه کنید:\n";
	echo "  dss-check.php?product=1026\n";
} else {
	$product_id = absint( $requested );

	// اگر با آن شناسه محصولی نبود، به‌عنوان SKU امتحان کن.
	if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
		$by_sku = wc_get_product_id_by_sku( $requested );

		if ( $by_sku ) {
			echo "«{$requested}» به‌عنوان SKU تفسیر شد → شناسه‌ی پست {$by_sku}\n\n";
			$product_id = $by_sku;
		}
	}

	$product = $product_id ? wc_get_product( $product_id ) : false;

	if ( ! $product ) {
		echo "❌ محصولی با شناسه یا SKU «{$requested}» پیدا نشد.\n";
	} else {
		echo 'محصول: ' . $product->get_name() . "  (ID: {$product_id}, SKU: " . ( $product->get_sku() ?: '—' ) . ", نوع: " . $product->get_type() . ")\n";
		echo 'تصویر شاخص: ' . ( $product->get_image_id() ?: '—' ) . '   گالری: ' . ( implode( ',', $product->get_gallery_image_ids() ) ?: '—' ) . "\n";

		foreach ( array( '_dss_remote_id', '_dss_remote_site', '_dss_last_sync' ) as $meta ) {
			echo "  {$meta} = " . ( get_post_meta( $product_id, $meta, true ) ?: '—' ) . "\n";
		}

		$children = $product->get_children();

		if ( empty( $children ) ) {
			echo "\nاین محصول واریشن ندارد.\n";
		} else {
			echo "\nواریشن‌ها (" . count( $children ) . " مورد):\n\n";

			foreach ( $children as $variation_id ) {
				$variation = wc_get_product( $variation_id );

				echo '  #' . $variation_id . '  SKU: ' . ( $variation ? ( $variation->get_sku() ?: '—' ) : '?' ) . "\n";
				echo '    تصویر اصلی (_thumbnail_id) : ' . ( get_post_meta( $variation_id, '_thumbnail_id', true ) ?: '— ندارد' ) . "\n";

				// همه‌ی متاهای غیر استاندارد این واریشن.
				$extras = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key",
						$variation_id
					)
				);

				$shown = 0;

				foreach ( $extras as $extra ) {
					if ( in_array( $extra->meta_key, $core, true ) || 0 === strpos( $extra->meta_key, 'attribute_' ) ) {
						continue;
					}

					$value = maybe_unserialize( $extra->meta_value );
					$value = is_array( $value ) ? implode( ',', array_map( 'strval', $value ) ) : (string) $value;
					$value = '' === $value ? '(خالی)' : $value;

					if ( strlen( $value ) > 120 ) {
						$value = substr( $value, 0, 120 ) . '…';
					}

					echo "    {$extra->meta_key} = {$value}\n";
					$shown++;
				}

				if ( ! $shown ) {
					echo "    (هیچ متای افزونه‌ای روی این واریشن نیست)\n";
				}

				echo "\n";
			}
		}
	}
}

echo "\n{$line}⚠️ پس از رفع مشکل، این فایل را از سرور پاک کنید.\n";
echo '</pre>';
