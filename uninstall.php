<?php
/**
 * Remoção completa — só ocorre se "Apagar dados ao excluir o plugin" estiver marcado.
 *
 * @package Scairato_AI_Content_Chat
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$saic_settings = get_option( 'saic_settings', array() );

if ( ! empty( $saic_settings['delete_on_uninstall'] ) ) {
	global $wpdb;

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}saic_chunks" ); // phpcs:ignore
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}saic_log" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_saic_hash','_saic_indexed_at')" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_saic\_%' OR option_name LIKE '\_transient\_timeout\_saic\_%'" ); // phpcs:ignore

	delete_option( 'saic_settings' );
	delete_option( 'saic_db_version' );
	delete_option( 'saic_sources' );
	delete_option( 'saic_sources_hashes' );
}

wp_clear_scheduled_hook( 'saic_purge_log' );
wp_unschedule_hook( 'saic_index_post' );
wp_unschedule_hook( 'saic_index_sources' );
