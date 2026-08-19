<?php
/**
 * لاگ عملیات همگام‌سازی.
 *
 * @package DualSiteSync
 */

defined( 'ABSPATH' ) || exit;

class DSS_Logger {

	const OPTION = 'dss_activity_log';
	const LIMIT  = 200;

	/**
	 * ثبت یک رویداد.
	 *
	 * @param string $level   info|success|warning|error.
	 * @param string $message پیام.
	 * @param array  $context اطلاعات اضافه.
	 */
	public static function log( $level, $message, array $context = array() ) {
		$entry = array(
			'time'    => current_time( 'mysql' ),
			'level'   => $level,
			'message' => $message,
			'context' => $context,
			'user'    => get_current_user_id(),
		);

		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::LIMIT );

		update_option( self::OPTION, $log, false );

		if ( DSS_Config::get( 'debug_log' ) ) {
			error_log( sprintf( '[DSS][%s] %s %s', $level, $message, wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) ) );
		}
	}

	public static function info( $message, array $context = array() ) {
		self::log( 'info', $message, $context );
	}

	public static function success( $message, array $context = array() ) {
		self::log( 'success', $message, $context );
	}

	public static function warning( $message, array $context = array() ) {
		self::log( 'warning', $message, $context );
	}

	public static function error( $message, array $context = array() ) {
		self::log( 'error', $message, $context );
	}

	/**
	 * @return array
	 */
	public static function all() {
		$log = get_option( self::OPTION, array() );

		return is_array( $log ) ? $log : array();
	}

	public static function clear() {
		delete_option( self::OPTION );
	}
}
