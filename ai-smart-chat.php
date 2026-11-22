<?php
/**
 * Plugin Name:       AI Smart Chat
 * Description:       AI-powered search chatbot (RAG) for your site: answers visitors' questions from your published content and cites the sources.
 * Version:           1.3.5
 * Author: Antonio Scairato
 * Author URI: https://github.com/antonioscairato
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-smart-chat
 * Domain Path:       /languages
 *
 * @package AI_Smart_Chat
 */

defined( 'ABSPATH' ) || exit;

define( 'SAIC_VERSION', '1.3.5' );
define( 'SAIC_FILE', __FILE__ );
define( 'SAIC_DIR', plugin_dir_path( __FILE__ ) );
define( 'SAIC_URL', plugin_dir_url( __FILE__ ) );

require_once SAIC_DIR . 'includes/class-saic-settings.php';
require_once SAIC_DIR . 'includes/class-saic-db.php';
require_once SAIC_DIR . 'includes/class-saic-openai.php';
require_once SAIC_DIR . 'includes/class-saic-chunker.php';
require_once SAIC_DIR . 'includes/class-saic-html.php';
require_once SAIC_DIR . 'includes/class-saic-sources.php';
require_once SAIC_DIR . 'includes/class-saic-indexer.php';
require_once SAIC_DIR . 'includes/class-saic-search.php';
require_once SAIC_DIR . 'includes/class-saic-chat.php';
require_once SAIC_DIR . 'includes/class-saic-rest.php';
require_once SAIC_DIR . 'includes/class-saic-frontend.php';
require_once SAIC_DIR . 'includes/class-saic-block.php';
require_once SAIC_DIR . 'includes/class-saic-help.php';
require_once SAIC_DIR . 'includes/class-saic-admin.php';
require_once SAIC_DIR . 'includes/class-saic-plugin.php';

register_activation_hook( __FILE__, array( 'SAIC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SAIC_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'SAIC_Plugin', 'init' ) );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SAIC_DIR . 'includes/class-saic-cli.php';
	WP_CLI::add_command( 'saic', 'SAIC_CLI' );
}
