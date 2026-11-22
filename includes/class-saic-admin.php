<?php
/**
 * Admin screens, settings and AJAX handlers.
 *
 * @package AI_Smart_Chat
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- custom tables of this plugin; names come from $wpdb->prefix and every value is passed through prepare()/intval().

/**
 * Painel administrativo: configurações, índice e histórico de perguntas.
 */
class SAIC_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_saic_reindex', array( __CLASS__, 'ajax_reindex' ) );
		add_action( 'wp_ajax_saic_clear_index', array( __CLASS__, 'ajax_clear_index' ) );
		add_action( 'wp_ajax_saic_test', array( __CLASS__, 'ajax_test' ) );
		add_action( 'wp_ajax_saic_preview_source', array( __CLASS__, 'ajax_preview_source' ) );
		add_action( 'wp_ajax_saic_reindex_sources', array( __CLASS__, 'ajax_reindex_sources' ) );
		add_action( 'admin_post_saic_clear_log', array( __CLASS__, 'clear_log' ) );
		add_action( 'admin_post_saic_export_log', array( __CLASS__, 'export_log' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SAIC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=saic' ) ) . '">' . esc_html__( 'Settings', 'ai-smart-chat' ) . '</a>' );
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=saic-help' ) ) . '">' . esc_html__( 'Help', 'ai-smart-chat' ) . '</a>';
		return $links;
	}

	public static function menu() {
		add_menu_page( 'AI Smart Chat', 'AI Smart Chat', 'manage_options', 'saic', array( __CLASS__, 'page_settings' ), 'dashicons-format-chat', 58 );
		add_submenu_page( 'saic', __( 'Settings', 'ai-smart-chat' ), __( 'Settings', 'ai-smart-chat' ), 'manage_options', 'saic', array( __CLASS__, 'page_settings' ) );
		add_submenu_page( 'saic', __( 'Index', 'ai-smart-chat' ), __( 'Index', 'ai-smart-chat' ), 'manage_options', 'saic-index', array( __CLASS__, 'page_index' ) );
		add_submenu_page( 'saic', __( 'Extra sources', 'ai-smart-chat' ), __( 'Extra sources', 'ai-smart-chat' ), 'manage_options', 'saic-sources', array( __CLASS__, 'page_sources' ) );
		add_submenu_page( 'saic', __( 'History', 'ai-smart-chat' ), __( 'History', 'ai-smart-chat' ), 'manage_options', 'saic-history', array( __CLASS__, 'page_history' ) );
		add_submenu_page( 'saic', __( 'Help', 'ai-smart-chat' ), __( 'Help', 'ai-smart-chat' ), 'manage_options', 'saic-help', array( 'SAIC_Help', 'render' ) );
	}

	public static function register_settings() {
		register_setting(
			'saic_group',
			SAIC_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'SAIC_Settings', 'sanitize' ),
			)
		);
		register_setting(
			'saic_sources_group',
			SAIC_Sources::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'SAIC_Sources', 'sanitize' ),
			)
		);
	}

	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'saic' ) ) {
			return;
		}
		wp_enqueue_style( 'saic-admin', SAIC_URL . 'assets/css/admin.css', array(), SAIC_VERSION );
		wp_enqueue_script( 'saic-admin', SAIC_URL . 'assets/js/admin.js', array(), SAIC_VERSION, true );
		wp_localize_script(
			'saic-admin',
			'SAIC_ADMIN',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'saic_admin' ),
				'i18n'    => array(
					'working'       => __( 'Indexing…', 'ai-smart-chat' ),
					/* translators: 1: processed, 2: total, 3: indexed, 4: unchanged */
					'progress'      => __( 'Indexing… %1$d/%2$d (%3$d indexed, %4$d unchanged)', 'ai-smart-chat' ),
					/* translators: 1: indexed, 2: unchanged, 3: removed */
					'summary'       => __( 'Done. %1$d indexed, %2$d unchanged, %3$d removed.', 'ai-smart-chat' ),
					'confirmClear'  => __( 'Remove the entire index? You will need to reindex before the chat can answer again.', 'ai-smart-chat' ),
					'loading'       => __( 'Loading…', 'ai-smart-chat' ),
					'enterSelector' => __( 'Enter a CSS selector.', 'ai-smart-chat' ),
					'testing'       => __( 'Testing…', 'ai-smart-chat' ),
					'requestFail'   => __( 'Request failed.', 'ai-smart-chat' ),
				),
			)
		);
	}

	/* -------------------------------------------------------------------- AJAX */

	protected static function ajax_guard() {
		check_ajax_referer( 'saic_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ai-smart-chat' ) ), 403 );
		}
	}

	public static function ajax_reindex() {
		self::ajax_guard();
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in ajax_guard().
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$force  = ! empty( $_POST['force'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		wp_send_json_success( SAIC_Indexer::reindex_batch( $offset, 5, $force ) );
	}

	public static function ajax_clear_index() {
		self::ajax_guard();
		SAIC_Indexer::clear_index();
		wp_send_json_success();
	}

	public static function ajax_test() {
		self::ajax_guard();
		$emb = SAIC_OpenAI::embed( array( 'teste' ) );
		if ( is_wp_error( $emb ) ) {
			wp_send_json_error( array( 'message' => 'Embeddings: ' . $emb->get_error_message() ) );
		}
		$chat = SAIC_OpenAI::chat(
			array(
				array(
					'role'    => 'user',
					'content' => 'Responda apenas: ok',
				),
			)
		);
		if ( is_wp_error( $chat ) ) {
			wp_send_json_error( array( 'message' => 'Chat: ' . $chat->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				/* translators: 1: vector dimensions, 2: model reply */
				'message' => sprintf( __( 'Connection OK. Embeddings have %1$d dimensions; model reply: "%2$s".', 'ai-smart-chat' ), count( $emb[0] ), $chat ),
			)
		);
	}

	/* ------------------------------------------------------------ form helpers */

	protected static function name( $key ) {
		return SAIC_Settings::OPTION . '[' . $key . ']';
	}

	protected static function row( $label, $html, $desc = '' ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html; // phpcs:ignore WordPress.Security.EscapeOutput -- montado com helpers que escapam.
		if ( $desc ) {
			echo '<p class="description">' . wp_kses_post( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	protected static function input( $key, $type = 'text', $css_class = 'regular-text', $extra = array() ) {
		$attrs = '';
		foreach ( $extra as $k => $v ) {
			$attrs .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
		}
		return sprintf(
			'<input type="%1$s" class="%2$s" name="%3$s" value="%4$s"%5$s>',
			esc_attr( $type ),
			esc_attr( $css_class ),
			esc_attr( self::name( $key ) ),
			esc_attr( SAIC_Settings::get( $key ) ),
			$attrs
		);
	}

	protected static function text_input( $key, $default_text = false ) {
		$extra = $default_text ? array( 'placeholder' => SAIC_Settings::text( $key ) ) : array();
		return self::input( $key, 'text', 'regular-text', $extra );
	}

	protected static function number( $key, $min, $max, $step = 1 ) {
		return self::input(
			$key,
			'number',
			'small-text',
			array(
				'min'  => $min,
				'max'  => $max,
				'step' => $step,
			)
		);
	}

	protected static function checkbox( $key, $label ) {
		return sprintf(
			'<label><input type="checkbox" name="%1$s" value="1" %2$s> %3$s</label>',
			esc_attr( self::name( $key ) ),
			checked( (bool) SAIC_Settings::get( $key ), true, false ),
			esc_html( $label )
		);
	}

	protected static function textarea( $key, $rows = 4, $placeholder = '', $code = false ) {
		return sprintf(
			'<textarea class="large-text%1$s" rows="%2$d" name="%3$s" placeholder="%4$s">%5$s</textarea>',
			$code ? ' code' : '',
			(int) $rows,
			esc_attr( self::name( $key ) ),
			esc_attr( $placeholder ),
			esc_textarea( SAIC_Settings::get( $key ) )
		);
	}

	protected static function select( $key, array $options ) {
		$html = '<select name="' . esc_attr( self::name( $key ) ) . '">';
		foreach ( $options as $value => $label ) {
			$html .= sprintf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( SAIC_Settings::get( $key ), $value, false ), esc_html( $label ) );
		}
		return $html . '</select>';
	}

	/* ----------------------------------------------------------------- Settings */

	public static function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$templates = array();
		foreach ( SAIC_Frontend::templates() as $slug => $t ) {
			$templates[ $slug ] = $t['label'];
		}
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $slug => $obj ) {
			if ( 'attachment' !== $slug ) {
				$types[ $slug ] = $obj->labels->name . ' (' . $slug . ')';
			}
		}
		$selected  = (array) SAIC_Settings::get( 'post_types' );
		$key_const = defined( 'SAIC_OPENAI_API_KEY' ) && SAIC_OPENAI_API_KEY;
		?>
		<div class="wrap saic-admin">
			<h1>AI Smart Chat — <?php esc_html_e( 'Settings', 'ai-smart-chat' ); ?></h1>
			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'saic_group' ); ?>

				<h2><?php esc_html_e( 'AI API', 'ai-smart-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					if ( $key_const ) {
						self::row( __( 'API key', 'ai-smart-chat' ), '<em>' . esc_html__( 'Defined in wp-config.php (SAIC_OPENAI_API_KEY).', 'ai-smart-chat' ) . '</em>' );
					} else {
						$has = '' !== SAIC_Settings::get( 'api_key' );
						self::row(
							__( 'API key', 'ai-smart-chat' ),
							'<input type="password" class="regular-text" autocomplete="new-password" name="' . esc_attr( self::name( 'api_key' ) ) . '" placeholder="' . esc_attr( $has ? __( '•••••• (saved — leave blank to keep)', 'ai-smart-chat' ) : 'sk-...' ) . '">'
							. ( $has ? '<br><label><input type="checkbox" name="' . esc_attr( self::name( 'api_key_clear' ) ) . '" value="1"> ' . esc_html__( 'Remove saved key', 'ai-smart-chat' ) . '</label>' : '' ),
							__( 'Recommended: define <code>define( \'SAIC_OPENAI_API_KEY\', \'sk-...\' );</code> in wp-config.php so the key is not stored in the database.', 'ai-smart-chat' )
						);
					}
					self::row( __( 'Base URL', 'ai-smart-chat' ), self::input( 'base_url', 'url' ), __( 'OpenAI default. Works with any compatible API (Azure, OpenRouter, Ollama/LM Studio via proxy, etc.). HTTPS is required in production.', 'ai-smart-chat' ) );
					self::row( __( 'Embeddings model', 'ai-smart-chat' ), self::text_input( 'embedding_model' ), __( 'When you change the model or the dimensions, run a full (forced) reindex.', 'ai-smart-chat' ) );
					self::row( __( 'Embedding dimensions', 'ai-smart-chat' ), self::number( 'embedding_dimensions', 0, 3072 ), __( '0 = model default. Smaller values (e.g. 512) make search faster and the database smaller, on models that support it.', 'ai-smart-chat' ) );
					self::row( __( 'Chat model', 'ai-smart-chat' ), self::text_input( 'chat_model' ), __( 'Check your provider\'s documentation for the models available today.', 'ai-smart-chat' ) );
					self::row( __( 'Temperature', 'ai-smart-chat' ), self::input( 'temperature', 'text', 'small-text' ), __( 'Leave empty to not send the parameter (required by some reasoning models).', 'ai-smart-chat' ) );
					self::row(
						__( 'Reasoning effort', 'ai-smart-chat' ),
						self::input(
							'reasoning_effort',
							'text',
							'medium-text',
							array(
								'list'        => 'saic-effort',
								'placeholder' => 'low',
							)
						)
						. '<datalist id="saic-effort"><option value="none"><option value="minimal"><option value="low"><option value="medium"><option value="high"></datalist>',
						__( 'Optional. Sent as reasoning_effort to reasoning models; "low" is usually enough for Q&A about your content and is cheaper and faster. Leave empty to not send it (required for models that do not support it). Accepted values depend on the model.', 'ai-smart-chat' )
					);
					self::row( __( 'Max output tokens', 'ai-smart-chat' ), self::number( 'max_completion_tokens', 0, 128000, 100 ), __( '0 = do not send. For reasoning models the reasoning tokens count toward this limit, so keep it generous or answers may come back empty.', 'ai-smart-chat' ) );
					self::row( __( 'Test', 'ai-smart-chat' ), '<button type="button" class="button" id="saic-test">' . esc_html__( 'Test connection (save first)', 'ai-smart-chat' ) . '</button> <span id="saic-test-result"></span>' );
					?>
				</table>

				<h2><?php esc_html_e( 'Indexed content', 'ai-smart-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$boxes = '';
					foreach ( $types as $slug => $label ) {
						$boxes .= sprintf(
							'<label style="display:block"><input type="checkbox" name="%s[]" value="%s" %s> %s</label>',
							esc_attr( self::name( 'post_types' ) ),
							esc_attr( $slug ),
							checked( in_array( $slug, $selected, true ), true, false ),
							esc_html( $label )
						);
					}
					self::row( __( 'Content types', 'ai-smart-chat' ), $boxes, __( 'Posts, pages, products (WooCommerce), events and any public custom post type. Only published, non-password-protected content is indexed.', 'ai-smart-chat' ) );
					self::row( __( 'Extra custom fields', 'ai-smart-chat' ), self::textarea( 'extra_meta_keys', 4, "_EventStartDate\n_EventVenueID", true ), __( 'One meta_key per line, added to the indexed text (useful for event dates/venues and ACF fields).', 'ai-smart-chat' ) );
					self::row( __( 'Automatic reindexing', 'ai-smart-chat' ), self::checkbox( 'auto_index', __( 'Update the index when content is created, edited or deleted (in the background, via WP-Cron)', 'ai-smart-chat' ) ), __( 'Unchecked = manual only, from the Index tab or WP-CLI.', 'ai-smart-chat' ) );
					self::row( __( 'Chunk size', 'ai-smart-chat' ), self::number( 'chunk_size', 400, 4000, 100 ) . ' ' . esc_html__( 'characters', 'ai-smart-chat' ) );
					self::row( __( 'Overlap', 'ai-smart-chat' ), self::number( 'chunk_overlap', 0, 800, 10 ) . ' ' . esc_html__( 'characters', 'ai-smart-chat' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Answers', 'ai-smart-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::row( __( 'Chunks per question (top-k)', 'ai-smart-chat' ), self::number( 'top_k', 1, 20 ) );
					self::row( __( 'Items for list questions', 'ai-smart-chat' ), self::number( 'list_limit', 0, 100 ), __( 'For questions such as "list all products", how many different pages are sent to the chat (one excerpt per page). 0 = disable.', 'ai-smart-chat' ) );
					self::row( __( 'Minimum similarity', 'ai-smart-chat' ), self::input( 'min_score', 'text', 'small-text' ), __( 'From 0 to 1. Chunks below this are ignored; if none pass, the chat replies with the "not found" message without calling the chat model.', 'ai-smart-chat' ) );
					self::row( __( 'Sources shown', 'ai-smart-chat' ), self::number( 'max_sources', 1, 10 ), __( 'Used when the model does not explicitly cite any source.', 'ai-smart-chat' ) );
					self::row( __( 'History messages', 'ai-smart-chat' ), self::number( 'history_messages', 0, 20 ), __( 'How many previous messages accompany the question (0 = no conversation memory).', 'ai-smart-chat' ) );
					self::row( __( 'System prompt', 'ai-smart-chat' ), self::textarea( 'system_prompt', 7, SAIC_Settings::default_system_prompt() ), __( 'Variables: {site_name}, {site_url}. Empty = default.', 'ai-smart-chat' ) );
					self::row( __( 'Message when there is no answer', 'ai-smart-chat' ), self::text_input( 'fallback_message', true ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Interface', 'ai-smart-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::row( __( 'Default template', 'ai-smart-chat' ), self::select( 'template', $templates ), __( 'To customize the HTML/CSS, copy the template folder to <code>your-theme/ai-smart-chat/&lt;template&gt;/</code>. See the Help page.', 'ai-smart-chat' ) );
					self::row( __( 'Floating launcher', 'ai-smart-chat' ), self::checkbox( 'floating_enabled', __( 'Show the floating chat button across the whole site', 'ai-smart-chat' ) ) );
					self::row( __( 'Launcher template', 'ai-smart-chat' ), self::select( 'floating_template', array( '' => __( '(same as default template)', 'ai-smart-chat' ) ) + $templates ) );
					self::row(
						__( 'Position', 'ai-smart-chat' ),
						self::select(
							'floating_position',
							array(
								'right' => __( 'Right corner', 'ai-smart-chat' ),
								'left'  => __( 'Left corner', 'ai-smart-chat' ),
							)
						)
					);
					self::row( __( 'Home page only', 'ai-smart-chat' ), self::checkbox( 'show_on_front_only', __( 'Show the launcher only on the front page', 'ai-smart-chat' ) ) );
					self::row( __( 'Primary color', 'ai-smart-chat' ), self::input( 'primary_color', 'color', '' ) );
					self::row( __( 'Title', 'ai-smart-chat' ), self::text_input( 'title', true ) );
					self::row( __( 'Welcome message', 'ai-smart-chat' ), self::text_input( 'welcome_message', true ) );
					self::row( __( 'Field placeholder', 'ai-smart-chat' ), self::text_input( 'placeholder', true ) );
					self::row( __( 'Launcher text', 'ai-smart-chat' ), self::text_input( 'launcher_label', true ) );
					self::row( __( 'Suggested questions', 'ai-smart-chat' ), self::textarea( 'suggestions', 4 ), __( 'One per line. They appear as buttons before the first question.', 'ai-smart-chat' ) );
					self::row( __( 'Notice (footer)', 'ai-smart-chat' ), self::text_input( 'disclaimer', true ) );
					self::row( __( 'Custom CSS', 'ai-smart-chat' ), self::textarea( 'custom_css', 6, '.saic { --saic-radius: 8px; --saic-font: Georgia, serif; }', true ), __( 'Useful variables: --saic-primary, --saic-bg, --saic-fg, --saic-radius, --saic-font, --saic-height, --saic-user-bg, --saic-bot-bg.', 'ai-smart-chat' ) );
					if ( current_user_can( 'unfiltered_html' ) ) {
						self::row( __( 'Custom JavaScript', 'ai-smart-chat' ), self::textarea( 'custom_js', 6, "document.addEventListener('saic:answer', function(e){ console.log(e.detail); });", true ), __( 'Runs after the chat script. Events: saic:ready, saic:question, saic:answer, saic:error.', 'ai-smart-chat' ) );
					}
					?>
				</table>

				<h2><?php esc_html_e( 'Limits, history and privacy', 'ai-smart-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::row( __( 'Questions per minute (per visitor)', 'ai-smart-chat' ), self::number( 'rate_limit_minute', 0, 120 ), __( '0 = no limit.', 'ai-smart-chat' ) );
					self::row( __( 'Global daily cap', 'ai-smart-chat' ), self::number( 'daily_limit', 0, 1000000, 10 ), __( 'Total questions per day across the whole site; protects against unexpected costs. 0 = no limit.', 'ai-smart-chat' ) );
					self::row( __( 'Maximum question length', 'ai-smart-chat' ), self::number( 'max_question_length', 50, 2000, 10 ) . ' ' . esc_html__( 'characters', 'ai-smart-chat' ) );
					self::row( __( 'History in admin', 'ai-smart-chat' ), self::checkbox( 'log_enabled', __( 'Log questions and answers', 'ai-smart-chat' ) ) );
					self::row( __( 'History retention', 'ai-smart-chat' ), self::number( 'log_retention_days', 0, 3650 ) . ' ' . esc_html__( 'days (0 = keep forever)', 'ai-smart-chat' ), __( 'Visitor questions may contain personal data: mention this in your privacy policy.', 'ai-smart-chat' ) );
					self::row( __( 'Uninstall', 'ai-smart-chat' ), self::checkbox( 'delete_on_uninstall', __( 'Delete index, history and settings when the plugin is deleted', 'ai-smart-chat' ) ) );
					?>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr>
			<p>
				<?php
				printf(
					/* translators: %s: link to the Help page. */
					esc_html__( 'Need a hand with shortcodes, templates, modes or WP-CLI? Open the %s page.', 'ai-smart-chat' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=saic-help' ) ) . '">' . esc_html__( 'Help', 'ai-smart-chat' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}


	public static function ajax_preview_source() {
		self::ajax_guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in ajax_guard().
		$url      = isset( $_POST['url'] ) ? SAIC_Sources::clean_url( sanitize_text_field( wp_unslash( $_POST['url'] ) ) ) : '';
		$selector = isset( $_POST['selector'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['selector'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$text = SAIC_Sources::preview( $url, $selector );
		if ( is_wp_error( $text ) ) {
			wp_send_json_error( array( 'message' => $text->get_error_message() ) );
		}
		wp_send_json_success( array( 'text' => mb_strlen( $text ) > 1500 ? mb_substr( $text, 0, 1500 ) . '…' : $text ) );
	}

	public static function ajax_reindex_sources() {
		self::ajax_guard();
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore
		}
		wp_send_json_success( SAIC_Sources::reindex( true ) );
	}

	/* ------------------------------------------------------------ Extra sources */

	protected static function source_row( $index, $item ) {
		$item = wp_parse_args(
			$item,
			array(
				'id'       => '',
				'type'     => 'text',
				'title'    => '',
				'url'      => '',
				'text'     => '',
				'selector' => '',
			)
		);
		$n    = SAIC_Sources::OPTION . '[items][' . $index . ']';
		?>
		<div class="saic-src is-<?php echo 'html' === $item['type'] ? 'html' : 'text'; ?>">
			<input type="hidden" name="<?php echo esc_attr( $n . '[id]' ); ?>" value="<?php echo esc_attr( $item['id'] ); ?>">
			<div class="saic-src__head">
				<select class="saic-src__type" name="<?php echo esc_attr( $n . '[type]' ); ?>">
					<option value="text" <?php selected( $item['type'], 'text' ); ?>><?php esc_html_e( 'Fixed text', 'ai-smart-chat' ); ?></option>
					<option value="html" <?php selected( $item['type'], 'html' ); ?>><?php esc_html_e( 'Text from a page', 'ai-smart-chat' ); ?></option>
				</select>
				<input type="text" class="regular-text" name="<?php echo esc_attr( $n . '[title]' ); ?>" value="<?php echo esc_attr( $item['title'] ); ?>" placeholder="<?php esc_attr_e( 'Title (shown as the source)', 'ai-smart-chat' ); ?>">
				<button type="button" class="button-link button-link-delete saic-src__remove"><?php esc_html_e( 'Remove', 'ai-smart-chat' ); ?></button>
			</div>
			<div class="saic-src__only-text">
				<textarea class="large-text" rows="4" name="<?php echo esc_attr( $n . '[text]' ); ?>" placeholder="<?php esc_attr_e( '221B Baker Street, London. Open Tuesday to Sunday, 2 pm to 9 pm. Phone: +44 20 0000 0000', 'ai-smart-chat' ); ?>"><?php echo esc_textarea( $item['text'] ); ?></textarea>
			</div>
			<p class="saic-src__row">
				<label>
					<span class="saic-src__only-text"><?php esc_html_e( 'Source link (optional)', 'ai-smart-chat' ); ?></span>
					<span class="saic-src__only-html"><?php esc_html_e( 'Page URL (empty = home page, or a path such as /contact)', 'ai-smart-chat' ); ?></span><br>
					<input type="text" class="large-text" name="<?php echo esc_attr( $n . '[url]' ); ?>" value="<?php echo esc_attr( $item['url'] ); ?>" placeholder="/contact">
				</label>
			</p>
			<div class="saic-src__only-html">
				<label>
					<?php esc_html_e( 'CSS selector', 'ai-smart-chat' ); ?><br>
					<input type="text" class="regular-text code" name="<?php echo esc_attr( $n . '[selector]' ); ?>" value="<?php echo esc_attr( $item['selector'] ); ?>" placeholder="footer .contact">
				</label>
				<button type="button" class="button saic-src__preview"><?php esc_html_e( 'Preview', 'ai-smart-chat' ); ?></button>
				<pre class="saic-src__out" hidden></pre>
			</div>
		</div>
		<?php
	}

	public static function page_sources() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$cfg = SAIC_Sources::all();
		$st  = SAIC_Indexer::status();
		?>
		<div class="wrap saic-admin">
			<h1>AI Smart Chat — <?php esc_html_e( 'Extra sources', 'ai-smart-chat' ); ?></h1>
			<?php settings_errors(); ?>
			<p class="description" style="max-width:780px"><?php esc_html_e( 'Add information the chat should know that is not in your posts: address, opening hours, contacts, or text that lives in your theme (such as the footer).', 'ai-smart-chat' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'saic_sources_group' ); ?>

				<h2><?php esc_html_e( 'Sources', 'ai-smart-chat' ); ?></h2>
				<div id="saic-src-list">
					<?php
					foreach ( array_values( (array) $cfg['items'] ) as $i => $item ) {
						self::source_row( (string) $i, $item );
					}
					?>
				</div>
				<p><button type="button" class="button" id="saic-src-add"><?php esc_html_e( 'Add source', 'ai-smart-chat' ); ?></button></p>
				<template id="saic-src-template"><?php self::source_row( '__INDEX__', array() ); ?></template>

				<h2><?php esc_html_e( 'Site information', 'ai-smart-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Site name and address', 'ai-smart-chat' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( SAIC_Sources::OPTION . '[site_info]' ); ?>" value="1" <?php checked( (bool) $cfg['site_info'] ); ?>> <?php esc_html_e( 'Include the site name, tagline and address', 'ai-smart-chat' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Extra WordPress options', 'ai-smart-chat' ); ?></th>
						<td>
							<textarea class="large-text code" rows="4" name="<?php echo esc_attr( SAIC_Sources::OPTION . '[option_keys]' ); ?>" placeholder="options_address"><?php echo esc_textarea( $cfg['option_keys'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'WordPress option names only, one per line (for example, a field of an ACF options page such as options_address). Do not enter CSS selectors here; use a "Text from a page" source for those. Only simple text values are read; names that look like secrets (password, key, token...) are always skipped.', 'ai-smart-chat' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php $warnings = SAIC_Sources::option_warnings(); ?>
			<?php if ( $warnings ) : ?>
				<div class="notice notice-warning inline">
					<ul>
						<?php foreach ( $warnings as $w ) : ?>
							<li><?php echo esc_html( $w ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<hr>
			<h2><?php esc_html_e( 'Indexed sources', 'ai-smart-chat' ); ?></h2>
			<?php
			$indexed = SAIC_Sources::indexed_docs();
			$labels  = array();
			foreach ( (array) $cfg['items'] as $it ) {
				$labels[ ( 'html' === $it['type'] ? 'h:' : 't:' ) . $it['id'] ] = ( 'html' === $it['type'] ) ? __( 'Text from a page', 'ai-smart-chat' ) : __( 'Fixed text', 'ai-smart-chat' );
			}
			$labels['o:site'] = __( 'Site information', 'ai-smart-chat' );
			$pending          = array();
			foreach ( $cfg['items'] as $it ) {
				$k = ( 'html' === $it['type'] ? 'h:' : 't:' ) . $it['id'];
				if ( ! isset( $indexed[ $k ] ) ) {
					$pending[] = '' !== $it['title'] ? $it['title'] : __( 'Site information', 'ai-smart-chat' );
				}
			}
			?>
			<table class="widefat striped" style="max-width:640px">
				<thead><tr><th><?php esc_html_e( 'Source', 'ai-smart-chat' ); ?></th><th><?php esc_html_e( 'Type', 'ai-smart-chat' ); ?></th><th><?php esc_html_e( 'Chunks', 'ai-smart-chat' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $indexed as $k => $d ) : ?>
						<tr>
							<td><?php echo esc_html( $d['title'] ); ?></td>
							<td><?php echo esc_html( isset( $labels[ $k ] ) ? $labels[ $k ] : '—' ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $d['chunks'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php foreach ( $pending as $t ) : ?>
						<tr>
							<td><?php echo esc_html( $t ); ?></td>
							<td colspan="2"><span class="saic-badge saic-badge--warn"><?php esc_html_e( 'Not indexed yet', 'ai-smart-chat' ); ?></span></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $indexed && ! $pending ) : ?>
						<tr><td colspan="3">—</td></tr>
					<?php endif; ?>
				</tbody>
			</table>
			<p>
				<?php
				/* translators: %d: number of extra sources in the index */
				echo esc_html( sprintf( __( 'Extra sources in the index: %d', 'ai-smart-chat' ), $st['sources'] ) );
				?>
				<button type="button" class="button" id="saic-src-reindex"><?php esc_html_e( 'Reindex sources now', 'ai-smart-chat' ); ?></button>
			</p>
			<p id="saic-src-result" class="description"></p>
			<ul id="saic-src-errors" class="saic-errors"></ul>
			<p class="description" style="max-width:780px"><?php esc_html_e( 'Changes are indexed in the background after saving. Pages are read from this site as a visitor (not logged in), so cached or dynamic content may differ from what you see.', 'ai-smart-chat' ); ?></p>
		</div>
		<?php
	}

	/* -------------------------------------------------------------------- Index */

	public static function page_index() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$st = SAIC_Indexer::status();
		?>
		<div class="wrap saic-admin">
			<h1>AI Smart Chat — <?php esc_html_e( 'Index', 'ai-smart-chat' ); ?></h1>

			<?php if ( ! $st['has_key'] ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Set the API key before indexing.', 'ai-smart-chat' ); ?></p></div>
			<?php endif; ?>

			<table class="widefat striped saic-status" style="max-width:520px">
				<tbody>
					<tr><td><?php esc_html_e( 'Eligible items', 'ai-smart-chat' ); ?></td><td id="saic-st-eligible"><?php echo esc_html( number_format_i18n( $st['eligible'] ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Indexed items', 'ai-smart-chat' ); ?></td><td><?php echo esc_html( number_format_i18n( $st['indexed'] ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Extra sources', 'ai-smart-chat' ); ?></td><td><?php echo esc_html( number_format_i18n( $st['sources'] ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Chunks', 'ai-smart-chat' ); ?></td><td><?php echo esc_html( number_format_i18n( $st['chunks'] ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Index model', 'ai-smart-chat' ); ?></td><td><?php echo esc_html( $st['model'] ? $st['model'] : '—' ); ?></td></tr>
				</tbody>
			</table>

			<p style="margin-top:16px">
				<button type="button" class="button button-primary" id="saic-reindex" data-force="0"><?php esc_html_e( 'Reindex changed items', 'ai-smart-chat' ); ?></button>
				<button type="button" class="button" id="saic-reindex-force" data-force="1"><?php esc_html_e( 'Full reindex (forced)', 'ai-smart-chat' ); ?></button>
				<button type="button" class="button button-link-delete" id="saic-clear"><?php esc_html_e( 'Clear index', 'ai-smart-chat' ); ?></button>
			</p>

			<div class="saic-progress" id="saic-progress" hidden>
				<div class="saic-progress__bar"><span id="saic-progress-fill"></span></div>
				<p id="saic-progress-text"></p>
				<ul id="saic-progress-errors"></ul>
			</div>

			<p class="description" style="max-width:640px">
				<?php esc_html_e( 'Only new or modified content triggers API calls. Changed the model or dimensions? Use the full reindex. For large sites, prefer WP-CLI: wp saic index --all', 'ai-smart-chat' ); ?>
			</p>
		</div>
		<?php
	}

	/* ----------------------------------------------------------------- History */

	public static function page_history() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$table    = SAIC_DB::log_table();
		$per_page = 20;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters (search/pagination), no state change.
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$filter = ( isset( $_GET['filter'] ) && 'unanswered' === sanitize_key( wp_unslash( $_GET['filter'] ) ) ) ? 'unanswered' : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$where = '1=1';
		$args  = array();
		if ( '' !== $search ) {
			$where .= ' AND (question LIKE %s OR answer LIKE %s)';
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$args[] = $like;
			$args[] = $like;
		}
		if ( 'unanswered' === $filter ) {
			$where .= ' AND found = 0';
		}

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore
		$rows_sql  = "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $args, array( $per_page, ( $paged - 1 ) * $per_page ) ) ) ); // phpcs:ignore

		$top = $wpdb->get_results( "SELECT LOWER(question) AS q, COUNT(*) AS c FROM {$table} GROUP BY q ORDER BY c DESC, q ASC LIMIT 10" ); // phpcs:ignore
		$all = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
		$una = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE found = 0" ); // phpcs:ignore
		$base = admin_url( 'admin.php?page=saic-history' );
		?>
		<div class="wrap saic-admin">
			<h1>AI Smart Chat — <?php esc_html_e( 'History', 'ai-smart-chat' ); ?></h1>

			<p>
				<a href="<?php echo esc_url( $base ); ?>"><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'All (%d)', 'ai-smart-chat' ), $all ) ); ?></a> |
				<a href="<?php echo esc_url( add_query_arg( 'filter', 'unanswered', $base ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Not answered by the site (%d)', 'ai-smart-chat' ), $una ) ); ?></a>
			</p>

			<form method="get" style="margin:8px 0">
				<input type="hidden" name="page" value="saic-history">
				<?php
				if ( $filter ) :
					?>
					<input type="hidden" name="filter" value="unanswered"><?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search…', 'ai-smart-chat' ); ?>">
				<button class="button"><?php esc_html_e( 'Search', 'ai-smart-chat' ); ?></button>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=saic_export_log' ), 'saic_export_log' ) ); ?>"><?php esc_html_e( 'Export CSV', 'ai-smart-chat' ); ?></a>
			</form>

			<?php if ( $top ) : ?>
				<details style="margin:10px 0">
					<summary><strong><?php esc_html_e( 'Most frequent questions', 'ai-smart-chat' ); ?></strong></summary>
					<ol>
						<?php foreach ( $top as $t ) : ?>
							<li><?php echo esc_html( $t->q ); ?> <span class="saic-badge"><?php echo (int) $t->c; ?>×</span></li>
						<?php endforeach; ?>
					</ol>
				</details>
			<?php endif; ?>

			<table class="widefat striped">
				<thead><tr>
					<th style="width:140px"><?php esc_html_e( 'Date', 'ai-smart-chat' ); ?></th>
					<th><?php esc_html_e( 'Question', 'ai-smart-chat' ); ?></th>
					<th><?php esc_html_e( 'Answer', 'ai-smart-chat' ); ?></th>
					<th style="width:220px"><?php esc_html_e( 'Sources', 'ai-smart-chat' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No questions logged.', 'ai-smart-chat' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<?php $sources = json_decode( $r->sources, true ); ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $r->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
						<td><strong><?php echo esc_html( $r->question ); ?></strong>
							<?php
							if ( ! $r->found ) :
								?>
								<br><span class="saic-badge saic-badge--warn"><?php esc_html_e( 'not answered by the site', 'ai-smart-chat' ); ?></span><?php endif; ?>
						</td>
						<td><details><summary><?php echo esc_html( wp_trim_words( $r->answer, 18 ) ); ?></summary><div style="white-space:pre-wrap"><?php echo esc_html( $r->answer ); ?></div></details></td>
						<td>
							<?php if ( is_array( $sources ) ) : ?>
								<?php foreach ( $sources as $source ) : ?>
									<a href="<?php echo esc_url( $source['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $source['title'] ); ?></a><br>
								<?php endforeach; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$pages = (int) ceil( $total / $per_page );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $pages,
						)
					)
				) . '</div></div>';
			}
			?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:20px" onsubmit="return confirm('<?php echo esc_js( __( 'Delete the entire history?', 'ai-smart-chat' ) ); ?>');">
				<input type="hidden" name="action" value="saic_clear_log">
				<?php wp_nonce_field( 'saic_clear_log' ); ?>
				<button class="button button-link-delete"><?php esc_html_e( 'Delete entire history', 'ai-smart-chat' ); ?></button>
			</form>
		</div>
		<?php
	}

	public static function clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-smart-chat' ), 403 );
		}
		check_admin_referer( 'saic_clear_log' );
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . SAIC_DB::log_table() ); // phpcs:ignore
		wp_safe_redirect( admin_url( 'admin.php?page=saic-history' ) );
		exit;
	}

	public static function export_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-smart-chat' ), 403 );
		}
		check_admin_referer( 'saic_export_log' );
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . SAIC_DB::log_table() . ' ORDER BY id DESC LIMIT 50000', ARRAY_A ); // phpcs:ignore

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="saic-history-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$safe = function ( $v ) {
			$v = (string) $v;
			return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $v : $v; // Evita injeção de fórmulas no Excel.
		};
		// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming CSV straight to php://output.
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'date_utc', 'question', 'answer', 'sources', 'found_on_site' ) );
		foreach ( $rows as $r ) {
			$src = json_decode( $r['sources'], true );
			$src = is_array( $src ) ? implode( ' | ', wp_list_pluck( $src, 'url' ) ) : '';
			fputcsv( $out, array( $r['created_at'], $safe( $r['question'] ), $safe( $r['answer'] ), $src, $r['found'] ? 'yes' : 'no' ) );
		}
		fclose( $out );
		// phpcs:enable WordPress.WP.AlternativeFunctions
		exit;
	}
}
