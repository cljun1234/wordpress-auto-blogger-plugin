<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AAB_Gemini_Client implements AAB_API_Client {
    private $api_key;
    private $api_url_base = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct() {
        $options = get_option( 'aab_settings' );
        $this->api_key = isset( $options['gemini_api_key'] ) ? $options['gemini_api_key'] : '';
    }

    public function generate_content( $system_prompt, $user_prompt, $model = 'gemini-1.5-pro' ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'missing_key', 'Gemini API Key is missing.' );
        }

        // Gemini API structure is slightly different
        $url = $this->api_url_base . $model . ':generateContent?key=' . $this->api_key;

        $body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array( 'text' => $system_prompt . "\n\nTask: " . $user_prompt )
                    )
                )
            )
        );

        $response = wp_remote_post( $url, array(
            'headers' => array(
                'Content-Type'  => 'application/json',
            ),
            'body'    => json_encode( $body ),
            'timeout' => 120,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( isset( $data['error'] ) ) {
            return new WP_Error( 'api_error', $data['error']['message'] );
        }

        if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
            return $data['candidates'][0]['content']['parts'][0]['text'];
        }

        return new WP_Error( 'invalid_response', 'Invalid response from Gemini' );
    }
}
