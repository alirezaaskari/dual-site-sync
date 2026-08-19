<?php
/**
 * پل ارتباطی با افزونه‌ی «Variation Swatches for WooCommerce» (نسخه رایگان و Pro).
 *
 * داده‌های این افزونه به‌صورت خام قابل انتقال بین دو سایت نیستند، چون:
 *
 *  1. تنظیمات هر محصول (_woo_variation_swatches_product_settings) با term_id
 *     کلید خورده و term_id در دو سایت یکی نیست  →  به slug ترجمه می‌شود.
 *  2. کلیدهای image_id و tooltip_image_id شناسه‌ی پیوست هستند
 *     →  به URL ترجمه می‌شوند و در مقصد دوباره به شناسه‌ی محلی برمی‌گردند.
 *  3. group_name به option سراسری woo_variation_swatches_groups ارجاع می‌دهد
 *     →  گروه‌های ارجاع‌شده همراه داده منتقل و در مقصد در صورت نبود ساخته می‌شوند.
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

class DSS_Swatches {

	const PRODUCT_META        = '_woo_variation_swatches_product_settings';
	const PRODUCT_META_LEGACY = '_wvs_product_attributes';
	const GROUPS_OPTION       = 'woo_variation_swatches_groups';

	/**
	 * کلیدهای متای ترم که مقدارشان شناسه‌ی پیوست است.
	 */
	const TERM_IMAGE_KEYS = array( 'product_attribute_image', 'tooltip_image_id' );

	/**
	 * کلیدهای متای ترم که مقدارشان متن ساده است.
	 */
	const TERM_SCALAR_KEYS = array(
		'product_attribute_color',
		'secondary_color',
		'is_dual_color',
		'image_size',
		'show_tooltip',
		'tooltip_text',
		'group_name',
	);

	/**
	 * کلیدهای تنظیمات ترم در سطح محصول که شناسه‌ی پیوست هستند.
	 * نگاشت: کلید محلی => کلید انتقالی.
	 */
	const PRODUCT_TERM_IMAGE_KEYS = array(
		'image_id'         => 'image_url',
		'tooltip_image_id' => 'tooltip_image_url',
	);

	/**
	 * آیا افزونه‌ی سواچ فعال است؟
	 *
	 * @return bool
	 */
	public static function is_active() {
		return function_exists( 'woo_variation_swatches' );
	}

	/**
	 * آیا نسخه‌ی Pro فعال است؟
	 *
	 * @return bool
	 */
	public static function is_pro() {
		return self::is_active() && method_exists( woo_variation_swatches(), 'is_pro' ) && woo_variation_swatches()->is_pro();
	}

	/* ---------------------------------------------------------------------
	 * خروجی گرفتن (سایت مبدأ)
	 * ------------------------------------------------------------------ */

	/**
	 * ساخت بسته‌ی داده‌ی سواچ برای یک محصول.
	 *
	 * @param WC_Product $product        محصول.
	 * @param bool       $include_images آیا تصاویر (سواچ تصویری/تولتیپ) منتقل شوند.
	 *
	 * @return array
	 */
	public static function export( $product, $include_images = true ) {
		if ( ! self::is_active() ) {
			return array( 'available' => false );
		}

		$product_id = $product->get_id();

		$raw = get_post_meta( $product_id, self::PRODUCT_META, true );

		if ( empty( $raw ) ) {
			$raw = get_post_meta( $product_id, self::PRODUCT_META_LEGACY, true );
		}

		$payload = array(
			'available'        => true,
			'is_pro'           => self::is_pro(),
			'product_settings' => is_array( $raw ) ? self::export_product_settings( $raw, $include_images ) : array(),
			'term_meta'        => array(),
			'groups'           => array(),
		);

		if ( DSS_Config::is_on( 'sync_term_meta' ) ) {
			$payload['term_meta'] = self::export_term_meta( $product, $include_images );
			$payload['groups']    = self::export_groups( $payload['term_meta'] );
		}

		return $payload;
	}

	/**
	 * تبدیل تنظیمات محصول به شکل قابل انتقال.
	 *
	 * @param array $settings       تنظیمات خام.
	 * @param bool  $include_images آیا تصاویر منتقل شوند.
	 *
	 * @return array
	 */
	private static function export_product_settings( array $settings, $include_images ) {
		$out = array();

		foreach ( $settings as $key => $value ) {
			// کلیدهای سطح بالا (default_to_button، catalog_mode_attribute و ...).
			if ( ! is_array( $value ) ) {
				$out[ $key ] = $value;
				continue;
			}

			$attribute = $value;

			if ( ! isset( $attribute['terms'] ) || ! is_array( $attribute['terms'] ) ) {
				$out[ $key ] = $attribute;
				continue;
			}

			$taxonomy   = taxonomy_exists( $key ) ? $key : '';
			$terms_out  = array();
			$terms_ref  = $taxonomy ? 'slug' : 'index';

			foreach ( $attribute['terms'] as $term_key => $term_settings ) {
				if ( ! is_array( $term_settings ) ) {
					continue;
				}

				$new_key = $term_key;

				if ( $taxonomy ) {
					$term = get_term( absint( $term_key ), $taxonomy );

					if ( ! $term || is_wp_error( $term ) ) {
						// ترم دیگر وجود ندارد؛ ارزش انتقال ندارد.
						continue;
					}

					$new_key = $term->slug;
				}

				$terms_out[ $new_key ] = self::export_term_settings( $term_settings, $include_images );
			}

			$attribute['terms']     = $terms_out;
			$attribute['terms_ref'] = $terms_ref;

			$out[ $key ] = $attribute;
		}

		return $out;
	}

	/**
	 * تبدیل تنظیمات یک ترم در سطح محصول.
	 *
	 * @param array $term_settings تنظیمات.
	 * @param bool  $include_images آیا تصاویر منتقل شوند.
	 *
	 * @return array
	 */
	private static function export_term_settings( array $term_settings, $include_images ) {
		foreach ( self::PRODUCT_TERM_IMAGE_KEYS as $local_key => $remote_key ) {
			if ( ! array_key_exists( $local_key, $term_settings ) ) {
				continue;
			}

			$attachment_id = absint( $term_settings[ $local_key ] );
			unset( $term_settings[ $local_key ] );

			if ( $include_images && $attachment_id ) {
				$url = DSS_Media::url( $attachment_id );

				if ( $url ) {
					$term_settings[ $remote_key ] = $url;
				}
			}
		}

		return $term_settings;
	}

	/**
	 * استخراج متای ترم‌های مورد استفاده‌ی این محصول.
	 *
	 * @param WC_Product $product        محصول.
	 * @param bool       $include_images آیا تصاویر منتقل شوند.
	 *
	 * @return array<string,array<string,array>>
	 */
	private static function export_term_meta( $product, $include_images ) {
		$out = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute->is_taxonomy() ) {
				continue;
			}

			$taxonomy = $attribute->get_taxonomy();
			$terms    = array();

			foreach ( (array) $attribute->get_options() as $term_id ) {
				$term = get_term( absint( $term_id ), $taxonomy );

				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}

				$meta = array();

				foreach ( self::TERM_SCALAR_KEYS as $meta_key ) {
					$value = get_term_meta( $term->term_id, $meta_key, true );

					if ( '' !== $value && null !== $value ) {
						$meta[ $meta_key ] = $value;
					}
				}

				if ( $include_images ) {
					foreach ( self::TERM_IMAGE_KEYS as $meta_key ) {
						$attachment_id = absint( get_term_meta( $term->term_id, $meta_key, true ) );

						if ( ! $attachment_id ) {
							continue;
						}

						$url = DSS_Media::url( $attachment_id );

						if ( $url ) {
							$meta[ $meta_key . '__url' ] = $url;
						}
					}
				}

				if ( ! empty( $meta ) ) {
					$meta['__name'] = $term->name;
					$terms[ $term->slug ] = $meta;
				}
			}

			if ( ! empty( $terms ) ) {
				$out[ $taxonomy ] = $terms;
			}
		}

		return $out;
	}

	/**
	 * گروه‌های ارجاع‌شده در متای ترم‌ها.
	 *
	 * @param array $term_meta متای ترم‌ها.
	 *
	 * @return array<string,string>
	 */
	private static function export_groups( array $term_meta ) {
		$all_groups  = (array) get_option( self::GROUPS_OPTION, array() );
		$referenced  = array();

		foreach ( $term_meta as $terms ) {
			foreach ( $terms as $meta ) {
				if ( empty( $meta['group_name'] ) ) {
					continue;
				}

				$slug = $meta['group_name'];

				if ( isset( $all_groups[ $slug ] ) ) {
					$referenced[ $slug ] = $all_groups[ $slug ];
				}
			}
		}

		return $referenced;
	}

	/* ---------------------------------------------------------------------
	 * ورودی گرفتن (سایت مقصد)
	 * ------------------------------------------------------------------ */

	/**
	 * اعمال بسته‌ی داده‌ی سواچ روی یک محصول.
	 *
	 * @param int   $product_id شناسه محصول محلی.
	 * @param array $payload    داده‌ی دریافتی.
	 *
	 * @return string[] فهرست بخش‌های به‌روزشده.
	 */
	public static function import( $product_id, $payload ) {
		$applied = array();

		if ( ! self::is_active() || empty( $payload['available'] ) ) {
			return $applied;
		}

		if ( ! empty( $payload['groups'] ) && is_array( $payload['groups'] ) ) {
			self::import_groups( $payload['groups'] );
		}

		if ( ! empty( $payload['term_meta'] ) && is_array( $payload['term_meta'] ) && DSS_Config::is_on( 'sync_term_meta' ) ) {
			if ( self::import_term_meta( $payload['term_meta'] ) ) {
				$applied[] = 'متای ترم‌های سواچ';
			}
		}

		if ( isset( $payload['product_settings'] ) && is_array( $payload['product_settings'] ) ) {
			$settings = self::import_product_settings( $payload['product_settings'], $product_id );

			if ( ! empty( $settings ) ) {
				update_post_meta( $product_id, self::PRODUCT_META, $settings );

				// همان اکشنی که خود افزونه برای پاک‌سازی کش استفاده می‌کند.
				do_action( 'woo_variation_swatches_product_settings_update', $product_id, $settings );

				$applied[] = 'تنظیمات سواچ محصول';
			}
		}

		return $applied;
	}

	/**
	 * ساخت گروه‌های نبود.
	 *
	 * @param array $groups گروه‌ها (slug => name).
	 */
	private static function import_groups( array $groups ) {
		$existing = (array) get_option( self::GROUPS_OPTION, array() );
		$changed  = false;

		foreach ( $groups as $slug => $name ) {
			$slug = sanitize_key( $slug );

			if ( '' === $slug || isset( $existing[ $slug ] ) ) {
				continue;
			}

			$existing[ $slug ] = sanitize_text_field( $name );
			$changed           = true;
		}

		if ( $changed ) {
			update_option( self::GROUPS_OPTION, $existing );
		}
	}

	/**
	 * اعمال متای ترم‌ها.
	 *
	 * @param array $term_meta داده.
	 *
	 * @return bool آیا چیزی تغییر کرد.
	 */
	private static function import_term_meta( array $term_meta ) {
		$changed = false;

		foreach ( $term_meta as $taxonomy => $terms ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			foreach ( $terms as $slug => $meta ) {
				$term = get_term_by( 'slug', $slug, $taxonomy );

				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}

				foreach ( $meta as $key => $value ) {
					if ( '__name' === $key ) {
						continue;
					}

					// کلیدهای تصویری: URL → شناسه‌ی پیوست محلی.
					if ( substr( $key, -5 ) === '__url' ) {
						$meta_key      = substr( $key, 0, -5 );
						$attachment_id = DSS_Media::resolve( $value );

						if ( $attachment_id ) {
							update_term_meta( $term->term_id, $meta_key, $attachment_id );
							$changed = true;
						}

						continue;
					}

					if ( in_array( $key, self::TERM_SCALAR_KEYS, true ) ) {
						update_term_meta( $term->term_id, $key, sanitize_text_field( $value ) );
						$changed = true;
					}
				}
			}
		}

		if ( $changed && class_exists( 'Woo_Variation_Swatches_Cache' ) ) {
			Woo_Variation_Swatches_Cache::delete_cache_group( 'woo_variation_swatches' );
		}

		return $changed;
	}

	/**
	 * ترجمه‌ی تنظیمات محصول به شناسه‌های محلی.
	 *
	 * @param array $settings   داده‌ی دریافتی.
	 * @param int   $product_id محصول مقصد.
	 *
	 * @return array
	 */
	private static function import_product_settings( array $settings, $product_id ) {
		$out = array();

		foreach ( $settings as $key => $value ) {
			if ( ! is_array( $value ) ) {
				$out[ $key ] = $value;
				continue;
			}

			$attribute = $value;

			if ( ! isset( $attribute['terms'] ) || ! is_array( $attribute['terms'] ) ) {
				$out[ $key ] = $attribute;
				continue;
			}

			$terms_ref = isset( $attribute['terms_ref'] ) ? $attribute['terms_ref'] : 'index';
			unset( $attribute['terms_ref'] );

			$taxonomy  = ( 'slug' === $terms_ref && taxonomy_exists( $key ) ) ? $key : '';
			$terms_out = array();

			foreach ( $attribute['terms'] as $term_key => $term_settings ) {
				if ( ! is_array( $term_settings ) ) {
					continue;
				}

				$local_key = $term_key;

				if ( $taxonomy ) {
					$term = get_term_by( 'slug', $term_key, $taxonomy );

					if ( ! $term || is_wp_error( $term ) ) {
						// ترم معادل در این سایت وجود ندارد؛ رد می‌شود.
						continue;
					}

					$local_key = (string) $term->term_id;
				}

				$terms_out[ $local_key ] = self::import_term_settings( $term_settings, $product_id );
			}

			$attribute['terms'] = $terms_out;
			$out[ $key ]        = $attribute;
		}

		return $out;
	}

	/**
	 * ترجمه‌ی تنظیمات یک ترم.
	 *
	 * @param array $term_settings تنظیمات.
	 * @param int   $product_id    محصول مقصد.
	 *
	 * @return array
	 */
	private static function import_term_settings( array $term_settings, $product_id ) {
		foreach ( self::PRODUCT_TERM_IMAGE_KEYS as $local_key => $remote_key ) {
			if ( ! array_key_exists( $remote_key, $term_settings ) ) {
				continue;
			}

			$url = $term_settings[ $remote_key ];
			unset( $term_settings[ $remote_key ] );

			$attachment_id = DSS_Media::resolve( $url, $product_id );

			if ( $attachment_id ) {
				$term_settings[ $local_key ] = $attachment_id;
			}
		}

		return $term_settings;
	}
}
