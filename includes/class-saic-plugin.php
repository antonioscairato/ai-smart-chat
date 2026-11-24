<?php
/**
 * Plugin bootstrap, activation and privacy text.
 *
 * @package Scairato_AI_Content_Chat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap do plugin.
 */
class SAIC_Plugin {

	public static function init() {
		SAIC_DB::maybe_upgrade();
		SAIC_Indexer::init();
		SAIC_Sources::init();
		SAIC_REST::init();
		SAIC_Frontend::init();
		SAIC_Block::init();

		if ( is_admin() ) {
			SAIC_Admin::init();
		}

		add_action( 'saic_purge_log', array( 'SAIC_DB', 'purge_log' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy' ) );
	}

	public static function activate() {
		SAIC_DB::install();
		if ( ! get_option( SAIC_Settings::OPTION ) ) {
			add_option( SAIC_Settings::OPTION, SAIC_Settings::defaults() );
		}
		if ( ! wp_next_scheduled( 'saic_purge_log' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'saic_purge_log' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'saic_purge_log' );
		wp_unschedule_hook( 'saic_index_post' );
		wp_unschedule_hook( 'saic_index_sources' );
	}

	public static function privacy_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p>' . esc_html__( 'The AI assistant sends the site\'s public text (for indexing) and visitors\' questions to the configured AI provider (OpenAI by default) to generate embeddings and answers. Questions may be stored in the admin history, associated with an anonymous identifier (IP hash) and, if the visitor is logged in, with their user account.', 'scairato-ai-content-chat' ) . '</p>';
		wp_add_privacy_policy_content( 'Scairato AI Content Chat', wp_kses_post( $content ) );
	}
}
