xtools.globalcontribs = {};

$( () => {
	// Don't do anything if this isn't a Global Contribs page.
	if ( $( 'body.globalcontribs' ).length === 0 ) {
		return;
	}

	xtools.application.setupContributionsNavListeners( ( params ) => `globalcontribs/${ params.username }/${ params.namespace }/${ params.start }/${ params.end }`, 'globalcontribs' );
} );
