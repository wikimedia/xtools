xtools.pageinfo = {};

$( () => {
	if ( !$( 'body.pageinfo' ).length ) {
		return;
	}

	const setupToggleTable = function () {
		xtools.application.setupToggleTable(
			window.textshares,
			window.textsharesChart,
			'percentage',
			$.noop
		);
	};

	const $textsharesContainer = $( '.textshares-container' );

	if ( $textsharesContainer[ 0 ] ) {
		/** global: xtBaseUrl */
		let url = xtBaseUrl + 'authorship/' +
			$textsharesContainer.data( 'project' ) + '/' +
			$textsharesContainer.data( 'page' ) + '/' +
			( xtools.pageinfo.endDate ? xtools.pageinfo.endDate + '/' : '' );
		// Remove extraneous forward slash that would cause a 301 redirect, and request over HTTP instead of HTTPS.
		url = `${ url.replace( /\/$/, '' ) }?htmlonly=yes`;

		$.ajax( {
			url: url,
			timeout: 30000
		} ).done( ( data ) => {
			$textsharesContainer.replaceWith( data );
			xtools.application.buildSectionOffsets();
			xtools.application.setupTocListeners();
			xtools.application.setupColumnSorting();
			setupToggleTable();
		} ).fail( ( _xhr, _status, message ) => {
			$textsharesContainer.replaceWith(
				$.i18n( 'api-error', 'Authorship API: <code>' + message + '</code>' )
			);
		} );
	} else if ( $( '.textshares-table' ).length ) {
		setupToggleTable();
	}
} );
