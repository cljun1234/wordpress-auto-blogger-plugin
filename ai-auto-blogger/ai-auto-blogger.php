<?php
/**
 * Plugin Name: AI Auto Blogger
 * Plugin URI:  https://example.com/ai-auto-blogger
 * Description: Automated SEO-optimized blogging using OpenAI, Gemini, or DeepSeek.
 * Version:     1.0.0
 * Author:      Jules
 * License:     GPL-2.0+
 * Text Domain: ai-auto-blogger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define Constants
define( 'AAB_VERSION', '1.0.0' );
define( 'AAB_PATH', plugin_dir_path( __FILE__ ) );
define( 'AAB_URL', plugin_dir_url( __FILE__ ) );

// Include Classes
require_once AAB_PATH . 'includes/class-ai-settings.php';
require_once AAB_PATH . 'includes/class-ai-templates.php';
require_once AAB_PATH . 'includes/class-ai-generator-ui.php';
require_once AAB_PATH . 'includes/class-ai-engine.php';

// Include API Classes
require_once AAB_PATH . 'includes/api/class-api-factory.php';
require_once AAB_PATH . 'includes/api/class-openai.php';
require_once AAB_PATH . 'includes/api/class-gemini.php';
require_once AAB_PATH . 'includes/api/class-deepseek.php';


// Initialize Plugin
function aab_init() {
	new AAB_Settings();
	new AAB_Templates();
	new AAB_Generator_UI();
	new AAB_Engine();
}
add_action( 'plugins_loaded', 'aab_init' );

// Activation Hook
register_activation_hook( __FILE__, 'aab_activate' );
function aab_activate() {
    // Flush rewrite rules if we register CPTs on activation (though we do it on init)
}
