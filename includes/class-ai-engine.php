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

            // 5. Process Images (New Feature)
            $this->process_images( $post_id, $keyword, $generated_content, $template_data );

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

    /**
     * Handles image fetching, sideloading, and insertion.
     */
    private function process_images( $post_id, $keyword, $html_content, $data ) {
        // Check if image provider is selected
        if ( empty( $data['image_provider'] ) || empty( $data['image_count'] ) ) {
            return;
        }

        $provider = $data['image_provider'];
        $count = intval( $data['image_count'] );
        $set_featured = isset( $data['image_featured'] ) && $data['image_featured'] === 'yes';
        $add_attribution = isset( $data['image_attribution'] ) && $data['image_attribution'] === 'yes';

        // 1. Determine Search Queries
        // We need 1 for Featured (optional) + $count for body
        // Body queries come from H2 tags
        $search_queries = array();

        // Featured Image Query (always main keyword)
        if ( ! has_post_thumbnail( $post_id ) && $set_featured ) {
            $search_queries[] = array( 'type' => 'featured', 'query' => $keyword );
        }

        // Body Image Queries
        // Extract H2s
        preg_match_all( '/<h2>(.*?)<\/h2>/i', $html_content, $matches );
        $h2s = ! empty( $matches[1] ) ? $matches[1] : array();

        // If we have H2s, use them. If not, fallback to keyword variations
        for ( $i = 0; $i < $count; $i++ ) {
            $q = isset( $h2s[ $i ] ) ? strip_tags( $h2s[ $i ] ) : $keyword . ' ' . ( $i + 1 );
            $search_queries[] = array( 'type' => 'body', 'query' => $q );
        }

        // 2. Fetch and Insert Images
        // To avoid multiple insertions, we modify content in memory then update once.
        // However, we need the CURRENT content from the post because create_wordpress_post strips H1.
        $post = get_post( $post_id );
        $current_content = $post->post_content;

        $inserted_count = 0;

        foreach ( $search_queries as $index => $item ) {
            // Fetch 1 image for this query
            $images = AAB_Image_Factory::get_images( $provider, $item['query'], 1 );

            if ( is_wp_error( $images ) || empty( $images ) ) {
                continue;
            }

            $img_data = $images[0];

            // Sideload
            $attach_id = AAB_Image_Factory::sideload_image( $img_data['url'], $post_id, $img_data['alt'] );

            if ( is_wp_error( $attach_id ) ) {
                continue;
            }

            // Handle Featured Image
            if ( $item['type'] === 'featured' ) {
                set_post_thumbnail( $post_id, $attach_id );
                // Add attribution to caption if requested
                if ( $add_attribution ) {
                    $caption = sprintf( 'Photo by <a href="%s" target="_blank" rel="nofollow">%s</a> on %s',
                        $img_data['photographer_url'],
                        $img_data['photographer'],
                        ucfirst( $provider )
                    );
                    $args = array( 'ID' => $attach_id, 'post_excerpt' => $caption );
                    wp_update_post( $args );
                }
            }
            // Handle Body Images
            elseif ( $item['type'] === 'body' && $inserted_count < $count ) {
                $img_url = wp_get_attachment_url( $attach_id );
                $caption = '';
                if ( $add_attribution ) {
                    $caption = sprintf( 'Photo by <a href="%s" target="_blank" rel="nofollow">%s</a> on %s',
                        $img_data['photographer_url'],
                        $img_data['photographer'],
                        ucfirst( $provider )
                    );
                }

                // Construct HTML
                $img_html = '<!-- wp:image {"id":' . $attach_id . ',"sizeSlug":"large","linkDestination":"none"} -->';
                $img_html .= '<figure class="wp-block-image size-large"><img src="' . esc_url( $img_url ) . '" alt="' . esc_attr( $img_data['alt'] ) . '" class="wp-image-' . $attach_id . '"/>';
                if ( $caption ) {
                    $img_html .= '<figcaption>' . $caption . '</figcaption>';
                }
                $img_html .= '</figure><!-- /wp:image -->';

                // Insert into content
                // Strategy: Find the H2 used for query and insert AFTER it.
                // If query was fallback, insert near end or evenly.
                // Simple approach: Replace the H2 with H2 + Image
                // We use the original query text to find the H2 again.

                $h2_text = $item['query'];
                // Escape regex characters in the query
                $h2_safe = preg_quote( $h2_text, '/' );

                // Try to find exact H2
                $pattern = '/<h2>\s*' . $h2_safe . '\s*<\/h2>/i';
                if ( preg_match( $pattern, $current_content ) ) {
                     $current_content = preg_replace( $pattern, "<h2>$h2_text</h2>\n" . $img_html, $current_content, 1 );
                } else {
                    // Fallback: Append to content if not found (or if it was a keyword fallback)
                    // Or improved fallback: Insert after the Nth paragraph?
                    // Let's just append for now to be safe, or try to insert after a paragraph.
                    // If we just append, it might bunch up at bottom.
                    // Let's try to insert after the ($inserted_count + 1) * 2 paragraph.
                    $paragraphs = explode( '</p>', $current_content );
                    $p_index = ( $inserted_count + 1 ) * 2;
                    if ( isset( $paragraphs[ $p_index ] ) ) {
                        $paragraphs[ $p_index ] .= '</p>' . $img_html;
                        $current_content = implode( '', $paragraphs ); // Reassemble without adding extra </p> since we appended it
                        // Wait, explode removes delimiter. We need to add it back.
                        // Better:
                        $current_content = $this->insert_after_paragraph( $img_html, $p_index, $current_content );
                    } else {
                        $current_content .= "\n" . $img_html;
                    }
                }

                $inserted_count++;
            }
        }

        // Save updated content
        $update_args = array(
            'ID' => $post_id,
            'post_content' => $current_content
        );
        wp_update_post( $update_args );
    }

    private function insert_after_paragraph( $insertion, $paragraph_id, $content ) {
        $closing_p = '</p>';
        $paragraphs = explode( $closing_p, $content );
        foreach ( $paragraphs as $index => $paragraph ) {
            if ( trim( $paragraph ) ) {
                $paragraphs[ $index ] .= $closing_p;
            }
            if ( $paragraph_id == $index + 1 ) {
                $paragraphs[ $index ] .= $insertion;
            }
        }
        return implode( '', $paragraphs );
    }
}
