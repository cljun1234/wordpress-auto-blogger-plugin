<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AAB_Pixabay_Client implements AAB_Image_Client {
    private $api_key;

    public function __construct( $api_key ) {
        $this->api_key = $api_key;
    }

    public function search_images( $query, $count ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'missing_key', 'Pixabay API Key is missing.' );
        }

        $url = 'https://pixabay.com/api/';
        $args = array(
            'key' => $this->api_key,
            'q' => urlencode( $query ),
            'image_type' => 'photo',
            'per_page' => $count,
            'orientation' => 'horizontal'
        );

        $response = wp_remote_get( $url . '?' . http_build_query( $args ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( isset( $data['hits'] ) ) {
            $images = array();
            // Check if count > hits available
            $limit = min( count($data['hits']), $count );

            for ( $i = 0; $i < $limit; $i++ ) {
                $item = $data['hits'][$i];
                $images[] = array(
                    'url' => $item['largeImageURL'],
                    'photographer' => $item['user'],
                    'photographer_url' => 'https://pixabay.com/users/' . $item['user'] . '-' . $item['user_id'] . '/',
                    'site_name' => 'Pixabay'
                );
            }
            return $images;
        }

        return array();
    }
}
