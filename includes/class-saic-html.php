<?php
/**
 * HTML text extraction by CSS selector.
 *
 * @package Scairato_AI_Content_Chat
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- property names belong to PHP's DOM API (parentNode, nodeType...).

/**
 * Lê uma página do próprio site e extrai o texto de um trecho dela, escolhido por seletor CSS
 * (ex.: "footer", ".contato", "#endereco", "footer .address p").
 *
 * Seletores suportados: tag, #id, .classe, [atributo], [atributo=valor] (também ^= $= *= ~= |=),
 * combinações (a.btn[href]), descendente (espaço), filho direto (>) e listas separadas por vírgula.
 * Pseudo-classes (:hover, :nth-child...) e os combinadores + e ~ não são suportados.
 */
class SAIC_HTML {

	/**
	 * Baixa o HTML de uma página do próprio site (visão de visitante, sem login).
	 *
	 * @param string $url URL absoluta ou caminho relativo (ex.: "/contato").
	 * @return string|WP_Error
	 */
	public static function fetch( $url ) {
		$url    = SAIC_Sources::resolve_url( $url );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return new WP_Error( 'saic_url', __( 'Invalid page URL.', 'scairato-ai-content-chat' ) );
		}
		if ( ! self::host_allowed( $url ) ) {
			return new WP_Error( 'saic_host', __( 'The page must be on this site.', 'scairato-ai-content-chat' ) );
		}

		/**
		 * Ajusta a requisição HTTP que busca a página (ex.: sslverify, cabeçalhos, autenticação básica).
		 */
		$args = apply_filters(
			'saic_html_source_request_args',
			array(
				'timeout'             => 20,
				'redirection'         => 3,
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter. // Mesmo padrão das requisições de loopback do WordPress.
				'limit_response_size' => 2 * 1024 * 1024,
				'user-agent'          => 'Scairato AI Content Chat/' . SAIC_VERSION,
				'headers'             => array( 'Accept' => 'text/html' ),
			),
			$url
		);

		// Os redirecionamentos são seguidos aqui, validando o domínio de CADA salto (evita requisições a hosts internos).
		$args['redirection'] = 0;
		$target              = $url;
		$response            = null;
		for ( $hop = 0; $hop <= 3; $hop++ ) {
			$response = wp_remote_get( $target, $args );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( $status < 300 || $status >= 400 ) {
				break;
			}
			$location = wp_remote_retrieve_header( $response, 'location' );
			$location = is_array( $location ) ? (string) reset( $location ) : (string) $location;
			if ( '' === $location ) {
				break;
			}
			$next   = class_exists( 'WP_Http' ) && method_exists( 'WP_Http', 'make_absolute_url' ) ? WP_Http::make_absolute_url( $location, $target ) : $location;
			$scheme = strtolower( (string) wp_parse_url( $next, PHP_URL_SCHEME ) );
			if ( ( 'http' !== $scheme && 'https' !== $scheme ) || ! self::host_allowed( $next ) ) {
				return new WP_Error( 'saic_host', __( 'The page must be on this site.', 'scairato-ai-content-chat' ) );
			}
			$target = $next;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'saic_http', sprintf( __( 'The page returned HTTP %d.', 'scairato-ai-content-chat' ), $code ) );
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	protected static function host_allowed( $url ) {
		$host    = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$allowed = array(
			strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
		);
		/**
		 * Domínios permitidos para buscar páginas (por padrão, só o do próprio site).
		 */
		$allowed = (array) apply_filters( 'saic_html_source_hosts', $allowed );
		return '' !== $host && in_array( $host, array_map( 'strtolower', $allowed ), true );
	}

	/**
	 * @param string $html
	 * @param string $selector
	 * @return string|WP_Error Texto extraído (vazio se o seletor não encontrou nada).
	 */
	public static function extract( $html, $selector ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error( 'saic_no_dom', __( 'The PHP DOM extension is required to read HTML pages.', 'scairato-ai-content-chat' ) );
		}
		$expr = self::css_to_xpath( $selector );
		if ( is_wp_error( $expr ) ) {
			return $expr;
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		$loaded   = $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return new WP_Error( 'saic_parse', __( 'Could not parse the page HTML.', 'scairato-ai-content-chat' ) );
		}

		$xp = new DOMXPath( $dom );

		// Remove o que não é conteúdo legível.
		$noise = $xp->query( '//script | //style | //noscript | //template | //svg | //iframe | //select | //option' );
		if ( $noise ) {
			foreach ( $noise as $n ) {
				if ( $n->parentNode ) {
					$n->parentNode->removeChild( $n );
				}
			}
		}

