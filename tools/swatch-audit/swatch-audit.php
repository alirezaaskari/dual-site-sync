<?php
/**
 * swatch-audit.php — بازرسی سواچ‌های ناقص
 *
 * ویژگی‌هایی که نوعشان روی «Color» تنظیم شده ولی کد رنگ ترم‌هایشان خالی
 * (یا نامعتبر) است را پیدا می‌کند. همین کار را برای ویژگی‌های نوع «Image»
 * هم انجام می‌دهد.
 *
 * افزونه‌ی مرجع: Variation Swatches for WooCommerce (رایگان و Pro)
 * کلیدهای متای ترم:
 *   product_attribute_color   کد رنگ اصلی
 *   is_dual_color             yes/no  (فقط Pro)
 *   secondary_color           رنگ دوم، وقتی is_dual_color = yes  (فقط Pro)
 *   product_attribute_image   شناسه‌ی پیوست تصویر
 *
 * ── نحوه‌ی استفاده ─────────────────────────────────────────────────────
 *
 * این فایل را در ریشه‌ی وردپرس (کنار wp-config.php) بگذارید و به‌عنوان مدیر
 * در مرورگر باز کنید:
 *
 *   swatch-audit.php               گزارش کامل
 *   swatch-audit.php?only=color    فقط ویژگی‌های رنگ
 *   swatch-audit.php?only=image    فقط ویژگی‌های تصویر
 *   swatch-audit.php?all=1         همه‌ی ترم‌ها، نه فقط ناقص‌ها
 *   swatch-audit.php?format=text   خروجی ساده برای کپی کردن
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

/* =====================================================================
 * جمع‌آوری داده
 * ================================================================== */

/**
 * آیا مقدار یک کد رنگ معتبر است؟
 *
 * @param mixed $value مقدار متا.
 *
 * @return bool
 */
function swa_is_valid_color( $value ) {
	$value = trim( (string) $value );

	if ( '' === $value ) {
		return false;
	}

	// sanitize_hex_color فقط #rgb و #rrggbb را می‌پذیرد.
	return (bool) sanitize_hex_color( $value );
}

/**
 * بازرسی همه‌ی ویژگی‌ها.
 *
 * @param string $only 'color'، 'image' یا رشته‌ی خالی برای هر دو.
 *
 * @return array
 */
function swa_audit( $only = '' ) {
	$report = array();

	foreach ( wc_get_attribute_taxonomies() as $attribute ) {
		$type = $attribute->attribute_type;

		if ( ! in_array( $type, array( 'color', 'image' ), true ) ) {
			continue;
		}

		if ( $only && $only !== $type ) {
			continue;
		}

		$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			continue;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			continue;
		}

		$rows = array();

		foreach ( $terms as $term ) {
			$row = array(
				'term_id'   => $term->term_id,
				'name'      => $term->name,
				'slug'      => $term->slug,
				'count'     => (int) $term->count,
				'edit_link' => get_edit_term_link( $term->term_id, $taxonomy, 'product' ),
				'problems'  => array(),
				'value'     => '',
			);

			if ( 'color' === $type ) {
				$color        = get_term_meta( $term->term_id, 'product_attribute_color', true );
				$row['value'] = (string) $color;

				if ( '' === trim( (string) $color ) ) {
					$row['problems'][] = 'کد رنگ خالی است';
				} elseif ( ! swa_is_valid_color( $color ) ) {
					$row['problems'][] = 'کد رنگ نامعتبر است';
				}

				// رنگ دوم (فقط وقتی حالت دو رنگ روشن باشد).
				$is_dual = get_term_meta( $term->term_id, 'is_dual_color', true );

				if ( 'yes' === $is_dual ) {
					$secondary = get_term_meta( $term->term_id, 'secondary_color', true );

					if ( '' === trim( (string) $secondary ) ) {
						$row['problems'][] = 'حالت دو رنگ روشن است ولی رنگ دوم خالی است';
					} elseif ( ! swa_is_valid_color( $secondary ) ) {
						$row['problems'][] = 'رنگ دوم نامعتبر است';
					}
				}
			} else {
				$attachment_id = absint( get_term_meta( $term->term_id, 'product_attribute_image', true ) );
				$row['value']  = $attachment_id ? (string) $attachment_id : '';

				if ( ! $attachment_id ) {
					$row['problems'][] = 'تصویر انتخاب نشده است';
				} elseif ( 'attachment' !== get_post_type( $attachment_id ) ) {
					$row['problems'][] = 'تصویر انتخاب‌شده دیگر وجود ندارد';
				}
			}

			$rows[] = $row;
		}

		// ترم‌های ناقص اول، و در هر گروه پرکاربردترها اول.
		usort(
			$rows,
			function ( $a, $b ) {
				$a_bad = empty( $a['problems'] ) ? 1 : 0;
				$b_bad = empty( $b['problems'] ) ? 1 : 0;

				if ( $a_bad !== $b_bad ) {
					return $a_bad <=> $b_bad;
				}

				return $b['count'] <=> $a['count'];
			}
		);

		$report[] = array(
			'taxonomy' => $taxonomy,
			'label'    => $attribute->attribute_label,
			'type'     => $type,
			'terms'    => $rows,
			'broken'   => count( array_filter( $rows, function ( $r ) { return ! empty( $r['problems'] ); } ) ),
		);
	}

	return $report;
}

