<?php
/**
 * Turns posts into indexable text and chunks.
 *
 * @package AI_Smart_Chat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Converte posts em texto limpo e divide em trechos para indexação.
 */
class SAIC_Chunker {

	/**
	 * Cabeçalho de contexto de um post (título, tipo e termos). Entra no vetor de cada trecho
	 * para que a busca "saiba" a que tipo e categoria o trecho pertence.
	 */
	public static function header( WP_Post $post ) {
		$lines = array( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) );
		$obj   = get_post_type_object( $post->post_type );
		if ( $obj ) {
			/* translators: %s: content type name (for example, Product) */
			$lines[] = sprintf( __( 'Type: %s', 'ai-smart-chat' ), $obj->labels->singular_name );
		}
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
			if ( ! $tax->public ) {
				continue;
			}
			$terms = get_the_terms( $post, $tax->name );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$lines[] = $tax->labels->singular_name . ': ' . implode( ', ', wp_list_pluck( $terms, 'name' ) );
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Texto indexável de um post: conteúdo renderizado (compatível com Gutenberg,
	 * Elementor, Avada etc.), resumo, taxonomias, dados de produto e metas extras.
	 */
	public static function post_to_text( WP_Post $post ) {
		$parts = array();

		if ( has_excerpt( $post ) ) {
			$parts[] = wp_strip_all_tags( $post->post_excerpt );
		}

		$parts[] = self::render_content( $post );

		// Taxonomias públicas (categorias, tags, categorias de produto, etc.).
		$terms_line = array();
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
			if ( ! $tax->public ) {
				continue;
			}
			$terms = get_the_terms( $post, $tax->name );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$terms_line[] = $tax->labels->singular_name . ': ' . implode( ', ', wp_list_pluck( $terms, 'name' ) );
			}
		}
		if ( $terms_line ) {
			$parts[] = implode( '. ', $terms_line );
		}

		// WooCommerce.
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$parts[] = self::woo_text( $post->ID );
		}

		// Metas extras definidas nas configurações (ex.: datas e local de eventos).
		$keys = preg_split( '/\r\n|\r|\n/', (string) SAIC_Settings::get( 'extra_meta_keys' ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $keys as $key ) {
			$key = trim( $key );
			$val = get_post_meta( $post->ID, $key, true );
			if ( is_scalar( $val ) && '' !== (string) $val ) {
				$parts[] = $key . ': ' . wp_strip_all_tags( (string) $val );
			}
		}

		$text = implode( "\n", array_filter( array_map( 'trim', $parts ) ) );
		$text = self::clean( $text );

		/**
		 * Filtra o texto final indexado para um post.
		 */
		return (string) apply_filters( 'saic_post_text', $text, $post );
	}

	protected static function render_content( WP_Post $post ) {
		global $wp_query;
		$previous = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

		SAIC_Indexer::$indexing = true; // Impede que o shortcode do próprio plugin seja renderizado.
		$GLOBALS['post']        = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- temporary, restored below so shortcodes and blocks render in the post's context.
		setup_postdata( $post );

		$html = '';
		try {
			$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, needed to render blocks and shortcodes.
		} catch ( Throwable $e ) {
			$html = $post->post_content;
		}

		SAIC_Indexer::$indexing = false;
		if ( $previous ) {
			$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the original global.
			setup_postdata( $previous );
		} else {
			wp_reset_postdata();
		}

		// Preserva quebras de parágrafo antes de remover as tags.
		$html = preg_replace( '#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = preg_replace( '#</(p|div|li|h[1-6]|tr|blockquote|section|article)>|<br\s*/?>#i', "\n", $html );
		return wp_strip_all_tags( $html );
	}

	protected static function woo_text( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return '';
		}
		$lines = array();
		if ( $product->get_short_description() ) {
			$lines[] = wp_strip_all_tags( $product->get_short_description() );
		}
		if ( $product->get_sku() ) {
			$lines[] = 'SKU: ' . $product->get_sku();
		}
		$price = $product->get_price();
		if ( '' !== $price ) {
			/* translators: %s: product price */
			$lines[] = sprintf( __( 'Price: %s', 'ai-smart-chat' ), wp_strip_all_tags( html_entity_decode( wc_price( $price ) ) ) );
		}
		$lines[] = $product->is_in_stock() ? __( 'Availability: in stock', 'ai-smart-chat' ) : __( 'Availability: out of stock', 'ai-smart-chat' );
		foreach ( $product->get_attributes() as $attr ) {
			if ( is_object( $attr ) && method_exists( $attr, 'get_name' ) ) {
				$name = wc_attribute_label( $attr->get_name() );
				if ( $attr->is_taxonomy() ) {
					$values = wc_get_product_terms( $product_id, $attr->get_name(), array( 'fields' => 'names' ) );
				} else {
					$values = $attr->get_options();
				}
				if ( $values ) {
					$lines[] = $name . ': ' . implode( ', ', $values );
				}
			}
		}
		return implode( "\n", $lines );
	}

	public static function clean( $text ) {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text );
		$text = preg_replace( '/\s*\n\s*/', "\n", $text );
		return trim( $text );
	}

	/**
	 * Divide o texto em trechos de ~$max caracteres respeitando parágrafos,
	 * com sobreposição ($overlap) para não perder contexto entre trechos.
	 *
	 * @return string[]
	 */
	public static function split( $text, $max = 1200, $overlap = 150 ) {
		$max     = max( 300, (int) $max );
		$overlap = max( 0, min( (int) $overlap, (int) ( $max / 2 ) ) );

		// Quebra parágrafos grandes demais em frases / pedaços.
		$units = array();
		foreach ( preg_split( '/\n+/', $text, -1, PREG_SPLIT_NO_EMPTY ) as $para ) {
			if ( mb_strlen( $para ) <= $max ) {
				$units[] = $para;
				continue;
			}
			$sentences = preg_split( '/(?<=[.!?…])\s+/u', $para, -1, PREG_SPLIT_NO_EMPTY );
			foreach ( $sentences as $s ) {
				while ( mb_strlen( $s ) > $max ) {
					$units[] = mb_substr( $s, 0, $max );
					$s       = mb_substr( $s, $max );
				}
				if ( '' !== $s ) {
					$units[] = $s;
				}
			}
		}

		$chunks  = array();
		$current = '';
		foreach ( $units as $unit ) {
			if ( '' !== $current && mb_strlen( $current ) + mb_strlen( $unit ) + 1 > $max ) {
				$chunks[] = $current;
				$tail     = $overlap ? self::tail( $current, $overlap ) : '';
				$current  = $tail;
			}
			$current .= ( '' === $current ? '' : "\n" ) . $unit;
		}
		if ( '' !== trim( $current ) ) {
			$chunks[] = $current;
		}
		return array_values( array_filter( array_map( 'trim', $chunks ) ) );
	}

	protected static function tail( $text, $len ) {
		if ( mb_strlen( $text ) <= $len ) {
			return $text;
		}
		$tail = mb_substr( $text, -$len );
		$pos  = mb_strpos( $tail, ' ' );
		return false === $pos ? $tail : mb_substr( $tail, $pos + 1 );
	}
}
