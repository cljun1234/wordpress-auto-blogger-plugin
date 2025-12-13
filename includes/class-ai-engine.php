<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AAB_Engine {

    public function __construct() {
        add_action( 'wp_ajax_aab_generate_post', array( $this, 'ajax_generate_post' ) );
    }

    public function ajax_generate_post() {
        check_ajax_referer( 'aab_generate_nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $keyword = sanitize_text_field( $_POST['keyword'] );
        $template_id = intval( $_POST['template_id'] );
        $provider = sanitize_text_field( $_POST['provider'] );
        $model = sanitize_text_field( $_POST['model'] );

        if ( empty( $keyword ) || empty( $template_id ) ) {
            wp_send_json_error( 'Missing keyword or template.' );
        }

        // 1. Fetch Template Data
        $template_data = get_post_meta( $template_id, '_aab_template_data', true );
        if ( ! $template_data ) {
            wp_send_json_error( 'Invalid template data.' );
        }

        // 2. Construct Prompt
        $system_prompt = $this->build_system_prompt( $template_data );
        $user_prompt = "Write an article about: " . $keyword . ". Return ONLY the HTML content (starting with H1).";

        // 3. Call API
        try {
            $client = AAB_API_Factory::get_client( $provider );
            if ( is_wp_error( $client ) ) {
                wp_send_json_error( $client->get_error_message() );
            }

            $generated_content = $client->generate_content( $system_prompt, $user_prompt, $model );

            if ( is_wp_error( $generated_content ) ) {
                wp_send_json_error( $generated_content->get_error_message() );
            }

            // 4. Create Post
            $post_id = $this->create_wordpress_post( $keyword, $generated_content, $template_data );

            if ( is_wp_error( $post_id ) ) {
                 wp_send_json_error( $post_id->get_error_message() );
            }

            wp_send_json_success( array(
                'post_id' => $post_id,
                'edit_url' => get_edit_post_link( $post_id, '' ),
                'view_url' => get_permalink( $post_id )
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    private function build_system_prompt( $data ) {
        $prompt = "You are an expert SEO content writer.";

        // Persona & Intent
        if ( ! empty( $data['persona'] ) ) $prompt .= " Your target audience is: " . $data['persona'] . ".";
        if ( ! empty( $data['intent'] ) ) $prompt .= " The search intent is " . $data['intent'] . ".";

        // Structure
        $prompt .= "\n\nFormat Requirements:";
        if ( ! empty( $data['article_type'] ) ) $prompt .= "\n- Article Type: " . $data['article_type'];
        if ( ! empty( $data['min_words'] ) ) $prompt .= "\n- Minimum Words: " . $data['min_words'];
        if ( ! empty( $data['max_words'] ) ) $prompt .= "\n- Maximum Words: " . $data['max_words'];

        $prompt .= "\n- Use proper HTML tags (H1, H2, H3, p, ul, table).";
        $prompt .= "\n- The first line must be the <h1>Title</h1>.";

        if ( ! empty( $data['headings'] ) ) {
            $prompt .= "\n- You MUST include these headings (or similar): \n" . $data['headings'];
        }

        // Tone
        if ( ! empty( $data['tone'] ) ) $prompt .= "\n\nTone of Voice: " . $data['tone'];
        if ( ! empty( $data['readability'] ) ) $prompt .= "\nReadability Level: " . $data['readability'];
        if ( ! empty( $data['avoid_words'] ) ) $prompt .= "\nAvoid these words: " . $data['avoid_words'];

        // SEO Rules
        $prompt .= "\n\nSEO Rules:";
        $prompt .= "\n- Include the keyword naturally in the H1, introduction, and at least one H2.";
        $prompt .= "\n- Add a 'Key Takeaways' table or list if appropriate.";

        // Internal Links (Mock Context)
        if ( ! empty( $data['internal_links'] ) ) {
             $prompt .= "\n- Suggest placements for these internal links if relevant (format as <a href='URL'>Anchor</a>): \n" . $data['internal_links'];
        }

        // Schema (Just instruction for now, as full JSON-LD is complex to inline in content usually handled by plugins)
        if ( ! empty( $data['schema_type'] ) && $data['schema_type'] !== 'Article' ) {
            $prompt .= "\n- Structure the content to support " . $data['schema_type'] . " schema (e.g. if FAQ, use proper Q&A format).";
        }

        $prompt .= "\n\nIMPORTANT: Return ONLY the raw HTML content for the body of the post. Do not include markdown code blocks (```html). Start directly with the <h1> tag.";

        return $prompt;
    }

    private function create_wordpress_post( $keyword, $html_content, $data ) {

        // Cleanup Markdown if AI adds it
        $html_content = preg_replace( '/^```html/', '', $html_content );
        $html_content = preg_replace( '/```$/', '', $html_content );
        $html_content = trim( $html_content );

        // Extract H1 for Title
        $title = $keyword; // Default
        if ( preg_match( '/<h1>(.*?)<\/h1>/i', $html_content, $matches ) ) {
            $title = strip_tags( $matches[1] );
            // Remove H1 from body since WP adds it via title
             $html_content = preg_replace( '/<h1>.*?<\/h1>/i', '', $html_content, 1 );
        } else {
            // Apply formula if no H1 found or as fallback
             if ( ! empty( $data['title_formula'] ) ) {
                $title = str_replace(
                    array( '{keyword}', '{year}' ),
                    array( $keyword, date('Y') ),
                    $data['title_formula']
                );
            }
        }

        // Generate Meta Description
        $meta_desc = '';
        if ( ! empty( $data['meta_desc_formula'] ) ) {
             $meta_desc = str_replace(
                array( '{keyword}', '{year}' ),
                array( $keyword, date('Y') ),
                $data['meta_desc_formula']
            );
        } else {
            // Grab first 160 chars of content
            $text_content = strip_tags( $html_content );
            $meta_desc = substr( $text_content, 0, 155 ) . '...';
        }

        // Create Post
        $post_arr = array(
            'post_title'    => $title,
            'post_content'  => $html_content,
            'post_status'   => 'draft',
            'post_author'   => get_current_user_id(),
            'post_type'     => 'post',
        );

        $post_id = wp_insert_post( $post_arr );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Save Custom Fields (SEO)
        update_post_meta( $post_id, '_ai_meta_description', $meta_desc );
        update_post_meta( $post_id, '_ai_generated_keyword', $keyword );

        // If Yoast is active, try to save to Yoast fields
        update_post_meta( $post_id, '_yoast_wpseo_title', $title );
        update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
        update_post_meta( $post_id, '_yoast_wpseo_focuskw', $keyword );

        // If RankMath is active
        update_post_meta( $post_id, 'rank_math_title', $title );
        update_post_meta( $post_id, 'rank_math_description', $meta_desc );
        update_post_meta( $post_id, 'rank_math_focus_keyword', $keyword );

        return $post_id;
    }
}