$only      = isset( $_GET['only'] ) ? sanitize_key( wp_unslash( $_GET['only'] ) ) : '';
$show_all  = ! empty( $_GET['all'] );
$as_text   = isset( $_GET['format'] ) && 'text' === $_GET['format'];
$report    = swa_audit( in_array( $only, array( 'color', 'image' ), true ) ? $only : '' );

$total_broken = array_sum( wp_list_pluck( $report, 'broken' ) );

/* =====================================================================
 * خروجی متنی ساده
 * ================================================================== */

if ( $as_text ) {
	header( 'Content-Type: text/plain; charset=utf-8' );

	foreach ( $report as $group ) {
		if ( ! $show_all && ! $group['broken'] ) {
			continue;
		}

		printf( "%s  (%s، نوع: %s)\n", $group['label'], $group['taxonomy'], $group['type'] );
		echo str_repeat( '-', 60 ) . "\n";

		foreach ( $group['terms'] as $row ) {
			if ( ! $show_all && empty( $row['problems'] ) ) {
				continue;
			}

			printf(
				"  #%-7d %-25s %-20s %4d محصول   %s\n",
				$row['term_id'],
				$row['name'],
				$row['slug'],
				$row['count'],
				implode( ' / ', $row['problems'] )
			);
		}

		echo "\n";
	}

	printf( "مجموع ترم‌های ناقص: %d\n", $total_broken );
	exit;
}

/* =====================================================================
 * خروجی HTML
 * ================================================================== */

