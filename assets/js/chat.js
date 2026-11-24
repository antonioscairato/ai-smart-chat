/**
 * Scairato AI Content Chat — front-end.
 *
 * O JS se liga ao HTML do template por atributos data-saic-*:
 *   [data-saic]              raiz (gerada pelo plugin)
 *   [data-saic-messages]     lista de mensagens
 *   [data-saic-form]         formulário
 *   [data-saic-input]        textarea/input da pergunta
 *   [data-saic-send]         botão enviar (opcional)
 *   [data-saic-launcher]     botão flutuante (modo floating)
 *   [data-saic-panel]        painel do chat (modo floating)
 *   [data-saic-close]        botão fechar (modo floating)
 *   [data-saic-reset]        botão "nova conversa" (opcional)
 *   [data-saic-suggestions]  contêiner de sugestões (opcional)
 *   [data-saic-suggestion]   botões de sugestão (opcional)
 *
 * Extensão:
 *   - Eventos DOM (bubbles) na raiz: saic:ready, saic:question, saic:answer, saic:error
 *   - window.SAICChat.filters.beforeRequest(payload) -> payload
 *   - window.SAICChat.filters.renderAnswer(html, data) -> html
 *   - window.SAICChat.open(id?) / close(id?) / ask(texto, id?) / init(el)
 */
