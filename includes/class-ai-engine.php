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
            $this->process_images( $post_id, $keyword, $generated_content, $template_data );

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

        // Image Query Instruction
        $prompt .= "\n\nIMPORTANT: For every <h2> section, suggest a visual stock photo search query. Format it as a hidden comment right after the <h2> tag like this: <h2>Heading Text</h2> <!-- IMAGE_QUERY: A creative search term description -->";
        $prompt .= "\nEnsure the search term is descriptive but simple (e.g. 'coding laptop neon light' instead of 'best laptop').";

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
    private function process_images( $post_id, $keyword, $html_content, $data ) {
        // Check if image provider is selected
        if ( empty( $data['image_provider'] ) || empty( $data['image_count'] ) ) {
            return;
        }

        // FIX: Fetch Cleaned Content from DB instead of using the raw $html_content from AI response.
        // The raw $html_content contains H1 and Markdown which were stripped in create_wordpress_post.
        // However, we instructed AI to put IMAGE_QUERY in comments. create_wordpress_post does NOT strip comments.
        // So the cleaned content in DB *should* still have the comments.
        $post = get_post( $post_id );
        if ( ! $post ) return;

        $current_content = $post->post_content;

        $provider = $data['image_provider'];
        $count = intval( $data['image_count'] );
        $set_featured = isset( $data['image_featured'] ) && $data['image_featured'] === 'yes';
        $add_attribution = isset( $data['image_attribution'] ) && $data['image_attribution'] === 'yes';

        AAB_Logger::log( $post_id, "Starting image processing using provider: $provider. Target count: $count", 'info' );

        // 1. Determine Search Queries from Content Markers or Fallback
        // We need 1 for Featured (optional) + $count for body
        $search_queries = array();

        // Extract H2s and adjacent comments
        // Regex looks for <h2>...</h2> followed optionally by whitespace and <!-- IMAGE_QUERY: ... -->
        preg_match_all( '/<h2[^>]*>(.*?)<\/h2>(?:\s*<!-- IMAGE_QUERY:\s*(.*?)-->)?/is', $current_content, $matches, PREG_SET_ORDER );

        // Featured Image Query (always main keyword)
        if ( ! has_post_thumbnail( $post_id ) && $set_featured ) {
            $search_queries[] = array( 'type' => 'featured', 'query' => $keyword );
        }

        // Body Image Queries
        for ( $i = 0; $i < $count; $i++ ) {
            if ( isset( $matches[ $i ] ) ) {
                // If AI provided a specific query, use it
                if ( ! empty( $matches[ $i ][2] ) ) {
                    $q = strip_tags( $matches[ $i ][2] );
                    // Remove the comment from content (cleanup)
                    // Note: We will do a global cleanup at the end, but we can also replace it here to ensure we don't match it again.
                    // But since we are iterating, let's just collect the queries first.
                } else {
                    // Fallback to H2 text
                    $q = strip_tags( $matches[ $i ][1] );
                }
            } else {
                 $q = $keyword . ' ' . ( $i + 1 );
            }
            $search_queries[] = array( 'type' => 'body', 'query' => $q );
        }

        AAB_Logger::log( $post_id, "Generated search queries: " . json_encode( $search_queries ), 'info' );

        // 2. Fetch and Insert Images

        // Fetch exclusion list (used images)
        $used_images = AAB_Image_Factory::get_used_images();

        $inserted_count = 0;

        foreach ( $search_queries as $index => $item ) {
            // Fetch batch of images for this query, excluding used
            $images = AAB_Image_Factory::get_images( $provider, $item['query'], 20, $used_images ); // Fetch 20 to find a unique one

            if ( is_wp_error( $images ) ) {
                AAB_Logger::log( $post_id, "Image fetch failed for query '{$item['query']}': " . $images->get_error_message(), 'error' );
                continue;
            }

            if ( empty( $images ) ) {
                AAB_Logger::log( $post_id, "No images found for query '{$item['query']}'", 'warning' );
                continue;
            }

            // Pick the first one (factory logic now filters used ones, or gives random if all used)
            $img_data = $images[0];

            // Mark as used
            // We use URL or ID depending on provider. For now URL is safest unique identifier across providers without complex ID storage
            AAB_Image_Factory::mark_image_as_used( $img_data['url'] );

            // Sideload
            $attach_id = AAB_Image_Factory::sideload_image( $img_data['url'], $post_id, $img_data['alt'] );

            if ( is_wp_error( $attach_id ) ) {
                AAB_Logger::log( $post_id, "Sideload failed for url '{$img_data['url']}': " . $attach_id->get_error_message(), 'error' );
                continue;
            }

            AAB_Logger::log( $post_id, "Successfully sideloaded image ID: $attach_id for query: " . $item['query'], 'success' );

            // Handle Featured Image
            if ( $item['type'] === 'featured' ) {
                set_post_thumbnail( $post_id, $attach_id );
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

                $h2_text = $item['query'];
                // Since we might have replaced the comment, the query in $item might be different from H2 text if AI provided it.
                // Re-find the H2.

                // Find all H2s again
                 preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/is', $current_content, $h2_matches, PREG_OFFSET_CAPTURE );

                 if ( isset( $h2_matches[0][$inserted_count] ) ) {
                     // Insert after this H2
                     $match_str = $h2_matches[0][$inserted_count][0];

                     // Use specific replacement for Nth occurrence
                     $current_content = $this->preg_replace_nth( '/<h2[^>]*>.*?<\/h2>/is', $match_str . "\n" . $img_html, $current_content, $inserted_count + 1 );

                 } else {
                    // Fallback
                    $p_index = ( $inserted_count + 1 ) * 2;
                    $current_content = $this->insert_after_paragraph( $img_html, $p_index, $current_content );
                 }

                $inserted_count++;
            }
        }

        // Final Cleanup: Remove any remaining IMAGE_QUERY comments from $current_content
        $current_content = preg_replace( '/<!-- IMAGE_QUERY:\s*(.*?)-->/i', '', $current_content );

        // Save updated content
        $update_args = array(
            'ID' => $post_id,
            'post_content' => $current_content
        );
        wp_update_post( $update_args );

        AAB_Logger::log( $post_id, "Image processing complete. Inserted $inserted_count body images.", 'success' );
    }

    private function insert_after_paragraph( $insertion, $paragraph_id, $content ) {
        $closing_p = '</p>';
        $paragraphs = explode( $closing_p, $content );
        $new_content = '';

        foreach ( $paragraphs as $index => $paragraph ) {
            if ( trim( $paragraph ) ) {
                $new_content .= $paragraph . $closing_p;
            }
            if ( $paragraph_id == $index + 1 ) {
                $new_content .= "\n" . $insertion . "\n";
            }
        }
        return $new_content;
    }

    private function preg_replace_nth($pattern, $replacement, $subject, $nth=1) {
        return preg_replace_callback($pattern, function($found) use (&$pattern, &$replacement, &$nth) {
                $nth--;
                if ($nth==0) return $replacement;
                return reset($found);
        }, $subject, -1, $count);
    }
}
