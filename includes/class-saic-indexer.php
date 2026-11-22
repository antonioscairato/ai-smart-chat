<?php
/**
 * Indexing of posts into the vector table.
 *
 * @package AI_Smart_Chat
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names come from $wpdb->prefix, never from user input.
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- custom tables of this plugin; names come from $wpdb->prefix and every value is passed through prepare()/intval().

/**
 * Indexação: mantém a tabela de trechos/embeddings sincronizada com o conteúdo.
 */
class SAIC_Indexer {

	/** True enquanto o conteúdo está sendo renderizado para indexação. */
	public static $indexing = false;

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'saic_index_post', array( __CLASS__, 'cron_index' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_post' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'delete_post' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 20, 3 );
	}

	public static function allowed_post_types() {
		$types = (array) SAIC_Settings::get( 'post_types' );
		return array_values( array_filter( $types, 'post_type_exists' ) );
	}

	public static function is_indexable( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		$ok = in_array( $post->post_type, self::allowed_post_types(), true )
			&& 'publish' === $post->post_status
			&& '' === $post->post_password;
		return (bool) apply_filters( 'saic_is_indexable', $ok, $post );
	}

	/* ------------------------------------------------------------------ hooks */

	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! SAIC_Settings::get( 'auto_index' ) ) {
			return;
		}
		if ( self::is_indexable( $post ) ) {
			self::schedule( $post_id );
		} else {
			self::delete_post( $post_id );
		}
	}

	public static function on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status && 'publish' === $old_status ) {
			self::delete_post( $post->ID );
		}
	}

	/** Agenda a indexação em segundo plano para não atrasar o salvamento. */
	public static function schedule( $post_id ) {
		if ( ! wp_next_scheduled( 'saic_index_post', array( $post_id ) ) ) {
			wp_schedule_single_event( time() + 15, 'saic_index_post', array( $post_id ) );
		}
	}

	public static function cron_index( $post_id ) {
		self::index_post( (int) $post_id );
	}

	/* --------------------------------------------------------------- operations */

	/**
	 * Indexa (ou remove) um post.
	 *
	 * @return array|WP_Error ['status' => indexed|skipped|removed, 'chunks' => int]
	 */
	public static function index_post( $post_id, $force = false ) {
		global $wpdb;
		$post = get_post( $post_id );

		if ( ! $post || ! self::is_indexable( $post ) ) {
			self::delete_post( $post_id );
			return array(
				'status' => 'removed',
				'chunks' => 0,
			);
		}

		$text = SAIC_Chunker::post_to_text( $post );
		if ( '' === $text ) {
			self::delete_post( $post_id );
			return array(
				'status' => 'removed',
				'chunks' => 0,
			);
		}

		$size    = (int) SAIC_Settings::get( 'chunk_size' );
		$overlap = (int) SAIC_Settings::get( 'chunk_overlap' );
		$model   = (string) SAIC_Settings::get( 'embedding_model' );
		$header  = SAIC_Chunker::header( $post );
		$hash    = md5( 'v2|' . $header . '|' . $text . '|' . $model . '|' . SAIC_Settings::get( 'embedding_dimensions' ) . "|$size|$overlap" );

		// Só pula se o hash bate E ainda existem trechos no índice (o hash sozinho pode ficar "órfão").
		$has_chunks = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . SAIC_DB::chunks_table() . ' WHERE post_id = %d LIMIT 1', $post_id ) ); // phpcs:ignore
		if ( ! $force && $has_chunks && get_post_meta( $post_id, '_saic_hash', true ) === $hash ) {
			return array(
				'status' => 'skipped',
				'chunks' => 0,
			);
		}

		$chunks = SAIC_Chunker::split( $text, $size, $overlap );
		if ( ! $chunks ) {
			self::delete_post( $post_id );
			return array(
				'status' => 'removed',
				'chunks' => 0,
			);
		}

		// Posts longos ganham um trecho-resumo ("catálogo") que compete em igualdade com os curtos.
		if ( count( $chunks ) > 1 ) {
			$chunks[] = $header . "\n" . mb_substr( preg_replace( '/\s+/u', ' ', $text ), 0, 280 );
		}
		$inputs = array();
		foreach ( $chunks as $chunk ) {
			$inputs[] = $header . "\n\n" . $chunk;
		}

		$vectors = SAIC_OpenAI::embed( $inputs );
		if ( is_wp_error( $vectors ) ) {
			return $vectors;
		}

		// Troca atômica-ish: só remove os antigos depois de ter os novos vetores.
		$table = SAIC_DB::chunks_table();
		$wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) );

		$now = current_time( 'mysql', true );
		foreach ( $chunks as $i => $chunk ) {
			$wpdb->insert(
				$table,
				array(
					'post_id'     => $post_id,
					'chunk_index' => $i,
					'content'     => $chunk,
					'embedding'   => SAIC_DB::pack_vector( $vectors[ $i ] ),
					'model'       => $model,
					'updated_at'  => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s' )
			);
		}

		update_post_meta( $post_id, '_saic_hash', $hash );
		update_post_meta( $post_id, '_saic_indexed_at', time() );

		return array(
			'status' => 'indexed',
			'chunks' => count( $chunks ),
		);
	}

	public static function delete_post( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;
		$wpdb->delete( SAIC_DB::chunks_table(), array( 'post_id' => $post_id ), array( '%d' ) );
		delete_post_meta( $post_id, '_saic_hash' );
		delete_post_meta( $post_id, '_saic_indexed_at' );
	}

	/** Remove trechos de posts que não são mais indexáveis (tipo desmarcado, despublicado, excluído). */
	public static function purge_orphans() {
		global $wpdb;
		$types = self::allowed_post_types();
		$table = SAIC_DB::chunks_table();

		if ( ! $types ) {
			$wpdb->query( "DELETE FROM {$table} WHERE doc_key = ''" ); // Mantém as fontes extras.
			return;
		}
		$ph = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $ph holds one %s placeholder per post type.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE c FROM {$table} c LEFT JOIN {$wpdb->posts} p ON p.ID = c.post_id
				 WHERE c.doc_key = '' AND ( p.ID IS NULL OR p.post_status <> 'publish' OR p.post_type NOT IN ({$ph}) )",
				$types
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	public static function clear_index() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . SAIC_DB::chunks_table() );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_saic_hash','_saic_indexed_at')" );
		delete_option( SAIC_Sources::HASHES );
	}

	/**
	 * Processa um lote de posts (usado pelo admin via AJAX e pelo WP-CLI).
	 *
	 * @return array ['processed','indexed','skipped','removed','errors'=>[],'total','next'(int|null)]
	 */
	public static function reindex_batch( $offset = 0, $limit = 5, $force = false, $post_type = '' ) {
		$types = $post_type ? array_intersect( array( $post_type ), self::allowed_post_types() ) : self::allowed_post_types();
		$res   = array(
			'processed' => 0,
			'indexed'   => 0,
			'skipped'   => 0,
			'removed'   => 0,
			'errors'    => array(),
			'total'     => 0,
			'next'      => null,
		);
		if ( ! $types ) {
			return $res;
		}

		if ( 0 === (int) $offset && ! $post_type ) {
			self::purge_orphans();

			// Fontes extras (informações fixas, opções do WP, trechos de páginas) entram no primeiro lote.
			$src             = SAIC_Sources::reindex( $force );
			$res['indexed'] += $src['indexed'];
			$res['skipped'] += $src['skipped'];
			$res['removed'] += $src['removed'];
			$res['errors']   = array_merge( $res['errors'], $src['errors'] );
		}

		$q = new WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);

		$res['total'] = (int) $q->found_posts;
		foreach ( $q->posts as $id ) {
			$r = self::index_post( $id, $force );
			++$res['processed'];
			if ( is_wp_error( $r ) ) {
				$res['errors'][] = sprintf( '#%d: %s', $id, $r->get_error_message() );
			} else {
				++$res[ $r['status'] ];
			}
		}

		$next        = $offset + $limit;
		$res['next'] = ( $next < $res['total'] ) ? $next : null;
		return $res;
	}

	public static function status() {
		global $wpdb;
		$types = self::allowed_post_types();
		$total = 0;
		if ( $types ) {
			$q     = new WP_Query(
				array(
					'post_type'      => $types,
					'post_status'    => 'publish',
					'has_password'   => false,
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			$total = (int) $q->found_posts;
		}
		$table = SAIC_DB::chunks_table();
		return array(
			'eligible' => $total,
			'indexed'  => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$table} WHERE doc_key = ''" ),
			'sources'  => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT doc_key) FROM {$table} WHERE doc_key <> ''" ),
			'chunks'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
			'model'    => (string) $wpdb->get_var( "SELECT model FROM {$table} ORDER BY id DESC LIMIT 1" ),
			'has_key'  => '' !== SAIC_Settings::api_key(),
		);
	}
}
