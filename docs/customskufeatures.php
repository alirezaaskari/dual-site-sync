<?php
/**
 * customskufeatures.php  —  نسخه‌ی به‌روزشده
 *
 * همان فایل قبلی شما، با همان نام توابع و همان ترتیب بخش‌ها.
 * فقط چهار تغییر دارد که هرکدام با  ‹‹ تغییر ›› در محل خودش علامت خورده:
 *
 *   ۱. محافظ همگام‌سازی  — هنگام دریافت داده از سایت مقابل، هیچ SKU جدیدی
 *      ساخته نمی‌شود تا شماره‌ی سایت مبدأ عیناً حفظ شود.
 *   ۲. رفع سقف ۹۹۹۹    — الگوی قبلی «^[0-9]{4}$» بود؛ به‌محض رسیدن به شماره‌ی
 *      ۱۰۰۰۰ دیگر هیچ SKU با آن مطابقت نمی‌کرد، MAX() مقدار NULL برمی‌گرداند و
 *      شمارنده به ۱ برمی‌گشت → SKU تکراری.
 *   ۳. قفل هم‌زمانی     — دو ذخیره‌ی هم‌زمان دیگر یک شماره را نمی‌گیرند.
 *   ۴. پیشوند اختیاری  — اگر سایت دوم هم محصول مستقل می‌سازد، با تعریف ثابت
 *      LOTUS_SKU_PREFIX در wp-config.php فضای شماره‌ها از هم جدا می‌شود.
 *
 * بقیه‌ی فایل (استایل و اسکریپت کپی SKU) بدون تغییر منطقی است.
 */

defined( 'ABSPATH' ) || exit;

/**
 * ‹‹ تغییر ۱ ›› آیا Dual Site Sync هم‌اکنون در حال نوشتن داده است؟
 *
 * وقتی سایت مقابل محصولی را می‌فرستد، SKU همراه بسته می‌آید و باید عیناً
 * نوشته شود. این تابع جلوی دخالت مولد محلی را می‌گیرد.
 * اگر DSS نصب نباشد، همیشه false برمی‌گرداند و چیزی تغییر نمی‌کند.
 */
function lotus_sku_is_syncing() {
	return class_exists( 'DSS_Context' ) && DSS_Context::is_syncing();
}

/**
 * ‹‹ تغییر ۴ ›› پیشوند SKU این سایت. پیش‌فرض: بدون پیشوند (رفتار قبلی).
 *
 * برای جدا کردن شماره‌های سایت دوم، در wp-config.php آن سایت بنویسید:
 *     define( 'LOTUS_SKU_PREFIX', 'R' );
 */
function lotus_sku_prefix() {
	$prefix = defined( 'LOTUS_SKU_PREFIX' ) ? (string) LOTUS_SKU_PREFIX : '';

	// فقط حروف/عدد/خط‌تیره — این مقدار داخل الگوی REGEXP مای‌اس‌کیو‌ال می‌رود.
	return preg_replace( '/[^A-Za-z0-9\-]/', '', $prefix );
}

/**
 * ‹‹ تغییر ۲ ›› شماره‌ی بعدی دنباله، بدون سقف رقم.
 */
function lotus_next_sku() {
	global $wpdb;

	$prefix  = lotus_sku_prefix();
	$pattern = $prefix ? '^' . $prefix . '[0-9]{4,}$' : '^[0-9]{4,}$';
	$strip   = strlen( $prefix ) + 1;

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

	$next  = $last ? (int) $last + 1 : 1;
	$guard = 0;

	// اگر شماره‌ای به هر دلیل اشغال بود، جلو برو.
	do {
		$candidate = $prefix . str_pad( (string) $next, 4, '0', STR_PAD_LEFT );
		$next++;
		$guard++;
	} while ( wc_get_product_id_by_sku( $candidate ) && $guard < 500 );

	return $candidate;
}

/**
 * اولین SKU آزاد برای واریشن. (استخراج‌شده از کد قبلی تا در دو جا تکرار نشود.)
 *
 * @param string $base_sku SKU والد.
 * @param int    $index    شمارنده (ارجاعی).
 *
 * @return string رشته‌ی خالی یعنی پیدا نشد.
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

// تولید خودکار SKU فقط موقع ایجاد محصول
function lotus_auto_generate_sku( $post_id, $post, $update ) {
	if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
		return;
	}

	if ( get_post_type( $post_id ) !== 'product' ) {
		return;
	}

	// اگر محصول جدید نیست (در حال ویرایش است)، کاری نکن
	if ( $update ) {
		return;
	}

	// ‹‹ تغییر ۱ ›› در طول همگام‌سازی، SKU سایت مبدأ دست‌نخورده می‌ماند.
	if ( lotus_sku_is_syncing() ) {
		return;
	}

	$product = wc_get_product( $post_id );

	if ( ! $product || $product->get_sku() ) {
		return;
	}

	// ‹‹ تغییر ۳ ›› قفل سبک، تا دو ذخیره‌ی هم‌زمان یک شماره را نگیرند.
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

	// بازه‌ی قیمت و وضعیت موجودی والد پس از تغییر واریشن‌ها تازه‌سازی شود.
	if ( class_exists( 'WC_Product_Variable' ) ) {
		WC_Product_Variable::sync( $post_id );
	}
}
add_action( 'save_post_product', 'lotus_auto_generate_sku', 10, 3 );

// تولید SKU جداگانه برای ورییشن‌های جدید
function lotus_generate_variation_sku_individually( $variation_id, $i = 1 ) {
	// ‹‹ تغییر ۱ ››
	if ( lotus_sku_is_syncing() ) {
		return;
	}

	$variation = wc_get_product( $variation_id );

	if ( ! $variation ) {
		return;
	}

	// اگر SKU دارد، تغییر نده
	if ( $variation->get_sku() ) {
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
add_action( 'woocommerce_create_product_variation', 'lotus_generate_variation_sku_individually', 10, 2 );

/**
 * ---------------------------
 * 6. استایل سفارشی SKU
 * ---------------------------
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
			transition: background-color 0.3s ease;
		}

		.sku:hover { background-color: #d9d9d9; }

		.sku.copied::after {
			content: 'کپی شد!';
			position: absolute;
			top: -28px;
			right: 0;
			background: #4caf50;
			color: white;
			padding: 3px 10px;
			border-radius: 6px;
			font-size: 12px;
			opacity: 0;
			animation: fadeInOut 2s forwards;
		}

		@keyframes fadeInOut {
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
 * ---------------------------
 * 7. اسکریپت کپی SKU
 * ---------------------------
 */
function sku_copy_to_clipboard_script() {
	?>
	<script>
	document.addEventListener('DOMContentLoaded', function() {
		document.querySelectorAll('.sku').forEach(function(el){
			el.addEventListener('click', function(){
				const text = this.textContent.trim();
				if (!text) return;

				// navigator.clipboard روی http در دسترس نیست؛ بدون خطا رد شو.
				if (!navigator.clipboard) return;

				const self = this;
				navigator.clipboard.writeText(text).then(function(){
					self.classList.add('copied');
					setTimeout(function(){ self.classList.remove('copied'); }, 2000);
				}).catch(function(){ alert('کپی انجام نشد.'); });
			});
		});
	});
	</script>
	<?php
}
add_action( 'wp_head', 'sku_copy_to_clipboard_script' );
