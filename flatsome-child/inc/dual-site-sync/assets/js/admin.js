/* global jQuery, DSS_Admin */
( function ( $ ) {
	'use strict';

	if ( typeof DSS_Admin === 'undefined' ) {
		return;
	}

	var $status = null;

	function setStatus( state, message ) {
		if ( ! $status || ! $status.length ) {
			return;
		}

		$status
			.removeClass( 'dss-status--busy dss-status--success dss-status--error' )
			.addClass( 'dss-status--' + state )
			.text( message );
	}

	/**
	 * اجرای یک همگام‌سازی.
	 */
	function runSync( $button ) {
		var mode      = $button.data( 'mode' );
		var productId = $button.data( 'product' );
		var $all      = $( '.dss-btn' );

		if ( 'create' !== mode && DSS_Admin.i18n.confirmSync ) {
			if ( ! window.confirm( DSS_Admin.i18n.confirmSync ) ) {
				return;
			}
		}

		$all.addClass( 'is-busy' );
		setStatus( 'busy', DSS_Admin.i18n.working );

		$.post( DSS_Admin.ajaxUrl, {
			action: 'dss_sync',
			mode: mode,
			product_id: productId,
			nonce: DSS_Admin.nonce
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					setStatus( 'success', '✓ ' + response.data );

					// پس از ایجاد موفق، دکمه‌ی ایجاد دیگر معنا ندارد.
					if ( 'create' === mode ) {
						$( '.dss-btn--create' ).remove();
					}
				} else {
					setStatus( 'error', '✗ ' + ( response && response.data ? response.data : 'خطای نامشخص' ) );
				}
			} )
			.fail( function () {
				setStatus( 'error', '✗ ' + DSS_Admin.i18n.networkError );
			} )
			.always( function () {
				$all.removeClass( 'is-busy' );
			} );
	}

	$( function () {
		$status = $( '#dss-status' );

		$( document ).on( 'click', '.dss-btn', function ( event ) {
			event.preventDefault();
			runSync( $( this ) );
		} );

		$( document ).on( 'click', '#dss-ping', function ( event ) {
			event.preventDefault();

			var $button = $( this );
			var $result = $( '#dss-ping-result' );

			$button.prop( 'disabled', true );
			$result.removeClass( 'dss-good dss-bad' ).text( DSS_Admin.i18n.working );

			$.post( DSS_Admin.ajaxUrl, {
				action: 'dss_ping',
				nonce: DSS_Admin.nonce
			} )
				.done( function ( response ) {
					if ( response && response.success ) {
						$result.addClass( 'dss-good' ).text( '✓ ' + response.data );
					} else {
						$result.addClass( 'dss-bad' ).text( '✗ ' + ( response && response.data ? response.data : 'خطای نامشخص' ) );
					}
				} )
				.fail( function () {
					$result.addClass( 'dss-bad' ).text( '✗ ' + DSS_Admin.i18n.networkError );
				} )
				.always( function () {
					$button.prop( 'disabled', false );
				} );
		} );
	} );
}( jQuery ) );
