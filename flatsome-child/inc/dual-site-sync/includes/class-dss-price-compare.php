<?php
/**
 * مقایسه‌ی قیمت محصولات بین دو سایت.
 *
 * محصولات متصل (آن‌هایی که متای _dss_remote_id دارند) را دسته‌دسته با سایت
 * مقابل مقایسه می‌کند و هر اختلاف قیمتی را گزارش می‌دهد — هم برای محصول ساده و
 * هم برای تک‌تک واریشن‌های محصول متغیر.
 *
 * پویش به‌صورت تدریجی و با AJAX انجام می‌شود؛ هر درخواست یک دسته را می‌گیرد،
 * یک درخواست به سایت مقابل می‌زند و نتیجه را برمی‌گرداند. اینطور نه PHP تایم‌اوت
 * می‌شود و نه کاربر پشت یک صفحه‌ی سفید می‌ماند.
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

class DSS_Price_Compare {

	const PAGE       = 'dss-price-compare';
	const BATCH = 20;

	/**
	 * سقف دسته، عمداً پایین‌تر از سقف گیرنده (MAX_STATE_ITEMS) تا حاشیه‌ی امن
	 * بماند و درخواست هیچ‌وقت بریده نشود.
	 */
	const MAX_BATCH = 25;

	/**
	 * اختلاف کمتر از این مقدار، اختلاف حساب نمی‌شود (خطای ممیز شناور).
	 */
	const EPSILON = 0.0001;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'wp_ajax_dss_price_scan', array( $this, 'handle_scan' ) );
	}

	/* ------------------------------------------------------------------
	 * منو و صفحه
	 * --------------------------------------------------------------- */

	/**
	 * آدرس صفحه.
	 *
	 * @return string
	 */
	public static function url() {
		return add_query_arg( array( 'page' => self::PAGE ), admin_url( 'options-general.php' ) );
	}

	public function add_menu() {
		add_options_page(
			'مقایسه قیمت دو سایت',
			'مقایسه قیمت دو سایت',
			DSS_Settings::CAPABILITY,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/* ------------------------------------------------------------------
	 * منطق مقایسه — بدون وابستگی به وردپرس، تا قابل تست باشد
	 * --------------------------------------------------------------- */

	/**
	 * تبدیل قیمت به عدد. رشته‌ی خالی یعنی «قیمتی ثبت نشده» و با صفر فرق دارد.
	 *
	 * @param mixed $value مقدار.
	 *
	 * @return float|null
	 */
	public static function normalize_price( $value ) {
		if ( null === $value ) {
			return null;
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		if ( function_exists( 'wc_format_decimal' ) ) {
			$value = wc_format_decimal( $value );
		}

		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * آیا دو قیمت فرق دارند؟
	 *
	 * @param mixed $a قیمت اول.
	 * @param mixed $b قیمت دوم.
	 *
	 * @return bool
	 */
	public static function prices_differ( $a, $b ) {
		$a = self::normalize_price( $a );
		$b = self::normalize_price( $b );

		if ( null === $a && null === $b ) {
			return false;
		}

		if ( null === $a || null === $b ) {
			return true;
		}

		return abs( $a - $b ) > self::EPSILON;
	}

	/**
	 * امضای یکتای یک واریشن از روی ترکیب ویژگی‌هایش.
	 *
	 * شناسه‌ی واریشن در دو سایت یکی نیست، ولی ترکیب ویژگی‌ها یکی است. کلیدها
	 * مرتب می‌شوند تا ترتیب ذخیره‌سازی روی نتیجه اثر نگذارد.
	 *
	 * @param array $attributes ویژگی‌ها (کلید => نامک).
	 *
	 * @return string
	 */
	public static function variation_key( $attributes ) {
		if ( ! is_array( $attributes ) ) {
			return '';
		}

		$pairs = array();

		foreach ( $attributes as $key => $value ) {
			// پیشوند attribute_ در دو سایت ممکن است متفاوت ذخیره شده باشد.
			$key = ( 0 === strpos( (string) $key, 'attribute_' ) ) ? substr( (string) $key, 10 ) : (string) $key;

			$pairs[ strtolower( $key ) ] = strtolower( (string) $value );
		}

		ksort( $pairs );

		$out = array();

		foreach ( $pairs as $key => $value ) {
			$out[] = $key . '=' . $value;
		}

		return implode( '|', $out );
	}

	/**
	 * برچسب خوانا برای یک واریشن.
	 *
	 * @param array $attributes ویژگی‌ها.
	 *
	 * @return string
	 */
	public static function variation_label( $attributes ) {
		if ( ! is_array( $attributes ) || empty( $attributes ) ) {
			return 'واریشن';
		}

		$parts = array();

		foreach ( $attributes as $key => $value ) {
			$key   = ( 0 === strpos( (string) $key, 'attribute_' ) ) ? substr( (string) $key, 10 ) : (string) $key;
			$value = (string) $value;

			if ( '' === $value ) {
				$value = 'هر مقدار';
			} elseif ( taxonomy_exists( $key ) ) {
				$term = get_term_by( 'slug', $value, $key );

				if ( $term && ! is_wp_error( $term ) ) {
					$value = $term->name;
				}
			}

			$label   = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $key ) : $key;
			$parts[] = $label . ': ' . $value;
		}

		return implode( '، ', $parts );
	}

	/**
	 * مقایسه‌ی یک محصول محلی با همتای راه دورش.
	 *
	 * @param array $local  وضعیت محلی.
	 * @param array $remote وضعیت راه دور.
	 *
	 * @return array فهرست اختلاف‌ها؛ آرایه‌ی خالی یعنی قیمت‌ها یکی است.
	 */
	public static function diff_product( array $local, array $remote ) {
		$issues = array();

		// ---- قیمت خود محصول ----
		foreach ( array( 'regular_price' => 'قیمت عادی', 'sale_price' => 'قیمت فروش ویژه' ) as $field => $label ) {
			$local_value  = isset( $local[ $field ] ) ? $local[ $field ] : '';
			$remote_value = isset( $remote[ $field ] ) ? $remote[ $field ] : '';

			if ( self::prices_differ( $local_value, $remote_value ) ) {
				$issues[] = array(
					'scope'  => 'product',
					'label'  => $label,
					'local'  => $local_value,
					'remote' => $remote_value,
				);
			}
		}

		// ---- واریشن‌ها ----
		$local_variations  = isset( $local['variations'] ) && is_array( $local['variations'] ) ? $local['variations'] : array();
		$remote_variations = isset( $remote['variations'] ) && is_array( $remote['variations'] ) ? $remote['variations'] : array();

		if ( empty( $local_variations ) && empty( $remote_variations ) ) {
			return $issues;
		}

		$remote_by_key = array();

		foreach ( $remote_variations as $variation ) {
			$key = self::variation_key( isset( $variation['attributes'] ) ? $variation['attributes'] : array() );

			if ( '' !== $key ) {
				$remote_by_key[ $key ] = $variation;
			}
		}

		$matched = array();

		foreach ( $local_variations as $variation ) {
			$attributes = isset( $variation['attributes'] ) ? $variation['attributes'] : array();
			$key        = self::variation_key( $attributes );
			$label      = self::variation_label( $attributes );

			if ( '' === $key || ! isset( $remote_by_key[ $key ] ) ) {
				$issues[] = array(
					'scope'  => 'variation',
					'label'  => $label,
					'note'   => 'واریشن معادل در سایت مقابل پیدا نشد',
					'local'  => isset( $variation['regular_price'] ) ? $variation['regular_price'] : '',
					'remote' => null,
				);

				continue;
			}

			$matched[ $key ] = true;
			$counterpart     = $remote_by_key[ $key ];

			foreach ( array( 'regular_price' => 'قیمت عادی', 'sale_price' => 'قیمت فروش ویژه' ) as $field => $field_label ) {
				$local_value  = isset( $variation[ $field ] ) ? $variation[ $field ] : '';
				$remote_value = isset( $counterpart[ $field ] ) ? $counterpart[ $field ] : '';

				if ( self::prices_differ( $local_value, $remote_value ) ) {
					$issues[] = array(
						'scope'  => 'variation',
						'label'  => $label,
						'note'   => $field_label,
						'local'  => $local_value,
						'remote' => $remote_value,
					);
				}
			}
		}

		// واریشن‌هایی که فقط در سایت مقابل هستند.
		foreach ( $remote_by_key as $key => $variation ) {
			if ( isset( $matched[ $key ] ) ) {
				continue;
			}

			$issues[] = array(
				'scope'  => 'variation',
				'label'  => self::variation_label( isset( $variation['attributes'] ) ? $variation['attributes'] : array() ),
				'note'   => 'واریشن فقط در سایت مقابل وجود دارد',
				'local'  => null,
				'remote' => isset( $variation['regular_price'] ) ? $variation['regular_price'] : '',
			);
		}

		return $issues;
	}

	/* ------------------------------------------------------------------
	 * پویش
	 * --------------------------------------------------------------- */

	/**
	 * شناسه‌ی محصولات محلی که به سایت مقابل متصل‌اند.
	 *
	 * @return int[]
	 */
	public static function linked_product_ids() {
		global $wpdb;

		$target = DSS_Config::target_key();

		if ( ! $target ) {
			return array();
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm1.post_id
				 FROM {$wpdb->postmeta} pm1
				 INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = pm1.post_id
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm1.post_id
				 WHERE pm1.meta_key = %s AND pm1.meta_value > 0
				   AND pm2.meta_key = %s AND pm2.meta_value = %s
				   AND p.post_type = 'product'
				   AND p.post_status NOT IN ('trash','auto-draft')
				 ORDER BY pm1.post_id ASC",
				DSS_Importer::META_REMOTE_ID,
				DSS_Importer::META_REMOTE_SITE,
				$target
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * وضعیت قیمت یک محصول محلی، هم‌شکل با پاسخ سایت مقابل.
	 *
	 * @param WC_Product $product محصول.
	 *
	 * @return array
	 */
	public static function local_state( $product ) {
		$state = array(
			'id'            => $product->get_id(),
			'sku'           => (string) $product->get_sku( 'edit' ),
			'name'          => $product->get_name(),
			'type'          => $product->get_type(),
			'regular_price' => (string) $product->get_regular_price( 'edit' ),
			'sale_price'    => (string) $product->get_sale_price( 'edit' ),
			'variations'    => array(),
		);

		if ( ! $product->is_type( 'variable' ) ) {
			return $state;
		}

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation ) {
				continue;
			}

			$state['variations'][] = array(
				'id'            => $variation->get_id(),
				'sku'           => (string) $variation->get_sku( 'edit' ),
				'attributes'    => $variation->get_attributes(),
				'regular_price' => (string) $variation->get_regular_price( 'edit' ),
				'sale_price'    => (string) $variation->get_sale_price( 'edit' ),
			);
		}

		return $state;
	}

	/**
	 * هندلر AJAX یک دسته.
	 */
	public function handle_scan() {
		check_ajax_referer( DSS_Ajax::NONCE, 'nonce' );

		if ( ! current_user_can( DSS_Settings::CAPABILITY ) ) {
			wp_send_json_error( 'دسترسی ندارید.' );
		}

		$errors = DSS_Config::configuration_errors();

		if ( ! empty( $errors ) ) {
			wp_send_json_error( 'پیکربندی ناقص است: ' . implode( ' / ', $errors ) );
		}

		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$batch  = isset( $_POST['batch'] ) ? absint( wp_unslash( $_POST['batch'] ) ) : self::BATCH;
		$batch  = max( 1, min( self::MAX_BATCH, $batch ) );

		$all_ids = self::linked_product_ids();
		$total   = count( $all_ids );

		if ( 0 === $total ) {
			wp_send_json_success(
				array(
					'rows'      => array(),
					'processed' => 0,
					'total'     => 0,
					'done'      => true,
					'message'   => 'هیچ محصول متصلی پیدا نشد. اول محصولات را همگام‌سازی کنید.',
				)
			);
		}

		$slice = array_slice( $all_ids, $offset, $batch );

		if ( empty( $slice ) ) {
			wp_send_json_success( array( 'rows' => array(), 'processed' => $total, 'total' => $total, 'done' => true ) );
		}

		// نگاشت شناسه‌ی راه دور → محصول محلی.
		$locals     = array();
		$remote_ids = array();

		foreach ( $slice as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$remote_id = absint( get_post_meta( $product_id, DSS_Importer::META_REMOTE_ID, true ) );

			if ( ! $remote_id ) {
				continue;
			}

			$locals[ $remote_id ] = self::local_state( $product );
			$remote_ids[]         = $remote_id;
		}

		if ( empty( $remote_ids ) ) {
			wp_send_json_success(
				array(
					'rows'      => array(),
					'processed' => min( $offset + $batch, $total ),
					'total'     => $total,
					'done'      => ( $offset + $batch ) >= $total,
				)
			);
		}

		$response = DSS_Client::fetch_state( $remote_ids );

		if ( empty( $response['success'] ) ) {
			wp_send_json_error( $response['message'] );
		}

		if ( ! empty( $response['truncated'] ) ) {
			wp_send_json_error(
				sprintf(
					'سایت مقابل فقط %d محصول در هر درخواست می‌پذیرد ولی %d تا فرستاده شد. اندازه‌ی دسته را کمتر کنید.',
					(int) $response['max_items'],
					count( $remote_ids )
				)
			);
		}

		$remote_by_id = array();

		foreach ( (array) $response['products'] as $remote_product ) {
			if ( isset( $remote_product['id'] ) ) {
				$remote_by_id[ (int) $remote_product['id'] ] = $remote_product;
			}
		}

		$rows           = array();
		$remote_currency = '';

		foreach ( $locals as $remote_id => $local ) {
			if ( ! isset( $remote_by_id[ $remote_id ] ) ) {
				$rows[] = array(
					'id'        => $local['id'],
					'name'      => $local['name'],
					'sku'       => $local['sku'],
					'type'      => $local['type'],
					'remote_id' => $remote_id,
					'edit_link' => get_edit_post_link( $local['id'], 'raw' ),
					'missing'   => true,
					'issues'    => array(
						array(
							'scope' => 'product',
							'label' => 'محصول در سایت مقابل پیدا نشد',
							'local' => '',
							'remote' => null,
						),
					),
				);

				continue;
			}

			$remote = $remote_by_id[ $remote_id ];

			if ( '' === $remote_currency && ! empty( $remote['currency'] ) ) {
				$remote_currency = $remote['currency'];
			}

			$issues = self::diff_product( $local, $remote );

			if ( empty( $issues ) ) {
				continue;
			}

			$rows[] = array(
				'id'          => $local['id'],
				'name'        => $local['name'],
				'sku'         => $local['sku'],
				'type'        => $local['type'],
				'remote_id'   => $remote_id,
				'edit_link'   => get_edit_post_link( $local['id'], 'raw' ),
				'remote_edit' => isset( $remote['edit_link'] ) ? $remote['edit_link'] : '',
				'missing'     => false,
				'issues'      => $issues,
			);
		}

		wp_send_json_success(
			array(
				'rows'            => $rows,
				'processed'       => min( $offset + $batch, $total ),
				'total'           => $total,
				'done'            => ( $offset + $batch ) >= $total,
				'local_currency'  => get_woocommerce_currency(),
				'remote_currency' => $remote_currency,
			)
		);
	}

	/* ------------------------------------------------------------------
	 * رندر صفحه
	 * --------------------------------------------------------------- */

	public function render() {
		$errors = DSS_Config::configuration_errors();
		$target = DSS_Config::target();

		wp_enqueue_style( 'dss-admin', DSS_URL . 'assets/css/admin.css', array(), DSS_VERSION );
		wp_enqueue_script( 'dss-price-compare', DSS_URL . 'assets/js/price-compare.js', array( 'jquery' ), DSS_VERSION, true );

		wp_localize_script(
			'dss-price-compare',
			'DSS_Price',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( DSS_Ajax::NONCE ),
				'batch'      => self::BATCH,
				'targetName' => $target ? $target['label'] : '',
			)
		);
		?>
		<div class="wrap dss-settings">
			<h1>مقایسه قیمت دو سایت</h1>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="notice notice-error"><p><strong>پیکربندی ناقص است:</strong></p><ul style="list-style:disc;margin-right:20px;">
				<?php foreach ( $errors as $error ) : ?>
					<li><?php echo esc_html( $error ); ?></li>
				<?php endforeach; ?>
				</ul></div>

				<p>ابتدا پیکربندی را در <a href="<?php echo esc_url( DSS_Settings::url() ); ?>">تنظیمات همگام‌سازی</a> کامل کنید.</p>
				</div>
				<?php
				return;
			endif;
			?>

			<p class="description">
				قیمت محصولاتی که به «<?php echo esc_html( $target['label'] ); ?>» متصل‌اند بررسی می‌شود —
				هم قیمت خود محصول و هم قیمت تک‌تک واریشن‌ها. فقط مواردی که اختلاف دارند نمایش داده می‌شوند.
			</p>

			<p>
				<button type="button" class="button button-primary" id="dss-price-start">شروع بررسی</button>
				<button type="button" class="button" id="dss-price-stop" disabled>توقف</button>
				<button type="button" class="button" id="dss-price-csv" disabled>دانلود CSV</button>
			</p>

			<div id="dss-price-progress" class="dss-progress" hidden>
				<div class="dss-progress__bar"><span></span></div>
				<div class="dss-progress__text"></div>
			</div>

			<div id="dss-price-notice"></div>

			<table class="widefat striped dss-price-table" id="dss-price-results" hidden>
				<thead>
					<tr>
						<th style="width:70px;">شناسه</th>
						<th>محصول</th>
						<th style="width:110px;">SKU</th>
						<th>اختلاف</th>
						<th style="width:150px;"></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>

			<div class="dss-price-empty" id="dss-price-empty" hidden>
				<strong>هیچ اختلاف قیمتی پیدا نشد.</strong> قیمت همه‌ی محصولات متصل در دو سایت یکسان است.
			</div>
		</div>
		<?php
	}
}
