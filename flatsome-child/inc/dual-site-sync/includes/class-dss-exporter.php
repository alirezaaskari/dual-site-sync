<?php
/**
 * ساخت بسته‌ی داده برای ارسال به سایت مقابل.
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

class DSS_Exporter {

	/**
	 * حالت‌های مجاز.
	 */
	const MODES = array( 'create', 'full', 'full_no_images', 'partial', 'stock' );

	/**
	 * آیا این حالت تصاویر را منتقل می‌کند؟
	 *
	 * @param string $mode حالت.
	 *
	 * @return bool
	 */
	public static function mode_includes_images( $mode ) {
		return in_array( $mode, array( 'create', 'full', 'partial' ), true );
	}

	/**
	 * آیا این حالت فیلدهای محتوایی (نام/توضیحات/وضعیت) را منتقل می‌کند؟
	 *
	 * @param string $mode حالت.
	 *
	 * @return bool
	 */
	public static function mode_includes_content( $mode ) {
		return in_array( $mode, array( 'create', 'full', 'full_no_images' ), true );
	}

	/**
	 * آیا این حالت فیلدهای موجودی را منتقل می‌کند؟
	 *
	 * @param string $mode حالت.
	 *
	 * @return bool
	 */
	public static function mode_includes_stock( $mode ) {
		if ( 'stock' === $mode ) {
			return true;
		}

		return DSS_Config::is_on( 'sync_stock_fields' ) || DSS_Config::is_on( 'shared_inventory' );
	}

	/**
	 * برچسب فارسی حالت.
	 *
	 * @param string $mode حالت.
	 *
	 * @return string
	 */
	public static function mode_label( $mode ) {
		$labels = array(
			'create'         => 'ایجاد محصول',
			'full'           => 'همگام‌سازی کامل',
			'full_no_images' => 'همگام‌سازی بدون تصاویر',
			'partial'        => 'به‌روزرسانی جزئی',
			'stock'          => 'همگام‌سازی موجودی',
		);

		return isset( $labels[ $mode ] ) ? $labels[ $mode ] : $mode;
	}

	/**
	 * ساخت بسته.
	 *
	 * @param WC_Product $product محصول.
	 * @param string     $mode    حالت.
	 *
	 * @return array
	 */
	public static function build( $product, $mode ) {
		$product_id     = $product->get_id();
		$with_images    = self::mode_includes_images( $mode );
		$with_content   = self::mode_includes_content( $mode );
		$with_stock     = self::mode_includes_stock( $mode );
		$is_variable    = $product->is_type( 'variable' );

		$payload = array(
			'dss_version' => DSS_VERSION,
			'mode'        => $mode,
			'source_site' => DSS_Config::current_key(),
			'source_id'   => $product_id,
			'source_url'  => get_permalink( $product_id ),
			'source_edit' => get_edit_post_link( $product_id, 'raw' ),
			'type'        => $product->get_type(),
			'sku'         => $product->get_sku(),
			'flags'       => array(
				'images'  => $with_images,
				'content' => $with_content,
				'stock'   => $with_stock,
			),
			'product'     => array(),
		);

		// ---- فیلدهای همیشگی: قیمت و توضیح کوتاه ----
		$payload['product']['regular_price']     = $product->get_regular_price();
		$payload['product']['sale_price']        = $product->get_sale_price();
		$payload['product']['date_on_sale_from'] = $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->getTimestamp() : null;
		$payload['product']['date_on_sale_to']   = $product->get_date_on_sale_to() ? $product->get_date_on_sale_to()->getTimestamp() : null;
		$payload['product']['short_description'] = $product->get_short_description();

		if ( 'stock' === $mode ) {
			$payload['product'] = array();
		}

		// ---- موجودی ----
		if ( $with_stock ) {
			$payload['product']['manage_stock']   = $product->get_manage_stock();
			$payload['product']['stock_quantity'] = $product->get_stock_quantity();
			$payload['product']['stock_status']   = $product->get_stock_status();
			$payload['product']['backorders']     = $product->get_backorders();
			$payload['product']['low_stock_amount'] = $product->get_low_stock_amount();
		}

		// ---- محتوا ----
		if ( $with_content ) {
			$payload['product']['name']               = $product->get_name();
			$payload['product']['description']        = $product->get_description();
			$payload['product']['menu_order']         = $product->get_menu_order();
			$payload['product']['catalog_visibility'] = $product->get_catalog_visibility();
			$payload['product']['featured']           = $product->get_featured();
			$payload['product']['tax_status']         = $product->get_tax_status();
			$payload['product']['tax_class']          = $product->get_tax_class();
			$payload['product']['weight']             = $product->get_weight();
			$payload['product']['length']             = $product->get_length();
			$payload['product']['width']              = $product->get_width();
			$payload['product']['height']             = $product->get_height();
			$payload['product']['purchase_note']      = $product->get_purchase_note();

			if ( DSS_Config::is_on( 'sync_status' ) || 'create' === $mode ) {
				$payload['product']['status'] = $product->get_status();
			}

			// نامک فقط هنگام ایجاد منتقل می‌شود تا آدرس محصول در مقصد پایدار بماند.
			if ( 'create' === $mode ) {
				$payload['product']['slug'] = $product->get_slug();
			}
		}

		// ---- دسته‌بندی و برچسب ----
		if ( 'stock' !== $mode && DSS_Config::is_on( 'sync_categories' ) ) {
			$payload['categories'] = self::export_terms( $product_id, 'product_cat' );
			$payload['tags']       = self::export_terms( $product_id, 'product_tag' );
		}

		// ---- برندها ----
		if ( 'stock' !== $mode && DSS_Config::is_on( 'sync_brands' ) ) {
			$brands = array();

			foreach ( self::brand_taxonomies() as $taxonomy ) {
				$terms = self::export_terms( $product_id, $taxonomy );

				if ( ! empty( $terms ) ) {
					$brands[ $taxonomy ] = $terms;
				}
			}

			$payload['brands'] = $brands;
		}

		// ---- تصاویر ----
		if ( $with_images ) {
			$payload['images'] = array(
				'featured' => DSS_Media::featured_url( $product_id ),
				'gallery'  => array_values( array_filter( array_map(
					array( 'DSS_Media', 'url' ),
					$product->get_gallery_image_ids()
				) ) ),
			);
		}

		// ---- ویژگی‌ها و واریشن‌ها ----
		if ( $is_variable && 'stock' !== $mode ) {
			$payload['attributes'] = self::export_attributes( $product );
		}

		if ( $is_variable ) {
			$payload['variations'] = self::export_variations( $product, $mode );
		}

		// ---- افزونه‌ی سواچ ----
		if ( 'stock' !== $mode && DSS_Config::is_on( 'sync_swatches' ) ) {
			$payload['swatches'] = DSS_Swatches::export( $product, $with_images );
		}

		return apply_filters( 'dss_export_payload', $payload, $product, $mode );
	}

	/**
	 * تاکسونومی‌های برند که ممکن است روی سایت فعال باشند.
	 *
	 * ووکامرس ۹.۴ به بعد product_brand را به‌صورت داخلی دارد؛ نسخه‌های قدیمی‌تر
	 * از افزونه‌های جانبی استفاده می‌کنند که هرکدام نام تاکسونومی خودشان را
	 * دارند. فقط آن‌هایی که واقعاً ثبت شده‌اند منتقل می‌شوند.
	 *
	 * @return string[]
	 */
	public static function brand_taxonomies() {
		$candidates = array(
			'product_brand',       // ووکامرس داخلی و افزونه‌ی WooCommerce Brands
			'pwb-brand',           // Perfect WooCommerce Brands
			'yith_product_brand',  // YITH WooCommerce Brands
			'berocket_brand',      // BeRocket Brands
		);

		$active = array();

		foreach ( apply_filters( 'dss_brand_taxonomies', $candidates ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$active[] = $taxonomy;
			}
		}

		return $active;
	}

	/**
	 * خروجی ترم‌های یک تاکسونومی به‌همراه سلسله‌مراتب، مرتب‌شده از ریشه به برگ.
	 *
	 * @param int    $product_id محصول.
	 * @param string $taxonomy   تاکسونومی.
	 *
	 * @return array
	 */
	private static function export_terms( $product_id, $taxonomy ) {
		$terms = wp_get_post_terms( $product_id, $taxonomy );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$collected = array();

		foreach ( $terms as $term ) {
			self::collect_term_with_ancestors( $term, $taxonomy, $collected );
		}

		// مرتب‌سازی: والدها قبل از فرزندان.
		usort(
			$collected,
			function ( $a, $b ) {
				return $a['depth'] <=> $b['depth'];
			}
		);

		return array_values( $collected );
	}

	/**
	 * افزودن ترم و همه‌ی نیاکانش به فهرست.
	 *
	 * @param WP_Term $term      ترم.
	 * @param string  $taxonomy  تاکسونومی.
	 * @param array   $collected خروجی (ارجاعی).
	 */
	private static function collect_term_with_ancestors( $term, $taxonomy, array &$collected ) {
		$chain = array();
		$node  = $term;
		$guard = 0;

		while ( $node && $guard < 20 ) {
			array_unshift( $chain, $node );

			if ( ! $node->parent ) {
				break;
			}

			$node = get_term( $node->parent, $taxonomy );

			if ( is_wp_error( $node ) ) {
				break;
			}

			$guard++;
		}

		foreach ( $chain as $depth => $item ) {
			if ( isset( $collected[ $item->term_id ] ) ) {
				continue;
			}

			$parent      = $item->parent ? get_term( $item->parent, $taxonomy ) : null;
			$parent_slug = ( $parent && ! is_wp_error( $parent ) ) ? $parent->slug : '';

			$collected[ $item->term_id ] = array(
				'slug'        => $item->slug,
				'name'        => $item->name,
				'parent_slug' => $parent_slug,
				'depth'       => $depth,
				'assigned'    => ( $item->term_id === $term->term_id ),
			);
		}

		// اگر ترم قبلاً به‌عنوان نیای ترم دیگری ثبت شده بود، پرچم assigned را حفظ کن.
		if ( isset( $collected[ $term->term_id ] ) ) {
			$collected[ $term->term_id ]['assigned'] = true;
		}
	}

	/**
	 * خروجی ویژگی‌های محصول.
	 *
	 * @param WC_Product $product محصول.
	 *
	 * @return array
	 */
	private static function export_attributes( $product ) {
		$out = array();

		foreach ( $product->get_attributes() as $attribute ) {
			$is_taxonomy = $attribute->is_taxonomy();
			$options     = array();

			if ( $is_taxonomy ) {
				foreach ( (array) $attribute->get_options() as $term_id ) {
					$term = get_term( absint( $term_id ), $attribute->get_taxonomy() );

					if ( $term && ! is_wp_error( $term ) ) {
						$options[] = array(
							'slug' => $term->slug,
							'name' => $term->name,
						);
					}
				}
			} else {
				$options = array_values( (array) $attribute->get_options() );
			}

			$taxonomy_object = $is_taxonomy ? $attribute->get_taxonomy_object() : null;

			$out[] = array(
				'name'        => wc_attribute_label( $attribute->get_name() ),
				'slug'        => $is_taxonomy ? $attribute->get_taxonomy() : sanitize_title( $attribute->get_name() ),
				'raw_name'    => $attribute->get_name(),
				'is_taxonomy' => $is_taxonomy,
				'type'        => $taxonomy_object ? $taxonomy_object->attribute_type : 'select',
				'orderby'     => $taxonomy_object ? $taxonomy_object->attribute_orderby : 'menu_order',
				'position'    => $attribute->get_position(),
				'visible'     => $attribute->get_visible(),
				'variation'   => $attribute->get_variation(),
				'options'     => $options,
			);
		}

		return $out;
	}

	/**
	 * خروجی واریشن‌ها.
	 *
	 * @param WC_Product_Variable $product محصول.
	 * @param string              $mode    حالت.
	 *
	 * @return array
	 */
	private static function export_variations( $product, $mode ) {
		$with_images  = self::mode_includes_images( $mode );
		$with_content = self::mode_includes_content( $mode );
		$with_stock   = self::mode_includes_stock( $mode );
		$out          = array();

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$row = array(
				'source_variation_id' => $variation_id,
				'sku'                 => $variation->get_sku(),
				'attributes'          => $variation->get_variation_attributes(),
				'regular_price'       => $variation->get_regular_price(),
				'sale_price'          => $variation->get_sale_price(),
			);

			if ( 'stock' === $mode ) {
				$row = array(
					'source_variation_id' => $variation_id,
					'sku'                 => $variation->get_sku(),
					'attributes'          => $variation->get_variation_attributes(),
				);
			}

			if ( $with_stock ) {
				$row['manage_stock']   = $variation->get_manage_stock();
				$row['stock_quantity'] = $variation->get_stock_quantity();
				$row['stock_status']   = $variation->get_stock_status();
				$row['backorders']     = $variation->get_backorders();
			}

			if ( $with_content ) {
				$row['status']      = $variation->get_status();
				$row['description'] = $variation->get_description();
				$row['menu_order']  = $variation->get_menu_order();
				$row['weight']      = $variation->get_weight();
				$row['length']      = $variation->get_length();
				$row['width']       = $variation->get_width();
				$row['height']      = $variation->get_height();
			}

			if ( $with_images && DSS_Config::is_on( 'sync_variation_images' ) ) {
				$row['image'] = DSS_Media::url( $variation->get_image_id() );

				// تصاویر اضافی واریشن (افزونه‌ی Additional Variation Images و مشابه‌ها).
				$extra = DSS_Variation_Gallery::export( $variation_id );

				if ( ! empty( $extra ) ) {
					$row['extra_images'] = $extra;
				}
			}

			$out[] = $row;
		}

		return $out;
	}
}
