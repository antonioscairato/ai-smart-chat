<?php
/**
 * RAG pipeline: retrieval, context building and answer.
 *
 * @package Scairato_AI_Content_Chat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pipeline RAG: recupera trechos relevantes, monta o contexto e pede a resposta ao LLM.
 */
class SAIC_Chat {

	/**
	 * @param string $question
	 * @param array  $history  [ ['role' => 'user'|'assistant', 'content' => '...'], ... ]
	 * @return array|WP_Error  ['answer' => string, 'sources' => [ ['n','title','url','excerpt'] ], 'found' => bool]
	 */
	public static function ask( $question, array $history = array() ) {
		$question = trim( wp_strip_all_tags( $question ) );
		$history  = self::clean_history( $history );

		// Em perguntas de acompanhamento, usa a pergunta anterior para melhorar a busca.
		$retrieval = $question;
		for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
			if ( 'user' === $history[ $i ]['role'] ) {
				$retrieval = $history[ $i ]['content'] . "\n" . $question;
				break;
			}
		}
		$retrieval = apply_filters( 'saic_retrieval_query', $retrieval, $question, $history );

		// Perguntas de lista ("liste todos…", "quais são…"): mais páginas distintas, um trecho de cada.
		$list_n  = (int) SAIC_Settings::get( 'list_limit' );
		$is_list = $list_n > 0 && SAIC_Search::is_list_query( $question );
		$scope   = array();
		$hits    = null;
		if ( $is_list ) {
			$tm = SAIC_Search::term_members( $question, SAIC_Search::types_in_query( $question ), $list_n );
			if ( $tm ) {
				$hits  = $tm['hits'];
				$scope = $tm['terms'];
			}
		}
		if ( null === $hits && $is_list ) {
			$hits = SAIC_Search::search(
				$retrieval,
				$list_n,
				array(
					'unique' => true,
					'types'  => SAIC_Search::types_in_query( $question ),
				)
			);
		} elseif ( null === $hits ) {
			$hits = SAIC_Search::search( $retrieval );
		}
		if ( is_wp_error( $hits ) ) {
			return $hits;
		}

		if ( ! $hits ) {
			$answer = SAIC_Settings::text( 'fallback_message' );
			SAIC_DB::log_question( $question, $answer, array(), false );
			return self::finish(
				array(
					'answer'  => $answer,
					'sources' => array(),
					'found'   => false,
				),
				$question
			);
		}

		// Agrupa trechos por post: cada post vira uma fonte numerada.
		$sources = array();
		$context = array();
		foreach ( $hits as $hit ) {
			$pid = ( isset( $hit['doc_key'] ) && '' !== $hit['doc_key'] ) ? $hit['doc_key'] : 'p' . $hit['post_id'];
			if ( ! isset( $sources[ $pid ] ) ) {
				$n               = count( $sources ) + 1;
				$sources[ $pid ] = array(
					'n'       => $n,
					'title'   => $hit['title'],
					'url'     => $hit['url'],
					'excerpt' => self::excerpt( $hit['content'] ),
				);
				$context[ $pid ] = array();
			}
			$context[ $pid ][] = $is_list ? self::excerpt_len( $hit['content'], 400 ) : $hit['content'];
		}

		$blocks = array();
		foreach ( $sources as $pid => $src ) {
			$blocks[] = sprintf( "[%d] %s (%s)\n%s", $src['n'], $src['title'], $src['url'], implode( "\n---\n", $context[ $pid ] ) );
		}

