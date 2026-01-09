<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AAB_Settings {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
		add_action( 'admin_init', array( $this, 'page_init' ) );
	}

	public function add_plugin_page() {
        // We do NOT add the main menu page here anymore.
        // It is handled by AAB_Generator_UI to ensure the Generator is the main view.

        // Add Settings as a submenu
        add_submenu_page(
            'ai-auto-blogger', // Parent slug (registered by Generator UI)
            'Settings',
            'Settings',
            'manage_options',
            'ai-auto-blogger-settings',
            array( $this, 'create_settings_page' )
        );
	}

	public function create_settings_page() {
		?>
		<div class="wrap">
			<h1>AI Auto Blogger Settings</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'aab_option_group' );
				do_settings_sections( 'ai-auto-blogger-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function page_init() {
		register_setting(
			'aab_option_group', // Option group
			'aab_settings', // Option name
			array( $this, 'sanitize' ) // Sanitize
		);

        // AI Providers Section
		add_settings_section(
			'ai_api_section', // ID
			'AI Text Generators', // Title
			array( $this, 'print_ai_section_info' ), // Callback
			'ai-auto-blogger-settings' // Page
		);

		add_settings_field(
			'openai_api_key',
			'OpenAI API Key',
			array( $this, 'openai_callback' ),
			'ai-auto-blogger-settings',
			'ai_api_section'
		);

        add_settings_field(
			'gemini_api_key',
			'Gemini API Key',
			array( $this, 'gemini_callback' ),
			'ai-auto-blogger-settings',
			'ai_api_section'
		);

        add_settings_field(
			'deepseek_api_key',
			'DeepSeek API Key',
			array( $this, 'deepseek_callback' ),
			'ai-auto-blogger-settings',
			'ai_api_section'
		);

        // Image Providers Section
        add_settings_section(
			'image_api_section', // ID
			'Stock Image Providers', // Title
			array( $this, 'print_image_section_info' ), // Callback
			'ai-auto-blogger-settings' // Page
		);

        add_settings_field(
			'unsplash_access_key',
			'Unsplash Access Key',
			array( $this, 'unsplash_callback' ),
			'ai-auto-blogger-settings',
			'image_api_section'
		);

        // Added Unsplash fields
        add_settings_field(
			'unsplash_app_id',
			'Unsplash Application ID (Optional)',
			array( $this, 'unsplash_app_id_callback' ),
			'ai-auto-blogger-settings',
			'image_api_section'
		);

        add_settings_field(
			'unsplash_secret_key',
			'Unsplash Secret Key (Optional)',
			array( $this, 'unsplash_secret_key_callback' ),
			'ai-auto-blogger-settings',
			'image_api_section'
		);

        add_settings_field(
			'pexels_api_key',
			'Pexels API Key',
			array( $this, 'pexels_callback' ),
			'ai-auto-blogger-settings',
			'image_api_section'
		);

        add_settings_field(
			'pixabay_api_key',
			'Pixabay API Key',
			array( $this, 'pixabay_callback' ),
			'ai-auto-blogger-settings',
			'image_api_section'
		);
	}

	public function sanitize( $input ) {
		$new_input = array();
		if( isset( $input['openai_api_key'] ) )
			$new_input['openai_api_key'] = sanitize_text_field( $input['openai_api_key'] );
        if( isset( $input['gemini_api_key'] ) )
			$new_input['gemini_api_key'] = sanitize_text_field( $input['gemini_api_key'] );
        if( isset( $input['deepseek_api_key'] ) )
			$new_input['deepseek_api_key'] = sanitize_text_field( $input['deepseek_api_key'] );

        if( isset( $input['unsplash_access_key'] ) )
			$new_input['unsplash_access_key'] = sanitize_text_field( $input['unsplash_access_key'] );
        if( isset( $input['unsplash_app_id'] ) )
			$new_input['unsplash_app_id'] = sanitize_text_field( $input['unsplash_app_id'] );
        if( isset( $input['unsplash_secret_key'] ) )
			$new_input['unsplash_secret_key'] = sanitize_text_field( $input['unsplash_secret_key'] );

        if( isset( $input['pexels_api_key'] ) )
			$new_input['pexels_api_key'] = sanitize_text_field( $input['pexels_api_key'] );
        if( isset( $input['pixabay_api_key'] ) )
			$new_input['pixabay_api_key'] = sanitize_text_field( $input['pixabay_api_key'] );

		return $new_input;
	}

	public function print_ai_section_info() {
		print 'Enter your AI Text Generator API keys below:';
	}

    public function print_image_section_info() {
		print 'Enter your Stock Image Provider API keys below:';
	}

	public function openai_callback() {
		$options = get_option( 'aab_settings' );
        $val = isset( $options['openai_api_key'] ) ? $options['openai_api_key'] : '';
		printf(
			'<input type="password" id="openai_api_key" name="aab_settings[openai_api_key]" value="%s" class="regular-text" />',
			esc_attr( $val )
		);
	}

    public function gemini_callback() {
		$options = get_option( 'aab_settings' );
        $val = isset( $options['gemini_api_key'] ) ? $options['gemini_api_key'] : '';
		printf(
			'<input type="password" id="gemini_api_key" name="aab_settings[gemini_api_key]" value="%s" class="regular-text" />',
			esc_attr( $val )
		);
	}

    public function deepseek_callback() {
		$options = get_option( 'aab_settings' );
        $val = isset( $options['deepseek_api_key'] ) ? $options['deepseek_api_key'] : '';
		printf(
			'<input type="password" id="deepseek_api_key" name="aab_settings[deepseek_api_key]" value="%s" class="regular-text" />',
			esc_attr( $val )
		);
	}

    public function unsplash_callback() {
		$options = get_option( 'aab_settings' );
        $val = isset( $options['unsplash_access_key'] ) ? $options['unsplash_access_key'] : '';
		printf(
			'<input type="password" id="unsplash_access_key" name="aab_settings[unsplash_access_key]" value="%s" class="regular-text" />
            <p class="description">Required for searching images.</p>',
			esc_attr( $val )
		);
	}

    public function unsplash_app_id_callback() {
		$options = get_option( 'aab_settings' );
        $val = isset( $options['unsplash_app_id'] ) ? $options['unsplash_app_id'] : '';
		printf(
			'<input type="text" id="unsplash_app_id" name="aab_settings[unsplash_app_id]" value="%s" class="regular-text" />',
			esc_attr( $val )
		);
	}

    public function unsplash_secret_key_callback() {
		$options = get_option( 'aab_settings' );
        $val = isset( $options['unsplash_secret_key'] ) ? $options['unsplash_secret_key'] : '';
		printf(
			'<input type="password" id="unsplash_secret_key" name="aab_settings[unsplash_secret_key]" value="%s" class="regular-text" />',
			esc_attr( $val )
		);
	}

    public function pexels_callback() {
		$options = get_option( 'aab_settings' );
        $val = isset( $options['pexels_api_key'] ) ? $options['pexels_api_key'] : '';
		printf(
			'<input type="password" id="pexels_api_key" name="aab_settings[pexels_api_key]" value="%s" class="regular-text" />',
			esc_attr( $val )
		);
	}

    public function pixabay_callback() {
		$options = get_option( 'aab_settings' );
        $val = isset( $options['pixabay_api_key'] ) ? $options['pixabay_api_key'] : '';
		printf(
			'<input type="password" id="pixabay_api_key" name="aab_settings[pixabay_api_key]" value="%s" class="regular-text" />',
			esc_attr( $val )
		);
	}
}
