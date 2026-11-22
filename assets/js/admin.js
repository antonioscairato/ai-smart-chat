( function () {
	'use strict';

	var CFG = window.SAIC_ADMIN || {};
	var I18N = CFG.i18n || {};

	function fmt( s ) {
		var a = Array.prototype.slice.call( arguments, 1 );
		return String( s ).replace( /%(\d+)\$d/g, function ( m, i ) {
			return a[ i - 1 ];
		} );
	}

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', CFG.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( CFG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
			return r.json();
		} );
	}

	/* Teste de conexão */
	var testBtn = document.getElementById( 'saic-test' );
	if ( testBtn ) {
		testBtn.addEventListener( 'click', function () {
			var out = document.getElementById( 'saic-test-result' );
			out.textContent = I18N.testing;
			out.style.color = '';
			post( 'saic_test' )
				.then( function ( res ) {
					out.textContent = ( res.data && res.data.message ) || '';
					out.style.color = res.success ? '#0a7d33' : '#b00020';
				} )
				.catch( function () {
					out.textContent = I18N.requestFail;
					out.style.color = '#b00020';
				} );
		} );
	}

	/* Reindexação em lotes */
	var btns = [ document.getElementById( 'saic-reindex' ), document.getElementById( 'saic-reindex-force' ) ].filter( Boolean );
	var box = document.getElementById( 'saic-progress' );

	function setDisabled( v ) {
		btns.concat( [ document.getElementById( 'saic-clear' ) ] ).forEach( function ( b ) {
			if ( b ) {
				b.disabled = v;
			}
		} );
	}

	function run( force ) {
		var fill = document.getElementById( 'saic-progress-fill' );
		var text = document.getElementById( 'saic-progress-text' );
		var errs = document.getElementById( 'saic-progress-errors' );
		var tot = { indexed: 0, skipped: 0, removed: 0, done: 0 };
		box.hidden = false;
		errs.innerHTML = '';
		fill.style.width = '0%';
		text.textContent = I18N.working;
		setDisabled( true );

		function step( offset ) {
			post( 'saic_reindex', { offset: offset, force: force ? 1 : 0 } )
				.then( function ( res ) {
					if ( ! res.success ) {
						throw new Error( ( res.data && res.data.message ) || I18N.requestFail );
					}
					var d = res.data;
					tot.indexed += d.indexed;
					tot.skipped += d.skipped;
					tot.removed += d.removed;
					tot.done += d.processed;
					( d.errors || [] ).forEach( function ( e ) {
						var li = document.createElement( 'li' );
						li.textContent = e;
						errs.appendChild( li );
					} );
					var pct = d.total ? Math.min( 100, Math.round( ( tot.done / d.total ) * 100 ) ) : 100;
					fill.style.width = pct + '%';
					text.textContent = fmt( I18N.progress, tot.done, d.total, tot.indexed, tot.skipped );
					if ( null !== d.next && d.next !== undefined && d.processed > 0 ) {
						step( d.next );
					} else {
						text.textContent = fmt( I18N.summary, tot.indexed, tot.skipped, tot.removed );
						fill.style.width = '100%';
						setDisabled( false );
						setTimeout( function () {
							window.location.reload();
						}, 1800 );
					}
				} )
				.catch( function ( e ) {
					text.textContent = e.message || I18N.requestFail;
					setDisabled( false );
				} );
		}
		step( 0 );
	}

	btns.forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			run( '1' === b.getAttribute( 'data-force' ) );
		} );
	} );

	var clear = document.getElementById( 'saic-clear' );
	if ( clear ) {
		clear.addEventListener( 'click', function () {
			if ( ! window.confirm( I18N.confirmClear ) ) {
				return;
			}
			post( 'saic_clear_index' ).then( function () {
				window.location.reload();
			} );
		} );
	}

	/* Fontes extras: linhas, pré-visualização e reindexação */
	var srcList = document.getElementById( 'saic-src-list' );
	if ( srcList ) {
		var srcTpl = document.getElementById( 'saic-src-template' );

		document.getElementById( 'saic-src-add' ).addEventListener( 'click', function () {
			var wrap = document.createElement( 'div' );
			wrap.innerHTML = srcTpl.innerHTML.replace( /__INDEX__/g, 'n' + Date.now() );
			var row = wrap.querySelector( '.saic-src' );
			if ( row ) {
				srcList.appendChild( row );
				var first = row.querySelector( 'input[type="text"]' );
				if ( first ) {
					first.focus();
				}
			}
		} );

		srcList.addEventListener( 'change', function ( e ) {
			if ( e.target.classList.contains( 'saic-src__type' ) ) {
				var row = e.target.closest( '.saic-src' );
				var isHtml = 'html' === e.target.value;
				row.classList.toggle( 'is-html', isHtml );
				row.classList.toggle( 'is-text', ! isHtml );
			}
		} );

		srcList.addEventListener( 'click', function ( e ) {
			var row = e.target.closest( '.saic-src' );
			if ( ! row ) {
				return;
			}
			if ( e.target.closest( '.saic-src__remove' ) ) {
				row.parentNode.removeChild( row );
				return;
			}
			if ( e.target.closest( '.saic-src__preview' ) ) {
				var out = row.querySelector( '.saic-src__out' );
				var url = row.querySelector( 'input[name$="[url]"]' ).value;
				var selector = row.querySelector( 'input[name$="[selector]"]' ).value.trim();
				out.hidden = false;
				if ( ! selector ) {
					out.textContent = I18N.enterSelector;
					return;
				}
				out.textContent = I18N.loading;
				post( 'saic_preview_source', { url: url, selector: selector } )
					.then( function ( res ) {
						out.textContent = ( res.data && ( res.data.text || res.data.message ) ) || '';
					} )
					.catch( function () {
						out.textContent = I18N.requestFail;
					} );
			}
		} );

		var srcBtn = document.getElementById( 'saic-src-reindex' );
		srcBtn.addEventListener( 'click', function () {
			var result = document.getElementById( 'saic-src-result' );
			var errs = document.getElementById( 'saic-src-errors' );
			errs.innerHTML = '';
			result.textContent = I18N.working;
			srcBtn.disabled = true;
			post( 'saic_reindex_sources', {} )
				.then( function ( res ) {
					var d = res.data || {};
					result.textContent = res.success ? fmt( I18N.summary, d.indexed || 0, d.skipped || 0, d.removed || 0 ) : ( d.message || I18N.requestFail );
					( d.warnings || [] ).concat( d.errors || [] ).forEach( function ( m ) {
						var li = document.createElement( 'li' );
						li.textContent = m;
						errs.appendChild( li );
					} );
				} )
				.catch( function () {
					result.textContent = I18N.requestFail;
				} )
				.then( function () {
					srcBtn.disabled = false;
				} );
		} );
	}
} )();
