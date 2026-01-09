<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AAB_Image_Factory {

    /**
     * Fetch images from the selected provider.
     *
     * @param string $provider 'unsplash', 'pexels', 'pixabay'
     * @param string $query    Search query
     * @param int    $count    Number of images
     * @return array|WP_Error  Array of image data ['url', 'alt', 'photographer', 'photographer_url']
     */
    public static function get_images( $provider, $query, $count = 1 ) {
        $settings = get_option( 'aab_settings' );

        switch ( $provider ) {
            case 'unsplash':
                $api_key = isset( $settings['unsplash_access_key'] ) ? $settings['unsplash_access_key'] : '';
                return self::fetch_unsplash( $api_key, $query, $count );
            case 'pexels':
                $api_key = isset( $settings['pexels_api_key'] ) ? $settings['pexels_api_key'] : '';
                return self::fetch_pexels( $api_key, $query, $count );
            case 'pixabay':
                $api_key = isset( $settings['pixabay_api_key'] ) ? $settings['pixabay_api_key'] : '';
                return self::fetch_pixabay( $api_key, $query, $count );
            default:
                return new WP_Error( 'invalid_provider', 'Invalid image provider.' );
        }
    }

    private static function fetch_unsplash( $api_key, $query, $count ) {
        if ( empty( $api_key ) ) return new WP_Error( 'missing_key', 'Unsplash API Key missing.' );

        $url = "https://api.unsplash.com/search/photos?query=" . urlencode( $query ) . "&per_page=" . $count . "&orientation=landscape";

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Authorization' => 'Client-ID ' . $api_key
            )
        ) );

        if ( is_wp_error( $response ) ) return $response;

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( isset( $data['errors'] ) ) {
            return new WP_Error( 'api_error', implode( ', ', $data['errors'] ) );
        }

        $images = array();
        if ( ! empty( $data['results'] ) ) {
            foreach ( $data['results'] as $img ) {
                $images[] = array(
                    'url' => $img['urls']['regular'],
                    'alt' => isset( $img['alt_description'] ) ? $img['alt_description'] : $query,
                    'photographer' => $img['user']['name'],
                    'photographer_url' => $img['user']['links']['html'] . '?utm_source=ai_auto_blogger&utm_medium=referral'
                );
            }
        }

        return $images;
    }

    private static function fetch_pexels( $api_key, $query, $count ) {
        if ( empty( $api_key ) ) return new WP_Error( 'missing_key', 'Pexels API Key missing.' );

        $url = "https://api.pexels.com/v1/search?query=" . urlencode( $query ) . "&per_page=" . $count . "&orientation=landscape";

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Authorization' => $api_key
            )
        ) );

        if ( is_wp_error( $response ) ) return $response;

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( isset( $data['error'] ) ) {
            return new WP_Error( 'api_error', $data['error'] );
        }

        $images = array();
        if ( ! empty( $data['photos'] ) ) {
            foreach ( $data['photos'] as $img ) {
                $images[] = array(
                    'url' => $img['src']['large'],
                    'alt' => isset( $img['alt'] ) ? $img['alt'] : $query,
                    'photographer' => $img['photographer'],
                    'photographer_url' => $img['photographer_url']
                );
            }
        }

        return $images;
    }

    private static function fetch_pixabay( $api_key, $query, $count ) {
        if ( empty( $api_key ) ) return new WP_Error( 'missing_key', 'Pixabay API Key missing.' );

        $url = "https://pixabay.com/api/?key=" . $api_key . "&q=" . urlencode( $query ) . "&image_type=photo&per_page=" . $count . "&orientation=horizontal";

        $response = wp_remote_get( $url );

        if ( is_wp_error( $response ) ) return $response;

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        $images = array();
        if ( ! empty( $data['hits'] ) ) {
            foreach ( $data['hits'] as $img ) {
                $images[] = array(
                    'url' => $img['largeImageURL'], // Or webformatURL for smaller
                    'alt' => isset( $img['tags'] ) ? $img['tags'] : $query,
                    'photographer' => $img['user'],
                    'photographer_url' => "https://pixabay.com/users/" . $img['user'] . "-" . $img['user_id'] . "/"
                );
            }
        }

        return $images;
    }

    /**
     * Sideload an image from a URL and attach it to a post.
     *
     * @param string $url Post ID to attach to
     * @param int $post_id
     * @param string $desc Description/Alt text
     * @return int|WP_Error Attachment ID on success
     */
    public static function sideload_image( $url, $post_id, $desc = '' ) {
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );

        // Helper to download temp file
        $tmp = download_url( $url );

        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }

        // Clean filename by stripping query strings
        $clean_url = strtok( $url, '?' );
        $filename = basename( $clean_url );

        $file_array = array(
            'name' => $filename,
            'tmp_name' => $tmp
        );

        // Fix file extension if missing (API urls sometimes lack .jpg)
        // Simple check: if name doesn't have extension, assume jpg
        if ( ! preg_match( '/\.[a-z0-9]{3,4}$/i', $file_array['name'] ) ) {
            $file_array['name'] .= '.jpg';
        }

        $id = media_handle_sideload( $file_array, $post_id, $desc );

        if ( is_wp_error( $id ) ) {
            @unlink( $file_array['tmp_name'] );
            return $id;
        }

        // Set Alt Text
        if ( ! empty( $desc ) ) {
            update_post_meta( $id, '_wp_attachment_image_alt', $desc );
        }

        return $id;
    }
}
