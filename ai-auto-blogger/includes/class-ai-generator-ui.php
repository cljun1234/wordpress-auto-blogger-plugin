<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AAB_Generator_UI {

    public function __construct() {
        // Priority 5 ensures this parent menu exists before CPTs try to attach submenus at priority 10
        add_action( 'admin_menu', array( $this, 'add_generator_page' ), 5 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    public function add_generator_page() {
        // This is the main page "AI Auto Blogger"
        add_menu_page(
            'AI Auto Blogger',
            'AI Auto Blogger',
            'edit_posts',
            'ai-auto-blogger',
            array( $this, 'render_generator_page' ),
            'dashicons-superhero',
            6
        );
    }

    public function enqueue_scripts( $hook ) {
        if ( 'toplevel_page_ai-auto-blogger' !== $hook ) {
            return;
        }

        wp_enqueue_script( 'aab-generator-js', AAB_URL . 'admin/js/generator.js', array( 'jquery' ), AAB_VERSION, true );
        wp_localize_script( 'aab-generator-js', 'aab_vars', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'aab_generate_nonce' ),
        ) );

        wp_enqueue_style( 'aab-admin-css', AAB_URL . 'admin/css/admin.css', array(), AAB_VERSION );
    }

    public function render_generator_page() {
        $templates = get_posts( array(
            'post_type' => 'ai_template',
            'numberposts' => -1,
            'post_status' => 'publish',
        ) );

        ?>
        <div class="wrap">
            <h1>AI Auto Blogger Generator</h1>
            <p>Select a template and enter your keyword to generate a full blog post.</p>

            <div class="aab-card">
                <form id="aab-generator-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="aab_keyword">Target Keyword / Topic</label></th>
                            <td>
                                <input name="aab_keyword" type="text" id="aab_keyword" class="regular-text" placeholder="e.g. Best Gaming Laptops 2025" required>
                                <p class="description">The main topic for your article.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="aab_template">Select Template</label></th>
                            <td>
                                <select name="aab_template" id="aab_template" required>
                                    <option value="">-- Choose a Preset --</option>
                                    <?php if ( $templates ) : ?>
                                        <?php foreach ( $templates as $template ) : ?>
                                            <option value="<?php echo esc_attr( $template->ID ); ?>"><?php echo esc_html( $template->post_title ); ?></option>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <option value="" disabled>No templates found. Please create one.</option>
                                    <?php endif; ?>
                                </select>
                                <p class="description"><a href="<?php echo admin_url('edit.php?post_type=ai_template'); ?>">Manage Templates</a></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="aab_provider">AI Provider</label></th>
                            <td>
                                <select name="aab_provider" id="aab_provider">
                                    <option value="openai">OpenAI</option>
                                    <option value="gemini">Google Gemini</option>
                                    <option value="deepseek">DeepSeek</option>
                                </select>
                            </td>
                        </tr>
                        <tr class="aab-model-row">
                            <th scope="row"><label for="aab_model">Model</label></th>
                            <td>
                                <select name="aab_model" id="aab_model">
                                    <!-- Options populated via JS or defaults -->
                                    <option value="gpt-4o">GPT-4o</option>
                                    <option value="gpt-4o-mini">GPT-4o Mini</option>
                                    <option value="gemini-1.5-pro">Gemini 1.5 Pro</option>
                                    <option value="deepseek-chat">DeepSeek Chat</option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" id="aab-generate-btn" class="button button-primary button-large">Generate Article</button>
                        <span class="spinner" style="float:none; margin-top:0;"></span>
                    </p>
                </form>
            </div>

            <div id="aab-results" style="margin-top: 20px; display:none;">
                <div class="notice notice-success inline"><p><strong>Success!</strong> Article generated.</p></div>
                <div id="aab-generated-links" style="margin-top: 10px;"></div>
            </div>
             <div id="aab-error" style="margin-top: 20px; display:none;">
                <div class="notice notice-error inline"><p id="aab-error-msg"></p></div>
            </div>

        </div>
        <?php
    }
}
