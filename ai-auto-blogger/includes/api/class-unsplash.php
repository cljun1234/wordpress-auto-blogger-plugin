<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AAB_Unsplash_Client implements AAB_Image_Client {
    private $access_key;

    public function __construct( $access_key ) {
        $this->access_key = $access_key;
    }

    public function search_images( $query, $count ) {
        if ( empty( $this->access_key ) ) {
            return new WP_Error( 'missing_key', 'Unsplash Access Key is missing.' );
        }

        $url = 'https://api.unsplash.com/search/photos';
        $args = array(
            'headers' => array(
                'Authorization' => 'Client-ID ' . $this->access_key,
                'Accept-Version' => 'v1'
            ),
            'body' => array(
                'query' => $query,
                'per_page' => $count,
                'orientation' => 'landscape'
            )
        );

        $response = wp_remote_get( $url . '?' . http_build_query( $args['body'] ), array( 'headers' => $args['headers'] ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( isset( $data['errors'] ) ) {
            return new WP_Error( 'api_error', implode( ', ', $data['errors'] ) );
        }

        if ( empty( $data['results'] ) ) {
            return array();
        }

        $images = array();
        foreach ( $data['results'] as $item ) {
            $images[] = array(
                'url' => $item['urls']['regular'],
                'photographer' => $item['user']['name'],
                'photographer_url' => $item['user']['links']['html'],
                'site_name' => 'Unsplash',
                'download_location' => isset($item['links']['download_location']) ? $item['links']['download_location'] : ''
            );

            // Unsplash requires triggering the download endpoint for attribution tracking
            if ( isset($item['links']['download_location']) ) {
               $this->trigger_download($item['links']['download_location']);
            }
        }

        return $images;
    }

    private function trigger_download($url) {
        wp_remote_get($url, array(
             'headers' => array(
                'Authorization' => 'Client-ID ' . $this->access_key
            )
        ));
    }
}
