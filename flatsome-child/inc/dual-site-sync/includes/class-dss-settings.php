<?php
/**
 * صفحه‌ی تنظیمات و گزارش.
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

class DSS_Settings {

	const PAGE  = 'dss-settings';
	const NONCE = 'dss_save_settings';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_dss_save_settings', array( $this, 'save' ) );
		add_action( 'admin_post_dss_clear_log', array( $this, 'clear_log' ) );
	}

	/**
	 * دسترسی لازم برای صفحه‌ی تنظیمات.
	 *
	 * منوی «تنظیمات» وردپرس خودش با manage_options محافظت می‌شود، پس همین
	 * دسترسی برای صفحه هم استفاده می‌شود تا زیرمنو همیشه دیده شود.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * آدرس صفحه‌ی تنظیمات.
	 *
	 * @return string
	 */
	public static function url() {
		return add_query_arg( array( 'page' => self::PAGE ), admin_url( 'options-general.php' ) );
	}

	public function add_menu() {
		add_options_page(
			'همگام‌سازی دو سایته',
			'همگام‌سازی دو سایته',
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * توضیح گزینه‌ی برند، بر اساس تاکسونومی‌های واقعاً ثبت‌شده روی این سایت.
	 *
	 * @return string
	 */
	private static function brands_description() {
		$taxonomies = DSS_Exporter::brand_taxonomies();

		if ( empty( $taxonomies ) ) {
			return 'روی این سایت هیچ تاکسونومی برندی ثبت نشده است؛ این گزینه اثری ندارد.';
		}

		$labels = array();

		foreach ( $taxonomies as $taxonomy ) {
			$object   = get_taxonomy( $taxonomy );
			$labels[] = sprintf(
				'<code>%s</code>%s',
				esc_html( $taxonomy ),
				$object ? ' (' . esc_html( $object->labels->name ) . ')' : ''
			);
		}

		return 'تاکسونومی‌های شناسایی‌شده: ' . implode( '، ', $labels )
			. '. برند فقط زمانی منتقل می‌شود که همان تاکسونومی در سایت مقابل هم ثبت شده باشد؛'
			. ' یعنی افزونه‌ی برند باید روی هر دو سایت یکسان باشد. برندهای نبود، ساخته می‌شوند.';
	}

	/**
	 * تعریف فیلدها.
	 *
	 * @return array
	 */
	private function fields() {
		return array(

			'sync' => array(
				'title'  => 'محتوای همگام‌سازی',
				'fields' => array(
					'sync_categories'           => array( 'type' => 'checkbox', 'label' => 'دسته‌بندی‌ها و برچسب‌ها منتقل شوند' ),
					'sync_brands'               => array(
						'type'  => 'checkbox',
						'label' => 'برندها منتقل شوند',
						'desc'  => self::brands_description(),
					),
					'sync_variation_images'     => array(
						'type'  => 'checkbox',
						'label' => 'تصویر واریشن‌ها منتقل شود',
						'desc'  => 'اگر واریشنی در سایت مبدأ تصویر نداشته باشد، تصویر واریشن مقصد در هر حال دست‌نخورده می‌ماند — با تصویر شاخص یا گالری محصول پر نمی‌شود.',
					),
					'create_missing_categories' => array( 'type' => 'checkbox', 'label' => 'دسته‌بندی‌های نبود در سایت مقصد ساخته شوند', 'desc' => 'اگر خاموش باشد، دسته‌بندی‌هایی که در مقصد وجود ندارند نادیده گرفته می‌شوند.' ),
					'sync_status'               => array( 'type' => 'checkbox', 'label' => 'وضعیت انتشار (منتشرشده/پیش‌نویس) منتقل شود', 'desc' => 'خاموش نگه داشتن این گزینه امن‌تر است؛ در غیر این صورت پیش‌نویس کردن محصول در یک سایت آن را در سایت دیگر هم از دسترس خارج می‌کند. در حالت «ایجاد محصول» وضعیت همیشه منتقل می‌شود.' ),
					'delete_missing_variations' => array( 'type' => 'checkbox', 'label' => 'واریشن‌هایی که در مبدأ حذف شده‌اند، در مقصد هم حذف شوند' ),
				),
			),

			'swatches' => array(
				'title'  => 'افزونه‌ی Variation Swatches',
				'fields' => array(
					'sync_swatches'  => array( 'type' => 'checkbox', 'label' => 'تنظیمات سواچ محصول منتقل شود', 'desc' => 'شامل متای <code>_woo_variation_swatches_product_settings</code>؛ شناسه‌ی ترم‌ها و تصاویر به‌صورت خودکار به معادل محلی نگاشت می‌شوند.' ),
					'sync_term_meta' => array( 'type' => 'checkbox', 'label' => 'رنگ/تصویر/تولتیپ ترم‌های ویژگی هم منتقل شود', 'desc' => 'این داده‌ها سراسری‌اند (نه مخصوص یک محصول) و ترم‌های متناظر در سایت مقصد را بازنویسی می‌کنند.' ),
				),
			),

			'stock' => array(
				'title'  => 'موجودی و انبار',
				'fields' => array(
					'sync_stock_fields' => array( 'type' => 'checkbox', 'label' => 'فیلدهای موجودی در همگام‌سازی دستی ارسال شوند', 'desc' => 'تا وقتی انبار دو سایت مشترک نیست، خاموش بگذارید.' ),
					'shared_inventory'  => array( 'type' => 'checkbox', 'label' => 'انبار مشترک: هر تغییر موجودی (از جمله فروش) خودکار به سایت مقابل منتقل شود', 'desc' => '<strong>هشدار:</strong> فقط زمانی روشن کنید که واقعاً یک انبار فیزیکی مشترک دارید. باید در هر دو سایت روشن باشد.' ),
				),
			),

			'automation' => array(
				'title'  => 'خودکارسازی',
				'fields' => array(
					'auto_sync_mode' => array(
						'type'    => 'select',
						'label'   => 'همگام‌سازی خودکار پس از ذخیره‌ی محصول',
						'options' => array(
							'off'            => 'خاموش (فقط دستی)',
							'partial'        => 'به‌روزرسانی جزئی',
							'full_no_images' => 'کامل بدون تصاویر',
							'full'           => 'کامل',
						),
						'desc'    => 'فقط برای محصولاتی اجرا می‌شود که از قبل به سایت مقابل متصل شده‌اند. محصول جدید هرگز خودکار ساخته نمی‌شود.',
					),
				),
			),

			'sku' => array(
				'title'  => 'SKU',
				'fields' => array(
					'auto_variation_sku'           => array( 'type' => 'checkbox', 'label' => 'ساخت خودکار SKU واریشن‌ها هنگام ذخیره', 'desc' => 'اگر مولد SKU اختصاصی دارید، این را خاموش بگذارید. SKU موجود در هر حال عیناً به سایت مقابل منتقل می‌شود.' ),
					'protect_manual_variation_sku' => array( 'type' => 'checkbox', 'label' => 'SKU دستیِ واریشن‌ها بازنویسی نشود' ),
				),
			),

			'frontend' => array(
				'title'  => 'فرانت‌اند',
				'fields' => array(
					'frontend_sku_search' => array( 'type' => 'checkbox', 'label' => 'جست‌وجوی محصول با SKU در سایت فعال باشد' ),
				),
			),

			'advanced' => array(
				'title'  => 'پیشرفته',
				'fields' => array(
					'request_timeout' => array( 'type' => 'number', 'label' => 'مهلت درخواست (ثانیه)', 'min' => 30, 'max' => 600 ),
					'debug_log'       => array( 'type' => 'checkbox', 'label' => 'ثبت جزئیات در error_log سرور' ),
				),
			),
		);
	}

	/**
	 * ذخیره.
	 */
	public function save() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'دسترسی ندارید.' );
		}

		check_admin_referer( self::NONCE );

		$posted   = isset( $_POST['dss'] ) ? wp_unslash( $_POST['dss'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$settings = array();

		foreach ( $this->fields() as $section ) {
			foreach ( $section['fields'] as $key => $field ) {
				switch ( $field['type'] ) {
					case 'checkbox':
						$settings[ $key ] = ! empty( $posted[ $key ] ) ? 'yes' : 'no';
						break;

					case 'number':
						$value            = isset( $posted[ $key ] ) ? absint( $posted[ $key ] ) : 0;
						$settings[ $key ] = max( $field['min'], min( $field['max'], $value ) );
						break;

					case 'select':
						$value            = isset( $posted[ $key ] ) ? sanitize_key( $posted[ $key ] ) : '';
						$settings[ $key ] = isset( $field['options'][ $value ] ) ? $value : array_key_first( $field['options'] );
						break;
				}
			}
		}

		update_option( DSS_Config::OPTION, $settings );

		DSS_Logger::info( 'تنظیمات ذخیره شد.' );

		wp_safe_redirect( add_query_arg( 'saved', 1, self::url() ) );
		exit;
	}

	/**
	 * پاک‌کردن گزارش.
	 */
	public function clear_log() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'دسترسی ندارید.' );
		}

		check_admin_referer( self::NONCE );
		DSS_Logger::clear();

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * رندر صفحه.
	 */
	public function render() {
		$settings = DSS_Config::all();
		$errors   = DSS_Config::configuration_errors();

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
					'confirmSync'  => '',
				),
			)
		);
		?>
		<div class="wrap dss-settings">
			<h1>همگام‌سازی دو سایته</h1>

			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد.</p></div>
			<?php endif; ?>

			<h2>وضعیت اتصال</h2>
			<table class="widefat striped dss-status-table">
				<tbody>
					<tr>
						<th>این سایت</th>
						<td>
							<?php if ( DSS_Config::current_key() ) : ?>
								<code><?php echo esc_html( DSS_Config::current_key() ); ?></code> — <?php echo esc_html( DSS_Config::label( DSS_Config::current_key() ) ); ?>
							<?php else : ?>
								<span class="dss-bad">تشخیص داده نشد</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>سایت مقابل</th>
						<td>
							<?php $target = DSS_Config::target(); ?>
							<?php if ( $target ) : ?>
								<code><?php echo esc_html( DSS_Config::target_key() ); ?></code> — <?php echo esc_html( $target['label'] ); ?>
								(<a href="<?php echo esc_url( $target['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $target['url'] ); ?></a>)
							<?php else : ?>
								<span class="dss-bad">تعریف نشده</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>کلید رمز مشترک</th>
						<td>
							<?php if ( '' === DSS_Config::secret() ) : ?>
								<span class="dss-bad">تعریف نشده</span>
							<?php else : ?>
								<span class="dss-good">تعریف شده (<?php echo esc_html( strlen( DSS_Config::secret() ) ); ?> کاراکتر)</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>افزونه‌ی سواچ</th>
						<td>
							<?php if ( DSS_Swatches::is_pro() ) : ?>
								<span class="dss-good">فعال (نسخه Pro)</span>
							<?php elseif ( DSS_Swatches::is_active() ) : ?>
								<span class="dss-good">فعال (نسخه رایگان)</span>
							<?php else : ?>
								<span class="dss-muted">نصب/فعال نیست</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>تاکسونومی برند</th>
						<td>
							<?php $brand_taxonomies = DSS_Exporter::brand_taxonomies(); ?>
							<?php if ( empty( $brand_taxonomies ) ) : ?>
								<span class="dss-muted">ثبت نشده</span>
							<?php else : ?>
								<span class="dss-good"><?php echo esc_html( implode( '، ', $brand_taxonomies ) ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>آزمایش اتصال</th>
						<td>
							<button type="button" class="button" id="dss-ping">آزمایش اتصال به سایت مقابل</button>
							<span id="dss-ping-result" class="dss-inline-status"></span>
						</td>
					</tr>
				</tbody>
			</table>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="notice notice-error"><p><strong>پیکربندی wp-config.php ناقص است:</strong></p><ul style="list-style:disc;margin-right:20px;">
				<?php foreach ( $errors as $error ) : ?>
					<li><?php echo esc_html( $error ); ?></li>
				<?php endforeach; ?>
				</ul></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dss_save_settings">
				<?php wp_nonce_field( self::NONCE ); ?>

				<?php foreach ( $this->fields() as $section_key => $section ) : ?>
					<h2><?php echo esc_html( $section['title'] ); ?></h2>
					<table class="form-table" role="presentation">
						<tbody>
						<?php foreach ( $section['fields'] as $key => $field ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $field['label'] ); ?></th>
								<td>
									<?php if ( 'checkbox' === $field['type'] ) : ?>
										<label>
											<input type="checkbox" name="dss[<?php echo esc_attr( $key ); ?>]" value="yes" <?php checked( 'yes', $settings[ $key ] ); ?>>
											فعال
										</label>
									<?php elseif ( 'number' === $field['type'] ) : ?>
										<input type="number" name="dss[<?php echo esc_attr( $key ); ?>]"
											value="<?php echo esc_attr( $settings[ $key ] ); ?>"
											min="<?php echo esc_attr( $field['min'] ); ?>"
											max="<?php echo esc_attr( $field['max'] ); ?>" class="small-text">
									<?php elseif ( 'select' === $field['type'] ) : ?>
										<select name="dss[<?php echo esc_attr( $key ); ?>]">
											<?php foreach ( $field['options'] as $value => $label ) : ?>
												<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings[ $key ], $value ); ?>>
													<?php echo esc_html( $label ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									<?php endif; ?>

									<?php if ( ! empty( $field['desc'] ) ) : ?>
										<p class="description"><?php echo wp_kses_post( $field['desc'] ); ?></p>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endforeach; ?>

				<?php submit_button( 'ذخیره تنظیمات' ); ?>
			</form>

			<h2>گزارش عملیات (۲۰۰ مورد آخر)</h2>
			<?php $log = DSS_Logger::all(); ?>
			<?php if ( empty( $log ) ) : ?>
				<p class="dss-muted">هنوز عملیاتی ثبت نشده است.</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:10px;">
					<input type="hidden" name="action" value="dss_clear_log">
					<?php wp_nonce_field( self::NONCE ); ?>
					<button type="submit" class="button">پاک کردن گزارش</button>
				</form>
				<table class="widefat striped dss-log">
					<thead><tr><th style="width:150px;">زمان</th><th style="width:80px;">نوع</th><th>پیام</th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $log, 0, 60 ) as $entry ) : ?>
						<tr class="dss-log--<?php echo esc_attr( $entry['level'] ); ?>">
							<td><?php echo esc_html( $entry['time'] ); ?></td>
							<td><?php echo esc_html( $entry['level'] ); ?></td>
							<td>
								<?php echo esc_html( $entry['message'] ); ?>
								<?php if ( ! empty( $entry['context'] ) ) : ?>
									<code class="dss-context"><?php echo esc_html( wp_json_encode( $entry['context'], JSON_UNESCAPED_UNICODE ) ); ?></code>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
