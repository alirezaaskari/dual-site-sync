<?php
/**
 * swatch-audit.php — پیدا کردن محصولاتی که سواچ رنگشان خالی است
 *
 * همه‌ی محصولات را می‌گردد و محصولاتی را گزارش می‌کند که ویژگی‌شان روی نوع
 * «Color» است ولی کد رنگ دست‌کم یکی از ترم‌هایشان خالی یا نامعتبر است — یعنی
 * همان محصولاتی که در فروشگاه سواچ سفید/خالی نشان می‌دهند.
 *
 * افزونه‌ی مرجع: Variation Swatches for WooCommerce (رایگان و Pro)
 *
 * مقدار «مؤثر» رنگ اینطور حساب می‌شود، چون نسخه‌ی Pro اجازه‌ی override در سطح
 * محصول می‌دهد:
 *
 *   نوع ویژگی  =  تنظیم محصول  →  در نبودش، نوع سراسری ویژگی
 *   کد رنگ ترم =  تنظیم محصول  →  در نبودش، متای سراسری ترم
 *
 * کلیدها:
 *   termmeta  product_attribute_color                      کد رنگ سراسری ترم
 *   postmeta  _woo_variation_swatches_product_settings     تنظیمات سطح محصول
 *
 * ── نحوه‌ی استفاده ─────────────────────────────────────────────────────
 *
 * این فایل را در ریشه‌ی وردپرس (کنار wp-config.php) بگذارید و به‌عنوان مدیر
 * در مرورگر باز کنید:
 *
 *   swatch-audit.php                گزارش کامل
 *   swatch-audit.php?status=publish فقط محصولات منتشرشده
 *   swatch-audit.php?format=text    خروجی ساده برای کپی کردن
 *   swatch-audit.php?csv=1          دانلود CSV
 *
 * فقط می‌خواند؛ هیچ داده‌ای را تغییر نمی‌دهد.
 *
 * ⚠️ پس از استفاده، این فایل را از سرور پاک کنید.
 */

require_once __DIR__ . '/wp-load.php';

if ( ! current_user_can( 'manage_woocommerce' ) ) {
	wp_die( 'برای دیدن این صفحه باید دسترسی مدیریت ووکامرس داشته باشید.' );
}

if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
	wp_die( 'ووکامرس فعال نیست.' );
}

set_time_limit( 300 );

/* =====================================================================
 * توابع کمکی
 * ================================================================== */

/**
 * آیا مقدار یک کد رنگ معتبر است؟ (فقط #rgb و #rrggbb)
 *
 * @param mixed $value مقدار.
 *
 * @return bool
 */
function swa_is_valid_color( $value ) {
	$value = trim( (string) $value );

	return '' !== $value && (bool) sanitize_hex_color( $value );
}

/**
 * تنظیمات سواچ سطح محصول را با هر دو شکل کلید جست‌وجو می‌کند.
 *
 * @param array  $settings تنظیمات محصول.
 * @param string $taxonomy تاکسونومی ویژگی.
 *
 * @return array
 */
function swa_settings_for_attribute( array $settings, $taxonomy ) {
	foreach ( array( $taxonomy, sanitize_title( $taxonomy ) ) as $key ) {
		if ( isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ) {
			return $settings[ $key ];
		}
	}

	return array();
}

/* =====================================================================
 * ۱. ویژگی‌های نوع رنگ
 *
 * هم آن‌هایی که به‌صورت سراسری روی color هستند، هم آن‌هایی که در تنظیمات
 * دست‌کم یک محصول روی color تنظیم شده‌اند.
 * ================================================================== */

global $wpdb;

$global_types  = array();   // taxonomy => نوع سراسری
$color_global  = array();   // تاکسونومی‌هایی که سراسراً color هستند

foreach ( wc_get_attribute_taxonomies() as $attribute ) {
	$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );

	if ( ! taxonomy_exists( $taxonomy ) ) {
		continue;
	}

	$global_types[ $taxonomy ] = array(
		'type'  => $attribute->attribute_type,
		'label' => $attribute->attribute_label,
	);

	if ( 'color' === $attribute->attribute_type ) {
		$color_global[] = $taxonomy;
	}
}

// تنظیمات سواچ همه‌ی محصولات، یکجا.
$settings_rows = $wpdb->get_results(
	"SELECT post_id, meta_value
	 FROM {$wpdb->postmeta}
	 WHERE meta_key = '_woo_variation_swatches_product_settings'
	   AND meta_value != ''"
);

