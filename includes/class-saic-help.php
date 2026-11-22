<?php
/**
 * Help page: overview, quick start, display options, WP-CLI reference.
 *
 * @package AI_Smart_Chat
 */

defined( 'ABSPATH' ) || exit;

class SAIC_Help {

	/**
	 * Print a translated string that may contain a few inline tags.
	 *
	 * @param string $html Already translated HTML.
	 */
	private static function out( $html ) {
		echo wp_kses(
			$html,
			array(
				'code'   => array(),
				'strong' => array(),
				'em'     => array(),
				'a'      => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
			)
		);
	}

	private static function table( $headers, $rows ) {
		echo '<table class="widefat striped saic-help-table"><thead><tr>';
		foreach ( $headers as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( $row as $i => $cell ) {
				echo '<td>';
				if ( 0 === $i ) {
					echo '<code>' . esc_html( $cell ) . '</code>';
				} else {
					self::out( $cell );
				}
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function code( $text ) {
		echo '<pre class="saic-help-code"><code>' . esc_html( $text ) . '</code></pre>';
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$toc = array(
			'about'     => __( 'What it does', 'ai-smart-chat' ),
			'start'     => __( 'Quick start', 'ai-smart-chat' ),
			'display'   => __( 'Showing the chat', 'ai-smart-chat' ),
			'content'   => __( 'Teaching the chat more content', 'ai-smart-chat' ),
			'questions' => __( 'Types of questions', 'ai-smart-chat' ),
			'cli'       => __( 'WP-CLI commands', 'ai-smart-chat' ),
			'dev'       => __( 'For developers', 'ai-smart-chat' ),
			'trouble'   => __( 'Troubleshooting', 'ai-smart-chat' ),
		);
		?>
		<div class="wrap saic-admin saic-help">
			<h1>AI Smart Chat — <?php esc_html_e( 'Help', 'ai-smart-chat' ); ?></h1>
			<p class="saic-help-toc">
				<?php
				$links = array();
				foreach ( $toc as $id => $label ) {
					$links[] = '<a href="#saic-' . esc_attr( $id ) . '">' . esc_html( $label ) . '</a>';
				}
				echo wp_kses( implode( ' · ', $links ), array( 'a' => array( 'href' => array() ) ) );
				?>
			</p>

			<h2 id="saic-about"><?php echo esc_html( $toc['about'] ); ?></h2>
			<p><?php esc_html_e( 'AI Smart Chat adds a chat to your site where visitors ask questions in natural language and get answers written from your own content, with links to the pages the answer came from. It uses retrieval-augmented generation (RAG): your posts, pages and products are split into passages and turned into embeddings; for each question, the most relevant passages are found and sent to an AI model, which answers using only that material.', 'ai-smart-chat' ); ?></p>
			<h3><?php esc_html_e( 'Why it is different', 'ai-smart-chat' ); ?></h3>
			<ul class="saic-doc">
				<li><?php self::out( __( '<strong>Self-contained:</strong> no external vector database and no SaaS account. The index lives in your own WordPress database; you only need an OpenAI-compatible API key.', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<strong>Any content:</strong> any public post type (posts, pages, products, events…), plus fixed texts, parts of other pages (such as a footer address) and WordPress options through "Extra sources".', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<strong>Complete lists and counts:</strong> questions such as "which products are for pastures?" or "how many…?" are answered from the full set of items in a category, not just the few best matches.', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<strong>Honest answers:</strong> when the content does not have the answer, the chat says so and shows no sources, instead of inventing one.', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<strong>Flexible display:</strong> floating button, inline block, shortcode or PHP, with three ready-made templates that you can override in your theme.', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<strong>Control and privacy:</strong> rate limiting, question history with export, API key that can live in wp-config.php, translations (English, Portuguese, Spanish) and WP-CLI for everything.', 'ai-smart-chat' ) ); ?></li>
			</ul>

			<h2 id="saic-start"><?php echo esc_html( $toc['start'] ); ?></h2>
			<ol class="saic-doc">
				<li><?php self::out( __( '<strong>Settings → AI API.</strong> Paste your key (or define <code>SAIC_OPENAI_API_KEY</code> in wp-config.php) and choose the chat and embedding models.', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<strong>Settings → Indexed content.</strong> Tick the content types the chat may use. Only public, published content is indexed.', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<strong>Index.</strong> Click "Index" to build the index. Run it again after changing the settings or adding Extra sources. Posts are re-indexed automatically when saved.', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<strong>Show the chat.</strong> Enable the floating button in Settings → Interface, or place the block or shortcode in a page (see below).', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<strong>Test and refine.</strong> Ask real questions, then check the History page to see what visitors ask and which questions were not answered.', 'ai-smart-chat' ) ); ?></li>
			</ol>

			<h2 id="saic-display"><?php echo esc_html( $toc['display'] ); ?></h2>
			<h3><?php esc_html_e( 'Where the chat can appear', 'ai-smart-chat' ); ?></h3>
			<?php
			self::table(
				array( __( 'Method', 'ai-smart-chat' ), __( 'How', 'ai-smart-chat' ) ),
				array(
					array( __( 'Floating button', 'ai-smart-chat' ), __( 'Settings → Interface → "Floating launcher". Shows a button in a corner of every page (or only the front page).', 'ai-smart-chat' ) ),
					array( 'Gutenberg', __( 'Add the "AI Smart Chat" block (Widgets category).', 'ai-smart-chat' ) ),
					array( '[ai_site_chat]', __( 'Shortcode for any page, widget, or page builder (Elementor, Avada…).', 'ai-smart-chat' ) ),
					array( 'PHP', __( 'In a theme template, print the chat directly.', 'ai-smart-chat' ) ),
				)
			);
			self::code( "[ai_site_chat]\n[ai_site_chat template=\"modern\" height=\"480px\" title=\"Talk to us\" suggestions=\"Opening hours?|How to buy?\"]\n[ai_site_chat mode=\"floating\" position=\"left\"]\n\n<?php echo SAIC_Frontend::render( array( 'template' => 'minimal' ) ); ?>" );
			?>
			<h3><?php esc_html_e( 'Mode: inline or floating', 'ai-smart-chat' ); ?></h3>
			<ul class="saic-doc">
				<li><?php self::out( __( '<code>inline</code> (default): the chat box sits inside the page, exactly where you put the block or shortcode, with the height you choose.', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<code>floating</code>: only a launcher button is shown; clicking it opens the chat over the page. The global floating button in Settings uses this mode.', 'ai-smart-chat' ) ); ?></li>
			</ul>
			<h3><?php esc_html_e( 'Templates', 'ai-smart-chat' ); ?></h3>
			<?php
			self::table(
				array( __( 'Template', 'ai-smart-chat' ), __( 'Look', 'ai-smart-chat' ) ),
				array(
					array( 'classic', __( 'Coloured header, chat bubbles, light background. A safe choice for most sites.', 'ai-smart-chat' ) ),
					array( 'modern', __( 'Dark glass panel, gradient avatar and suggested questions on top.', 'ai-smart-chat' ) ),
					array( 'minimal', __( 'Thin lines, no coloured bubbles and text buttons. Blends with any theme.', 'ai-smart-chat' ) ),
				)
			);
			?>
			<p><?php self::out( __( 'The default template is chosen in Settings → Interface; the <code>template</code> attribute overrides it for a single chat. To customize the HTML/CSS, copy a folder from <code>wp-content/plugins/ai-smart-chat/templates/</code> to <code>your-theme/ai-smart-chat/&lt;name&gt;/</code> and keep the <code>data-saic-*</code> attributes.', 'ai-smart-chat' ) ); ?></p>
			<h3><?php esc_html_e( 'Shortcode attributes', 'ai-smart-chat' ); ?></h3>
			<?php
			self::table(
				array( __( 'Attribute', 'ai-smart-chat' ), __( 'Meaning', 'ai-smart-chat' ) ),
				array(
					array( 'template', __( '<code>classic</code>, <code>modern</code> or <code>minimal</code> (or a template of your own). Default: the one set in Settings.', 'ai-smart-chat' ) ),
					array( 'mode', __( '<code>inline</code> or <code>floating</code>. Default: inline.', 'ai-smart-chat' ) ),
					array( 'position', __( '<code>left</code> or <code>right</code>: corner of the floating button.', 'ai-smart-chat' ) ),
					array( 'title', __( 'Title shown in the chat header.', 'ai-smart-chat' ) ),
					array( 'placeholder', __( 'Text of the question field.', 'ai-smart-chat' ) ),
					array( 'welcome', __( 'First message shown by the chat.', 'ai-smart-chat' ) ),
					array( 'launcher_label', __( 'Text of the floating button.', 'ai-smart-chat' ) ),
					array( 'suggestions', __( 'Suggested questions separated by <code>|</code>.', 'ai-smart-chat' ) ),
					array( 'height', __( 'Height of the inline chat, such as <code>480px</code>, <code>70vh</code> or <code>30rem</code>. Default: 520px.', 'ai-smart-chat' ) ),
					array( 'color', __( 'Primary colour in hex, such as <code>#0a7d5a</code>.', 'ai-smart-chat' ) ),
					array( 'class', __( 'Extra CSS class for the chat container.', 'ai-smart-chat' ) ),
				)
			);
			?>
			<p class="description"><?php esc_html_e( 'Attributes you leave out use the values from Settings → Interface.', 'ai-smart-chat' ); ?></p>

			<h2 id="saic-content"><?php echo esc_html( $toc['content'] ); ?></h2>
			<p><?php self::out( __( 'Some information does not live in posts: a phone number in the footer, opening hours in a theme option, event dates in custom fields. Use the <strong>Extra sources</strong> page to add it to the index:', 'ai-smart-chat' ) ); ?></p>
			<ul class="saic-doc">
				<li><?php self::out( __( '<strong>Fixed text:</strong> type any text (policies, FAQ, address). It is indexed as is.', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<strong>Part of a page:</strong> give a URL (on this site) and a CSS selector, such as <code>footer .contact</code> or <code>.soc-m h5</code>. Use "Preview" to check what will be read.', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<strong>WordPress options:</strong> names of WordPress options (wp_options table) whose values should be indexed. Put CSS selectors in the page field, not here.', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<strong>Custom fields:</strong> names of post meta fields (for example <code>_dateStart_event</code>) that are added to the text of each post when it is indexed.', 'ai-smart-chat' ) ); ?></li>
			</ul>
			<p><?php esc_html_e( 'After any change, run the index again. The "Indexed sources" table on the Extra sources page shows what is in the index and warns about mistakes, such as a selector that matches nothing.', 'ai-smart-chat' ); ?></p>

			<h2 id="saic-questions"><?php echo esc_html( $toc['questions'] ); ?></h2>
			<ul class="saic-doc">
				<li><?php self::out( __( '<strong>Specific questions</strong> ("what is the address?"): the best passages are retrieved and the model writes the answer with citations.', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<strong>Lists and counts</strong> ("list all products for pastures", "how many…"): if the words match a category or tag, the chat uses all items of that term. A pure listing is answered directly by the plugin, with no model involved, so it is complete and consistent. The limit is "Items for list questions" in Settings → Answers.', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<strong>Dates:</strong> the model receives today\'s date, so "next show" works when the date is stored in a custom field that is indexed. Questions about a past year depend on the dates appearing in the indexed text.', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<strong>No answer:</strong> the chat says it did not find the information and shows no sources. Review these questions in History and add the missing content.', 'ai-smart-chat' ) ); ?></li>
			</ul>

			<h2 id="saic-cli"><?php echo esc_html( $toc['cli'] ); ?></h2>
			<p><?php self::out( __( 'Everything can be done from the terminal with WP-CLI, which is handy for large sites (no browser time limits) and for automation such as cron jobs.', 'ai-smart-chat' ) ); ?></p>
			<?php
			self::table(
				array( __( 'Command', 'ai-smart-chat' ), __( 'What it does', 'ai-smart-chat' ) ),
				array(
					array( 'wp saic status', __( 'Shows the key, models, number of indexed posts and chunks, and what is pending.', 'ai-smart-chat' ) ),
					array( 'wp saic index', __( 'Indexes new or changed content. Options: <code>--force</code> (re-index everything), <code>--post_type=product</code>, <code>--batch=20</code>.', 'ai-smart-chat' ) ),
					array( 'wp saic sources', __( 'Indexes the Extra sources and prints warnings. Option: <code>--force</code>.', 'ai-smart-chat' ) ),
					array( 'wp saic index-post <id>', __( 'Indexes a single post. Option: <code>--force</code>.', 'ai-smart-chat' ) ),
					array( 'wp saic delete <id>', __( 'Removes a post from the index.', 'ai-smart-chat' ) ),
					array( 'wp saic clear --yes', __( 'Empties the whole index (it can be rebuilt with <code>wp saic index</code>).', 'ai-smart-chat' ) ),
					array( 'wp saic search "<text>"', __( 'Shows the passages found for a query with their scores. Option: <code>--limit=20</code>. Great for debugging.', 'ai-smart-chat' ) ),
					array( 'wp saic ask "<question>"', __( 'Asks a question and prints the answer and sources, like a visitor would.', 'ai-smart-chat' ) ),
					array( 'wp saic history', __( 'Lists recent questions. Options: <code>--limit=50</code>, <code>--format=table|csv|json</code>.', 'ai-smart-chat' ) ),
				)
			);
			self::code( "wp saic status\nwp saic index --force --post_type=product\nwp saic sources --force\nwp saic search \"products for pastures\" --limit=30\nwp saic ask \"What is the address of the theatre?\"\nwp saic history --limit=100 --format=csv > questions.csv" );
			?>

			<h2 id="saic-dev"><?php echo esc_html( $toc['dev'] ); ?></h2>
			<ul class="saic-doc">
				<li><strong>PHP:</strong> <code>saic_templates</code>, <code>saic_template_data</code>, <code>saic_system_prompt</code>, <code>saic_post_text</code>, <code>saic_is_indexable</code>, <code>saic_context_chunks</code>, <code>saic_retrieval_query</code>, <code>saic_response</code>, <code>saic_chat_request_body</code>, <code>saic_show_floating</code>, <code>saic_client_ip</code>, <code>saic_search_hits</code>, <code>saic_term_expansion</code>, <code>saic_answer_not_found</code></li>
				<li><strong>JS:</strong> <code>AISiteChat.open()</code>, <code>AISiteChat.ask('text')</code>, <code>AISiteChat.filters.renderAnswer</code>, <code>AISiteChat.filters.beforeRequest</code></li>
				<li><strong>REST:</strong> <code>POST /wp-json/saic/v1/ask</code></li>
			</ul>

			<h2 id="saic-trouble"><?php echo esc_html( $toc['trouble'] ); ?></h2>
			<ul class="saic-doc">
				<li><?php self::out( __( '<strong>"I did not find…" but the content exists:</strong> run <code>wp saic search "your question"</code> (or check the Index page). If the page does not appear, it is not indexed: check the content types, re-index, or add it as an Extra source.', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<strong>Content changed but the answer is old:</strong> posts are re-indexed on save; for Extra sources, options and custom fields, run the index again.', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<strong>Lists are incomplete:</strong> raise "Items for list questions" and make sure the items belong to a category or tag that the question mentions.', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<strong>Empty answers or request errors:</strong> reasoning models need "Max output tokens" with room to spare; leave Temperature empty for them.', 'ai-smart-chat' ) ); ?></li>
				<li><?php self::out( __( '<strong>The chat does not show:</strong> check that the floating button is enabled (or the block/shortcode is on the page), that "Home page only" is not limiting it, and clear any page cache.', 'ai-smart-chat' ) ); ?></li>
			</ul>
		</div>
		<?php
	}
}
