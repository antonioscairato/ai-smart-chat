<?php
/**
 * Front-end rendering, shortcode and floating launcher.
 *
 * @package AI_Smart_Chat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end: shortcode, lançador flutuante, registro de templates e assets.
 *
 * Templates: cada template é uma pasta com `template.php` e `style.css`.
 * Para customizar sem editar o plugin, copie a pasta para
 *   wp-content/themes/SEU-TEMA/ai-smart-chat/<slug>/
 * e edite à vontade (HTML/CSS). O JS se liga por atributos data-saic-*.
 * Novos templates podem ser registrados com o filtro 'saic_templates'.
 */
class SAIC_Frontend {

	protected static $instance_count = 0;
	protected static $assets_ready   = false;
	protected static $queue          = array(); // HTML de shortcodes flutuantes, impresso no rodapé.

	public static function init() {
		add_shortcode( 'ai_site_chat', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'ai_smart_chat', array( __CLASS__, 'shortcode' ) );
		// Assets do lançador entram na fila antes de wp_head/wp_footer imprimirem as filas.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_floating' ) );
		// Prioridade < 20: o HTML sai antes de o core imprimir os scripts do rodapé.
		add_action( 'wp_footer', array( __CLASS__, 'floating' ), 5 );
	}

	/* ---------------------------------------------------------------- templates */

	/** @return array slug => [label, path, url, source] */
	public static function templates() {
		$base      = array(
			'classic' => __( 'Classic (bubbles, light)', 'ai-smart-chat' ),
			'modern'  => __( 'Modern (dark, glass)', 'ai-smart-chat' ),
			'minimal' => __( 'Minimal (lines, monochrome)', 'ai-smart-chat' ),
		);
		$templates = array();
		foreach ( $base as $slug => $label ) {
			$templates[ $slug ] = array(
				'label' => $label,
				'path'  => SAIC_DIR . 'templates/' . $slug . '/',
				'url'   => SAIC_URL . 'templates/' . $slug . '/',
			);
		}

		/**
		 * Registre templates próprios:
		 * $templates['meu'] = [ 'label' => 'Meu', 'path' => '/caminho/com/barra/final/', 'url' => 'https://.../' ];
		 */
		$templates = apply_filters( 'saic_templates', $templates );

		// Sobrescritas no tema (filho tem prioridade).
		foreach ( $templates as $slug => $t ) {
			foreach ( array( array( get_stylesheet_directory(), get_stylesheet_directory_uri() ), array( get_template_directory(), get_template_directory_uri() ) ) as $dir ) {
				$path = trailingslashit( $dir[0] ) . 'ai-smart-chat/' . $slug . '/';
				if ( file_exists( $path . 'template.php' ) ) {
					$templates[ $slug ]['path'] = $path;
					$templates[ $slug ]['url']  = trailingslashit( $dir[1] ) . 'ai-smart-chat/' . $slug . '/';
					break;
				}
			}
		}
		return $templates;
	}

	/* ------------------------------------------------------------------- assets */

	protected static function enqueue_assets( $template_slug ) {
		$templates = self::templates();
		if ( ! isset( $templates[ $template_slug ] ) ) {
			$template_slug = 'modern';
		}
		$t = $templates[ $template_slug ];

		if ( ! self::$assets_ready ) {
			wp_enqueue_style( 'saic-base', SAIC_URL . 'assets/css/base.css', array(), SAIC_VERSION );
			wp_enqueue_script( 'saic-chat', SAIC_URL . 'assets/js/chat.js', array(), SAIC_VERSION, true );

			$config = array(
				'restUrl'   => esc_url_raw( rest_url( 'saic/v1/ask' ) ),
				'i18n'      => array(
					'error'    => __( 'Something went wrong. Please try again.', 'ai-smart-chat' ),
					'sources'  => __( 'Sources', 'ai-smart-chat' ),
					'thinking' => __( 'Thinking…', 'ai-smart-chat' ),
					'open'     => __( 'Open chat', 'ai-smart-chat' ),
					'close'    => __( 'Close chat', 'ai-smart-chat' ),
					'newChat'  => __( 'New conversation', 'ai-smart-chat' ),
				),
				'maxLength' => (int) SAIC_Settings::get( 'max_question_length' ),
				'history'   => (int) SAIC_Settings::get( 'history_messages' ),
			);
			wp_add_inline_script( 'saic-chat', 'window.SAIC_CONFIG = ' . wp_json_encode( $config ) . ';', 'before' );

			$css = trim( (string) SAIC_Settings::get( 'custom_css' ) );
			if ( $css ) {
				wp_add_inline_style( 'saic-base', $css );
			}
			$js = trim( (string) SAIC_Settings::get( 'custom_js' ) );
			if ( $js ) {
				wp_add_inline_script( 'saic-chat', $js, 'after' );
			}
			self::$assets_ready = true;
		}

		if ( file_exists( $t['path'] . 'style.css' ) ) {
			wp_enqueue_style( 'saic-tpl-' . $template_slug, $t['url'] . 'style.css', array( 'saic-base' ), SAIC_VERSION );
		}
		return $template_slug;
	}

	/* ------------------------------------------------------------------- render */

