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

            // 3.5 Handle Image Extraction and Logic
            $image_queries = array();
            // Check if Image Queries are in the content
            if ( preg_match( '/\|\|IMAGE_QUERIES:\s*(.*?)\|\|/s', $generated_content, $matches ) ) {
                $queries_str = $matches[1];
                $image_queries = array_map( 'trim', explode( ',', $queries_str ) );
                // Remove the block from content
                $generated_content = str_replace( $matches[0], '', $generated_content );
            } else {
                // Fallback: use keyword
                $image_queries = array( $keyword );
            }

            // 4. Create Post
            $post_id = $this->create_wordpress_post( $keyword, $generated_content, $template_data );

            // 5. Handle Images Integration
            if ( ! is_wp_error( $post_id ) && isset( $template_data['image_provider'] ) && $template_data['image_provider'] !== 'none' ) {
                $this->process_images( $post_id, $template_data, $image_queries );
            }

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

        // Image Search Queries Instruction
        $prompt .= "\n\n- Also provide 3 short, relevant search queries for stock photos that would match this article content.";
        $prompt .= "\n- Format them at the very end of the response as: ||IMAGE_QUERIES: query1, query2, query3||";

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

    private function process_images( $post_id, $template_data, $queries ) {
        if ( empty( $queries ) ) {
            return;
        }

        require_once plugin_dir_path( __FILE__ ) . 'api/class-image-factory.php';

        $provider = $template_data['image_provider'];
        $count = isset( $template_data['image_count'] ) ? intval( $template_data['image_count'] ) : 3;

        $client = AAB_Image_Factory::get_client( $provider );
        if ( is_wp_error( $client ) ) {
            return; // Log error?
        }

        // Use the first query usually, or loop if we want variety?
        // Let's take the first query for simplicity and relevance to main topic
        $main_query = $queries[0];

        $images = $client->search_images( $main_query, $count );

        if ( is_wp_error( $images ) || empty( $images ) ) {
            // Fallback to keyword if specific queries failed
            // $images = $client->search_images( $template_data['keyword']... ); // We don't have keyword easily here unless passed
            return;
        }

        // Sideload Images
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );

        $attachment_ids = array();

        foreach ( $images as $img_data ) {
            // Sideload
            $desc = 'Photo by ' . $img_data['photographer'] . ' on ' . $img_data['site_name'];
            $id = media_sideload_image( $img_data['url'], $post_id, $desc, 'id' );

            if ( ! is_wp_error( $id ) ) {
                $attachment_ids[] = array(
                    'id' => $id,
                    'credit' => $template_data['image_credits'] === 'on' ? $desc : '',
                    'credit_url' => $img_data['photographer_url']
                );
            }
        }

        if ( empty( $attachment_ids ) ) {
            return;
        }

        // Set Featured Image
        if ( isset( $template_data['image_featured'] ) && $template_data['image_featured'] === 'on' ) {
            $feat_img = array_shift( $attachment_ids );
            set_post_thumbnail( $post_id, $feat_img['id'] );

            // If we only wanted 1 image (featured), we stop?
            // The user said "featured image PLUS distributed throughout".
            // So we use the rest for content.
        }

        // Insert remaining images into content
        if ( ! empty( $attachment_ids ) ) {
            $content = get_post_field( 'post_content', $post_id );

            // Split by H2 to distribute
            $chunks = preg_split( '/(<h2>.*?<\/h2>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
            $new_content = '';
            $img_idx = 0;

            foreach ( $chunks as $chunk ) {
                $new_content .= $chunk;

                // If this chunk was an H2 (or just text), and we have images left, insert one after a text block
                // Simple logic: Insert after every H2 block occurrence?
                // The split captures delimiters, so:
                // Chunk 0: Intro text
                // Chunk 1: H2
                // Chunk 2: Text under H2...

                // Let's assume we insert image AFTER the text block following an H2.
                // Or easier: Insert randomly or at fixed intervals.

                // Better regex strategy: Replace H2s with H2 + Image, but skipping the first few?
            }

            // Simplified insertion: Append to paragraphs?
            // Let's re-read content and inject after every Nth paragraph or header.

            // Strategy: Inject after the 2nd, 4th, 6th paragraphs? Or after H2s.
            // Let's use string replacement for simplicity and robustness.

            $h2_count = substr_count( strtolower($content), '<h2>' );
            $imgs_to_insert = count( $attachment_ids );

            if ( $h2_count > 0 && $imgs_to_insert > 0 ) {
                // Insert after H2s.
                $parts = explode( '<h2>', $content );
                $content_built = $parts[0]; // Intro

                for ( $i = 1; $i < count( $parts ); $i++ ) {
                    $content_built .= '<h2>' . $parts[$i];

                    // Add image after this section?
                    // Usually '<h2>...</h2><p>...</p>'
                    // We want to insert it somewhat deep.
                    // Let's just append it after the first paragraph following the H2?
                    // Too complex for regex.
                    // Let's just append it immediately after the H2 for now, or before the next H2.

                    if ( isset( $attachment_ids[ $i - 1 ] ) ) {
                        $img_info = $attachment_ids[ $i - 1 ];
                        $img_html = $this->generate_image_html( $img_info );

                        // Find the first closing </p> in this part and insert after it
                        $pos = strpos( $content_built, '</p>' ); // This searches from start, incorrect.

                        // We need to modify $parts[$i] BEFORE appending to content_built
                        // But $content_built is accumulating.

                        // Let's restart logic.
                        // We have an array of image HTMLs.
                        // We want to sprinkle them into $content.
                    }
                }
            }

            // Revised Insertion Logic:
            // Just insert one image after every 3rd paragraph.
            $doc = new DOMDocument();
            // Suppress warnings for HTML5 tags
            libxml_use_internal_errors(true);
            // Hack to load UTF-8 correctly
            $doc->loadHTML( mb_convert_encoding( $content, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
            libxml_clear_errors();

            $paragraphs = $doc->getElementsByTagName('p');
            $p_count = $paragraphs->length;
            $img_idx = 0;

            if ( $p_count > 2 ) {
                 // Insert after every 3 paragraphs approx
                $interval = ceil( $p_count / ($imgs_to_insert + 1) );

                foreach ( $paragraphs as $index => $p ) {
                    if ( $img_idx < $imgs_to_insert && ($index + 1) % $interval == 0 ) {
                        $img_info = $attachment_ids[$img_idx];
                        $img_node = $doc->createDocumentFragment();
                        $img_node->appendXML( $this->generate_image_html( $img_info ) );

                        // Insert after current p
                        if ( $p->nextSibling ) {
                            $p->parentNode->insertBefore( $img_node, $p->nextSibling );
                        } else {
                            $p->parentNode->appendChild( $img_node );
                        }
                        $img_idx++;
                    }
                }
                $content = $doc->saveHTML();
            } else {
                // Not enough paragraphs, just append to end?
                foreach ( $attachment_ids as $img_info ) {
                     $content .= $this->generate_image_html( $img_info );
                }
            }

            // Update Post
            wp_update_post( array(
                'ID' => $post_id,
                'post_content' => $content
            ) );
        }
    }

    private function generate_image_html( $img_info ) {
        $src = wp_get_attachment_url( $img_info['id'] );
        $html = '<figure class="wp-block-image">';
        $html .= '<img src="' . esc_url( $src ) . '" alt="" />';
        if ( ! empty( $img_info['credit'] ) ) {
            $html .= '<figcaption>' . esc_html( $img_info['credit'] ) . '</figcaption>';
        }
        $html .= '</figure>';
        return $html;
    }
}
