<?php
/**
 * متاباکس صفحه‌ی ویرایش محصول.
 *
 * چون به‌صورت متاباکس ثبت می‌شود (نه post_submitbox_misc_actions)، از طریق
 * «تنظیمات صفحه» بالای صفحه‌ی ویرایش محصول قابل مخفی کردن است.
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

class DSS_Metabox {

	const ID = 'dss_sync_panel';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * ثبت متاباکس.
	 */
	public function register() {
		add_meta_box(
			self::ID,
			'همگام‌سازی دو سایته',
			array( $this, 'render' ),
			'product',
			'side',
			'high'
		);
	}

	/**
	 * بارگذاری assets فقط در صفحه‌ی ویرایش محصول.
	 *
	 * @param string $hook صفحه‌ی جاری.
	 */
	public function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'dss-admin', DSS_URL . 'assets/css/admin.css', array(), DSS_VERSION );
		wp_enqueue_script( 'dss-admin', DSS_URL . 'assets/js/admin.js', array( 'jquery' ), DSS_VERSION, true );

		wp_localize_script(
			'dss-admin',
			'DSS_Admin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( DSS_Ajax::NONCE ),
				'i18n'    => array(
					'working'      => 'در حال انجام…',
					'networkError' => 'خطای ارتباط با سرور.',
					'confirmSync'  => 'اطلاعات این محصول روی سایت مقابل بازنویسی می‌شود. ادامه می‌دهید؟',
				),
			)
		);
	}

	/**
	 * محتوای متاباکس.
	 *
	 * @param WP_Post $post پست جاری.
	 */
	public function render( $post ) {
		$errors = DSS_Config::configuration_errors();

		if ( ! empty( $errors ) ) {
			echo '<div class="dss-box dss-box--error"><strong>پیکربندی ناقص است:</strong><ul>';
			foreach ( $errors as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';

			return;
		}

		if ( 'auto-draft' === $post->post_status ) {
			echo '<p class="dss-hint">ابتدا محصول را ذخیره کنید تا امکان همگام‌سازی فعال شود.</p>';

			return;
		}

		$product_id  = $post->ID;
		$target_key  = DSS_Config::target_key();
		$target      = DSS_Config::target();
		$target_name = DSS_Config::label( $target_key );

		$remote_id   = absint( get_post_meta( $product_id, DSS_Importer::META_REMOTE_ID, true ) );
		$remote_site = get_post_meta( $product_id, DSS_Importer::META_REMOTE_SITE, true );
		$last_sync   = get_post_meta( $product_id, DSS_Importer::META_LAST_SYNC, true );
		$is_linked   = ( $remote_id && $remote_site === $target_key );

		$sku = get_post_meta( $product_id, '_sku', true );
		?>
		<div class="dss-panel">

			<div class="dss-box <?php echo $is_linked ? 'dss-box--ok' : 'dss-box--neutral'; ?>">
				<?php if ( $is_linked ) : ?>
					<span class="dashicons dashicons-yes-alt"></span>
					متصل به محصول <code><?php echo esc_html( $remote_id ); ?></code> در «<?php echo esc_html( $target_name ); ?>»
					<?php if ( $last_sync ) : ?>
						<div class="dss-hint">آخرین همگام‌سازی: <?php echo esc_html( $last_sync ); ?></div>
					<?php endif; ?>
				<?php else : ?>
					<span class="dashicons dashicons-info-outline"></span>
					هنوز به محصولی در «<?php echo esc_html( $target_name ); ?>» متصل نیست.
				<?php endif; ?>
			</div>

			<div class="dss-actions">
				<button type="button" class="button button-primary dss-btn" data-mode="full" data-product="<?php echo esc_attr( $product_id ); ?>">
					همگام‌سازی کامل
				</button>

				<button type="button" class="button dss-btn" data-mode="full_no_images" data-product="<?php echo esc_attr( $product_id ); ?>">
					همگام‌سازی بدون تصاویر
				</button>

				<button type="button" class="button dss-btn" data-mode="partial" data-product="<?php echo esc_attr( $product_id ); ?>">
					به‌روزرسانی جزئی
				</button>

				<?php if ( ! $is_linked ) : ?>
					<button type="button" class="button dss-btn dss-btn--create" data-mode="create" data-product="<?php echo esc_attr( $product_id ); ?>">
						ایجاد محصول در سایت دیگر
					</button>
				<?php endif; ?>
			</div>

			<div id="dss-status" class="dss-status" aria-live="polite"></div>

			<details class="dss-legend">
				<summary>هر دکمه چه چیزی می‌فرستد؟</summary>
				<ul>
					<li><strong>کامل:</strong> نام، توضیحات، قیمت، دسته‌بندی، ویژگی‌ها، واریشن‌ها، تصاویر و تنظیمات سواچ.</li>
					<li><strong>بدون تصاویر:</strong> همان موارد بالا، ولی هیچ تصویری (شاخص، گالری، واریشن، سواچ) منتقل نمی‌شود.</li>
					<li><strong>جزئی:</strong> فقط قیمت، توضیح کوتاه، دسته‌بندی، ویژگی‌ها، تصاویر و سواچ — نام و توضیحات کامل دست‌نخورده می‌ماند.</li>
					<li><strong>موجودی:</strong> <?php echo DSS_Config::is_on( 'sync_stock_fields' ) ? 'در همگام‌سازی‌ها ارسال می‌شود.' : 'ارسال نمی‌شود (از تنظیمات فعال کنید).'; ?></li>
				</ul>
			</details>

			<?php if ( $target ) : ?>
				<div class="dss-crosslink">
					<?php if ( $is_linked ) : ?>

						<a class="button button-secondary dss-link-btn"
						   href="<?php echo esc_url( $target['url'] . '/?p=' . $remote_id ); ?>"
						   target="_blank" rel="noopener">
							<span class="dashicons dashicons-visibility"></span>
							مشاهده در «<?php echo esc_html( $target_name ); ?>»
						</a>

						<a class="button button-secondary dss-link-btn dss-link-btn--edit"
						   href="<?php echo esc_url( $target['url'] . '/wp-admin/post.php?post=' . $remote_id . '&action=edit' ); ?>"
						   target="_blank" rel="noopener">
							<span class="dashicons dashicons-edit"></span>
							ویرایش در «<?php echo esc_html( $target_name ); ?>»
						</a>

						<div class="dss-hint">محصول متصل، شناسه <?php echo esc_html( $remote_id ); ?></div>

					<?php else : ?>

						<?php
						if ( $sku ) {
							$link = $target['url'] . '/wp-admin/edit.php?post_type=product&s=' . rawurlencode( $sku );
							$note = 'جست‌وجوی SKU «' . $sku . '» در فهرست محصولات سایت مقابل';
						} else {
							$link = $target['url'] . '/wp-admin/edit.php?post_type=product';
							$note = 'فهرست محصولات سایت مقابل';
						}
						?>
						<a class="button button-secondary dss-link-btn"
						   href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener">
							<span class="dashicons dashicons-search"></span>
							جست‌وجو در «<?php echo esc_html( $target_name ); ?>»
						</a>

						<div class="dss-hint"><?php echo esc_html( $note ); ?></div>

					<?php endif; ?>
				</div>
			<?php endif; ?>

		</div>
		<?php
	}
}
