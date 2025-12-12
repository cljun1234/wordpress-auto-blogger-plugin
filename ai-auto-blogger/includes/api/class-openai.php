<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AAB_OpenAI_Client implements AAB_API_Client {
    private $api_key;
    private $api_url = 'https://api.openai.com/v1/chat/completions';

    public function __construct() {
        $options = get_option( 'aab_settings' );
        $this->api_key = isset( $options['openai_api_key'] ) ? $options['openai_api_key'] : '';
    }

    public function generate_content( $system_prompt, $user_prompt, $model = 'gpt-4o' ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'missing_key', 'OpenAI API Key is missing.' );
        }

        $body = array(
            'model' => $model,
            'messages' => array(
                array(
                    'role' => 'system',
                    'content' => $system_prompt
                ),
                array(
                    'role' => 'user',
                    'content' => $user_prompt
                )
            ),
            'temperature' => 0.7,
        );

        $response = wp_remote_post( $this->api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
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

        if ( isset( $data['choices'][0]['message']['content'] ) ) {
            return $data['choices'][0]['message']['content'];
        }

        return new WP_Error( 'invalid_response', 'Invalid response from OpenAI' );
    }
}
