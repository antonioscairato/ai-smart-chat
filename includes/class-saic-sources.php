<?php
/**
 * Extra sources: fixed text, options and page excerpts.
 *
 * @package Scairato_AI_Content_Chat
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- custom tables of this plugin; names come from $wpdb->prefix and every value is passed through prepare()/intval().

/**
 * Fontes extras: conteúdo que não está em posts, mas que o chat precisa conhecer.
 *
 *  - Texto fixo: endereço, horários, contatos... digitados nas configurações.
 *  - Página HTML: trecho de uma página do próprio site escolhido por seletor CSS (ex.: o footer do tema).
 *  - Informações do site: nome, descrição e opções do WordPress (ex.: página de opções do ACF).
 *
 * Cada fonte vira um "documento virtual" no índice (linhas com doc_key preenchido e post_id = 0).
 */
class SAIC_Sources {

	const OPTION    = 'saic_sources';
	const HASHES    = 'saic_sources_hashes';
	const MAX_ITEMS = 50;

	public static function init() {
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'schedule' ) );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'schedule' ) );
		add_action( 'saic_index_sources', array( __CLASS__, 'cron' ) );
	}

	public static function defaults() {
		return array(
			'items'       => array(),
			'option_keys' => '',
			'site_info'   => 1,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/* ---------------------------------------------------------------- salvar */

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? wp_unslash( $input ) : array();
		$items = array();
		$used  = array();
		$rows  = ( isset( $input['items'] ) && is_array( $input['items'] ) ) ? array_values( $input['items'] ) : array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || count( $items ) >= self::MAX_ITEMS ) {
				continue;
			}
			$type     = ( isset( $row['type'] ) && 'html' === $row['type'] ) ? 'html' : 'text';
			$title    = isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '';
			$url      = isset( $row['url'] ) ? self::clean_url( $row['url'] ) : '';
			$text     = isset( $row['text'] ) ? mb_substr( sanitize_textarea_field( $row['text'] ), 0, 20000 ) : '';
			$selector = isset( $row['selector'] ) ? mb_substr( trim( wp_strip_all_tags( $row['selector'] ) ), 0, 300 ) : '';

			if ( 'text' === $type && '' === trim( $text ) ) {
				continue; // Linha vazia.
			}
			if ( 'html' === $type && '' === $selector ) {
				continue;
			}

			$id = isset( $row['id'] ) ? substr( preg_replace( '/[^a-z0-9]/', '', strtolower( $row['id'] ) ), 0, 16 ) : '';
			if ( '' === $id || isset( $used[ $id ] ) ) {
				$id = substr( md5( wp_generate_uuid4() ), 0, 10 );
			}
			$used[ $id ] = true;

			$items[] = array(
				'id'       => $id,
				'type'     => $type,
				'title'    => $title,
				'url'      => $url,
				'text'     => 'text' === $type ? $text : '',
				'selector' => 'html' === $type ? $selector : '',
			);
		}

		return array(
			'items'       => $items,
			'option_keys' => isset( $input['option_keys'] ) ? sanitize_textarea_field( $input['option_keys'] ) : '',
			'site_info'   => empty( $input['site_info'] ) ? 0 : 1,
		);
	}

	public static function clean_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) && '/' !== $url[0] ) {
			$url = '/' . $url;
		}
		return esc_url_raw( $url );
	}

	/** Caminho relativo vira URL do site; vazio vira a página inicial. */
	public static function resolve_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return home_url( '/' );
		}
		if ( '/' === $url[0] ) {
			return home_url( $url );
		}
		return $url;
	}

	/* ---------------------------------------------------------------- agenda */

	/** Reindexa em segundo plano depois de salvar. */
	public static function schedule() {
		if ( ! wp_next_scheduled( 'saic_index_sources' ) ) {
			wp_schedule_single_event( time() + 10, 'saic_index_sources' );
		}
	}

	public static function cron() {
		self::reindex( false );
	}

	/* ------------------------------------------------------------ documentos */

	/** Opções com nomes que sugerem segredo nunca entram no índice (ele é público via chat). */
	public static function is_blocked_option( $name ) {
		$blocked = (bool) preg_match( '/(pass|secret|token|api[_-]?key|salt|auth|nonce|license|private|credential|admin_email)/i', $name );
		return (bool) apply_filters( 'saic_option_is_blocked', $blocked, $name );
	}

	/** Nomes de opção configurados (um por linha). */
	public static function option_list() {
		$cfg  = self::all();
		$keys = preg_split( '/\r\n|\r|\n/', (string) $cfg['option_keys'], -1, PREG_SPLIT_NO_EMPTY );
		$out  = array();
		foreach ( $keys as $key ) {
			$key = trim( $key );
			if ( '' !== $key ) {
				$out[] = $key;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Lê uma opção e diz por que ela não pode ser usada.
	 *
	 * @return array ['value' => string, 'issue' => null|'selector'|'blocked'|'missing'|'empty'|'type']
	 */
	public static function inspect_option( $key ) {
		$r = array(
			'value' => '',
			'issue' => null,
		);
		if ( preg_match( '/^[.#\[]|[\s>\[\]]/', $key ) ) {
			$r['issue'] = 'selector';
		} elseif ( self::is_blocked_option( $key ) ) {
			$r['issue'] = 'blocked';
		} else {
			$val = get_option( $key, null );
			if ( null === $val || false === $val ) {
				$r['issue'] = 'missing';
			} elseif ( ! is_scalar( $val ) ) {
				$r['issue'] = 'type';
			} elseif ( '' === trim( (string) $val ) ) {
				$r['issue'] = 'empty';
			} else {
				$r['value'] = wp_strip_all_tags( (string) $val );
			}
		}
		return $r;
	}

	/** Avisos legíveis sobre as opções configuradas. */
	public static function option_warnings() {
		$out = array();
		foreach ( self::option_list() as $key ) {
			$issue = self::inspect_option( $key )['issue'];
			if ( 'selector' === $issue ) {
				/* translators: %s: option name typed by the user */
				$out[] = sprintf( __( 'The option "%s" looks like a CSS selector. Use a "Text from a page" source for that.', 'scairato-ai-content-chat' ), $key );
			} elseif ( 'blocked' === $issue ) {
				/* translators: %s: option name typed by the user */
				$out[] = sprintf( __( 'The option "%s" was skipped because its name looks like a secret.', 'scairato-ai-content-chat' ), $key );
			} elseif ( 'missing' === $issue ) {
				/* translators: %s: option name typed by the user */
				$out[] = sprintf( __( 'The option "%s" was not found.', 'scairato-ai-content-chat' ), $key );
			} elseif ( 'empty' === $issue ) {
				/* translators: %s: option name typed by the user */
				$out[] = sprintf( __( 'The option "%s" is empty.', 'scairato-ai-content-chat' ), $key );
			} elseif ( 'type' === $issue ) {
				/* translators: %s: option name typed by the user */
				$out[] = sprintf( __( 'The option "%s" does not hold simple text.', 'scairato-ai-content-chat' ), $key );
			}
		}
		return $out;
	}

	/** O que há no índice por fonte: chave => [title, chunks]. */
	public static function indexed_docs() {
		global $wpdb;
		$table = SAIC_DB::chunks_table();
		$rows  = $wpdb->get_results( "SELECT doc_key, MAX(doc_title) AS title, COUNT(*) AS chunks FROM {$table} WHERE doc_key <> '' GROUP BY doc_key", ARRAY_A ); // phpcs:ignore
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['doc_key'] ] = array(
				'title'  => $row['title'],
				'chunks' => (int) $row['chunks'],
			);
		}
		return $out;
	}

	protected static function site_doc() {
		$cfg   = self::all();
		$lines = array();

		if ( $cfg['site_info'] ) {
			/* translators: %s: site name */
			$lines[] = sprintf( __( 'Site name: %s', 'scairato-ai-content-chat' ), wp_strip_all_tags( get_bloginfo( 'name' ) ) );
			$tagline = trim( wp_strip_all_tags( get_bloginfo( 'description' ) ) );
			if ( '' !== $tagline ) {
				/* translators: %s: site tagline */
				$lines[] = sprintf( __( 'Tagline: %s', 'scairato-ai-content-chat' ), $tagline );
			}
			/* translators: %s: site address (URL) */
			$lines[] = sprintf( __( 'Website: %s', 'scairato-ai-content-chat' ), home_url( '/' ) );
		}

		foreach ( self::option_list() as $key ) {
			$insp = self::inspect_option( $key );
			if ( null === $insp['issue'] ) {
				$lines[] = $key . ': ' . $insp['value'];
			}
		}

		if ( ! $lines ) {
			return null;
		}
		return array(
			'title' => __( 'Site information', 'scairato-ai-content-chat' ),
			'url'   => home_url( '/' ),
			'text'  => implode( "\n", $lines ),
			'error' => null,
		);
	}

	/**
	 * Monta todos os documentos virtuais. Os de página HTML são buscados agora.
	 *
	 * @return array chave => [title, url, text, error]
	 */
	public static function build_docs() {
		$cfg   = self::all();
		$docs  = array();
		$pages = array(); // Cache por URL: várias linhas podem usar a mesma página.

		foreach ( (array) $cfg['items'] as $item ) {
			$key   = ( 'html' === $item['type'] ? 'h:' : 't:' ) . $item['id'];
			$title = '' !== $item['title'] ? $item['title'] : __( 'Site information', 'scairato-ai-content-chat' );

			if ( 'text' === $item['type'] ) {
				$docs[ $key ] = array(
					'title' => $title,
					'url'   => '' !== $item['url'] ? self::resolve_url( $item['url'] ) : home_url( '/' ),
					'text'  => $item['text'],
					'error' => null,
				);
				continue;
			}

			$page = self::resolve_url( $item['url'] );
			if ( ! isset( $pages[ $page ] ) ) {
				$pages[ $page ] = SAIC_HTML::fetch( $page );
			}
			$doc = array(
				'title' => $title,
				'url'   => $page,
				'text'  => '',
				'error' => null,
			);
			if ( is_wp_error( $pages[ $page ] ) ) {
				$doc['error'] = $pages[ $page ];
			} else {
				$text = SAIC_HTML::extract( $pages[ $page ], $item['selector'] );
				if ( is_wp_error( $text ) ) {
					$doc['error'] = $text;
				} elseif ( '' === $text ) {
					$doc['error'] = new WP_Error( 'saic_empty', __( 'The selector matched nothing on the page.', 'scairato-ai-content-chat' ) );
				} else {
					$doc['text'] = $text;
				}
			}
			$docs[ $key ] = $doc;
		}

		$site = self::site_doc();
		if ( $site ) {
			$docs['o:site'] = $site;
		}
		return $docs;
	}

	/** Mostra o que um seletor extrai de uma página (usado pelo botão de pré-visualização). */
	public static function preview( $url, $selector ) {
		$html = SAIC_HTML::fetch( $url );
		if ( is_wp_error( $html ) ) {
			return $html;
		}
		$text = SAIC_HTML::extract( $html, $selector );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		if ( '' === $text ) {
			return new WP_Error( 'saic_empty', __( 'The selector matched nothing on the page.', 'scairato-ai-content-chat' ) );
		}
		return $text;
	}

	/* ------------------------------------------------------------ indexação */

	/**
	 * Sincroniza o índice com as fontes extras (só reindexa o que mudou, a menos que $force).
	 *
	 * @return array ['indexed','skipped','removed','errors'=>[]]
	 */
	public static function reindex( $force = false ) {
		global $wpdb;
		$res = array(
			'indexed'  => 0,
			'skipped'  => 0,
			'removed'  => 0,
			'errors'   => array(),
			'warnings' => self::option_warnings(),
		);

		if ( '' === SAIC_Settings::api_key() ) {
			$res['errors'][] = __( 'API key not set.', 'scairato-ai-content-chat' );
			return $res;
		}

		$table   = SAIC_DB::chunks_table();
		$docs    = self::build_docs();
		$hashes  = get_option( self::HASHES, array() );
		$hashes  = is_array( $hashes ) ? $hashes : array();
		$size    = (int) SAIC_Settings::get( 'chunk_size' );
		$overlap = (int) SAIC_Settings::get( 'chunk_overlap' );
		$model   = (string) SAIC_Settings::get( 'embedding_model' );
		$dims    = (int) SAIC_Settings::get( 'embedding_dimensions' );

		foreach ( $docs as $key => $doc ) {
			if ( is_wp_error( $doc['error'] ) ) {
				// Mantém o que já estava indexado e relata o problema.
				$res['errors'][] = $doc['title'] . ': ' . $doc['error']->get_error_message();
				continue;
			}

			$hash = md5( $doc['title'] . '|' . $doc['url'] . '|' . $doc['text'] . '|' . $model . '|' . $dims . "|$size|$overlap" );
			if ( ! $force && isset( $hashes[ $key ] ) && $hashes[ $key ] === $hash ) {
				++$res['skipped'];
				continue;
			}

			$chunks = SAIC_Chunker::split( $doc['text'], $size, $overlap );
			if ( ! $chunks ) {
				continue;
			}
			$inputs = array();
			foreach ( $chunks as $chunk ) {
				$inputs[] = $doc['title'] . "\n\n" . $chunk;
			}
			$vectors = SAIC_OpenAI::embed( $inputs );
			if ( is_wp_error( $vectors ) ) {
				$res['errors'][] = $doc['title'] . ': ' . $vectors->get_error_message();
				continue;
			}

			$wpdb->delete( $table, array( 'doc_key' => $key ), array( '%s' ) );
			$now = current_time( 'mysql', true );
			foreach ( $chunks as $i => $chunk ) {
				$wpdb->insert(
					$table,
					array(
						'post_id'     => 0,
						'doc_key'     => $key,
						'doc_title'   => $doc['title'],
						'doc_url'     => $doc['url'],
						'chunk_index' => $i,
						'content'     => $chunk,
						'embedding'   => SAIC_DB::pack_vector( $vectors[ $i ] ),
						'model'       => $model,
						'updated_at'  => $now,
					),
					array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
				);
			}
			$hashes[ $key ] = $hash;
			++$res['indexed'];
		}

		// Remove o que saiu da configuração (linha apagada, opção sem valor...).
		$existing = $wpdb->get_col( "SELECT DISTINCT doc_key FROM {$table} WHERE doc_key <> ''" ); // phpcs:ignore
		foreach ( $existing as $key ) {
			if ( ! isset( $docs[ $key ] ) ) {
				$wpdb->delete( $table, array( 'doc_key' => $key ), array( '%s' ) );
				++$res['removed'];
			}
		}
		foreach ( array_keys( $hashes ) as $key ) {
			if ( ! isset( $docs[ $key ] ) ) {
				unset( $hashes[ $key ] );
			}
		}
		update_option( self::HASHES, $hashes, false );

		return $res;
	}
}
