<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface AAB_API_Client {
    public function generate_content( $system_prompt, $user_prompt, $model );
}

class AAB_API_Factory {
    public static function get_client( $provider ) {
        switch ( $provider ) {
            case 'openai':
                return new AAB_OpenAI_Client();
            case 'gemini':
                return new AAB_Gemini_Client();
            case 'deepseek':
                return new AAB_DeepSeek_Client();
            default:
                return new WP_Error( 'invalid_provider', 'Invalid AI Provider' );
        }
    }
}
