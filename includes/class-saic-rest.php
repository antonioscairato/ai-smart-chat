<?php
/**
 * REST API endpoint for questions.
 *
 * @package Scairato_AI_Content_Chat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Endpoint público: POST /wp-json/saic/v1/ask
 */
class SAIC_REST {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'saic/v1',
			'/ask',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'ask' ),
				'permission_callback' => '__return_true', // Público; protegido por limites de uso.
				'args'                => array(
					'question' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'history'  => array(
						'required' => false,
						'type'     => 'array',
						'default'  => array(),
					),
				),
			)
		);
	}

	public static function ask( WP_REST_Request $request ) {
		$question = trim( (string) $request->get_param( 'question' ) );
		$max      = (int) SAIC_Settings::get( 'max_question_length' );

		if ( '' === $question ) {
			return new WP_Error( 'saic_empty_question', __( 'Type a question.', 'scairato-ai-content-chat' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $question ) > $max ) {
			/* translators: %d: maximum characters */
			return new WP_Error( 'saic_too_long', sprintf( __( 'The question must be at most %d characters long.', 'scairato-ai-content-chat' ), $max ), array( 'status' => 400 ) );
		}

		$limited = self::check_limits();
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$history = $request->get_param( 'history' );
		$result  = SAIC_Chat::ask( $question, is_array( $history ) ? $history : array() );

		if ( is_wp_error( $result ) ) {
			// Não vaza detalhes da API (podem conter chaves/ids) para visitantes.
			if ( current_user_can( 'manage_options' ) ) {
				return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 500 ) );
			}
			return new WP_Error( 'saic_unavailable', __( 'The assistant is unavailable right now. Please try again later.', 'scairato-ai-content-chat' ), array( 'status' => 503 ) );
		}

		return rest_ensure_response( $result );
	}

	/** Limite por visitante (por minuto) e teto global diário, para controlar custos. */
	protected static function check_limits() {
		$per_min = (int) SAIC_Settings::get( 'rate_limit_minute' );
		if ( $per_min > 0 ) {
			$key   = 'saic_rl_' . SAIC_DB::hash_ip();
			$count = (int) get_transient( $key );
			if ( $count >= $per_min ) {
				return new WP_Error( 'saic_rate_limited', __( 'Too many questions in a short time. Please wait a moment.', 'scairato-ai-content-chat' ), array( 'status' => 429 ) );
			}
			set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		}

		$daily = (int) SAIC_Settings::get( 'daily_limit' );
		if ( $daily > 0 ) {
			$key   = 'saic_daily_' . gmdate( 'Ymd' );
			$count = (int) get_transient( $key );
			if ( $count >= $daily ) {
				return new WP_Error( 'saic_daily_limit', __( 'The daily question limit has been reached. Come back tomorrow.', 'scairato-ai-content-chat' ), array( 'status' => 429 ) );
			}
			set_transient( $key, $count + 1, DAY_IN_SECONDS );
		}
		return true;
	}
}
