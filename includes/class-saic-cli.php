<?php
/**
 * WP-CLI commands (wp saic).
 *
 * @package Scairato_AI_Content_Chat
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI commands: wp saic <command>
 */
class SAIC_CLI extends WP_CLI_Command {

	/**
	 * Shows the index status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp saic status
	 */
	public function status( $args, $assoc_args ) {
		$st = SAIC_Indexer::status();
		WP_CLI::log( 'API key:            ' . ( $st['has_key'] ? 'set' : 'NOT set' ) );
		WP_CLI::log( 'Indexed types:      ' . implode( ', ', SAIC_Indexer::allowed_post_types() ) );
		WP_CLI::log( 'Eligible items:     ' . $st['eligible'] );
		WP_CLI::log( 'Indexed items:      ' . $st['indexed'] );
		WP_CLI::log( 'Chunks:             ' . $st['chunks'] );
		WP_CLI::log( 'Index model:        ' . ( $st['model'] ? $st['model'] : '-' ) );
	}

	/**
	 * Indexes content (only what changed, by default).
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Reindex everything, even unchanged items (use after changing the model/dimensions).
	 *
	 * [--post_type=<type>]
	 * : Limit to one content type (it must be enabled in the plugin settings).
	 *
	 * [--batch=<n>]
	 * : Posts per batch. Default: 10.
	 *
	 * ## EXAMPLES
	 *
	 *     wp saic index
	 *     wp saic index --force --post_type=product
	 */
	public function index( $args, $assoc_args ) {
		$force = isset( $assoc_args['force'] );
		$type  = isset( $assoc_args['post_type'] ) ? sanitize_key( $assoc_args['post_type'] ) : '';
		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 10;

		if ( '' === SAIC_Settings::api_key() ) {
			WP_CLI::error( 'API key not set.' );
		}
		if ( $type && ! in_array( $type, SAIC_Indexer::allowed_post_types(), true ) ) {
			WP_CLI::error( "The type '{$type}' is not enabled in the plugin settings." );
		}

		$offset   = 0;
		$tot      = array(
			'indexed' => 0,
			'skipped' => 0,
			'removed' => 0,
			'errors'  => 0,
		);
		$progress = null;

		do {
			$res = SAIC_Indexer::reindex_batch( $offset, $batch, $force, $type );
			if ( null === $progress && $res['total'] > 0 ) {
				$progress = \WP_CLI\Utils\make_progress_bar( 'Indexing', $res['total'] );
			}
			foreach ( array( 'indexed', 'skipped', 'removed' ) as $k ) {
				$tot[ $k ] += $res[ $k ];
			}
			foreach ( $res['errors'] as $e ) {
				++$tot['errors'];
				WP_CLI::warning( $e );
			}
			if ( $progress ) {
				for ( $i = 0; $i < $res['processed']; $i++ ) {
					$progress->tick();
				}
			}
			$offset = $res['next'];
		} while ( null !== $offset && $res['processed'] > 0 );

		if ( $progress ) {
			$progress->finish();
		}
		WP_CLI::success( sprintf( '%d indexed, %d unchanged, %d removed, %d errors.', $tot['indexed'], $tot['skipped'], $tot['removed'], $tot['errors'] ) );
	}

	/**
	 * Indexes the extra sources only (fixed text, WordPress options and page excerpts).
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Reindex everything, even unchanged sources.
	 *
	 * ## EXAMPLES
	 *
	 *     wp saic sources
	 */
	public function sources( $args, $assoc_args ) {
		$res = SAIC_Sources::reindex( isset( $assoc_args['force'] ) );
		foreach ( array_merge( $res['warnings'], $res['errors'] ) as $e ) {
			WP_CLI::warning( $e );
		}
		WP_CLI::success( sprintf( '%d indexed, %d unchanged, %d removed, %d errors.', $res['indexed'], $res['skipped'], $res['removed'], count( $res['errors'] ) ) );
	}

	/**
	 * Indexes a single post.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post ID.
	 *
	 * [--force]
	 * : Skip the change check.
	 *
	 * @subcommand index-post
	 */
	public function index_post( $args, $assoc_args ) {
		$res = SAIC_Indexer::index_post( (int) $args[0], isset( $assoc_args['force'] ) );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Post %d: %s (%d chunks).', $args[0], $res['status'], $res['chunks'] ) );
	}

	/**
	 * Removes a post from the index.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post ID.
	 */
	public function delete( $args, $assoc_args ) {
		SAIC_Indexer::delete_post( (int) $args[0] );
		WP_CLI::success( "Post {$args[0]} removed from the index." );
	}

	/**
	 * Deletes the whole index.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 */
	public function clear( $args, $assoc_args ) {
		WP_CLI::confirm( 'Delete the entire index?', $assoc_args );
		SAIC_Indexer::clear_index();
		WP_CLI::success( 'Index deleted.' );
	}

	/**
	 * Semantic search (does not call the chat model).
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : Search text.
	 *
	 * [--limit=<n>]
	 * : Number of chunks.
	 *
	 * ## EXAMPLES
	 *
	 *     wp saic search "opening hours"
	 */
	public function search( $args, $assoc_args ) {
		$hits = SAIC_Search::search( $args[0], isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : null );
		if ( is_wp_error( $hits ) ) {
			WP_CLI::error( $hits->get_error_message() );
		}
		if ( ! $hits ) {
			WP_CLI::warning( 'Nothing relevant found.' );
			return;
		}
		$items = array();
		foreach ( $hits as $h ) {
			$items[] = array(
				'score'   => round( $h['score'], 3 ),
				'id'      => $h['post_id'],
				'title'   => $h['title'],
				'excerpt' => mb_substr( preg_replace( '/\s+/u', ' ', $h['content'] ), 0, 90 ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $items, array( 'score', 'id', 'title', 'excerpt' ) );
	}

	/**
	 * Asks a full question (search + answer with sources).
	 *
	 * ## OPTIONS
	 *
	 * <question>
	 * : The question.
	 */
	public function ask( $args, $assoc_args ) {
		$res = SAIC_Chat::ask( $args[0] );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		WP_CLI::log( $res['answer'] );
		foreach ( $res['sources'] as $s ) {
			WP_CLI::log( sprintf( '  [%d] %s — %s', $s['n'], $s['title'], $s['url'] ) );
		}
	}

	/**
	 * Lists the latest logged questions.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : How many. Default: 20.
	 *
	 * [--format=<format>]
	 * : table, csv, json. Default: table.
	 */
	public function history( $args, $assoc_args ) {
		global $wpdb;
		$limit = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 20;
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_at, found, question FROM ' . SAIC_DB::log_table() . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A ); // phpcs:ignore
		\WP_CLI\Utils\format_items( isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table', $rows, array( 'id', 'created_at', 'found', 'question' ) );
	}
}
