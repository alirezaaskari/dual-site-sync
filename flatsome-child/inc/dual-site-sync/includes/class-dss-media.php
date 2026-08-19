<?php
/**
 * مدیریت تصاویر بین دو سایت.
 *
 * برخلاف نسخه‌ی قبلی، تطبیق تصویر با «نام فایل» انجام نمی‌شود (که باعث اتصال
 * تصاویر اشتباه می‌شد). هر پیوستی که از سایت مقابل دانلود می‌شود دو متا می‌گیرد:
 *
 *  - _dss_source_url  : آدرس اصلی در سایت مبدأ
 *  - _dss_source_hash : md5 محتوای فایل
 *
 * ترتیب جست‌وجو: متای آدرس → پیوست محلی (اگر آدرس متعلق به همین سایت باشد) →
 * هش محتوا پس از دانلود. اگر هیچ‌کدام نبود، پیوست جدید ساخته می‌شود.
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

class DSS_Media {

	const META_SOURCE_URL  = '_dss_source_url';
	const META_SOURCE_HASH = '_dss_source_hash';

	/**
	 * کش درون‌درخواستی: url => attachment_id.
	 *
	 * @var array<string,int>
	 */
	private static $runtime_cache = array();

	/**
	 * آدرس یک پیوست.
	 *
	 * @param int $attachment_id شناسه.
	 *
	 * @return string
	 */
	public static function url( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			return '';
		}

		$url = wp_get_attachment_url( $attachment_id );

		return $url ? $url : '';
	}

	/**
	 * آدرس تصویر شاخص یک پست.
	 *
	 * @param int $post_id شناسه پست.
	 *
	 * @return string
	 */
	public static function featured_url( $post_id ) {
		$thumb_id = get_post_thumbnail_id( $post_id );

		return $thumb_id ? self::url( $thumb_id ) : '';
	}

	/**
	 * دریافت (یا ساخت) شناسه‌ی پیوست محلی برای یک آدرس راه دور.
	 *
	 * @param string $image_url آدرس تصویر.
	 * @param int    $attach_to پستی که پیوست به آن نسبت داده شود.
	 *
	 * @return int شناسه پیوست، یا 0 در صورت شکست.
	 */
	public static function resolve( $image_url, $attach_to = 0 ) {
		$image_url = trim( (string) $image_url );

		if ( '' === $image_url || ! wp_http_validate_url( $image_url ) ) {
			return 0;
		}

		if ( isset( self::$runtime_cache[ $image_url ] ) ) {
			return self::$runtime_cache[ $image_url ];
		}

		$attachment_id = self::find_by_source_url( $image_url );

		if ( ! $attachment_id ) {
			$attachment_id = self::find_local( $image_url );
		}

		if ( ! $attachment_id ) {
			$attachment_id = self::sideload( $image_url, $attach_to );
		}

		self::$runtime_cache[ $image_url ] = $attachment_id;

		return $attachment_id;
	}

	/**
	 * تبدیل آرایه‌ای از آدرس‌ها به شناسه‌های پیوست.
	 *
	 * @param array $urls      آدرس‌ها.
	 * @param int   $attach_to پست مرجع.
	 *
	 * @return int[]
	 */
	public static function resolve_many( array $urls, $attach_to = 0 ) {
		$ids = array();

		foreach ( $urls as $url ) {
			$id = self::resolve( $url, $attach_to );

			if ( $id ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * جست‌وجو بر اساس متای آدرس مبدأ.
	 *
	 * @param string $image_url آدرس.
	 *
	 * @return int
	 */
	private static function find_by_source_url( $image_url ) {
		global $wpdb;

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND pm.meta_value = %s
				   AND p.post_type = 'attachment'
				 LIMIT 1",
				self::META_SOURCE_URL,
				$image_url
			)
		);

		return $id ? (int) $id : 0;
	}

	/**
	 * اگر آدرس متعلق به همین سایت است، پیوست محلی را پیدا کن.
	 *
	 * @param string $image_url آدرس.
	 *
	 * @return int
	 */
	private static function find_local( $image_url ) {
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$image_host  = wp_parse_url( $image_url, PHP_URL_HOST );

		if ( ! $site_host || ! $image_host ) {
			return 0;
		}

		if ( strtolower( $site_host ) !== strtolower( $image_host ) ) {
			return 0;
		}

		$id = attachment_url_to_postid( $image_url );

		return $id ? (int) $id : 0;
	}

	/**
	 * جست‌وجو بر اساس هش محتوا.
	 *
	 * @param string $hash هش md5.
	 *
	 * @return int
	 */
	private static function find_by_hash( $hash ) {
		global $wpdb;

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND pm.meta_value = %s
				   AND p.post_type = 'attachment'
				 LIMIT 1",
				self::META_SOURCE_HASH,
				$hash
			)
		);

		return $id ? (int) $id : 0;
	}

	/**
	 * دانلود و ثبت تصویر.
	 *
	 * @param string $image_url آدرس.
	 * @param int    $attach_to پست مرجع.
	 *
	 * @return int
	 */
	private static function sideload( $image_url, $attach_to = 0 ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $image_url, 60 );

		if ( is_wp_error( $tmp ) ) {
			DSS_Logger::warning( 'دانلود تصویر ناموفق بود.', array(
				'url'   => $image_url,
				'error' => $tmp->get_error_message(),
			) );

			return 0;
		}

		// اگر همین محتوا قبلاً وجود دارد، دوباره نساز.
		$hash        = md5_file( $tmp );
		$existing_id = $hash ? self::find_by_hash( $hash ) : 0;

		if ( $existing_id ) {
			wp_delete_file( $tmp );
			update_post_meta( $existing_id, self::META_SOURCE_URL, $image_url );

			return $existing_id;
		}

		$filename = basename( wp_parse_url( $image_url, PHP_URL_PATH ) );
		$filename = sanitize_file_name( $filename );

		if ( '' === $filename ) {
			$filename = 'dss-image-' . substr( (string) $hash, 0, 8 ) . '.jpg';
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, absint( $attach_to ) );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			DSS_Logger::warning( 'ثبت تصویر در کتابخانه رسانه ناموفق بود.', array(
				'url'   => $image_url,
				'error' => $attachment_id->get_error_message(),
			) );

			return 0;
		}

		update_post_meta( $attachment_id, self::META_SOURCE_URL, $image_url );

		if ( $hash ) {
			update_post_meta( $attachment_id, self::META_SOURCE_HASH, $hash );
		}

		return (int) $attachment_id;
	}
}
