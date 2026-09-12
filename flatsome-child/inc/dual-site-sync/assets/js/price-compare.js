/* global jQuery, DSS_Price */
( function ( $ ) {
	'use strict';

	if ( typeof DSS_Price === 'undefined' ) {
		return;
	}

	var offset  = 0;
	var running = false;
	var rows    = [];
	var warned  = false;

	var $start    = null;
	var $stop     = null;
	var $csv      = null;
	var $progress = null;
	var $bar      = null;
	var $text     = null;
	var $table    = null;
	var $body     = null;
	var $notice   = null;
	var $empty    = null;

	function esc( value ) {
		return $( '<div>' ).text( value === null || value === undefined ? '' : value ).html();
	}

	function money( value ) {
		if ( value === null || value === undefined || value === '' ) {
			return '<span class="dss-none">ثبت نشده</span>';
		}

		return '<span class="dss-money">' + esc( value ) + '</span>';
	}

	function setProgress( processed, total ) {
		var percent = total ? Math.round( ( processed / total ) * 100 ) : 0;

		$progress.prop( 'hidden', false );
		$bar.find( 'span' ).css( 'width', percent + '%' );
		$text.text( processed + ' از ' + total + ' محصول بررسی شد (' + percent + '٪)' );
	}

	function notice( type, message ) {
		$notice.html( '<div class="notice notice-' + type + '"><p>' + message + '</p></div>' );
	}

	/**
	 * افزودن ردیف‌های یک دسته به جدول.
	 */
	function appendRows( batch ) {
		if ( ! batch.length ) {
			return;
		}

		var html = '';

		batch.forEach( function ( row ) {
			var issues = '<ul class="dss-issues">';

			row.issues.forEach( function ( issue ) {
				var head = issue.scope === 'variation'
					? '<span class="dss-var">' + esc( issue.label ) + '</span>' + ( issue.note ? ' — ' + esc( issue.note ) : '' )
					: '<strong>' + esc( issue.label ) + '</strong>';

				issues += '<li>' + head +
					'<span class="dss-cmp">' +
						'اینجا ' + money( issue.local ) +
						' <span class="dss-arrow">↔</span> ' +
						esc( DSS_Price.targetName ) + ' ' + money( issue.remote ) +
					'</span></li>';
			} );

			issues += '</ul>';

			html += '<tr' + ( row.missing ? ' class="dss-missing"' : '' ) + '>' +
				'<td>' + esc( row.id ) + '</td>' +
				'<td><span class="dss-pname">' + esc( row.name ) + '</span>' +
					( row.type === 'variable' ? ' <span class="dss-badge">متغیر</span>' : '' ) + '</td>' +
				'<td>' + ( row.sku ? '<code>' + esc( row.sku ) + '</code>' : '—' ) + '</td>' +
				'<td>' + issues + '</td>' +
				'<td>' +
					( row.edit_link ? '<a class="button button-small" target="_blank" rel="noopener" href="' + esc( row.edit_link ) + '">ویرایش اینجا</a> ' : '' ) +
					( row.remote_edit ? '<a class="button button-small" target="_blank" rel="noopener" href="' + esc( row.remote_edit ) + '">ویرایش آنجا</a>' : '' ) +
				'</td>' +
			'</tr>';
		} );

		$table.prop( 'hidden', false );
		$empty.prop( 'hidden', true );
		$body.append( html );
	}

	function finish( message ) {
		running = false;
		$start.prop( 'disabled', false ).text( 'شروع دوباره' );
		$stop.prop( 'disabled', true );
		$csv.prop( 'disabled', rows.length === 0 );

		if ( message ) {
			notice( rows.length ? 'warning' : 'success', message );
		} else if ( rows.length ) {
			notice( 'warning', rows.length + ' محصول با اختلاف قیمت پیدا شد.' );
		} else {
			notice( 'success', 'بررسی کامل شد؛ هیچ اختلاف قیمتی پیدا نشد.' );
			$empty.prop( 'hidden', false );
		}
	}

	function step() {
		if ( ! running ) {
			return;
		}

		$.post( DSS_Price.ajaxUrl, {
			action: 'dss_price_scan',
			nonce: DSS_Price.nonce,
			offset: offset,
			batch: DSS_Price.batch
		} )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					finish( response && response.data ? esc( response.data ) : 'خطای نامشخص.' );
					return;
				}

				var data = response.data;

				if ( ! warned && data.remote_currency && data.local_currency && data.remote_currency !== data.local_currency ) {
					warned = true;
					notice( 'error', 'واحد پول دو سایت یکی نیست (' + esc( data.local_currency ) +
						' و ' + esc( data.remote_currency ) + '). مقایسه‌ی عددی قیمت‌ها معنا ندارد.' );
				}

				rows = rows.concat( data.rows );
				appendRows( data.rows );
				setProgress( data.processed, data.total );

				if ( data.done ) {
					finish( data.message || '' );
					return;
				}

				offset = data.processed;
				step();
			} )
			.fail( function () {
				finish( 'خطای ارتباط با سرور.' );
			} );
	}

	/**
	 * ساخت CSV از نتایج جمع‌شده.
	 */
	function downloadCsv() {
		var lines = [ [ 'شناسه', 'محصول', 'SKU', 'نوع', 'مورد', 'جزئیات', 'قیمت اینجا', 'قیمت ' + DSS_Price.targetName ] ];

		rows.forEach( function ( row ) {
			row.issues.forEach( function ( issue ) {
				lines.push( [
					row.id,
					row.name,
					row.sku,
					row.type,
					issue.label,
					issue.note || '',
					issue.local === null ? '' : issue.local,
					issue.remote === null ? '' : issue.remote
				] );
			} );
		} );

		var csv = lines.map( function ( cols ) {
			return cols.map( function ( c ) {
				return '"' + String( c === null || c === undefined ? '' : c ).replace( /"/g, '""' ) + '"';
			} ).join( ',' );
		} ).join( '\n' );

		// BOM تا اکسل فارسی درست باز کند.
		var blob = new Blob( [ '﻿' + csv ], { type: 'text/csv;charset=utf-8;' } );
		var link = document.createElement( 'a' );

		link.href = URL.createObjectURL( blob );
		link.download = 'price-diff.csv';
		document.body.appendChild( link );
		link.click();
		document.body.removeChild( link );
		URL.revokeObjectURL( link.href );
	}

	$( function () {
		$start    = $( '#dss-price-start' );
		$stop     = $( '#dss-price-stop' );
		$csv      = $( '#dss-price-csv' );
		$progress = $( '#dss-price-progress' );
		$bar      = $progress.find( '.dss-progress__bar' );
		$text     = $progress.find( '.dss-progress__text' );
		$table    = $( '#dss-price-results' );
		$body     = $table.find( 'tbody' );
		$notice   = $( '#dss-price-notice' );
		$empty    = $( '#dss-price-empty' );

		$start.on( 'click', function () {
			offset  = 0;
			rows    = [];
			warned  = false;
			running = true;

			$body.empty();
			$table.prop( 'hidden', true );
			$empty.prop( 'hidden', true );
			$notice.empty();
			$start.prop( 'disabled', true ).text( 'در حال بررسی…' );
			$stop.prop( 'disabled', false );
			$csv.prop( 'disabled', true );

			step();
		} );

		$stop.on( 'click', function () {
			running = false;
			finish( 'بررسی متوقف شد.' );
		} );

		$csv.on( 'click', downloadCsv );
	} );
}( jQuery ) );
