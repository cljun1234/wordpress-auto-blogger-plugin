<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AAB_Engine {

    public function __construct() {
        add_action( 'wp_ajax_aab_generate_post', array( $this, 'handle_ajax_generate_post' ) );
    }

    /**
     * AJAX Handler for manual generation.
     */
    public function handle_ajax_generate_post() {
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

        $result = $this->generate_post( $keyword, $template_id, $provider, $model );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'post_id' => $result,
            'edit_url' => get_edit_post_link( $result, '' ),
            'view_url' => get_permalink( $result )
        ) );
    }

    /**
     * Core generation logic. Can be called by AJAX or Scheduler.
     *
     * @param string $keyword
     * @param int $template_id
     * @param string $provider
     * @param string $model
     * @return int|WP_Error Post ID on success.
     */
    public function generate_post( $keyword, $template_id, $provider, $model ) {
        // 1. Fetch Template Data
        $template_data = get_post_meta( $template_id, '_aab_template_data', true );
        if ( ! $template_data ) {
            return new WP_Error( 'invalid_template', 'Invalid template data.' );
        }

        // 2. Construct Prompt
        $system_prompt = $this->build_system_prompt( $template_data );
        $user_prompt = "Write an article about: " . $keyword . ". Return ONLY the HTML content (starting with H1).";

        // 3. Call API
        try {
            $client = AAB_API_Factory::get_client( $provider );
            if ( is_wp_error( $client ) ) {
                return $client;
            }

            $generated_content = $client->generate_content( $system_prompt, $user_prompt, $model );

            if ( is_wp_error( $generated_content ) ) {
                return $generated_content;
            }

            // 4. Create Post
            $post_id = $this->create_wordpress_post( $keyword, $generated_content, $template_data );

            if ( is_wp_error( $post_id ) ) {
                 return $post_id;
            }

            // LOGGING START
            AAB_Logger::log( $post_id, "Generated post for keyword: $keyword. Provider: $provider", 'info' );
            // LOGGING END

            // 5. Process Images
            $this->process_images( $post_id, $keyword, $template_data, $provider, $model );

            return $post_id;

        } catch ( Exception $e ) {
            // LOGGING ERROR
            if ( isset( $post_id ) && ! is_wp_error( $post_id ) ) {
                AAB_Logger::log( $post_id, "Generation Exception: " . $e->getMessage(), 'error' );
            }
            return new WP_Error( 'exception', $e->getMessage() );
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

        // Schema
        if ( ! empty( $data['schema_type'] ) && $data['schema_type'] !== 'Article' ) {
            $prompt .= "\n- Structure the content to support " . $data['schema_type'] . " schema (e.g. if FAQ, use proper Q&A format).";
        }

        // Tags Instruction
        $prompt .= "\n\nIMPORTANT: At the very end of the content, strictly output a hidden HTML comment containing 3-5 relevant comma-separated tags like this: <!-- TAGS: Tag1, Tag2, Tag3 -->";

        $prompt .= "\n\nIMPORTANT: Return ONLY the raw HTML content for the body of the post. Do not include markdown code blocks (```html). Start directly with the <h1> tag.";

        return $prompt;
    }

    private function create_wordpress_post( $keyword, $html_content, $data ) {

        // Cleanup Markdown if AI adds it
        $html_content = preg_replace( '/^```html/', '', $html_content );
        $html_content = preg_replace( '/```$/', '', $html_content );
        $html_content = trim( $html_content );

        // Extract Tags
        $tags_input = array();
        if ( preg_match( '/<!-- TAGS:\s*(.*?)-->/i', $html_content, $tag_matches ) ) {
            $tags_string = $tag_matches[1];
            $tags_input = array_map( 'trim', explode( ',', $tags_string ) );
            // Remove tags comment from content
            $html_content = str_replace( $tag_matches[0], '', $html_content );
        }

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

        // If running via Cron, current user might be 0. Set to admin (ID 1) or keep 0 (if valid).
        if ( $post_arr['post_author'] == 0 ) {
            $post_arr['post_author'] = 1;
        }

        $post_id = wp_insert_post( $post_arr );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Set Tags
        if ( ! empty( $tags_input ) ) {
            wp_set_post_tags( $post_id, $tags_input, false );
        }

        // Save Custom Fields (SEO)
        update_post_meta( $post_id, '_ai_meta_description', $meta_desc );
        update_post_meta( $post_id, '_ai_generated_keyword', $keyword );

        // If Yoast is active
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
    private function process_images( $post_id, $keyword, $data, $provider_ai, $model_ai ) {
        // Check if image provider is selected
        if ( empty( $data['image_provider'] ) || empty( $data['image_count'] ) ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post ) return;
        $current_content = $post->post_content;

        $provider = $data['image_provider'];
        $count = intval( $data['image_count'] );
        $set_featured = isset( $data['image_featured'] ) && $data['image_featured'] === 'yes';
        $add_attribution = isset( $data['image_attribution'] ) && $data['image_attribution'] === 'yes';

        AAB_Logger::log( $post_id, "Starting image processing using provider: $provider. Target count: $count", 'info' );

        // 1. Segmentation
        // Split content by paragraphs
        $paragraphs = explode( '</p>', $current_content );
        // Filter empty
        $paragraphs = array_filter( $paragraphs, function($p) { return !empty(trim(strip_tags($p))); } );
        $paragraphs = array_values( $paragraphs ); // reindex

        $total_p = count( $paragraphs );
        if ( $total_p < 2 ) {
            // Not enough content to split, just query keyword
             // (Fallback logic if content is tiny)
             $segment_indices = array(0);
        } else {
            // Calculate indices for even distribution
            // e.g. 20 paragraphs, 4 images. Interval = 5. Indices: 4, 9, 14, 19 (insert after) or 0, 5, 10, 15 (insert before)
            // User requested "break the content approx equal segment".
            // Let's pick indices "before" which segment starts.
            $interval = floor( $total_p / max(1, $count) );
            $segment_indices = array();
            for ( $i = 0; $i < $count; $i++ ) {
                $idx = ( $i * $interval );
                if ( $idx < $total_p ) {
                    $segment_indices[] = $idx;
                }
            }
        }

        // 2. Extract Contexts
        $contexts = array();
        foreach ( $segment_indices as $idx ) {
            // Get text from this paragraph and maybe the next one
            $text = strip_tags( $paragraphs[$idx] );
            if ( isset( $paragraphs[$idx+1] ) ) {
                $text .= " " . strip_tags( $paragraphs[$idx+1] );
            }
            // Truncate
            $contexts[] = substr( $text, 0, 300 );
        }

        // 3. Generate Queries via AI
        $post_title = get_the_title( $post_id );
        $queries = $this->generate_image_queries_from_context( $post_title, $contexts, $provider_ai, $model_ai );

        if ( empty( $queries ) || ! isset( $queries['segments'] ) ) {
             AAB_Logger::log( $post_id, "Failed to generate image queries from AI context.", 'warning' );
             return;
        }

        $search_queries = $queries['segments']; // Array of strings matching segments
        $featured_query = isset( $queries['featured'] ) ? $queries['featured'] : $keyword;

        AAB_Logger::log( $post_id, "Generated search queries: " . json_encode( $queries ), 'info' );

        // 4. Fetch and Insert Images
        $used_images = AAB_Image_Factory::get_used_images();

        // Handle Featured
        if ( ! has_post_thumbnail( $post_id ) && $set_featured ) {
             $images = AAB_Image_Factory::get_images( $provider, $featured_query, 20, $used_images );
             if ( ! empty( $images ) && ! is_wp_error( $images ) ) {
                 $img_data = $images[0];
                 AAB_Image_Factory::mark_image_as_used( $img_data['url'] );
                 $attach_id = AAB_Image_Factory::sideload_image( $img_data['url'], $post_id, $img_data['alt'] );
                 if ( ! is_wp_error( $attach_id ) ) {
                     set_post_thumbnail( $post_id, $attach_id );
                     if ( $add_attribution ) {
                        $caption = sprintf( 'Photo by <a href="%s" target="_blank" rel="nofollow">%s</a> on %s',
                            $img_data['photographer_url'], $img_data['photographer'], ucfirst( $provider ) );
                        wp_update_post( array( 'ID' => $attach_id, 'post_excerpt' => $caption ) );
                     }
                 }
             }
        }

        // Handle Body Images
        // We need to inject images into $current_content at specific locations.
        // Doing this on the string $current_content is tricky because offsets change as we insert.
        // Better to rebuild the content from the $paragraphs array.

        $new_content = '';

        // We have $paragraphs array (without </p> closing tags because explode removed them, or maybe it kept them? explode removes separator)
        // Check explode behavior: explode('</p>', 'a</p>b</p>') -> ['a', 'b', '']
        // So we need to re-add </p>.

        $p_idx = 0;
        $inserted_count = 0;

        // Map segment indices to queries
        // $segment_indices = [0, 5, 10]
        // $search_queries = ["q1", "q2", "q3"]
        // Map: 0 => "q1", 5 => "q2"...
        $index_to_query = array();
        foreach ( $segment_indices as $k => $idx ) {
            if ( isset( $search_queries[$k] ) ) {
                $index_to_query[$idx] = $search_queries[$k];
            }
        }

        foreach ( $paragraphs as $idx => $p_text ) {
            // Check if we need to insert BEFORE this paragraph
            if ( isset( $index_to_query[$idx] ) ) {
                $query = $index_to_query[$idx];

                // Fetch Image
                $images = AAB_Image_Factory::get_images( $provider, $query, 20, $used_images );

                if ( ! empty( $images ) && ! is_wp_error( $images ) ) {
                    $img_data = $images[0];
                    AAB_Image_Factory::mark_image_as_used( $img_data['url'] );
                    $attach_id = AAB_Image_Factory::sideload_image( $img_data['url'], $post_id, $img_data['alt'] );

                    if ( ! is_wp_error( $attach_id ) ) {
                        $img_url = wp_get_attachment_url( $attach_id );
                        $caption = '';
                        if ( $add_attribution ) {
                            $caption = sprintf( 'Photo by <a href="%s" target="_blank" rel="nofollow">%s</a> on %s',
                                $img_data['photographer_url'], $img_data['photographer'], ucfirst( $provider ) );
                        }

                        $img_html = '<!-- wp:image {"id":' . $attach_id . ',"sizeSlug":"large","linkDestination":"none"} -->';
                        $img_html .= '<figure class="wp-block-image size-large"><img src="' . esc_url( $img_url ) . '" alt="' . esc_attr( $img_data['alt'] ) . '" class="wp-image-' . $attach_id . '"/>';
                        if ( $caption ) {
                            $img_html .= '<figcaption>' . $caption . '</figcaption>';
                        }
                        $img_html .= '</figure><!-- /wp:image -->';

                        $new_content .= "\n" . $img_html . "\n";
                        $inserted_count++;
                    }
                }
            }

            // Append paragraph (re-add closing tag)
            $new_content .= $p_text . "</p>";
        }

        // Save updated content
        $update_args = array(
            'ID' => $post_id,
            'post_content' => $new_content
        );
        wp_update_post( $update_args );

        AAB_Logger::log( $post_id, "Image processing complete. Inserted $inserted_count body images.", 'success' );
    }

    private function generate_image_queries_from_context( $title, $contexts, $provider, $model ) {
        // Build Prompt
        $prompt = "I need stock photo search queries for a blog post titled: '$title'.\n";
        $prompt .= "I have split the content into segments. Please provide a relevant, visual, non-generic search query (2-5 words) for each segment based on the text provided below.\n";
        $prompt .= "Also provide one 'featured' query for the main title.\n\n";

        foreach ( $contexts as $i => $text ) {
            $prompt .= "Segment " . ($i+1) . ": " . $text . "\n\n";
        }

        $prompt .= "Return the result as a raw JSON object with keys: 'featured' (string) and 'segments' (array of strings). Do not use Markdown formatting.";

        try {
            $client = AAB_API_Factory::get_client( $provider );
            if ( is_wp_error( $client ) ) return array();

            $json_str = $client->generate_content( "You are a helpful assistant.", $prompt, $model );

            // Clean JSON
            $json_str = str_replace( array('```json', '```'), '', $json_str );
            $data = json_decode( $json_str, true );

            if ( json_last_error() === JSON_ERROR_NONE && isset( $data['segments'] ) ) {
                return $data;
            }
            return array();

        } catch ( Exception $e ) {
            return array();
        }
    }
}