$product_settings = array();      // product_id => آرایه‌ی تنظیمات
$color_override   = array();      // تاکسونومی‌هایی که در سطح محصول color شده‌اند

foreach ( $settings_rows as $row ) {
	$value = maybe_unserialize( $row->meta_value );

	if ( ! is_array( $value ) ) {
		continue;
	}

	$product_settings[ (int) $row->post_id ] = $value;

	foreach ( $value as $key => $attribute_settings ) {
		if ( is_array( $attribute_settings ) && isset( $attribute_settings['type'] ) && 'color' === $attribute_settings['type'] ) {
			$color_override[] = $key;
		}
	}
}

// تاکسونومی‌هایی که باید بررسی شوند.
$check_taxonomies = array();

foreach ( array_keys( $global_types ) as $taxonomy ) {
	if ( in_array( $taxonomy, $color_global, true )
		|| in_array( $taxonomy, $color_override, true )
		|| in_array( sanitize_title( $taxonomy ), $color_override, true ) ) {
		$check_taxonomies[] = $taxonomy;
	}
}

$check_taxonomies = array_values( array_unique( $check_taxonomies ) );

/* =====================================================================
 * ۲. رنگ سراسری همه‌ی ترم‌های این ویژگی‌ها — یک کوئری
 * ================================================================== */

$term_info = array();   // term_id => ['name','slug','taxonomy','color']

if ( $check_taxonomies ) {
	$placeholders = implode( ',', array_fill( 0, count( $check_taxonomies ), '%s' ) );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT t.term_id, t.name, t.slug, tt.taxonomy, tm.meta_value AS color
			 FROM {$wpdb->terms} t
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			 LEFT JOIN {$wpdb->termmeta} tm
			        ON tm.term_id = t.term_id AND tm.meta_key = 'product_attribute_color'
			 WHERE tt.taxonomy IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
			$check_taxonomies
		)
	);

	foreach ( $rows as $row ) {
		$term_info[ (int) $row->term_id ] = array(
			'name'     => $row->name,
			'slug'     => $row->slug,
			'taxonomy' => $row->taxonomy,
			'color'    => (string) $row->color,
		);
	}
}

/* =====================================================================
 * ۳. رابطه‌ی محصول ↔ ترم — یک کوئری
 * ================================================================== */

$status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
$statuses      = ( 'publish' === $status_filter )
	? array( 'publish' )
	: array( 'publish', 'draft', 'pending', 'private' );

$assignments = array();   // product_id => [term_id, ...]

if ( $check_taxonomies ) {
	$tax_ph    = implode( ',', array_fill( 0, count( $check_taxonomies ), '%s' ) );
	$status_ph = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT tr.object_id, tt.term_id
			 FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			 INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
			 WHERE tt.taxonomy IN ({$tax_ph})
			   AND p.post_type = 'product'
			   AND p.post_status IN ({$status_ph})", // phpcs:ignore WordPress.DB.PreparedSQL
			array_merge( $check_taxonomies, $statuses )
		)
	);

	foreach ( $rows as $row ) {
		$assignments[ (int) $row->object_id ][] = (int) $row->term_id;
	}
}

/* =====================================================================
 * ۴. محاسبه‌ی مقدار مؤثر برای هر محصول
 * ================================================================== */

$broken_products = array();   // product_id => ['issues'=>[...]]
$term_impact     = array();   // term_id => تعداد محصول متأثر

