<?php
/**
 * نسخه‌ی سازگارشده‌ی مولد SKU اختصاصی (lotus) با Dual Site Sync.
 *
 * این فایل جایگزین کد فعلی SKU شما در functions.php می‌شود.
 * تفاوت‌ها با نسخه‌ی قبلی:
 *
 *  ۱. محافظ همگام‌سازی — هنگام دریافت داده از سایت مقابل هیچ SKU جدیدی ساخته
 *     نمی‌شود، تا شماره‌ی تولیدشده در سایت مبدأ عیناً حفظ شود.
 *  ۲. سقف ۹۹۹۹ برداشته شد — الگوی قبلی «^[0-9]{4}$» بود؛ به‌محض رسیدن به
 *     شماره‌ی ۱۰۰۰۰ دیگر هیچ SKU با آن مطابقت نمی‌کرد و شمارنده به ۱ برمی‌گشت
 *     و SKU تکراری می‌ساخت.
 *  ۳. پیشوند اختیاری per-site — اگر سایت دوم هم محصول مستقل می‌سازد، با تعریف
 *     ثابت LOTUS_SKU_PREFIX در wp-config.php فضای شماره‌ها از هم جدا می‌شود.
 *  ۴. قفل سبک — جلوگیری از گرفتن یک شماره توسط دو ذخیره‌ی هم‌زمان.
 *
 * @package FlatsomeChild
 */

defined( 'ABSPATH' ) || exit;

/**
 * آیا هم‌اکنون Dual Site Sync در حال نوشتن داده است؟
 *
 * @return bool
 */
function lotus_sku_is_syncing() {
	return class_exists( 'DSS_Context' ) && DSS_Context::is_syncing();
}

/**
 * پیشوند SKU این سایت (پیش‌فرض: بدون پیشوند).
 *
 * @return string
 */
function lotus_sku_prefix() {
	$prefix = defined( 'LOTUS_SKU_PREFIX' ) ? (string) LOTUS_SKU_PREFIX : '';

	// فقط حروف/اعداد/خط تیره — چون این مقدار داخل الگوی REGEXP مای‌اس‌کیو‌ال می‌رود.
	return preg_replace( '/[^A-Za-z0-9\-]/', '', $prefix );
}

/**
 * شماره‌ی بعدی دنباله.
 *
 * @return string
 */
function lotus_next_sku() {
	global $wpdb;

	$prefix  = lotus_sku_prefix();
	$pattern = $prefix ? '^' . $prefix . '[0-9]{4,}$' : '^[0-9]{4,}$';
	$strip   = strlen( $prefix ) + 1;

	// بیشترین شماره‌ی موجود، بدون سقف رقم.
	$last = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT MAX( CAST( SUBSTRING( meta_value, %d ) AS UNSIGNED ) )
			 FROM {$wpdb->postmeta}
			 WHERE meta_key = '_sku'
			   AND meta_value REGEXP %s",
			$strip,
			$pattern
		)
	);

	$next = $last ? (int) $last + 1 : 1;

	// اگر شماره‌ای به هر دلیل اشغال بود، جلو برو.
	$guard = 0;

	do {
		$candidate = $prefix . str_pad( (string) $next, 4, '0', STR_PAD_LEFT );
		$next++;
		$guard++;
	} while ( wc_get_product_id_by_sku( $candidate ) && $guard < 500 );

	return $candidate;
}

/**
 * تولید خودکار SKU فقط هنگام ایجاد محصول.
 *
 * @param int     $post_id شناسه.
 * @param WP_Post $post    پست.
 * @param bool    $update  آیا ویرایش است.
 */