	/**
	 * @param array $args template, mode (inline|floating), title, placeholder, welcome,
	 *                    height, position (left|right), launcher_label, suggestions, color, class.
	 */
	public static function render( $args = array() ) {
		if ( SAIC_Indexer::$indexing ) {
			return '';
		}

		$args = wp_parse_args(
			$args,
			array(
				'template'       => SAIC_Settings::get( 'template' ),
				'mode'           => 'inline',
				'title'          => '',
				'placeholder'    => '',
				'welcome'        => '',
				'height'         => '520px',
				'position'       => SAIC_Settings::get( 'floating_position' ),
				'launcher_label' => '',
				'suggestions'    => '',
				'color'          => '',
				'class'          => '',
			)
		);

		$slug = self::enqueue_assets( sanitize_key( $args['template'] ) );
		$tpl  = self::templates();
		$path = $tpl[ $slug ]['path'] . 'template.php';
		if ( ! file_exists( $path ) ) {
			return '';
		}

		++self::$instance_count;
		$suggestions = '' !== trim( (string) $args['suggestions'] ) ? $args['suggestions'] : SAIC_Settings::get( 'suggestions' );
		$suggestions = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n|\|/', (string) $suggestions ) ) ) );

		$saic = array(
			'id'             => 'saic-' . self::$instance_count,
			'template'       => $slug,
			'mode'           => 'floating' === $args['mode'] ? 'floating' : 'inline',
			'position'       => 'left' === $args['position'] ? 'left' : 'right',
			'title'          => '' !== $args['title'] ? $args['title'] : SAIC_Settings::text( 'title' ),
			'placeholder'    => '' !== $args['placeholder'] ? $args['placeholder'] : SAIC_Settings::text( 'placeholder' ),
			'welcome'        => '' !== $args['welcome'] ? $args['welcome'] : SAIC_Settings::text( 'welcome_message' ),
			'launcher_label' => '' !== $args['launcher_label'] ? $args['launcher_label'] : SAIC_Settings::text( 'launcher_label' ),
			'disclaimer'     => SAIC_Settings::text( 'disclaimer' ),
			'suggestions'    => $suggestions,
			'height'         => preg_match( '/^\d+(px|vh|rem|em|%)$/', (string) $args['height'] ) ? $args['height'] : '520px',
			'color'          => sanitize_hex_color( $args['color'] ) ? $args['color'] : SAIC_Settings::get( 'primary_color' ),
			'class'          => sanitize_html_class( $args['class'] ),
		);
		$saic = apply_filters( 'saic_template_data', $saic, $args );

		$classes = array( 'saic', 'saic--' . $saic['template'], 'saic--' . $saic['mode'], 'saic-pos-' . $saic['position'] );
		if ( $saic['class'] ) {
			$classes[] = $saic['class'];
		}
		$style = sprintf( '--saic-primary:%s;--saic-height:%s;', $saic['color'], $saic['height'] );

		ob_start();
		printf(
			'<div id="%1$s" class="%2$s" style="%3$s" data-saic data-mode="%4$s" data-template="%5$s" data-welcome="%6$s">',
			esc_attr( $saic['id'] ),
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $style ),
			esc_attr( $saic['mode'] ),
			esc_attr( $saic['template'] ),
			esc_attr( $saic['welcome'] )
		);
		include $path; // Variável $saic disponível no template.
		echo '</div>';
		return ob_get_clean();
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'template'       => '',
				'mode'           => 'inline',
				'title'          => '',
				'placeholder'    => '',
				'welcome'        => '',
				'height'         => '520px',
				'position'       => '',
				'launcher_label' => '',
				'suggestions'    => '',
				'color'          => '',
				'class'          => '',
			),
			$atts,
			'ai_site_chat'
		);
		if ( '' === $atts['template'] ) {
			unset( $atts['template'] );
		}
		if ( '' === $atts['position'] ) {
			unset( $atts['position'] );
		}

		// Flutuante: o HTML é impresso no rodapé (filho direto do <body>), porque um
		// elemento position:fixed dentro do conteúdo fica preso a ancestrais com
		// transform/filter/overflow (comum em Elementor, Avada e temas de bloco).
		if ( 'floating' === $atts['mode'] && ! did_action( 'wp_footer' ) ) {
			$html = self::render( $atts ); // Enfileira os assets agora.
			if ( '' !== $html ) {
				self::$queue[] = $html;
			}
			return '';
		}

		return self::render( $atts );
	}

	/** Define se o lançador flutuante global deve aparecer na requisição atual. */
	protected static function should_show_floating() {
		if ( ! SAIC_Settings::get( 'floating_enabled' ) || is_admin() || is_feed() ) {
			return false;
		}
		if ( SAIC_Settings::get( 'show_on_front_only' ) && ! is_front_page() ) {
			return false;
		}
		if ( '' === SAIC_Settings::api_key() ) {
			return false;
		}
		/**
		 * Retorne false para ocultar o lançador em determinadas páginas.
		 */
		return (bool) apply_filters( 'saic_show_floating', true );
	}

	protected static function floating_template() {
		$template = SAIC_Settings::get( 'floating_template' );
		return $template ? $template : SAIC_Settings::get( 'template' );
	}

	/** Enfileira CSS/JS do lançador cedo (wp_enqueue_scripts), para sair no <head> e no rodapé corretamente. */
	public static function enqueue_floating() {
		if ( self::should_show_floating() ) {
			self::enqueue_assets( sanitize_key( self::floating_template() ) );
		}
	}

	/** Lançador flutuante global (configurável em Configurações > Interface). */
	public static function floating() {
		// Shortcodes [ai_site_chat mode="floating"] da página têm prioridade sobre o lançador global.
		if ( self::$queue ) {
			echo implode( '', self::$queue ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado no render().
			self::$queue = array();
			return;
		}
		if ( ! self::should_show_floating() ) {
			return;
		}
		$html = self::render(
			array(
				'mode'     => 'floating',
				'template' => self::floating_template(),
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render().
	}
}