foreach ( $assignments as $product_id => $term_ids ) {
	$settings = isset( $product_settings[ $product_id ] ) ? $product_settings[ $product_id ] : array();
	$issues   = array();

	foreach ( array_unique( $term_ids ) as $term_id ) {
		if ( ! isset( $term_info[ $term_id ] ) ) {
			continue;
		}

		$term     = $term_info[ $term_id ];
		$taxonomy = $term['taxonomy'];

		$attribute_settings = swa_settings_for_attribute( $settings, $taxonomy );

		// نوع مؤثر: تنظیم محصول، وگرنه نوع سراسری.
		$type = ! empty( $attribute_settings['type'] )
			? $attribute_settings['type']
			: ( isset( $global_types[ $taxonomy ] ) ? $global_types[ $taxonomy ]['type'] : 'select' );

		if ( 'color' !== $type ) {
			continue;
		}

		// رنگ مؤثر: تنظیم محصول برای این ترم، وگرنه متای سراسری ترم.
		$override = '';

		if ( isset( $attribute_settings['terms'][ $term_id ]['primary_color'] ) ) {
			$override = (string) $attribute_settings['terms'][ $term_id ]['primary_color'];
		}

		$effective = '' !== trim( $override ) ? $override : $term['color'];

		if ( swa_is_valid_color( $effective ) ) {
			continue;
		}

		$issues[] = array(
			'term_id'  => $term_id,
			'name'     => $term['name'],
			'slug'     => $term['slug'],
			'taxonomy' => $taxonomy,
			'label'    => isset( $global_types[ $taxonomy ] ) ? $global_types[ $taxonomy ]['label'] : $taxonomy,
			'value'    => (string) $effective,
			'reason'   => '' === trim( (string) $effective ) ? 'خالی' : 'نامعتبر',
			'source'   => '' !== trim( $override ) ? 'تنظیم محصول' : 'متای ترم',
		);

		if ( ! isset( $term_impact[ $term_id ] ) ) {
			$term_impact[ $term_id ] = 0;
		}

		$term_impact[ $term_id ]++;
	}

	if ( $issues ) {
		$broken_products[ $product_id ] = $issues;
	}
}

/* =====================================================================
 * ۵. اطلاعات محصولات متأثر — یک کوئری
 * ================================================================== */

$products = array();

if ( $broken_products ) {
	$ids = array_map( 'intval', array_keys( $broken_products ) );
	$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_status, pm.meta_value AS sku
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku'
			 WHERE p.ID IN ({$ph})
			 ORDER BY p.post_title ASC", // phpcs:ignore WordPress.DB.PreparedSQL
			$ids
		)
	);

	foreach ( $rows as $row ) {
		$products[] = array(
			'id'     => (int) $row->ID,
			'title'  => $row->post_title,
			'sku'    => (string) $row->sku,
			'status' => $row->post_status,
			'issues' => $broken_products[ (int) $row->ID ],
		);
	}
}

arsort( $term_impact );

$total_products = count( $products );
$total_terms    = count( $term_impact );

/* =====================================================================
 * خروجی CSV
 * ================================================================== */

if ( ! empty( $_GET['csv'] ) ) {
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=swatch-audit.csv' );

	$out = fopen( 'php://output', 'w' );
	fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ); // BOM برای اکسل

	fputcsv( $out, array( 'شناسه محصول', 'نام محصول', 'SKU', 'وضعیت', 'ویژگی', 'ترم', 'نامک ترم', 'مشکل', 'منبع', 'لینک ویرایش' ) );

	foreach ( $products as $product ) {
		foreach ( $product['issues'] as $issue ) {
			fputcsv(
				$out,
				array(
					$product['id'],
					$product['title'],
					$product['sku'],
					$product['status'],
					$issue['label'],
					$issue['name'],
					$issue['slug'],
					$issue['reason'],
					$issue['source'],
					admin_url( 'post.php?post=' . $product['id'] . '&action=edit' ),
				)
			);
		}
	}

	fclose( $out );
	exit;
}

/* =====================================================================
 * خروجی متنی
 * ================================================================== */

if ( isset( $_GET['format'] ) && 'text' === $_GET['format'] ) {
	header( 'Content-Type: text/plain; charset=utf-8' );

	printf( "%d محصول با سواچ رنگ ناقص، ناشی از %d ترم\n\n", $total_products, $total_terms );

	echo "ترم‌های مقصر (به ترتیب تعداد محصول متأثر)\n";
	echo str_repeat( '=', 70 ) . "\n";

	foreach ( $term_impact as $term_id => $count ) {
		$term = $term_info[ $term_id ];
		printf(
			"  #%-6d %-22s %-18s %4d محصول   %s\n",
			$term_id,
			$term['name'],
			$term['slug'],
			$count,
			get_edit_term_link( $term_id, $term['taxonomy'], 'product' )
		);
	}

	echo "\n\nمحصولات\n";
	echo str_repeat( '=', 70 ) . "\n";

	foreach ( $products as $product ) {
		printf(
			"\n#%d  %s%s  [%s]\n  %s\n",
			$product['id'],
			$product['title'],
			$product['sku'] ? '  (SKU: ' . $product['sku'] . ')' : '',
			$product['status'],
			admin_url( 'post.php?post=' . $product['id'] . '&action=edit' )
		);

		foreach ( $product['issues'] as $issue ) {
			printf( "    %s → %s : %s (%s)\n", $issue['label'], $issue['name'], $issue['reason'], $issue['source'] );
		}
	}

	exit;
}

