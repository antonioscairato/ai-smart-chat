<?php
/**
 * Database tables and vector packing.
 *
 * @package Scairato_AI_Content_Chat
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names come from $wpdb->prefix, never from user input.
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- custom tables of this plugin; names come from $wpdb->prefix and every value is passed through prepare()/intval().

/**
 * Tabelas: trechos indexados (com embeddings) e histórico de perguntas.
 */
class SAIC_DB {

	const DB_VERSION = '1.1';

	public static function chunks_table() {
		global $wpdb;
		return $wpdb->prefix . 'saic_chunks';
	}

	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'saic_log';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$chunks  = self::chunks_table();
		$log     = self::log_table();

		// O embedding é guardado em base64 (float32 little-endian) para ser seguro em qualquer collation.
		dbDelta(
			"CREATE TABLE {$chunks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			doc_key varchar(64) NOT NULL DEFAULT '',
			doc_title text NOT NULL,
			doc_url text NOT NULL,
			chunk_index int(11) NOT NULL DEFAULT 0,
			content longtext NOT NULL,
			embedding longtext NOT NULL,
			model varchar(100) NOT NULL DEFAULT '',
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY doc_key (doc_key)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			question text NOT NULL,
			answer longtext NOT NULL,
			sources text NOT NULL,
			found tinyint(1) NOT NULL DEFAULT 1,
			ip_hash char(32) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
			) {$charset};"
		);

		update_option( 'saic_db_version', self::DB_VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'saic_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/** Vetor de floats -> string armazenável. */
	public static function pack_vector( array $vec ) {
		return base64_encode( pack( 'g*', ...$vec ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- packs a binary vector for storage; not obfuscation.
	}

	/** String armazenada -> vetor (índices a partir de 1, como o unpack do PHP). */
	public static function unpack_vector( $stored ) {
		return unpack( 'g*', base64_decode( $stored ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- unpacks a stored binary vector; not obfuscation.
	}

	public static function hash_ip() {
		$ip = apply_filters( 'saic_client_ip', isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
		return md5( $ip . wp_salt() );
	}

	public static function log_question( $question, $answer, array $sources, $found = true ) {
		if ( ! SAIC_Settings::get( 'log_enabled' ) ) {
			return;
		}
		global $wpdb;
		$wpdb->insert(
			self::log_table(),
			array(
				'question'   => $question,
				'answer'     => $answer,
				'sources'    => wp_json_encode( $sources ),
				'found'      => $found ? 1 : 0,
				'ip_hash'    => self::hash_ip(),
				'user_id'    => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
	}

	public static function purge_log() {
		global $wpdb;
		$days = (int) SAIC_Settings::get( 'log_retention_days' );
		if ( $days > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM ' . self::log_table() . ' WHERE created_at < %s',
					gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
				)
			);
		}
	}
}