( function () {
	'use strict';

	var CFG = window.SAIC_CONFIG || {};
	var I18N = CFG.i18n || {};

	var API = ( window.SAICChat = window.SAICChat || {} );
	API.instances = API.instances || {};
	API.filters = API.filters || {};

	/* ------------------------------------------------------------- utilidades */

	function esc( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	/**
	 * Markdown mínimo e seguro: escapa tudo primeiro e só então aplica
	 * negrito, código, listas, links http(s) e citações [n].
	 */
	function renderText( text, sources ) {
		var tokens = [];
		var s = esc( String( text ).replace( /\u0000/g, '' ) );

		function stash( html ) {
			tokens.push( html );
			return '\u0000' + ( tokens.length - 1 ) + '\u0000';
		}

		s = s.replace( /\[([^\]]+)\]\((https?:\/\/[^\s)]+|\/(?!\/)[^\s)]*)\)/g, function ( m, label, url ) {
			return stash( '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + label + '</a>' );
		} );

		var map = {};
		( sources || [] ).forEach( function ( x ) {
			map[ x.n ] = x;
		} );
		s = s.replace( /\[(\d{1,3})\]/g, function ( m, n ) {
			var src = map[ n ];
			if ( ! src ) {
				return m;
			}
			return stash(
				'<a class="saic-cite" href="' + esc( src.url ) + '" target="_blank" rel="noopener" title="' + esc( src.title ) + '">' + n + '</a>'
			);
		} );

		s = s.replace( /\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>' );
		s = s.replace( /`([^`\n]+)`/g, '<code>$1</code>' );

		var out = [];
		var para = [];
		var list = null;

		function flushPara() {
			if ( para.length ) {
				out.push( '<p>' + para.join( '<br>' ) + '</p>' );
				para = [];
			}
		}
		function flushList() {
			if ( list ) {
				out.push( '<' + list.tag + '>' + list.items.join( '' ) + '</' + list.tag + '>' );
				list = null;
			}
		}

		s.split( '\n' ).forEach( function ( line ) {
			var ul = line.match( /^\s*[-*•]\s+(.*)$/ );
			var ol = line.match( /^\s*\d+[.)]\s+(.*)$/ );
			if ( ul || ol ) {
				flushPara();
				var tag = ul ? 'ul' : 'ol';
				if ( list && list.tag !== tag ) {
					flushList();
				}
				list = list || { tag: tag, items: [] };
				list.items.push( '<li>' + ( ul || ol )[ 1 ] + '</li>' );
			} else if ( /^\s*$/.test( line ) ) {
				flushPara();
				flushList();
			} else {
				flushList();
				para.push( line );
			}
		} );
		flushPara();
		flushList();

		return out.join( '' ).replace( /\u0000(\d+)\u0000/g, function ( m, i ) {
			return tokens[ +i ];
		} );
	}

	function storageGet( key ) {
		try {
			return JSON.parse( window.sessionStorage.getItem( key ) || 'null' );
		} catch ( e ) {
			return null;
		}
	}
	function storageSet( key, value ) {
		try {
			window.sessionStorage.setItem( key, JSON.stringify( value ) );
		} catch ( e ) {}
	}
	function emit( root, name, detail ) {
		var ev;
		try {
			ev = new CustomEvent( name, { bubbles: true, detail: detail } );
		} catch ( e ) {
			ev = document.createEvent( 'CustomEvent' );
			ev.initCustomEvent( name, true, false, detail );
		}
		root.dispatchEvent( ev );
	}

	/* ------------------------------------------------------------------- Chat */

	function Chat( root ) {
		this.root = root;
		this.id = root.id;
		this.mode = root.getAttribute( 'data-mode' ) || 'inline';
		this.welcome = root.getAttribute( 'data-welcome' ) || '';
		this.msgs = root.querySelector( '[data-saic-messages]' );
		this.form = root.querySelector( '[data-saic-form]' );
		this.input = root.querySelector( '[data-saic-input]' );
		this.send = root.querySelector( '[data-saic-send]' );
		this.launcher = root.querySelector( '[data-saic-launcher]' );
		this.panel = root.querySelector( '[data-saic-panel]' );
		this.suggestions = root.querySelector( '[data-saic-suggestions]' );
		this.storeKey = 'saic_history_v1_' + this.mode;
		this.history = []; // [{role, content, sources}]
		this.busy = false;
		this.bind();
		this.restore();
		emit( root, 'saic:ready', { chat: this } );
	}

	Chat.prototype.bind = function () {
		var self = this;
		var root = this.root;

		if ( this.form ) {
			this.form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				self.ask( self.input ? self.input.value : '' );
			} );
		}
		if ( this.input ) {
			this.input.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key && ! e.shiftKey && ! e.isComposing ) {
					e.preventDefault();
					self.ask( self.input.value );
				}
			} );
			this.input.addEventListener( 'input', function () {
				if ( 'TEXTAREA' === self.input.tagName ) {
					self.input.style.height = 'auto';
					self.input.style.height = Math.min( self.input.scrollHeight, 140 ) + 'px';
				}
			} );
			if ( CFG.maxLength ) {
				this.input.setAttribute( 'maxlength', CFG.maxLength );
			}
		}
		if ( this.launcher ) {
			this.launcher.addEventListener( 'click', function () {
				self.isOpen() ? self.close() : self.open();
			} );
		}
		var closeBtn = root.querySelector( '[data-saic-close]' );
		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', function () {
				self.close();
			} );
		}
		var resetBtn = root.querySelector( '[data-saic-reset]' );
		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', function () {
				self.reset();
			} );
		}
		root.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && 'floating' === self.mode && self.isOpen() ) {
				self.close();
				if ( self.launcher ) {
					self.launcher.focus();
				}
			}
		} );
		Array.prototype.forEach.call( root.querySelectorAll( '[data-saic-suggestion]' ), function ( btn ) {
			btn.addEventListener( 'click', function () {
				self.ask( btn.getAttribute( 'data-saic-suggestion' ) || btn.textContent );
			} );
		} );
	};

	Chat.prototype.isOpen = function () {
		return 'floating' !== this.mode || this.root.classList.contains( 'is-open' );
	};

	Chat.prototype.open = function () {
		if ( 'floating' !== this.mode || ! this.panel ) {
			return;
		}
		this.panel.hidden = false;
		this.root.classList.add( 'is-open' );
		if ( this.launcher ) {
			this.launcher.setAttribute( 'aria-expanded', 'true' );
		}
		if ( this.input ) {
			this.input.focus();
		}
		this.scroll();
	};

	Chat.prototype.close = function () {
		if ( 'floating' !== this.mode || ! this.panel ) {
			return;
		}
		this.panel.hidden = true;
		this.root.classList.remove( 'is-open' );
		if ( this.launcher ) {
			this.launcher.setAttribute( 'aria-expanded', 'false' );
		}
	};

	Chat.prototype.restore = function () {
		var saved = storageGet( this.storeKey );
		if ( this.welcome ) {
			this.addMessage( 'bot', this.welcome, [], { welcome: true } );
		}
		if ( saved && saved.length ) {
			this.history = saved;
			saved.forEach( function ( m ) {
				this.addMessage( 'user' === m.role ? 'user' : 'bot', m.content, m.sources || [], { silent: true } );
			}, this );
			this.hideSuggestions();
		}
	};

	Chat.prototype.persist = function () {
		storageSet( this.storeKey, this.history.slice( -40 ) );
	};

	Chat.prototype.reset = function () {
		this.history = [];
		this.persist();
		this.msgs.innerHTML = '';
		if ( this.welcome ) {
			this.addMessage( 'bot', this.welcome, [], { welcome: true } );
		}
		if ( this.suggestions ) {
			this.suggestions.hidden = false;
		}
	};

	Chat.prototype.hideSuggestions = function () {
		if ( this.suggestions ) {
			this.suggestions.hidden = true;
		}
	};

	Chat.prototype.scroll = function () {
		if ( this.msgs ) {
			this.msgs.scrollTop = this.msgs.scrollHeight;
		}
	};

	Chat.prototype.addMessage = function ( role, content, sources, opts ) {
		opts = opts || {};
		var wrap = document.createElement( 'div' );
		wrap.className = 'saic-msg saic-msg--' + role + ( opts.error ? ' saic-msg--error' : '' ) + ( opts.typing ? ' saic-msg--typing' : '' );

		var bubble = document.createElement( 'div' );
		bubble.className = 'saic-bubble';

		if ( opts.typing ) {
			bubble.innerHTML = '<span class="saic-dots" aria-label="' + esc( I18N.thinking || '' ) + '"><i></i><i></i><i></i></span>';
		} else if ( 'user' === role ) {
			bubble.textContent = content;
		} else {
			var html = renderText( content, sources );
			if ( 'function' === typeof API.filters.renderAnswer ) {
				html = API.filters.renderAnswer( html, { text: content, sources: sources } ) || html;
			}
			bubble.innerHTML = html;
		}
		wrap.appendChild( bubble );

		if ( sources && sources.length ) {
			wrap.appendChild( this.buildSources( sources ) );
		}
		this.msgs.appendChild( wrap );
		this.scroll();
		return wrap;
	};

	Chat.prototype.buildSources = function ( sources ) {
		var box = document.createElement( 'div' );
		box.className = 'saic-sources';
		var label = document.createElement( 'span' );
		label.className = 'saic-sources__label';
		label.textContent = I18N.sources || 'Sources';
		box.appendChild( label );

		var ul = document.createElement( 'ul' );
		sources.forEach( function ( s ) {
			var li = document.createElement( 'li' );
			var a = document.createElement( 'a' );
			a.href = s.url;
			a.target = '_blank';
			a.rel = 'noopener';
			var n = document.createElement( 'span' );
			n.className = 'saic-sources__n';
			n.textContent = s.n;
			var t = document.createElement( 'span' );
			t.className = 'saic-sources__title';
			t.textContent = s.title;
			a.appendChild( n );
			a.appendChild( t );
			li.appendChild( a );
			if ( s.excerpt ) {
				var ex = document.createElement( 'small' );
				ex.className = 'saic-sources__excerpt';
				ex.textContent = s.excerpt;
				li.appendChild( ex );
			}
			ul.appendChild( li );
		} );
		box.appendChild( ul );
		return box;
	};

	Chat.prototype.setBusy = function ( busy ) {
		this.busy = busy;
		this.root.classList.toggle( 'is-busy', busy );
		this.root.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		if ( this.send ) {
			this.send.disabled = busy;
		}
	};

	Chat.prototype.ask = function ( text ) {
		var self = this;
		text = String( text || '' ).trim();
		if ( ! text || this.busy ) {
			return Promise.resolve();
		}
		if ( CFG.maxLength && text.length > CFG.maxLength ) {
			text = text.slice( 0, CFG.maxLength );
		}

		var past = this.history.slice( -( CFG.history || 0 ) ).map( function ( m ) {
			return { role: m.role, content: m.content };
		} );

		this.addMessage( 'user', text );
		this.history.push( { role: 'user', content: text } );
		this.hideSuggestions();
		if ( this.input ) {
			this.input.value = '';
			this.input.style.height = '';
		}
		emit( this.root, 'saic:question', { question: text } );

		var payload = { question: text, history: past };
		if ( 'function' === typeof API.filters.beforeRequest ) {
			payload = API.filters.beforeRequest( payload ) || payload;
		}

		this.setBusy( true );
		var typing = this.addMessage( 'bot', '', [], { typing: true } );

		return fetch( CFG.restUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( payload ),
			credentials: 'same-origin',
		} )
			.then( function ( res ) {
				return res.json().then(
					function ( data ) {
						return { ok: res.ok, data: data };
					},
					function () {
						return { ok: false, data: {} };
					}
				);
			} )
			.then( function ( r ) {
				typing.remove();
				if ( ! r.ok || ! r.data || 'string' !== typeof r.data.answer ) {
					var msg = ( r.data && r.data.message ) || I18N.error || 'Error';
					self.addMessage( 'bot', msg, [], { error: true } );
					emit( self.root, 'saic:error', { message: msg } );
					return;
				}
				self.addMessage( 'bot', r.data.answer, r.data.sources || [] );
				self.history.push( { role: 'assistant', content: r.data.answer, sources: r.data.sources || [] } );
				self.persist();
				emit( self.root, 'saic:answer', { question: text, answer: r.data.answer, sources: r.data.sources || [], found: r.data.found } );
			} )
			.catch( function () {
				typing.remove();
				self.addMessage( 'bot', I18N.error || 'Error', [], { error: true } );
				emit( self.root, 'saic:error', { message: 'network' } );
			} )
			.then( function () {
				self.setBusy( false );
				if ( self.input && self.isOpen() ) {
					self.input.focus();
				}
			} );
	};

	/* -------------------------------------------------------------- API pública */

	API.init = function ( el ) {
		var roots = el && el.nodeType === 1 ? ( el.matches( '[data-saic]' ) ? [ el ] : el.querySelectorAll( '[data-saic]' ) ) : document.querySelectorAll( '[data-saic]' );
		Array.prototype.forEach.call( roots, function ( root ) {
			if ( root.__saic ) {
				return;
			}
			root.__saic = new Chat( root );
			API.instances[ root.id ] = root.__saic;
		} );
	};

	function pick( id, floatingFirst ) {
		if ( id && API.instances[ id ] ) {
			return API.instances[ id ];
		}
		var keys = Object.keys( API.instances );
		for ( var i = 0; i < keys.length; i++ ) {
			if ( ! floatingFirst || 'floating' === API.instances[ keys[ i ] ].mode ) {
				return API.instances[ keys[ i ] ];
			}
		}
		return keys.length ? API.instances[ keys[ 0 ] ] : null;
	}
	API.open = function ( id ) {
		var c = pick( id, true );
		if ( c ) {
			c.open();
		}
	};
	API.close = function ( id ) {
		var c = pick( id, true );
		if ( c ) {
			c.close();
		}
	};
	API.ask = function ( text, id ) {
		var c = pick( id, true );
		if ( c ) {
			c.open();
			return c.ask( text );
		}
		return Promise.resolve();
	};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			API.init();
		} );
	} else {
		API.init();
	}
} )();
