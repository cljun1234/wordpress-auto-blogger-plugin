<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AAB_DeepSeek_Client implements AAB_API_Client {
    private $api_key;
    private $api_url = 'https://api.deepseek.com/chat/completions'; // Standard DeepSeek Endpoint

    public function __construct() {
        $options = get_option( 'aab_settings' );
        $this->api_key = isset( $options['deepseek_api_key'] ) ? $options['deepseek_api_key'] : '';
    }

    public function generate_content( $system_prompt, $user_prompt, $model = 'deepseek-chat' ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'missing_key', 'DeepSeek API Key is missing.' );
        }

        // DeepSeek is OpenAI Compatible
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
            'stream' => false
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

        return new WP_Error( 'invalid_response', 'Invalid response from DeepSeek' );
    }
}