		$found = @$xp->query( $expr ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $found ) {
			return new WP_Error( 'saic_selector', __( 'Invalid selector.', 'scairato-ai-content-chat' ) );
		}

		$list = array();
		foreach ( $found as $n ) {
			$list[] = $n;
		}
		// Ignora elementos que estão dentro de outro já encontrado (evita texto duplicado).
		$top = array();
		foreach ( $list as $n ) {
			$nested = false;
			foreach ( $list as $other ) {
				if ( ! $n->isSameNode( $other ) && self::is_inside( $n, $other ) ) {
					$nested = true;
					break;
				}
			}
			if ( ! $nested ) {
				$top[] = $n;
			}
		}

		$parts = array();
		foreach ( $top as $n ) {
			$parts[] = self::node_text( $n );
		}
		$text  = SAIC_Chunker::clean( implode( "\n", $parts ) );
		$lines = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line && ( ! $lines || end( $lines ) !== $line ) ) {
				$lines[] = $line;
			}
		}
		return implode( "\n", $lines );
	}

	protected static function is_inside( DOMNode $node, DOMNode $ancestor ) {
		for ( $p = $node->parentNode; $p; $p = $p->parentNode ) {
			if ( $p->isSameNode( $ancestor ) ) {
				return true;
			}
		}
		return false;
	}

	protected static function node_text( DOMNode $node ) {
		static $block = array( 'p', 'div', 'li', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'tr', 'table', 'section', 'article', 'address', 'footer', 'header', 'nav', 'aside', 'blockquote', 'dt', 'dd', 'dl', 'figure', 'figcaption', 'form', 'fieldset', 'main', 'pre' );

		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
			return (string) $node->nodeValue;
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}
		$name = strtolower( $node->nodeName );
		if ( 'br' === $name || 'hr' === $name ) {
			return "\n";
		}

		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= self::node_text( $child );
		}
		if ( in_array( $name, $block, true ) ) {
			$out = "\n" . $out . "\n";
		} elseif ( 'td' === $name || 'th' === $name ) {
			$out = ' ' . $out . ' ';
		} elseif ( 'a' === $name ) {
			// Mantém e-mail e telefone mesmo quando o texto do link é outro ("Fale conosco").
			$href = $node->getAttribute( 'href' );
			if ( preg_match( '/^(?:mailto|tel):([^?]+)/i', $href, $m ) ) {
				$value = trim( rawurldecode( $m[1] ) );
				if ( '' !== $value && false === stripos( $out, $value ) ) {
					$out .= ' (' . $value . ')';
				}
			}
		}
		return $out;
	}

	/* -------------------------------------------------------- CSS -> XPath */

	/**
	 * @return string|WP_Error Expressão XPath.
	 */
	public static function css_to_xpath( $selector ) {
		$selector = trim( (string) $selector );
		if ( '' === $selector ) {
			return new WP_Error( 'saic_selector', __( 'Enter a CSS selector.', 'scairato-ai-content-chat' ) );
		}
		$paths = array();
		foreach ( self::split_top( $selector ) as $alt ) {
			$alt = trim( $alt );
			if ( '' === $alt ) {
				return new WP_Error( 'saic_selector', __( 'Invalid selector.', 'scairato-ai-content-chat' ) );
			}
			$x = self::compile_complex( $alt );
			if ( is_wp_error( $x ) ) {
				return $x;
			}
			$paths[] = $x;
		}
		return implode( ' | ', $paths );
	}

	/** Divide por vírgulas que não estão dentro de [] ou aspas. */
	protected static function split_top( $s ) {
		$out   = array();
		$cur   = '';
		$depth = 0;
		$quote = '';
		$len   = strlen( $s );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $s[ $i ];
			if ( '' !== $quote ) {
				if ( $c === $quote ) {
					$quote = '';
				}
			} elseif ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '[' === $c ) {
				++$depth;
			} elseif ( ']' === $c ) {
				--$depth;
			} elseif ( ',' === $c && 0 === $depth ) {
				$out[] = $cur;
				$cur   = '';
				continue;
			}
			$cur .= $c;
		}
		$out[] = $cur;
		return $out;
	}

	protected static function unsupported() {
		return new WP_Error( 'saic_selector', __( 'Unsupported selector. Use tags, #id, .class, [attribute], spaces and ">".', 'scairato-ai-content-chat' ) );
	}

	protected static function compile_complex( $s ) {
		$len   = strlen( $s );
		$i     = 0;
		$xpath = '';
		$comb  = '//';
		$need  = false; // Após ">" precisa vir um seletor.
		while ( $i < $len ) {
			$ch = $s[ $i ];
			if ( preg_match( '/\s/', $ch ) ) {
				++$i;
				continue;
			}
			if ( '>' === $ch ) {
				if ( '' === $xpath || $need ) {
					return self::unsupported();
				}
				$comb = '/';
				$need = true;
				++$i;
				continue;
			}
			if ( '+' === $ch || '~' === $ch ) {
				return self::unsupported();
			}
			$c = self::compile_compound( $s, $i );
			if ( is_wp_error( $c ) ) {
				return $c;
			}
			$xpath .= $comb . $c;
			$comb   = '//';
			$need   = false;
		}
		if ( '' === $xpath || $need ) {
			return self::unsupported();
		}
		return $xpath;
	}

	protected static function compile_compound( $s, &$i ) {
		$len   = strlen( $s );
		$tag   = '*';
		$preds = array();

		if ( preg_match( '/\G(\*|[A-Za-z][A-Za-z0-9_-]*)/', $s, $m, 0, $i ) ) {
			$tag = strtolower( $m[1] );
			$i  += strlen( $m[1] );
		}

		while ( $i < $len && ! preg_match( '/[\s>]/', $s[ $i ] ) ) {
			$ch = $s[ $i ];
			if ( '#' === $ch ) {
				if ( ! preg_match( '/\G#([A-Za-z_][\w-]*)/', $s, $m, 0, $i ) ) {
					return self::unsupported();
				}
				$preds[] = '@id=' . self::lit( $m[1] );
				$i      += strlen( $m[0] );
			} elseif ( '.' === $ch ) {
				if ( ! preg_match( '/\G\.([A-Za-z_-][\w-]*)/', $s, $m, 0, $i ) ) {
					return self::unsupported();
				}
				$preds[] = "contains(concat(' ', normalize-space(@class), ' '), " . self::lit( ' ' . $m[1] . ' ' ) . ')';
				$i      += strlen( $m[0] );
			} elseif ( '[' === $ch ) {
				if ( ! preg_match( '/\G\[\s*([A-Za-z_:][\w:.-]*)\s*(?:([~|^$*]?=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]\s]*)))?\s*\]/', $s, $m, 0, $i ) ) {
					return self::unsupported();
				}
				$attr = '@' . strtolower( $m[1] );
				$op   = isset( $m[2] ) ? $m[2] : '';
				$val  = '';
				foreach ( array( 3, 4, 5 ) as $g ) {
					if ( isset( $m[ $g ] ) && '' !== $m[ $g ] ) {
						$val = $m[ $g ];
					}
				}
				$lit = self::lit( $val );
				switch ( $op ) {
					case '':
						$preds[] = $attr;
						break;
					case '=':
						$preds[] = $attr . '=' . $lit;
						break;
					case '*=':
						$preds[] = "contains({$attr}, {$lit})";
						break;
					case '^=':
						$preds[] = "starts-with({$attr}, {$lit})";
						break;
					case '$=':
						$preds[] = "substring({$attr}, string-length({$attr}) - string-length({$lit}) + 1) = {$lit}";
						break;
					case '~=':
						$preds[] = "contains(concat(' ', normalize-space({$attr}), ' '), " . self::lit( ' ' . $val . ' ' ) . ')';
						break;
					case '|=':
						$preds[] = "({$attr}={$lit} or starts-with({$attr}, " . self::lit( $val . '-' ) . '))';
						break;
					default:
						return self::unsupported();
				}
				$i += strlen( $m[0] );
			} else {
				return self::unsupported(); // Inclui pseudo-classes (:hover) e caracteres inesperados.
			}
		}

		return $tag . ( $preds ? '[' . implode( '][', $preds ) . ']' : '' );
	}

	/** Literal de string para XPath (trata aspas simples e duplas). */
	protected static function lit( $v ) {
		if ( false === strpos( $v, "'" ) ) {
			return "'" . $v . "'";
		}
		if ( false === strpos( $v, '"' ) ) {
			return '"' . $v . '"';
		}
		$parts = array();
		foreach ( explode( "'", $v ) as $piece ) {
			$parts[] = "'" . $piece . "'";
		}
		return 'concat(' . implode( ", \"'\", ", $parts ) . ')';
	}
}
