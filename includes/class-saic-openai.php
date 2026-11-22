<?php
/**
 * OpenAI-compatible API client.
 *
 * @package AI_Smart_Chat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cliente HTTP da API da OpenAI (ou qualquer API compatível: altere a "Base URL").
 */
class SAIC_OpenAI {

	/**
	 * @return array|WP_Error Corpo JSON decodificado.
	 */
	public static function request( $path, array $body, $timeout = 60 ) {
		$key = SAIC_Settings::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'saic_no_key', __( 'API key not set.', 'ai-smart-chat' ) );
		}

		$url      = rtrim( SAIC_Settings::get( 'base_url' ), '/' ) . $path;
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : sprintf( 'HTTP %d', $code );
			return new WP_Error( 'saic_api_error', $msg, array( 'status' => $code ) );
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'saic_bad_response', __( 'Invalid API response.', 'ai-smart-chat' ) );
		}
		return $data;
	}

	/**
	 * Gera embeddings (em lotes) preservando a ordem.
	 *
	 * @param string[] $texts
	 * @return array[]|WP_Error Lista de vetores normalizados.
	 */
	public static function embed( array $texts ) {
		$texts   = array_values( $texts );
		$vectors = array();
		$model   = SAIC_Settings::get( 'embedding_model' );
		$dims    = (int) SAIC_Settings::get( 'embedding_dimensions' );

		foreach ( array_chunk( $texts, 48 ) as $batch ) {
			$body = array(
				'model' => $model,
				'input' => $batch,
			);
			if ( $dims > 0 ) {
				$body['dimensions'] = $dims;
			}
			$data = self::request( '/embeddings', $body );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			if ( empty( $data['data'] ) ) {
				return new WP_Error( 'saic_bad_response', __( 'The API did not return embeddings.', 'ai-smart-chat' ) );
			}
			$rows = $data['data'];
			usort(
				$rows,
				function ( $a, $b ) {
					return $a['index'] <=> $b['index'];
				}
			);
			foreach ( $rows as $row ) {
				$vectors[] = self::normalize( $row['embedding'] );
			}
		}
		return $vectors;
	}

	/**
	 * Acrescenta uma dica quando a API recusa um parâmetro do modelo de chat
	 * (ex.: temperature em modelos de raciocínio).
	 */
	protected static function explain( WP_Error $error ) {
		if ( 'saic_api_error' !== $error->get_error_code() ) {
			return $error;
		}
		$data = $error->get_error_data();
		if ( empty( $data['status'] ) || 400 !== (int) $data['status'] ) {
			return $error;
		}
		if ( ! preg_match( '/temperature|top_p|unsupported|not supported|max_tokens|max_completion_tokens|reasoning_effort/i', $error->get_error_message() ) ) {
			return $error;
		}
		return new WP_Error(
			$error->get_error_code(),
			$error->get_error_message() . ' — ' . __( 'Hint: the model rejected a request parameter. Leave "Temperature" empty for reasoning models and check "Reasoning effort" and "Max output tokens" in the settings.', 'ai-smart-chat' ),
			$data
		);
	}

	public static function normalize( array $v ) {
		$sum = 0.0;
		foreach ( $v as $x ) {
			$sum += $x * $x;
		}
		$norm = sqrt( $sum );
		if ( $norm <= 0 ) {
			return $v;
		}
		foreach ( $v as $i => $x ) {
			$v[ $i ] = $x / $norm;
		}
		return $v;
	}

	/**
	 * @param array $messages Mensagens no formato da API (role/content).
	 * @return string|WP_Error Texto da resposta.
	 */
	public static function chat( array $messages ) {
		$body = array(
			'model'    => SAIC_Settings::get( 'chat_model' ),
			'messages' => $messages,
		);
		$temp = SAIC_Settings::get( 'temperature' );
		if ( '' !== (string) $temp ) {
			$body['temperature'] = (float) $temp;
		}
		$effort = (string) SAIC_Settings::get( 'reasoning_effort' );
		if ( '' !== $effort ) {
			$body['reasoning_effort'] = $effort;
		}
		$max_tokens = (int) SAIC_Settings::get( 'max_completion_tokens' );
		if ( $max_tokens > 0 ) {
			$body['max_completion_tokens'] = $max_tokens;
		}
		/**
		 * Permite ajustar o corpo da requisição (max_completion_tokens, reasoning_effort, etc.).
		 */
		$body = apply_filters( 'saic_chat_request_body', $body, $messages );

		$data = self::request( '/chat/completions', $body, 90 );
		if ( is_wp_error( $data ) ) {
			return self::explain( $data );
		}
		if ( empty( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'saic_empty', __( 'The API returned an empty response.', 'ai-smart-chat' ) );
		}
		return trim( $data['choices'][0]['message']['content'] );
	}
}
