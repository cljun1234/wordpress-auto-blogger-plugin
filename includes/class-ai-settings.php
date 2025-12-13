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

		add_settings_section(
			'setting_section_id', // ID
			'API Configuration', // Title
			array( $this, 'print_section_info' ), // Callback
			'ai-auto-blogger-settings' // Page
		);

		add_settings_field(
			'openai_api_key',
			'OpenAI API Key',
			array( $this, 'openai_callback' ),
			'ai-auto-blogger-settings',
			'setting_section_id'
		);

        add_settings_field(
			'gemini_api_key',
			'Gemini API Key',
			array( $this, 'gemini_callback' ),
			'ai-auto-blogger-settings',
			'setting_section_id'
		);

        add_settings_field(
			'deepseek_api_key',
			'DeepSeek API Key',
			array( $this, 'deepseek_callback' ),
			'ai-auto-blogger-settings',
			'setting_section_id'
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

		return $new_input;
	}

	public function print_section_info() {
		print 'Enter your API keys below:';
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
}