?><!doctype html>
<html lang="fa" dir="rtl">
<head>
	<meta charset="utf-8">
	<title>بازرسی سواچ‌ها</title>
	<style>
		body { font: 14px/1.8 Tahoma, sans-serif; background: #f0f0f1; color: #1d2327; margin: 0; padding: 24px; }
		.wrap { max-width: 1100px; margin: 0 auto; }
		h1 { font-size: 22px; margin: 0 0 4px; }
		.sub { color: #646970; margin: 0 0 20px; }
		.summary { padding: 14px 18px; border-radius: 6px; margin-bottom: 20px; font-weight: 700; }
		.summary.bad { background: #fcf0f1; border-right: 4px solid #d63638; }
		.summary.ok  { background: #f0f8f1; border-right: 4px solid #00a32a; }
		.filters { margin-bottom: 20px; }
		.filters a { display: inline-block; margin-left: 8px; padding: 5px 12px; background: #fff;
			border: 1px solid #c3c4c7; border-radius: 4px; text-decoration: none; color: #2271b1; font-size: 13px; }
		.filters a:hover { background: #f6f7f7; }
		.group { background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; margin-bottom: 20px; overflow: hidden; }
		.group > h2 { margin: 0; padding: 12px 16px; background: #f6f7f7; border-bottom: 1px solid #dcdcde; font-size: 15px; }
		.group > h2 code { font-size: 12px; color: #646970; font-weight: 400; }
		.badge { float: left; font-size: 12px; padding: 2px 10px; border-radius: 10px; font-weight: 700; }
		.badge.bad { background: #d63638; color: #fff; }
		.badge.ok  { background: #00a32a; color: #fff; }
		table { width: 100%; border-collapse: collapse; }
		th, td { padding: 9px 16px; text-align: right; border-bottom: 1px solid #f0f0f1; font-size: 13px; }
		th { background: #fbfbfc; color: #646970; font-weight: 700; }
		tr.bad td { background: #fffafa; }
		tr.bad td.problem { color: #d63638; font-weight: 700; }
		td.ok { color: #00734c; }
		.swatch { display: inline-block; width: 18px; height: 18px; border-radius: 3px;
			border: 1px solid rgba(0,0,0,.25); vertical-align: -4px; margin-left: 6px; }
		.empty { color: #a7aaad; }
		.note { color: #646970; font-size: 13px; margin-top: 24px; padding: 14px 18px;
			background: #fcf9e8; border-right: 4px solid #dba617; border-radius: 6px; }
		a.edit { color: #2271b1; text-decoration: none; }
		a.edit:hover { text-decoration: underline; }
	</style>
</head>
<body>
<div class="wrap">

	<h1>بازرسی سواچ‌ها</h1>
	<p class="sub">ویژگی‌هایی که نوعشان Color یا Image است ولی مقدار ترم‌هایشان خالی یا نامعتبر است.</p>

	<div class="summary <?php echo $total_broken ? 'bad' : 'ok'; ?>">
		<?php if ( $total_broken ) : ?>
			<?php printf( '%d ترم ناقص پیدا شد.', (int) $total_broken ); ?>
		<?php else : ?>
			هیچ ترم ناقصی پیدا نشد — همه‌چیز مرتب است.
		<?php endif; ?>
	</div>

	<div class="filters">
		<a href="?">همه</a>
		<a href="?only=color">فقط رنگ</a>
		<a href="?only=image">فقط تصویر</a>
		<a href="?<?php echo esc_attr( $show_all ? '' : 'all=1' ); ?><?php echo $only ? '&only=' . esc_attr( $only ) : ''; ?>">
			<?php echo $show_all ? 'فقط ناقص‌ها' : 'نمایش همه‌ی ترم‌ها'; ?>
		</a>
		<a href="?format=text<?php echo $only ? '&only=' . esc_attr( $only ) : ''; ?><?php echo $show_all ? '&all=1' : ''; ?>">خروجی متنی</a>
	</div>

	<?php if ( empty( $report ) ) : ?>
		<div class="group"><h2>هیچ ویژگی‌ای از نوع Color یا Image تعریف نشده است.</h2></div>
	<?php endif; ?>

	<?php foreach ( $report as $group ) : ?>
		<?php if ( ! $show_all && ! $group['broken'] ) { continue; } ?>

		<div class="group">
			<h2>
				<span class="badge <?php echo $group['broken'] ? 'bad' : 'ok'; ?>">
					<?php echo $group['broken'] ? esc_html( $group['broken'] ) . ' ناقص' : 'کامل'; ?>
				</span>
				<?php echo esc_html( $group['label'] ); ?>
				<code><?php echo esc_html( $group['taxonomy'] ); ?> — <?php echo esc_html( $group['type'] ); ?></code>
			</h2>

			<table>
				<thead>
					<tr>
						<th style="width:70px;">شناسه</th>
						<th>نام ترم</th>
						<th style="width:170px;">نامک</th>
						<th style="width:110px;">مقدار</th>
						<th style="width:90px;">محصولات</th>
						<th>وضعیت</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $group['terms'] as $row ) : ?>
					<?php if ( ! $show_all && empty( $row['problems'] ) ) { continue; } ?>
					<tr class="<?php echo empty( $row['problems'] ) ? '' : 'bad'; ?>">
						<td><?php echo (int) $row['term_id']; ?></td>
						<td>
							<?php if ( $row['edit_link'] ) : ?>
								<a class="edit" href="<?php echo esc_url( $row['edit_link'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['name'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $row['name'] ); ?>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $row['slug'] ); ?></code></td>
						<td>
							<?php if ( '' === $row['value'] ) : ?>
								<span class="empty">—</span>
							<?php elseif ( 'color' === $group['type'] ) : ?>
								<?php if ( swa_is_valid_color( $row['value'] ) ) : ?>
									<span class="swatch" style="background:<?php echo esc_attr( sanitize_hex_color( $row['value'] ) ); ?>"></span>
								<?php endif; ?>
								<code><?php echo esc_html( $row['value'] ); ?></code>
							<?php else : ?>
								<code>#<?php echo esc_html( $row['value'] ); ?></code>
							<?php endif; ?>
						</td>
						<td><?php echo (int) $row['count']; ?></td>
						<?php if ( empty( $row['problems'] ) ) : ?>
							<td class="ok">✓ سالم</td>
						<?php else : ?>
							<td class="problem"><?php echo esc_html( implode( ' / ', $row['problems'] ) ); ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endforeach; ?>

	<div class="note">
		ستون «محصولات» تعداد محصولاتی است که آن ترم به آن‌ها تخصیص داده شده — از بالا شروع کنید تا بیشترین اثر را بگذارید.
		<br>روی نام ترم کلیک کنید تا صفحه‌ی ویرایشش در تب جدید باز شود.
		<br><br><strong>⚠️ پس از استفاده، این فایل را از سرور پاک کنید.</strong>
	</div>

</div>
</body>
</html>
