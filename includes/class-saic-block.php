<?php
/**
 * Gutenberg block registration.
 *
 * @package AI_Smart_Chat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bloco Gutenberg "AI Smart Chat" (renderização no servidor, sem etapa de build).
 */
class SAIC_Block {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		$templates = array();
		foreach ( SAIC_Frontend::templates() as $slug => $t ) {
			$templates[] = array(
				'value' => $slug,
				'label' => $t['label'],
			);
		}

		wp_register_script(
			'saic-block-editor',
			SAIC_URL . 'blocks/chat/edit.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n' ),
			SAIC_VERSION,
			true
		);
		wp_add_inline_script(
			'saic-block-editor',
			'window.SAIC_BLOCK = ' . wp_json_encode(
				array(
					'templates' => $templates,
					'i18n'      => array(
						'defaultFromSettings' => __( 'Default from settings', 'ai-smart-chat' ),
						'template'            => __( 'Template', 'ai-smart-chat' ),
						'title'               => __( 'Title', 'ai-smart-chat' ),
						'placeholder'         => __( 'Field placeholder', 'ai-smart-chat' ),
						'welcome'             => __( 'Welcome message', 'ai-smart-chat' ),
						'height'              => __( 'Height (e.g. 520px, 70vh)', 'ai-smart-chat' ),
						'help'                => __( 'The chat appears here on the published site. Adjust template, title and height in the sidebar.', 'ai-smart-chat' ),
						'defaultTemplate'     => __( 'default template', 'ai-smart-chat' ),
					),
				)
			) . ';',
			'before'
		);

		register_block_type(
			SAIC_DIR . 'blocks/chat',
			array(
				'render_callback' => array( __CLASS__, 'render' ),
			)
		);
	}

	public static function render( $attrs ) {
		$args = array(
			'mode' => 'inline',
		);
		foreach ( array( 'template', 'title', 'placeholder', 'height', 'welcome' ) as $k ) {
			if ( ! empty( $attrs[ $k ] ) ) {
				$args[ $k ] = $attrs[ $k ];
			}
		}
		$out = SAIC_Frontend::render( $args );
		return '<div ' . get_block_wrapper_attributes() . '>' . $out . '</div>';
	}
}
