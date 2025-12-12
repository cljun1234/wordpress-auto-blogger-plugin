<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AAB_Templates {

    public function __construct() {
        add_action( 'init', array( $this, 'register_cpt' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_meta_boxes' ) );
    }

    public function register_cpt() {
        $labels = array(
            'name'                  => _x( 'Generation Templates', 'Post Type General Name', 'ai-auto-blogger' ),
            'singular_name'         => _x( 'Template', 'Post Type Singular Name', 'ai-auto-blogger' ),
            'menu_name'             => __( 'AI Templates', 'ai-auto-blogger' ),
            'all_items'             => __( 'All Templates', 'ai-auto-blogger' ),
            'add_new_item'          => __( 'Add New Template', 'ai-auto-blogger' ),
            'add_new'               => __( 'Add New', 'ai-auto-blogger' ),
            'new_item'              => __( 'New Template', 'ai-auto-blogger' ),
            'edit_item'             => __( 'Edit Template', 'ai-auto-blogger' ),
            'update_item'           => __( 'Update Template', 'ai-auto-blogger' ),
            'view_item'             => __( 'View Template', 'ai-auto-blogger' ),
            'search_items'          => __( 'Search Template', 'ai-auto-blogger' ),
        );
        $args = array(
            'label'                 => __( 'Template', 'ai-auto-blogger' ),
            'labels'                => $labels,
            'supports'              => array( 'title' ), // Only title, other data is meta
            'hierarchical'          => false,
            'public'                => false,
            'show_ui'               => true,
            'show_in_menu'          => 'ai-auto-blogger', // Show under our main menu
            'menu_position'         => 20,
            'show_in_admin_bar'     => false,
            'show_in_nav_menus'     => false,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'capability_type'       => 'post',
        );
        register_post_type( 'ai_template', $args );
    }

    public function add_meta_boxes() {
        add_meta_box( 'aab_template_settings', 'Template Settings', array( $this, 'render_meta_box' ), 'ai_template', 'normal', 'high' );
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'aab_save_template_data', 'aab_template_nonce' );

        $values = get_post_meta( $post->ID, '_aab_template_data', true );

        // Defaults
        $defaults = array(
            'intent' => 'informational',
            'persona' => 'General Audience',
            'article_type' => 'blog_post',
            'min_words' => 1000,
            'max_words' => 2000,
            'headings' => "H1\nH2\nH3",
            'schema_type' => 'Article',
            'title_formula' => '{keyword} - A Complete Guide',
            'meta_desc_formula' => 'Learn everything about {keyword} in this detailed guide.',
            'slug_rules' => 'short-hyphens',
            'tone' => 'Professional',
            'readability' => 'Grade 8',
            'avoid_words' => '',
            'internal_links' => '',
            'external_links' => 'authority_only',
            'image_provider' => 'none',
            'image_count' => 3,
            'image_featured' => 'on',
            'image_credits' => 'on',
        );

        $data = wp_parse_args( $values, $defaults );
        ?>
        <div class="aab-meta-box">
            <style>
                .aab-row { margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 15px; }
                .aab-row label { display: block; font-weight: bold; margin-bottom: 5px; }
                .aab-row input[type="text"], .aab-row textarea, .aab-row select { width: 100%; max-width: 400px; }
            </style>

            <!-- SEO Intent -->
            <h3>1. SEO Intent</h3>
            <div class="aab-row">
                <label>Search Intent</label>
                <select name="aab_data[intent]">
                    <option value="informational" <?php selected( $data['intent'], 'informational' ); ?>>Informational</option>
                    <option value="commercial" <?php selected( $data['intent'], 'commercial' ); ?>>Commercial</option>
                    <option value="transactional" <?php selected( $data['intent'], 'transactional' ); ?>>Transactional</option>
                    <option value="navigational" <?php selected( $data['intent'], 'navigational' ); ?>>Navigational</option>
                </select>
            </div>
            <div class="aab-row">
                <label>User Persona</label>
                <input type="text" name="aab_data[persona]" value="<?php echo esc_attr( $data['persona'] ); ?>" />
            </div>

            <!-- Content Structure -->
            <h3>2. Content Structure</h3>
            <div class="aab-row">
                <label>Article Type</label>
                <select name="aab_data[article_type]">
                    <option value="how_to" <?php selected( $data['article_type'], 'how_to' ); ?>>How-To / Tutorial</option>
                    <option value="listicle" <?php selected( $data['article_type'], 'listicle' ); ?>>Listicle</option>
                    <option value="review" <?php selected( $data['article_type'], 'review' ); ?>>Review</option>
                    <option value="comparison" <?php selected( $data['article_type'], 'comparison' ); ?>>Comparison</option>
                    <option value="news" <?php selected( $data['article_type'], 'news' ); ?>>News</option>
                    <option value="blog_post" <?php selected( $data['article_type'], 'blog_post' ); ?>>Standard Blog Post</option>
                </select>
            </div>
            <div class="aab-row">
                <label>Word Count (Min - Max)</label>
                <input type="number" name="aab_data[min_words]" value="<?php echo esc_attr( $data['min_words'] ); ?>" style="width: 80px;" /> -
                <input type="number" name="aab_data[max_words]" value="<?php echo esc_attr( $data['max_words'] ); ?>" style="width: 80px;" />
            </div>
            <div class="aab-row">
                <label>Required Headings (One per line)</label>
                <textarea name="aab_data[headings]" rows="4"><?php echo esc_textarea( $data['headings'] ); ?></textarea>
            </div>
            <div class="aab-row">
                <label>Schema Markup Type</label>
                <select name="aab_data[schema_type]">
                    <option value="Article" <?php selected( $data['schema_type'], 'Article' ); ?>>Article</option>
                    <option value="FAQPage" <?php selected( $data['schema_type'], 'FAQPage' ); ?>>FAQPage</option>
                    <option value="HowTo" <?php selected( $data['schema_type'], 'HowTo' ); ?>>HowTo</option>
                    <option value="Review" <?php selected( $data['schema_type'], 'Review' ); ?>>Review</option>
                </select>
            </div>

            <!-- SEO Metadata -->
            <h3>3. SEO Metadata</h3>
            <div class="aab-row">
                <label>Meta Title Formula (Use {keyword}, {year})</label>
                <input type="text" name="aab_data[title_formula]" value="<?php echo esc_attr( $data['title_formula'] ); ?>" />
            </div>
            <div class="aab-row">
                <label>Meta Description Formula</label>
                <textarea name="aab_data[meta_desc_formula]" rows="2"><?php echo esc_textarea( $data['meta_desc_formula'] ); ?></textarea>
            </div>

            <!-- Tone & Brand -->
            <h3>4. Tone & Brand</h3>
            <div class="aab-row">
                <label>Tone of Voice</label>
                <input type="text" name="aab_data[tone]" value="<?php echo esc_attr( $data['tone'] ); ?>" placeholder="e.g. Professional, Friendly, Witty" />
            </div>
             <div class="aab-row">
                <label>Readability Level</label>
                <input type="text" name="aab_data[readability]" value="<?php echo esc_attr( $data['readability'] ); ?>" placeholder="e.g. Grade 8, General Public" />
            </div>
            <div class="aab-row">
                <label>Negative Vocabulary (Avoid these words)</label>
                <textarea name="aab_data[avoid_words]" rows="2"><?php echo esc_textarea( $data['avoid_words'] ); ?></textarea>
            </div>

            <!-- Internal Links -->
             <h3>5. Linking</h3>
             <div class="aab-row">
                <label>Internal Link Targets (URL per line, AI will try to match context)</label>
                <textarea name="aab_data[internal_links]" rows="3"><?php echo esc_textarea( $data['internal_links'] ); ?></textarea>
            </div>

            <!-- Image Settings -->
            <h3>6. Image Settings</h3>
            <div class="aab-row">
                <label>Image Provider</label>
                <select name="aab_data[image_provider]">
                    <option value="none" <?php selected( $data['image_provider'], 'none' ); ?>>None</option>
                    <option value="unsplash" <?php selected( $data['image_provider'], 'unsplash' ); ?>>Unsplash</option>
                    <option value="pexels" <?php selected( $data['image_provider'], 'pexels' ); ?>>Pexels</option>
                    <option value="pixabay" <?php selected( $data['image_provider'], 'pixabay' ); ?>>Pixabay</option>
                </select>
            </div>
            <div class="aab-row">
                <label>Number of Images</label>
                <input type="number" name="aab_data[image_count]" value="<?php echo esc_attr( $data['image_count'] ); ?>" style="width: 80px;" min="0" max="10" />
            </div>
            <div class="aab-row">
                <label>
                    <input type="checkbox" name="aab_data[image_featured]" <?php checked( $data['image_featured'], 'on' ); ?> />
                    Set first image as Featured Image
                </label>
            </div>
            <div class="aab-row">
                <label>
                    <input type="checkbox" name="aab_data[image_credits]" <?php checked( $data['image_credits'], 'on' ); ?> />
                    Add image credits/attribution
                </label>
            </div>

        </div>
        <?php
    }

    public function save_meta_boxes( $post_id ) {
        if ( ! isset( $_POST['aab_template_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( $_POST['aab_template_nonce'], 'aab_save_template_data' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( isset( $_POST['aab_data'] ) ) {
            // Sanitize array
            $data = array_map( 'sanitize_textarea_field', $_POST['aab_data'] );
            update_post_meta( $post_id, '_aab_template_data', $data );
        }
    }
}