		$system   = str_replace(
			array( '{site_name}', '{site_url}' ),
			array( get_bloginfo( 'name' ), home_url() ),
			SAIC_Settings::text( 'system_prompt' )
		);
		$system   = apply_filters( 'saic_system_prompt', $system, $question );
		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
		);
		foreach ( $history as $m ) {
			$messages[] = $m;
		}
		$messages[] = array(
			'role'    => 'user',
			'content' => 'Today: ' . wp_date( 'Y-m-d (l)' ) . ( $is_list ? self::list_instruction( $scope, count( $hits ) ) : '' ) . "\nIf the Context does not contain the answer, begin your reply with [[NOT_FOUND]], say briefly that you did not find it, and cite no source.\n\nContext:\n\n" . implode( "\n\n=====\n\n", $blocks ) . "\n\nQuestion: " . $question,
		);

		$plain = false;

		// Pergunta que só pede para listar/contar os itens de uma categoria: o plugin responde sozinho (exato, sem custo).
		if ( $scope && self::is_pure_enumeration( $question, $scope ) ) {
			$lines = array();
			foreach ( $sources as $src ) {
				$lines[] = sprintf( '- [%s](%s)', str_replace( array( '[', ']' ), '', $src['title'] ), $src['url'] );
			}
			$plain = true; // A lista já tem os links: não repete o bloco "Fontes".
			/* translators: 1: number of items, 2: category name(s) */
			$answer = sprintf( __( '%1$d items found in: %2$s', 'scairato-ai-content-chat' ), count( $sources ), implode( ', ', $scope ) ) . "\n\n" . implode( "\n", $lines );
		} else {
			$answer = SAIC_OpenAI::chat( $messages );
			if ( is_wp_error( $answer ) ) {
				return $answer;
			}
		}

		// Lista completa montada pelo plugin (o modelo tende a omitir itens cujo texto não cita o tema).
		if ( $scope && false !== strpos( $answer, '{{LIST}}' ) ) {
			$lines = array();
			foreach ( $sources as $src ) {
				$lines[] = sprintf( '- %s [%d]', $src['title'], $src['n'] );
			}
			$answer = str_replace( '{{LIST}}', implode( "\n", $lines ), $answer );
		}

		// "Não encontrei": sem fontes e sem citações soltas (o modelo às vezes cita algo sem relação).
		$not_found = ! $plain && self::is_not_found( $answer );
		$answer    = trim( str_replace( '[[NOT_FOUND]]', '', $answer ) );
		if ( $not_found ) {
			$answer = trim( preg_replace( '/\s*\[\d{1,3}\]/', '', $answer ) );
		}

		// Mostra apenas as fontes citadas; se nenhuma foi citada, as mais relevantes.
		$by_n = array();
		foreach ( $sources as $src ) {
			$by_n[ $src['n'] ] = $src;
		}
		$shown = array();
		if ( ! $plain && ! $not_found ) {
			if ( preg_match_all( '/\[(\d{1,3})\]/', $answer, $m ) ) {
				foreach ( array_unique( array_map( 'intval', $m[1] ) ) as $n ) {
					if ( isset( $by_n[ $n ] ) ) {
						$shown[] = $by_n[ $n ];
					}
				}
			}
			if ( ! $shown ) {
				$shown = array_slice( array_values( $sources ), 0, (int) SAIC_Settings::get( 'max_sources' ) );
			}
		}

		$result = array(
			'answer'  => $answer,
			'sources' => array_values( $shown ),
			'found'   => ! $not_found,
		);

		SAIC_DB::log_question(
			$question,
			$answer,
			array_map(
				function ( $s ) {
					return array(
						'title' => $s['title'],
						'url'   => $s['url'],
					);
				},
				$result['sources']
			),
			! $not_found
		);

		return self::finish( $result, $question );
	}

	protected static function finish( array $result, $question ) {
		/**
		 * Filtra a resposta final enviada ao front-end.
		 */
		return apply_filters( 'saic_response', $result, $question );
	}

	/** O modelo disse que não encontrou a resposta? (marcador ou frase de abertura, em resposta curta) */
	protected static function is_not_found( $answer ) {
		if ( false !== strpos( $answer, '[[NOT_FOUND]]' ) ) {
			return true;
		}
		$clean = trim( preg_replace( '/\s*\[\d{1,3}\]/', '', $answer ) );
		$fb    = trim( (string) SAIC_Settings::text( 'fallback_message' ) );
		if ( '' !== $fb && false !== mb_stripos( $clean, mb_substr( $fb, 0, 40 ) ) ) {
			return true;
		}
		$re = '/^\W*(n[ãa]o (encontrei|consegui encontrar|localizei|h[áa]|existe)|no (encontr[ée]|he encontrado|hay)|i (could not|couldn\'t|did not|didn\'t|was unable to) find|there is no)/iu';
		$is = (bool) ( mb_strlen( $clean ) < 350 && preg_match( $re, $clean ) );
		return (bool) apply_filters( 'saic_answer_not_found', $is, $answer );
	}

	/** A pergunta só pede para listar/contar tudo (sem outra condição além do tipo e da categoria)? */
	protected static function is_pure_enumeration( $question, array $scope ) {
		$rest = SAIC_Search::tokens( $question );
		$drop = SAIC_Search::tokens( implode( ' ', $scope ) );
		foreach ( SAIC_Indexer::allowed_post_types() as $type ) {
			$obj = get_post_type_object( $type );
			if ( $obj ) {
				$drop += SAIC_Search::tokens( $obj->labels->name . ' ' . $obj->labels->singular_name );
			}
		}
		$filler = 'liste listar listagem lista quais quantos quantas todos todas existem existe temos possui possuem disponiveis cadastrados mostre mostrar apresente itens item site loja there have available list show many much which what exist existen hay cuantos cuantas cuales tienen muestra';
		$drop  += SAIC_Search::tokens( $filler );
		return ! array_diff_key( $rest, $drop );
	}

	protected static function list_instruction( array $scope, $count ) {
		$t = "\nThe question asks for a list: include every matching item found in the Context, each with its [n] citation. Answer in the same language as the question.";
		if ( $scope ) {
			$t .= ' The Context contains ALL ' . (int) $count . ' items of: ' . implode( ', ', $scope ) . '. Every one of them belongs to it, even if its text does not mention it.'
				. ' If the question simply asks to list or count them, reply with ONE short sentence that includes the number ' . (int) $count . ', then on its own line the exact token {{LIST}} (the system replaces it with the complete list; do not write the list yourself).'
				. ' If the question adds other conditions (for example, only the ones that control a given pest), do not use the token: answer normally, choosing from the Context.';
		}
		return $t;
	}

	protected static function excerpt_len( $text, $len ) {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		return mb_strlen( $text ) > $len ? mb_substr( $text, 0, $len - 1 ) . '…' : $text;
	}

	protected static function excerpt( $text ) {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		return mb_strlen( $text ) > 180 ? mb_substr( $text, 0, 177 ) . '…' : $text;
	}

	public static function clean_history( array $history ) {
		$max = (int) SAIC_Settings::get( 'history_messages' );
		if ( $max <= 0 ) {
			return array();
		}
		$out = array();
		foreach ( $history as $m ) {
			if ( ! is_array( $m ) || empty( $m['role'] ) || ! isset( $m['content'] ) ) {
				continue;
			}
			$role = 'assistant' === $m['role'] ? 'assistant' : ( 'user' === $m['role'] ? 'user' : '' );
			if ( '' === $role || ! is_string( $m['content'] ) ) {
				continue;
			}
			$content = trim( wp_strip_all_tags( $m['content'] ) );
			if ( '' === $content ) {
				continue;
			}
			$out[] = array(
				'role'    => $role,
				'content' => mb_substr( $content, 0, 1500 ),
			);
		}
		return array_slice( $out, -$max );
	}
}
