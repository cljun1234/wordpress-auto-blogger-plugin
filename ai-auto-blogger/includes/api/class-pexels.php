<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AAB_Pexels_Client implements AAB_Image_Client {
    private $api_key;

    public function __construct( $api_key ) {
        $this->api_key = $api_key;
    }

    public function search_images( $query, $count ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'missing_key', 'Pexels API Key is missing.' );
        }

        $url = 'https://api.pexels.com/v1/search';
        $args = array(
            'headers' => array(
                'Authorization' => $this->api_key
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

        if ( isset( $data['error'] ) ) {
            return new WP_Error( 'api_error', $data['error'] );
        }

        if ( empty( $data['photos'] ) ) {
            return array();
        }

        $images = array();
        foreach ( $data['photos'] as $item ) {
            $images[] = array(
                'url' => $item['src']['large2x'], // High res
                'photographer' => $item['photographer'],
                'photographer_url' => $item['photographer_url'],
                'site_name' => 'Pexels'
            );
        }

        return $images;
    }
}
