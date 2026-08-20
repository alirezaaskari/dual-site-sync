<?php
/**
 * اعمال بسته‌ی داده‌ی دریافتی روی محصول محلی.
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

class DSS_Importer {

	const META_REMOTE_ID     = '_dss_remote_id';
	const META_REMOTE_SITE   = '_dss_remote_site';
	const META_CREATED_BY    = '_dss_created_by_site';
	const META_LAST_SYNC     = '_dss_last_sync';

	/** کلیدهای نسخه‌ی قدیمی برای سازگاری رو به عقب. */
	const LEGACY_SOURCE_ID   = '_source_id';
	const LEGACY_SOURCE_SITE = '_source_site';

	/**
	 * پردازش یک بسته.
	 *
	 * @param array $payload بسته‌ی دریافتی.
	 *
	 * @return array{success:bool,message:string,id:int,sections:string[]}
	 * @throws Exception در صورت خطای غیرقابل جبران.
	 */
	public static function handle( array $payload ) {
		$mode        = isset( $payload['mode'] ) ? sanitize_key( $payload['mode'] ) : 'full';
		$source_site = isset( $payload['source_site'] ) ? sanitize_key( $payload['source_site'] ) : '';
		$source_id   = isset( $payload['source_id'] ) ? absint( $payload['source_id'] ) : 0;
		$sku         = isset( $payload['sku'] ) ? (string) $payload['sku'] : '';

		if ( ! $source_site || ! $source_id ) {
			throw new Exception( 'بسته‌ی دریافتی ناقص است (source_site یا source_id ندارد).' );
		}

		if ( ! in_array( $mode, DSS_Exporter::MODES, true ) ) {
			throw new Exception( 'حالت همگام‌سازی نامعتبر است: ' . $mode );
		}

		$existing_id = self::find_linked_product( $source_id, $source_site );
		$linked_note = '';

		// اتصال خودکار با SKU اگر پیوند قبلی وجود نداشت.
		if ( ! $existing_id && $sku && 'create' !== $mode ) {
			$candidate = self::find_product_by_sku( $sku );

			if ( $candidate ) {
				self::link( $candidate, $source_id, $source_site );
				$existing_id = $candidate;
				$linked_note = ' (اتصال خودکار با SKU انجام شد)';
			}
		}

		if ( ! $existing_id ) {
			if ( 'create' !== $mode ) {
				return array(
					'success'  => false,
					'message'  => 'محصول معادل در این سایت پیدا نشد. ابتدا «ایجاد محصول در سایت دیگر» را بزنید.',
					'id'       => 0,
					'sections' => array(),
				);
			}

			if ( $sku && self::find_product_by_sku( $sku ) ) {
				return array(
					'success'  => false,
					'message'  => sprintf( 'محصولی با SKU «%s» از قبل وجود دارد؛ برای جلوگیری از تکرار، ایجاد انجام نشد.', $sku ),
					'id'       => 0,
					'sections' => array(),
				);
			}
		}

		return DSS_Context::run(
			function () use ( $payload, $mode, $source_site, $source_id, $existing_id, $linked_note ) {
				return $existing_id
					? self::update_existing( $existing_id, $payload, $mode, $source_site, $source_id, $linked_note )
					: self::create_new( $payload, $source_site, $source_id );
			}
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * به‌روزرسانی محصول موجود.
	 */
	private static function update_existing( $product_id, array $payload, $mode, $source_site, $source_id, $linked_note ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			throw new Exception( sprintf( 'محصول محلی %d قابل بارگذاری نیست.', $product_id ) );
		}

		$sections = self::apply( $product, $payload, $mode, $source_site );

		self::link( $product_id, $source_id, $source_site );

		return array(
			'success'  => true,
			'message'  => sprintf(
				'به‌روزرسانی شد (%s): %s — شناسه %d%s',
				DSS_Exporter::mode_label( $mode ),
				implode( '، ', array_unique( $sections ) ),
				$product_id,
				$linked_note
			),
			'id'       => $product_id,
			'sections' => $sections,
		);
	}

	/**
	 * ساخت محصول جدید.
	 */
	private static function create_new( array $payload, $source_site, $source_id ) {
		$type    = isset( $payload['type'] ) ? sanitize_key( $payload['type'] ) : 'simple';
		$product = ( 'variable' === $type ) ? new WC_Product_Variable() : new WC_Product_Simple();

		// حداقل داده برای گرفتن شناسه.
		$product->set_name( isset( $payload['product']['name'] ) ? $payload['product']['name'] : 'محصول بدون نام' );
		$product->set_status( 'draft' );

		/*
		 * SKU پیش از نخستین ذخیره تنظیم می‌شود.
		 *
		 * دلیل: مولدهای SKU که روی save_post_product می‌نشینند (مثل مولد
		 * اختصاصی این پروژه) وقتی محصول تازه ذخیره‌شده SKU نداشته باشد،
		 * یک شماره‌ی محلی به آن می‌دهند. با ست کردن SKU قبل از save، آن
		 * مولدها خودشان کنار می‌کشند و شماره‌ی سایت مبدأ دست‌نخورده می‌ماند.
		 */
		if ( isset( $payload['sku'] ) && '' !== $payload['sku'] ) {
			try {
				$product->set_sku( wc_clean( $payload['sku'] ) );
			} catch ( Exception $e ) {
				DSS_Logger::warning( 'تنظیم SKU اولیه ناموفق بود.', array(
					'sku'   => $payload['sku'],
					'error' => $e->getMessage(),
				) );
			}
		}

		$product->save();

		$product_id = $product->get_id();

		if ( ! $product_id ) {
			throw new Exception( 'ساخت محصول جدید ناموفق بود.' );
		}

		update_post_meta( $product_id, self::META_CREATED_BY, $source_site );

		$product  = wc_get_product( $product_id );
		$sections = self::apply( $product, $payload, 'create', $source_site );

		self::link( $product_id, $source_id, $source_site );

		return array(
			'success'  => true,
			'message'  => sprintf(
				'محصول جدید ساخته شد: %s — شناسه %d',
				implode( '، ', array_unique( $sections ) ),
				$product_id
			),
			'id'       => $product_id,
			'sections' => $sections,
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * اعمال داده روی شیء محصول.
	 *
	 * @return string[] بخش‌های به‌روزشده.
	 */
	private static function apply( $product, array $payload, $mode, $source_site ) {
		$sections   = array();
		$product_id = $product->get_id();
		$data       = isset( $payload['product'] ) && is_array( $payload['product'] ) ? $payload['product'] : array();
		$flags      = isset( $payload['flags'] ) ? (array) $payload['flags'] : array();
		$with_images = ! empty( $flags['images'] );

		// ---- SKU ----
		if ( isset( $payload['sku'] ) && '' !== $payload['sku'] ) {
			try {
				$product->set_sku( wc_clean( $payload['sku'] ) );
			} catch ( Exception $e ) {
				DSS_Logger::warning( 'تنظیم SKU ناموفق بود.', array( 'sku' => $payload['sku'], 'error' => $e->getMessage() ) );
			}
		}

		// ---- قیمت‌ها و توضیح کوتاه ----
		$price_touched = false;

		foreach ( array( 'regular_price', 'sale_price', 'short_description', 'name', 'description', 'slug',
			'menu_order', 'catalog_visibility', 'featured', 'tax_status', 'tax_class', 'weight',
			'length', 'width', 'height', 'purchase_note', 'status' ) as $field ) {

			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}

			$setter = 'set_' . $field;

			if ( ! method_exists( $product, $setter ) ) {
				continue;
			}

			$value = $data[ $field ];

			if ( in_array( $field, array( 'regular_price', 'sale_price' ), true ) ) {
				$value         = ( null === $value ) ? '' : $value;
				$price_touched = true;
			}

			$product->{$setter}( $value );
		}

		if ( array_key_exists( 'date_on_sale_from', $data ) ) {
			$product->set_date_on_sale_from( $data['date_on_sale_from'] ? absint( $data['date_on_sale_from'] ) : null );
		}

		if ( array_key_exists( 'date_on_sale_to', $data ) ) {
			$product->set_date_on_sale_to( $data['date_on_sale_to'] ? absint( $data['date_on_sale_to'] ) : null );
		}

		if ( $price_touched ) {
			$sections[] = 'قیمت';
		}

		if ( array_key_exists( 'short_description', $data ) || array_key_exists( 'description', $data ) ) {
			$sections[] = 'توضیحات';
		}

		if ( array_key_exists( 'name', $data ) ) {
			$sections[] = 'نام';
		}

		// ---- موجودی ----
		if ( ! empty( $flags['stock'] ) ) {
			if ( array_key_exists( 'manage_stock', $data ) ) {
				$product->set_manage_stock( (bool) $data['manage_stock'] );
			}

			if ( array_key_exists( 'stock_quantity', $data ) ) {
				$product->set_stock_quantity( null === $data['stock_quantity'] ? null : wc_stock_amount( $data['stock_quantity'] ) );
			}

			if ( array_key_exists( 'stock_status', $data ) && $data['stock_status'] ) {
				$product->set_stock_status( $data['stock_status'] );
			}

			if ( array_key_exists( 'backorders', $data ) && $data['backorders'] ) {
				$product->set_backorders( $data['backorders'] );
			}

			if ( array_key_exists( 'low_stock_amount', $data ) ) {
				$product->set_low_stock_amount( '' === $data['low_stock_amount'] ? '' : $data['low_stock_amount'] );
			}

			$sections[] = 'موجودی';
		}

		// ---- ویژگی‌ها ----
		if ( ! empty( $payload['attributes'] ) && is_array( $payload['attributes'] ) ) {
			$attributes = self::prepare_attributes( $payload['attributes'] );

			if ( ! empty( $attributes ) ) {
				$product->set_attributes( $attributes );
				$sections[] = 'ویژگی‌ها';
			}
		}

		$product->save();

		// ---- دسته‌بندی و برچسب (نیازمند شناسه) ----
		if ( isset( $payload['categories'] ) && is_array( $payload['categories'] ) ) {
			if ( self::apply_terms( $product_id, 'product_cat', $payload['categories'] ) ) {
				$sections[] = 'دسته‌بندی‌ها';
			}
		}

		if ( isset( $payload['tags'] ) && is_array( $payload['tags'] ) ) {
			if ( self::apply_terms( $product_id, 'product_tag', $payload['tags'] ) ) {
				$sections[] = 'برچسب‌ها';
			}
		}

		// ---- برندها ----
		if ( isset( $payload['brands'] ) && is_array( $payload['brands'] ) && DSS_Config::is_on( 'sync_brands' ) ) {
			$brands_applied = false;

			foreach ( $payload['brands'] as $taxonomy => $terms ) {
				if ( ! is_array( $terms ) || ! taxonomy_exists( $taxonomy ) ) {
					// تاکسونومی برند در این سایت ثبت نشده؛ ساختنش کار DSS نیست.
					DSS_Logger::warning( 'تاکسونومی برند در سایت مقصد وجود ندارد؛ رد شد.', array( 'taxonomy' => $taxonomy ) );
					continue;
				}

				if ( self::apply_terms( $product_id, $taxonomy, $terms ) ) {
					$brands_applied = true;
				}
			}

			if ( $brands_applied ) {
				$sections[] = 'برندها';
			}
		}

		// ---- تصاویر ----
		if ( $with_images && isset( $payload['images'] ) && is_array( $payload['images'] ) ) {
			if ( self::apply_images( $product_id, $payload['images'] ) ) {
				$sections[] = 'تصاویر';
			}
		}

		// ---- واریشن‌ها ----
		if ( ! empty( $payload['variations'] ) && is_array( $payload['variations'] ) && $product->is_type( 'variable' ) ) {
			$count = self::sync_variations( $product_id, $payload['variations'], $source_site, $with_images, ! empty( $flags['stock'] ) );

			if ( $count ) {
				$sections[] = sprintf( 'واریشن‌ها (%d مورد)', $count );
			}
		}

		// ---- افزونه‌ی سواچ ----
		if ( isset( $payload['swatches'] ) && is_array( $payload['swatches'] ) && DSS_Config::is_on( 'sync_swatches' ) ) {
			$sections = array_merge( $sections, DSS_Swatches::import( $product_id, $payload['swatches'] ) );
		}

		// ---- پاک‌سازی کش ----
		if ( $product->is_type( 'variable' ) ) {
			WC_Product_Variable::sync( $product_id );
		}

		// بدون این، محصول در دیتابیس به‌روز می‌شود ولی صفحه‌ای که مشتری
		// می‌بیند از کش می‌آید و همگام‌سازی «انجام‌نشده» به نظر می‌رسد.
		DSS_Cache::purge_product( $product_id );

		if ( empty( $sections ) ) {
			$sections[] = 'بدون تغییر قابل ذکر';
		}

		do_action( 'dss_after_import', $product_id, $payload, $mode, $source_site );

		return $sections;
	}

	/* ------------------------------------------------------------------ */

	/**
	 * ساخت/تخصیص ترم‌های تاکسونومی با حفظ سلسله‌مراتب.
	 *
	 * @param int    $product_id محصول.
	 * @param string $taxonomy   تاکسونومی.
	 * @param array  $terms      داده‌ی ترم‌ها.
	 *
	 * @return bool
	 */
	private static function apply_terms( $product_id, $taxonomy, array $terms ) {
		if ( empty( $terms ) ) {
			return false;
		}

		$create_missing = DSS_Config::is_on( 'create_missing_categories' );
		$slug_to_id     = array();
		$assigned_ids   = array();

		foreach ( $terms as $term_data ) {
			if ( empty( $term_data['slug'] ) ) {
				continue;
			}

			$slug     = sanitize_title( $term_data['slug'] );
			$existing = get_term_by( 'slug', $slug, $taxonomy );

			if ( $existing && ! is_wp_error( $existing ) ) {
				$slug_to_id[ $slug ] = (int) $existing->term_id;
			} elseif ( $create_missing ) {
				$parent_slug = ! empty( $term_data['parent_slug'] ) ? sanitize_title( $term_data['parent_slug'] ) : '';
				$parent_id   = 0;

				if ( $parent_slug ) {
					if ( isset( $slug_to_id[ $parent_slug ] ) ) {
						$parent_id = $slug_to_id[ $parent_slug ];
					} else {
						$parent_term = get_term_by( 'slug', $parent_slug, $taxonomy );

						if ( $parent_term && ! is_wp_error( $parent_term ) ) {
							$parent_id = (int) $parent_term->term_id;
						}
					}
				}

				$created = wp_insert_term(
					isset( $term_data['name'] ) ? $term_data['name'] : $slug,
					$taxonomy,
					array(
						'slug'   => $slug,
						'parent' => $parent_id,
					)
				);

				if ( is_wp_error( $created ) ) {
					DSS_Logger::warning( 'ساخت ترم ناموفق بود.', array(
						'taxonomy' => $taxonomy,
						'slug'     => $slug,
						'error'    => $created->get_error_message(),
					) );
					continue;
				}

				$slug_to_id[ $slug ] = (int) $created['term_id'];
			} else {
				continue;
			}

			// فقط ترم‌هایی که واقعاً به محصول مبدأ تخصیص داده شده‌اند.
			if ( ! isset( $term_data['assigned'] ) || $term_data['assigned'] ) {
				$assigned_ids[] = $slug_to_id[ $slug ];
			}
		}

		if ( empty( $assigned_ids ) ) {
			return false;
		}

		wp_set_object_terms( $product_id, array_values( array_unique( $assigned_ids ) ), $taxonomy, false );

		return true;
	}

	/**
	 * اعمال تصویر شاخص و گالری.
	 *
	 * @param int   $product_id محصول.
	 * @param array $images     آرایه‌ی تصاویر.
	 *
	 * @return bool
	 */
	private static function apply_images( $product_id, array $images ) {
		$changed = false;
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return false;
		}

		if ( ! empty( $images['featured'] ) ) {
			$attachment_id = DSS_Media::resolve( $images['featured'], $product_id );

			if ( $attachment_id ) {
				$product->set_image_id( $attachment_id );
				$changed = true;
			}
		}

		if ( isset( $images['gallery'] ) && is_array( $images['gallery'] ) ) {
			$gallery_ids = DSS_Media::resolve_many( $images['gallery'], $product_id );
			$product->set_gallery_image_ids( $gallery_ids );
			$changed = true;
		}

		if ( $changed ) {
			$product->save();
		}

		return $changed;
	}

	/**
	 * آماده‌سازی اشیاء ویژگی.
	 *
	 * @param array $attributes_data داده‌ی ویژگی‌ها.
	 *
	 * @return WC_Product_Attribute[]
	 */
	private static function prepare_attributes( array $attributes_data ) {
		$prepared = array();

		foreach ( $attributes_data as $attr_data ) {
			if ( empty( $attr_data['slug'] ) && empty( $attr_data['name'] ) ) {
				continue;
			}

			$attribute   = new WC_Product_Attribute();
			$is_taxonomy = ! empty( $attr_data['is_taxonomy'] );

			if ( $is_taxonomy ) {
				$taxonomy = $attr_data['slug'];
				$attr_id  = wc_attribute_taxonomy_id_by_name( $taxonomy );

				if ( ! $attr_id ) {
					$attr_id = self::create_attribute_taxonomy( $attr_data, $taxonomy );

					if ( ! $attr_id ) {
						continue;
					}
				}

				if ( ! taxonomy_exists( $taxonomy ) ) {
					register_taxonomy(
						$taxonomy,
						apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy, array( 'product' ) ),
						apply_filters( 'woocommerce_taxonomy_args_' . $taxonomy, array( 'hierarchical' => true, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ) )
					);
				}

				$term_ids = array();

				foreach ( (array) $attr_data['options'] as $option ) {
					$slug = is_array( $option ) ? ( isset( $option['slug'] ) ? $option['slug'] : '' ) : $option;
					$name = is_array( $option ) ? ( isset( $option['name'] ) ? $option['name'] : $slug ) : $option;

					$slug = sanitize_title( $slug );

					if ( '' === $slug ) {
						continue;
					}

					$term = get_term_by( 'slug', $slug, $taxonomy );

					if ( $term && ! is_wp_error( $term ) ) {
						$term_ids[] = (int) $term->term_id;
						continue;
					}

					$inserted = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );

					if ( is_wp_error( $inserted ) ) {
						DSS_Logger::warning( 'ساخت ترم ویژگی ناموفق بود.', array(
							'taxonomy' => $taxonomy,
							'slug'     => $slug,
							'error'    => $inserted->get_error_message(),
						) );
						continue;
					}

					$term_ids[] = (int) $inserted['term_id'];
				}

				if ( empty( $term_ids ) ) {
					continue;
				}

				$attribute->set_id( $attr_id );
				$attribute->set_name( $taxonomy );
				$attribute->set_options( $term_ids );
			} else {
				$attribute->set_id( 0 );
				$attribute->set_name( isset( $attr_data['raw_name'] ) ? $attr_data['raw_name'] : $attr_data['name'] );
				$attribute->set_options( (array) $attr_data['options'] );
			}

			$attribute->set_position( isset( $attr_data['position'] ) ? absint( $attr_data['position'] ) : 0 );
			$attribute->set_visible( ! empty( $attr_data['visible'] ) );
			$attribute->set_variation( ! empty( $attr_data['variation'] ) );

			$prepared[] = $attribute;
		}

		return $prepared;
	}

	/**
	 * ساخت تاکسونومی ویژگی در ووکامرس.
	 *
	 * @param array  $attr_data داده.
	 * @param string $taxonomy  نام تاکسونومی (pa_xxx).
	 *
	 * @return int شناسه ویژگی یا 0.
	 */
	private static function create_attribute_taxonomy( array $attr_data, $taxonomy ) {
		$created = wc_create_attribute(
			array(
				'name'         => isset( $attr_data['name'] ) ? $attr_data['name'] : $taxonomy,
				'slug'         => $taxonomy,
				'type'         => isset( $attr_data['type'] ) ? $attr_data['type'] : 'select',
				'order_by'     => isset( $attr_data['orderby'] ) ? $attr_data['orderby'] : 'menu_order',
				'has_archives' => false,
			)
		);

		if ( is_wp_error( $created ) ) {
			DSS_Logger::warning( 'ساخت ویژگی ناموفق بود.', array(
				'taxonomy' => $taxonomy,
				'error'    => $created->get_error_message(),
			) );

			return 0;
		}

		delete_transient( 'wc_attribute_taxonomies' );

		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		}

		return (int) $created;
	}

	/**
	 * همگام‌سازی واریشن‌ها.
	 *
	 * @param int    $parent_id       والد.
	 * @param array  $variations_data داده.
	 * @param string $source_site     سایت مبدأ.
	 * @param bool   $with_images     انتقال تصاویر.
	 * @param bool   $with_stock      انتقال موجودی.
	 *
	 * @return int تعداد واریشن پردازش‌شده.
	 */
	private static function sync_variations( $parent_id, array $variations_data, $source_site, $with_images, $with_stock ) {
		$parent = wc_get_product( $parent_id );

		if ( ! $parent ) {
			return 0;
		}

		$touched              = array();
		$existing             = $parent->get_children();
		$extra_images_touched = false;

		foreach ( $variations_data as $var_data ) {
			$source_var_id = isset( $var_data['source_variation_id'] ) ? absint( $var_data['source_variation_id'] ) : 0;
			$var_sku       = isset( $var_data['sku'] ) ? (string) $var_data['sku'] : '';
			$var_attrs     = isset( $var_data['attributes'] ) && is_array( $var_data['attributes'] ) ? $var_data['attributes'] : array();

			$var_id = $source_var_id ? self::find_linked_product( $source_var_id, $source_site ) : 0;

			// تطبیق با SKU (فقط اگر واریشنِ همین والد باشد).
			if ( ! $var_id && $var_sku ) {
				$candidate = self::find_product_by_sku( $var_sku, 'product_variation' );

				if ( $candidate && wp_get_post_parent_id( $candidate ) === (int) $parent_id ) {
					$var_id = $candidate;
				}
			}

			// تطبیق با ترکیب ویژگی‌ها.
			if ( ! $var_id && $var_attrs ) {
				$var_id = self::match_variation_by_attributes( $existing, $var_attrs );
			}

			$variation = $var_id ? wc_get_product( $var_id ) : null;

			if ( ! $variation instanceof WC_Product_Variation ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $parent_id );
				$var_id = 0;
			}

			if ( $var_attrs ) {
				$variation->set_attributes( self::normalize_variation_attributes( $var_attrs ) );
			}

			/*
			 * SKU خالی هم اعمال می‌شود.
			 *
			 * مبدأ با context = 'edit' خوانده می‌شود، پس رشته‌ی خالی یعنی آن
			 * واریشن واقعاً SKU ندارد و ووکامرس فقط در نمایش SKU والد را نشان
			 * می‌دهد. مقصد هم باید همان‌طور باشد؛ در غیر این صورت SKU والد روی
			 * همه‌ی واریشن‌ها به‌صورت مقدار حقیقی ذخیره می‌ماند.
			 */
			if ( array_key_exists( 'sku', $var_data ) ) {
				try {
					$variation->set_sku( '' === $var_sku ? '' : wc_clean( $var_sku ) );
				} catch ( Exception $e ) {
					DSS_Logger::warning( 'تنظیم SKU واریشن ناموفق بود.', array( 'sku' => $var_sku, 'error' => $e->getMessage() ) );
				}
			}

			foreach ( array( 'regular_price', 'sale_price', 'description', 'menu_order', 'status',
				'weight', 'length', 'width', 'height' ) as $field ) {

				if ( ! array_key_exists( $field, $var_data ) ) {
					continue;
				}

				$setter = 'set_' . $field;

				if ( method_exists( $variation, $setter ) ) {
					$value = $var_data[ $field ];
					$variation->{$setter}( null === $value ? '' : $value );
				}
			}

			if ( $with_stock ) {
				if ( array_key_exists( 'manage_stock', $var_data ) ) {
					$variation->set_manage_stock( (bool) $var_data['manage_stock'] );
				}

				if ( array_key_exists( 'stock_quantity', $var_data ) ) {
					$variation->set_stock_quantity( null === $var_data['stock_quantity'] ? null : wc_stock_amount( $var_data['stock_quantity'] ) );
				}

				if ( ! empty( $var_data['stock_status'] ) ) {
					$variation->set_stock_status( $var_data['stock_status'] );
				}

				if ( ! empty( $var_data['backorders'] ) ) {
					$variation->set_backorders( $var_data['backorders'] );
				}
			}

			/*
			 * تصویر واریشن دقیقاً آینه‌ی مبدأ می‌شود.
			 *
			 * رشته‌ی خالی یعنی واریشن مبدأ تصویر مخصوص خودش ندارد (ووکامرس در
			 * نمایش تصویر شاخص محصول را نشان می‌دهد، ولی چیزی ذخیره نیست)، پس
			 * تصویر مقصد هم پاک می‌شود.
			 */
			if ( $with_images && DSS_Config::is_on( 'sync_variation_images' ) && array_key_exists( 'image', $var_data ) ) {
				if ( '' === $var_data['image'] || null === $var_data['image'] ) {
					$variation->set_image_id( '' );
				} else {
					$attachment_id = DSS_Media::resolve( $var_data['image'], $parent_id );

					if ( $attachment_id ) {
						$variation->set_image_id( $attachment_id );
					}
				}
			}

			$variation->save();

			$new_id = $variation->get_id();

			// تصاویر اضافی واریشن.
			if ( $with_images && DSS_Config::is_on( 'sync_variation_images' ) && isset( $var_data['extra_images'] ) ) {
				if ( DSS_Variation_Gallery::import( $new_id, $var_data['extra_images'], $parent_id ) ) {
					$extra_images_touched = true;
				}
			}

			if ( $source_var_id ) {
				self::link( $new_id, $source_var_id, $source_site );
			}

			if ( ! $var_id ) {
				update_post_meta( $new_id, self::META_CREATED_BY, $source_site );
			}

			$touched[] = $new_id;
		}

		// حذف واریشن‌هایی که در مبدأ دیگر وجود ندارند.
		if ( DSS_Config::is_on( 'delete_missing_variations' ) ) {
			foreach ( $existing as $old_id ) {
				if ( in_array( (int) $old_id, array_map( 'intval', $touched ), true ) ) {
					continue;
				}

				$old = wc_get_product( $old_id );

				if ( $old ) {
					$old->delete( true );
					DSS_Logger::info( 'واریشن حذف شد (در سایت مبدأ وجود ندارد).', array( 'variation_id' => $old_id ) );
				}
			}
		}

		if ( $extra_images_touched ) {
			DSS_Logger::info( 'تصاویر اضافی واریشن‌ها همگام شد.', array( 'product_id' => $parent_id ) );
		}

		return count( $touched );
	}

	/**
	 * نرمال‌سازی ویژگی‌های واریشن؛ کلیدها بدون پیشوند attribute_ لازم است.
	 *
	 * @param array $attributes ویژگی‌ها به شکل attribute_pa_color => slug.
	 *
	 * @return array
	 */
	private static function normalize_variation_attributes( array $attributes ) {
		$out = array();

		foreach ( $attributes as $key => $value ) {
			$clean_key         = 0 === strpos( $key, 'attribute_' ) ? substr( $key, 10 ) : $key;
			$out[ $clean_key ] = $value;
		}

		return $out;
	}

	/**
	 * پیدا کردن واریشن موجود با ترکیب ویژگی یکسان.
	 *
	 * @param int[] $existing_ids واریشن‌های موجود.
	 * @param array $var_attrs    ویژگی‌های هدف.
	 *
	 * @return int
	 */
	private static function match_variation_by_attributes( array $existing_ids, array $var_attrs ) {
		$target = self::normalize_variation_attributes( $var_attrs );
		ksort( $target );

		foreach ( $existing_ids as $existing_id ) {
			$existing = wc_get_product( $existing_id );

			if ( ! $existing instanceof WC_Product_Variation ) {
				continue;
			}

			$current = self::normalize_variation_attributes( $existing->get_variation_attributes() );
			ksort( $current );

			if ( $current === $target ) {
				return (int) $existing_id;
			}
		}

		return 0;
	}

	/* ------------------------------------------------------------------ */

	/**
	 * ثبت پیوند بین این پست و همتای آن در سایت مقابل.
	 *
	 * @param int    $post_id     پست محلی.
	 * @param int    $remote_id   شناسه در سایت مقابل.
	 * @param string $remote_site کلید سایت مقابل.
	 */
	public static function link( $post_id, $remote_id, $remote_site ) {
		update_post_meta( $post_id, self::META_REMOTE_ID, absint( $remote_id ) );
		update_post_meta( $post_id, self::META_REMOTE_SITE, sanitize_key( $remote_site ) );
		update_post_meta( $post_id, self::META_LAST_SYNC, current_time( 'mysql' ) );

		// سازگاری با نسخه‌ی قدیمی.
		update_post_meta( $post_id, self::LEGACY_SOURCE_ID, absint( $remote_id ) );
		update_post_meta( $post_id, self::LEGACY_SOURCE_SITE, sanitize_key( $remote_site ) );
	}

	/**
	 * پیدا کردن پست محلی که به شناسه‌ی داده‌شده در سایت مقابل پیوند خورده.
	 *
	 * @param int    $remote_id   شناسه راه دور.
	 * @param string $remote_site کلید سایت راه دور.
	 *
	 * @return int
	 */
	public static function find_linked_product( $remote_id, $remote_site ) {
		global $wpdb;

		$remote_id = absint( $remote_id );

		if ( ! $remote_id || ! $remote_site ) {
			return 0;
		}

		foreach ( array(
			array( self::META_REMOTE_ID, self::META_REMOTE_SITE ),
			array( self::LEGACY_SOURCE_ID, self::LEGACY_SOURCE_SITE ),
		) as $pair ) {

			$id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT pm1.post_id
					 FROM {$wpdb->postmeta} pm1
					 INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
					 INNER JOIN {$wpdb->posts}   p   ON p.ID        = pm1.post_id
					 WHERE pm1.meta_key = %s AND pm1.meta_value = %d
					   AND pm2.meta_key = %s AND pm2.meta_value = %s
					   AND p.post_type IN ('product','product_variation')
					   AND p.post_status != 'trash'
					 LIMIT 1",
					$pair[0],
					$remote_id,
					$pair[1],
					$remote_site
				)
			);

			if ( $id ) {
				return (int) $id;
			}
		}

		return 0;
	}

	/**
	 * پیدا کردن محصول با SKU.
	 *
	 * @param string $sku       SKU.
	 * @param string $post_type نوع پست مورد انتظار.
	 *
	 * @return int
	 */
	public static function find_product_by_sku( $sku, $post_type = 'product' ) {
		$sku = trim( (string) $sku );

		if ( '' === $sku ) {
			return 0;
		}

		$id = wc_get_product_id_by_sku( $sku );

		if ( ! $id ) {
			return 0;
		}

		if ( $post_type && get_post_type( $id ) !== $post_type ) {
			return 0;
		}

		if ( 'trash' === get_post_status( $id ) ) {
			return 0;
		}

		return (int) $id;
	}
}