function lotus_auto_generate_sku( $post_id, $post, $update ) {
	if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
		return;
	}

	if ( 'product' !== get_post_type( $post_id ) ) {
		return;
	}

	if ( $update ) {
		return;
	}

	// ⬅ کلیدی: در طول همگام‌سازی، SKU سایت مبدأ دست‌نخورده می‌ماند.
	if ( lotus_sku_is_syncing() ) {
		return;
	}

	$product = wc_get_product( $post_id );

	if ( ! $product || $product->get_sku() ) {
		return;
	}

	// قفل سبک برای جلوگیری از تخصیص هم‌زمان یک شماره.
	$lock = 'lotus_sku_lock';

	if ( get_transient( $lock ) ) {
		return;
	}

	set_transient( $lock, 1, 10 );

	$new_sku = lotus_next_sku();

	try {
		$product->set_sku( $new_sku );
		$product->save();
	} catch ( Exception $e ) {
		delete_transient( $lock );

		return;
	}

	delete_transient( $lock );

	if ( ! $product->is_type( 'variable' ) ) {
		return;
	}

	$index = 1;

	foreach ( $product->get_children() as $variation_id ) {
		$variation = wc_get_product( $variation_id );

		if ( ! $variation || $variation->get_sku() ) {
			continue;
		}

		$variation_sku = lotus_free_variation_sku( $new_sku, $index );

		if ( '' === $variation_sku ) {
			continue;
		}

		try {
			$variation->set_sku( $variation_sku );
			$variation->save();
		} catch ( Exception $e ) {
			continue;
		}

		$index++;
	}

	if ( class_exists( 'WC_Product_Variable' ) ) {
		WC_Product_Variable::sync( $post_id );
	}
}
add_action( 'save_post_product', 'lotus_auto_generate_sku', 10, 3 );

/**
 * اولین SKU آزاد برای واریشن.
 *
 * @param string $base_sku SKU والد.
 * @param int    $index    شمارنده (ارجاعی).
 *
 * @return string
 */
function lotus_free_variation_sku( $base_sku, &$index ) {
	$guard = 0;

	while ( $guard < 500 ) {
		$candidate = $base_sku . '-' . $index;

		if ( ! wc_get_product_id_by_sku( $candidate ) ) {
			return $candidate;
		}

		$index++;
		$guard++;
	}

	return '';
}

/**
 * تولید SKU برای واریشن‌های تازه‌ساخته‌شده در پنل.
 *
 * @param int $variation_id شناسه واریشن.
 */
function lotus_generate_variation_sku_individually( $variation_id ) {
	if ( lotus_sku_is_syncing() ) {
		return;
	}

	$variation = wc_get_product( $variation_id );

	if ( ! $variation || $variation->get_sku() ) {
		return;
	}

	$parent_id = wp_get_post_parent_id( $variation_id );
	$parent    = $parent_id ? wc_get_product( $parent_id ) : null;

	if ( ! $parent || ! $parent->get_sku() ) {
		return;
	}

	$index         = 1;
	$variation_sku = lotus_free_variation_sku( $parent->get_sku(), $index );

	if ( '' === $variation_sku ) {
		return;
	}

	try {
		$variation->set_sku( $variation_sku );
		$variation->save();
	} catch ( Exception $e ) {
		return;
	}
}
add_action( 'woocommerce_create_product_variation', 'lotus_generate_variation_sku_individually', 10, 1 );

/* -------------------------------------------------------------------------
 * استایل و اسکریپت کپی SKU در فرانت‌اند
 * ---------------------------------------------------------------------- */

/**
 * استایل SKU.
 */
function custom_woocommerce_sku_style() {
	?>
	<style>
		.sku {
			font-weight: 600;
			color: #444;
			background-color: #f0f0f0;
			padding: 3px 8px;
			border-radius: 5px;
			font-size: 13px;
			display: inline-block;
			cursor: pointer !important;
			position: relative;
			transition: background-color .3s ease;
		}

		.sku:hover { background-color: #d9d9d9; }

		.sku.copied::after {
			content: 'کپی شد!';
			position: absolute;
			top: -28px;
			right: 0;
			background: #4caf50;
			color: #fff;
			padding: 3px 10px;
			border-radius: 6px;
			font-size: 12px;
			opacity: 0;
			animation: lotusSkuFade 2s forwards;
		}

		@keyframes lotusSkuFade {
			0%   { opacity: 0; transform: translateY(5px); }
			10%  { opacity: 1; transform: translateY(0); }
			90%  { opacity: 1; }
			100% { opacity: 0; transform: translateY(-5px); }
		}
	</style>
	<?php
}
add_action( 'wp_head', 'custom_woocommerce_sku_style' );

/**
 * کپی SKU با کلیک.
 */
function sku_copy_to_clipboard_script() {
	?>
	<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			document.querySelectorAll( '.sku' ).forEach( function ( el ) {
				el.addEventListener( 'click', function () {
					var text = this.textContent.trim();
					var self = this;

					if ( ! text || ! navigator.clipboard ) {
						return;
					}

					navigator.clipboard.writeText( text ).then( function () {
						self.classList.add( 'copied' );
						setTimeout( function () {
							self.classList.remove( 'copied' );
						}, 2000 );
					} );
				} );
			} );
		} );
	</script>
	<?php
}
add_action( 'wp_head', 'sku_copy_to_clipboard_script' );