/* =====================================================================
 * خروجی HTML
 * ================================================================== */

?><!doctype html>
<html lang="fa" dir="rtl">
<head>
	<meta charset="utf-8">
	<title>محصولات با سواچ رنگ ناقص</title>
	<style>
		body { font: 14px/1.8 Tahoma, sans-serif; background: #f0f0f1; color: #1d2327; margin: 0; padding: 24px; }
		.wrap { max-width: 1150px; margin: 0 auto; }
		h1 { font-size: 22px; margin: 0 0 4px; }
		h2 { font-size: 17px; margin: 28px 0 12px; }
		.sub { color: #646970; margin: 0 0 20px; }
		.summary { padding: 14px 18px; border-radius: 6px; margin-bottom: 8px; font-weight: 700; }
		.summary.bad { background: #fcf0f1; border-right: 4px solid #d63638; }
		.summary.ok  { background: #f0f8f1; border-right: 4px solid #00a32a; }
		.filters { margin: 16px 0 24px; }
		.filters a { display: inline-block; margin-left: 8px; padding: 5px 12px; background: #fff;
			border: 1px solid #c3c4c7; border-radius: 4px; text-decoration: none; color: #2271b1; font-size: 13px; }
		.filters a:hover { background: #f6f7f7; }
		.card { background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; overflow: hidden; margin-bottom: 24px; }
		table { width: 100%; border-collapse: collapse; }
		th, td { padding: 9px 14px; text-align: right; border-bottom: 1px solid #f0f0f1; font-size: 13px; vertical-align: top; }
		th { background: #fbfbfc; color: #646970; font-weight: 700; }
		tr:last-child td { border-bottom: none; }
		.pname { font-weight: 700; }
		code { background: #f0f0f1; padding: 1px 6px; border-radius: 3px; font-size: 12px; }
		.status { background: #fcf9e8; color: #8a6d0b; padding: 1px 7px; border-radius: 3px; font-size: 11px; }
		a.btn { display: inline-block; padding: 3px 10px; border: 1px solid #c3c4c7; border-radius: 3px;
			background: #f6f7f7; color: #2271b1; text-decoration: none; font-size: 12px; margin-left: 5px; }
		a.btn:hover { background: #fff; }
		ul.issues { list-style: none; margin: 0; padding: 0; }
		ul.issues li { margin-bottom: 3px; }
		.reason-empty   { color: #d63638; font-weight: 700; }
		.reason-invalid { color: #bd8600; font-weight: 700; }
		.src { color: #646970; font-size: 11px; }
		.impact { font-weight: 700; color: #d63638; }
		.note { color: #646970; font-size: 13px; margin-top: 24px; padding: 14px 18px;
			background: #fcf9e8; border-right: 4px solid #dba617; border-radius: 6px; }
		.tip { background: #f0f6fc; border-right: 4px solid #2271b1; padding: 14px 18px;
			border-radius: 6px; margin-bottom: 20px; font-size: 13px; }
	</style>
</head>
<body>
<div class="wrap">

	<h1>محصولات با سواچ رنگ ناقص</h1>
	<p class="sub">محصولاتی که ویژگی‌شان روی <strong>Color</strong> است ولی کد رنگ دست‌کم یکی از ترم‌هایشان خالی یا نامعتبر است.</p>

	<div class="summary <?php echo $total_products ? 'bad' : 'ok'; ?>">
		<?php if ( $total_products ) : ?>
			<?php printf( '%d محصول متأثر است، ناشی از %d ترم.', (int) $total_products, (int) $total_terms ); ?>
		<?php else : ?>
			هیچ محصولی با سواچ رنگ ناقص پیدا نشد.
		<?php endif; ?>
	</div>

	<div class="filters">
		<a href="?">همه‌ی وضعیت‌ها</a>
		<a href="?status=publish">فقط منتشرشده</a>
		<a href="?format=text<?php echo $status_filter ? '&status=' . esc_attr( $status_filter ) : ''; ?>">خروجی متنی</a>
		<a href="?csv=1<?php echo $status_filter ? '&status=' . esc_attr( $status_filter ) : ''; ?>">دانلود CSV</a>
	</div>

	<?php if ( $total_terms ) : ?>
		<div class="tip">
			<strong>از اینجا شروع کنید:</strong> معمولاً اصلاح یک ترم، همه‌ی محصولاتی که از آن استفاده می‌کنند را
			یکجا درست می‌کند. جدول زیر ترم‌ها را به ترتیب تعداد محصول متأثر مرتب کرده است.
		</div>

		<h2>ترم‌های مقصر</h2>
		<div class="card">
			<table>
				<thead>
					<tr>
						<th style="width:70px;">شناسه</th>
						<th>ترم</th>
						<th style="width:170px;">نامک</th>
						<th style="width:150px;">ویژگی</th>
						<th style="width:120px;">محصول متأثر</th>
						<th style="width:100px;"></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $term_impact as $term_id => $count ) : ?>
					<?php $term = $term_info[ $term_id ]; ?>
					<tr>
						<td><?php echo (int) $term_id; ?></td>
						<td class="pname"><?php echo esc_html( $term['name'] ); ?></td>
						<td><code><?php echo esc_html( $term['slug'] ); ?></code></td>
						<td><?php echo esc_html( isset( $global_types[ $term['taxonomy'] ] ) ? $global_types[ $term['taxonomy'] ]['label'] : $term['taxonomy'] ); ?></td>
						<td class="impact"><?php echo (int) $count; ?></td>
						<td>
							<?php $edit = get_edit_term_link( $term_id, $term['taxonomy'], 'product' ); ?>
							<?php if ( $edit ) : ?>
								<a class="btn" href="<?php echo esc_url( $edit ); ?>" target="_blank" rel="noopener">اصلاح ترم</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<?php if ( $products ) : ?>
		<h2>محصولات (<?php echo (int) $total_products; ?>)</h2>
		<div class="card">
			<table>
				<thead>
					<tr>
						<th style="width:70px;">شناسه</th>
						<th>محصول</th>
						<th style="width:110px;">SKU</th>
						<th>ترم‌های بدون رنگ</th>
						<th style="width:160px;"></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $products as $product ) : ?>
					<tr>
						<td><?php echo (int) $product['id']; ?></td>
						<td>
							<span class="pname"><?php echo esc_html( $product['title'] ); ?></span>
							<?php if ( 'publish' !== $product['status'] ) : ?>
								<span class="status"><?php echo esc_html( $product['status'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo $product['sku'] ? '<code>' . esc_html( $product['sku'] ) . '</code>' : '—'; ?></td>
						<td>
							<ul class="issues">
								<?php foreach ( $product['issues'] as $issue ) : ?>
									<li>
										<?php echo esc_html( $issue['label'] ); ?> →
										<strong><?php echo esc_html( $issue['name'] ); ?></strong>
										<span class="<?php echo 'خالی' === $issue['reason'] ? 'reason-empty' : 'reason-invalid'; ?>">
											<?php echo esc_html( $issue['reason'] ); ?>
										</span>
										<?php if ( 'نامعتبر' === $issue['reason'] ) : ?>
											<code><?php echo esc_html( $issue['value'] ); ?></code>
										<?php endif; ?>
										<span class="src">(<?php echo esc_html( $issue['source'] ); ?>)</span>
									</li>
								<?php endforeach; ?>
							</ul>
						</td>
						<td>
							<a class="btn" href="<?php echo esc_url( admin_url( 'post.php?post=' . $product['id'] . '&action=edit' ) ); ?>" target="_blank" rel="noopener">ویرایش</a>
							<a class="btn" href="<?php echo esc_url( get_permalink( $product['id'] ) ); ?>" target="_blank" rel="noopener">مشاهده</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<div class="note">
		<strong>منبع مشکل</strong> در هر ردیف مشخص شده: «متای ترم» یعنی کد رنگ سراسری آن ترم خالی است
		(اصلاحش همه‌ی محصولات را درست می‌کند)؛ «تنظیم محصول» یعنی در تب Swatches Settings همان محصول
		رنگ خالی گذاشته شده و باید داخل خود محصول اصلاح شود.
		<br><br>
		«نامعتبر» یعنی مقداری هست ولی کد رنگ درستی نیست — مثلاً <code>قرمز</code> یا <code>ff0000</code>
		بدون <code>#</code>. افزونه چنین مقداری را نادیده می‌گیرد و سواچ خالی نشان می‌دهد.
		<br><br>
		<strong>⚠️ پس از استفاده، این فایل را از سرور پاک کنید.</strong>
	</div>

</div>
</body>
</html>
