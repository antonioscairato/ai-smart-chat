<?php
/**
 * Semantic search and list-question helpers.
 *
 * @package AI_Smart_Chat
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names come from $wpdb->prefix, never from user input.
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- custom tables of this plugin; names come from $wpdb->prefix and every value is passed through prepare()/intval().

/**
 * Busca semântica: similaridade de cosseno entre a pergunta e os trechos indexados.
 *
 * Implementação em PHP puro (sem extensões de banco vetorial), adequada a sites de
 * pequeno e médio porte (alguns milhares de trechos). Para escalar, substitua o
 * armazenamento pelo filtro 'saic_search_hits'.
 */
class SAIC_Search {

	/**
	 * @param string   $query
	 * @param int|null $limit Quantidade máxima de trechos (ou de páginas, com 'unique').
	 * @param array    $args  'unique' => um único trecho por página; 'types' => tipos de conteúdo preferidos.
	 * @return array|WP_Error Lista de hits ordenada por relevância.
	 */
	public static function search( $query, $limit = null, $args = array() ) {
		global $wpdb;

		$limit = $limit ? (int) $limit : (int) SAIC_Settings::get( 'top_k' );
		$min   = (float) SAIC_Settings::get( 'min_score' );

		$vectors = SAIC_OpenAI::embed( array( $query ) );
		if ( is_wp_error( $vectors ) ) {
			return $vectors;
		}
		$q = $vectors[0];

		$override = apply_filters( 'saic_search_hits', null, $q, $limit );
		if ( is_array( $override ) ) {
			return $override;
		}

		$table = SAIC_DB::chunks_table();
		$last  = 0;
		$top   = array(); // Mantém apenas os $limit melhores.
		$uniq  = ! empty( $args['unique'] );
		$best  = array(); // Modo "unique": melhor trecho de cada página.

		do {
			$rows       = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, post_id, doc_key, doc_title, doc_url, chunk_index, content, embedding FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT 400",
					$last
				)
			);
			$batch_size = count( $rows );
			foreach ( $rows as $row ) {
				$last = (int) $row->id;
				$d    = SAIC_DB::unpack_vector( $row->embedding );
				$n    = min( count( $q ), count( $d ) );
				if ( count( $q ) !== count( $d ) ) {
					continue; // Dimensões diferentes: o índice precisa ser refeito.
				}
				$score = 0.0;
				for ( $i = 0; $i < $n; $i++ ) {
					$score += $q[ $i ] * $d[ $i + 1 ];
				}
				if ( $score < $min ) {
					continue;
				}
				$hit = array(
					'score'       => $score,
					'post_id'     => (int) $row->post_id,
					'doc_key'     => (string) $row->doc_key,
					'doc_title'   => (string) $row->doc_title,
					'doc_url'     => (string) $row->doc_url,
					'chunk_index' => (int) $row->chunk_index,
					'content'     => $row->content,
				);
				if ( $uniq ) {
					$k = '' !== $hit['doc_key'] ? $hit['doc_key'] : 'p' . $hit['post_id'];
					if ( ! isset( $best[ $k ] ) || $best[ $k ]['score'] < $score ) {
						$best[ $k ] = $hit;
					}
				} else {
					$top[] = $hit;
				}
			}
			if ( ! $uniq && count( $top ) > $limit * 3 ) {
				usort(
					$top,
					function ( $a, $b ) {
						return $b['score'] <=> $a['score'];
					}
				);
				$top = array_slice( $top, 0, $limit );
			}
		} while ( 400 === $batch_size );

		if ( $uniq ) {
			$top = array_values( $best );
			usort(
				$top,
				function ( $a, $b ) {
					return $b['score'] <=> $a['score'];
				}
			);
			$top = self::prefer_types( array_slice( $top, 0, $limit * 3 ), isset( $args['types'] ) ? (array) $args['types'] : array() );
		}

		usort(
			$top,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		$top = array_slice( $top, 0, $limit );

		// Completa com dados do post e descarta o que deixou de ser público.
		$hits = array();
		foreach ( $top as $hit ) {
			if ( '' !== $hit['doc_key'] ) {
				// Fonte extra (sem post): título e link vêm da própria linha.
				$hit['title'] = $hit['doc_title'];
				$hit['url']   = '' !== $hit['doc_url'] ? $hit['doc_url'] : home_url( '/' );
				$hits[]       = $hit;
				continue;
			}
			if ( ! SAIC_Indexer::is_indexable( $hit['post_id'] ) ) {
				continue;
			}
			$hit['title'] = html_entity_decode( get_the_title( $hit['post_id'] ), ENT_QUOTES, 'UTF-8' );
			$hit['url']   = get_permalink( $hit['post_id'] );
			$hits[]       = $hit;
		}

		/**
		 * Filtra os trechos recuperados antes de montar o contexto do LLM.
		 */
		return apply_filters( 'saic_context_chunks', $hits, $query );
	}

	/* ------------------------------------------------------- perguntas de lista */

	/** A pergunta pede uma lista ou "todos" de alguma coisa? */
	public static function is_list_query( $q ) {
		$re = '/(?<![\p{L}])(list[ae]r?|liste|listar|relacion[ae]|enumere|todos|todas|quais|quantos|quantas|cu[aá]les|todos los|todas las|all|every|which|list|how many)(?![\p{L}])/iu';
		$is = (bool) preg_match( $re, $q );
		return (bool) apply_filters( 'saic_is_list_query', $is, $q );
	}

	/* ------------------------------------------- listas por categoria/termo */

	/** Palavras (4+ letras, sem acento) de um texto: [ prefixo de 6 letras => tamanho original ]. */
	public static function tokens( $text ) {
		$text = remove_accents( mb_strtolower( (string) $text, 'UTF-8' ) );
		preg_match_all( '/[a-z0-9]{4,}/', $text, $m );
		$stop = array( 'para', 'com', 'sem', 'por', 'dos', 'das', 'the', 'and', 'for', 'del', 'los', 'las', 'que', 'uma', 'uns' );
		$out  = array();
		foreach ( $m[0] as $t ) {
			if ( ! in_array( $t, $stop, true ) ) {
				$out[ substr( $t, 0, 6 ) ] = strlen( $t ); // Prefixo simples: pastagem ~ pastagens.
			}
		}
		return $out;
	}

	/**
	 * Quais termos (categorias, linhas...) a pergunta cita? Função pura, fácil de testar.
	 *
	 * @param array  $terms    id => nome do termo (de uma taxonomia).
	 * @param string $question
	 * @return array ids dos termos citados.
	 */
	public static function match_terms( array $terms, $question ) {
		$q     = array_keys( self::tokens( $question ) );
		$count = array();
		$tok   = array();
		foreach ( $terms as $id => $name ) {
			$tok[ $id ] = self::tokens( $name );
			foreach ( array_keys( $tok[ $id ] ) as $t ) {
				$count[ $t ] = isset( $count[ $t ] ) ? $count[ $t ] + 1 : 1;
			}
		}

		// 1) Regra estrita: todas as palavras distintivas do termo aparecem na pergunta.
		$found = array();
		foreach ( $tok as $id => $map ) {
			$list     = array_keys( $map );
			$distinct = count( $terms ) >= 3 ? array_values(
				array_filter(
					$list,
					function ( $t ) use ( $count ) {
						return $count[ $t ] < 2;
					}
				)
			) : $list;
			$use      = $distinct ? $distinct : $list;
			if ( $use && ! array_diff( $use, $q ) ) {
				$found[] = $id;
			}
		}

		// 2) Regra flexível ("Linha Pastagem" ~ "pastagens"): uma palavra longa e exclusiva do termo basta.
		if ( ! $found ) {
			foreach ( $tok as $id => $map ) {
				foreach ( $map as $t => $len ) {
					if ( $len >= 7 && 1 === $count[ $t ] && in_array( $t, $q, true ) ) {
						$found[] = $id;
						break;
					}
				}
			}
		}
		return $found;
	}

	/**
	 * Para perguntas de lista que citam uma categoria/termo do site, devolve TODOS os itens dele.
	 *
	 * @return array|null ['hits' => [...], 'terms' => [nomes]] ou null se nenhum termo foi citado.
	 */
	public static function term_members( $question, array $types, $limit ) {
		if ( ! apply_filters( 'saic_term_expansion', true, $question ) ) {
			return null;
		}
		$types = $types ? $types : SAIC_Indexer::allowed_post_types();
		$skip  = array( 'post_format', 'product_type', 'product_visibility', 'language', 'post_translations', 'term_language', 'term_translations' );
		$tax_q = array();
		$names = array();
		$done  = array();

		foreach ( $types as $type ) {
			foreach ( get_object_taxonomies( $type, 'objects' ) as $tax ) {
				if ( ! $tax->public || in_array( $tax->name, $skip, true ) || isset( $done[ $tax->name ] ) ) {
					continue;
				}
				$done[ $tax->name ] = true;
				$terms              = get_terms(
					array(
						'taxonomy'   => $tax->name,
						'hide_empty' => true,
						'number'     => 0,
					)
				);
				if ( is_wp_error( $terms ) || ! $terms ) {
					continue;
				}
				$map = array();
				foreach ( $terms as $t ) {
					$map[ $t->term_id ] = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
				}
				$hit = self::match_terms( $map, $question );
				if ( $hit ) {
					$tax_q[] = array(
						'taxonomy' => $tax->name,
						'field'    => 'term_id',
						'terms'    => $hit,
					);
					foreach ( $hit as $id ) {
						$names[] = $map[ $id ];
					}
				}
			}
		}
		if ( ! $tax_q ) {
			return null;
		}

		$ids = array();
		foreach ( array( 'AND', 'OR' ) as $rel ) {
			if ( 'OR' === $rel && count( $tax_q ) < 2 ) {
				break;
			}
			$q   = new WP_Query(
				array(
					'post_type'              => $types,
					'post_status'            => 'publish',
					'has_password'           => false,
					'posts_per_page'         => (int) $limit,
					'orderby'                => 'title',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'suppress_filters'       => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- needed to list every item of a category; bounded by posts_per_page.
					'tax_query'              => array_merge( array( 'relation' => $rel ), $tax_q ),
				)
			);
			$ids = $q->posts;
			if ( $ids ) {
				break;
			}
		}
		if ( ! $ids ) {
			return null;
		}

		global $wpdb;
		$table = SAIC_DB::chunks_table();
		$in    = implode( ',', array_map( 'intval', $ids ) );
		$rows  = $wpdb->get_results( "SELECT post_id, content FROM {$table} WHERE post_id IN ({$in}) AND chunk_index = 0" ); // phpcs:ignore
		$body  = array();
		foreach ( (array) $rows as $r ) {
			$body[ (int) $r->post_id ] = $r->content;
		}

		$hits = array();
		foreach ( $ids as $id ) {
			if ( ! SAIC_Indexer::is_indexable( $id ) ) {
				continue;
			}
			$hits[] = array(
				'score'       => 1.0,
				'post_id'     => (int) $id,
				'doc_key'     => '',
				'doc_title'   => '',
				'doc_url'     => '',
				'chunk_index' => 0,
				'content'     => isset( $body[ $id ] ) ? $body[ $id ] : wp_strip_all_tags( get_the_excerpt( $id ) ),
				'title'       => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'url'         => get_permalink( $id ),
			);
		}
		return $hits ? array(
			'hits'  => $hits,
			'terms' => array_values( array_unique( $names ) ),
		) : null;
	}

	/** Tipos de conteúdo citados na pergunta (ex.: "produtos" → product). */
	public static function types_in_query( $q ) {
		$found = array();
		foreach ( SAIC_Indexer::allowed_post_types() as $type ) {
			$obj = get_post_type_object( $type );
			if ( ! $obj ) {
				continue;
			}
			foreach ( array( $obj->labels->name, $obj->labels->singular_name ) as $label ) {
				$label = trim( (string) $label );
				if ( '' !== $label && preg_match( '/(?<![\p{L}])' . preg_quote( $label, '/' ) . '(?![\p{L}])/iu', $q ) ) {
					$found[] = $type;
					break;
				}
			}
		}
		return $found;
	}

	/** Dá preferência (bônus de pontuação) a páginas dos tipos citados na pergunta. */
	protected static function prefer_types( array $hits, array $types ) {
		if ( ! $types ) {
			return $hits;
		}
		foreach ( $hits as &$h ) {
			if ( '' === $h['doc_key'] && in_array( get_post_type( $h['post_id'] ), $types, true ) ) {
				$h['score'] += 0.15;
			}
		}
		unset( $h );
		usort(
			$hits,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		return $hits;
	}
}
