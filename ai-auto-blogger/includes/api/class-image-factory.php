<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'interface-image-client.php';
require_once plugin_dir_path( __FILE__ ) . 'class-unsplash.php';
require_once plugin_dir_path( __FILE__ ) . 'class-pexels.php';
require_once plugin_dir_path( __FILE__ ) . 'class-pixabay.php';

class AAB_Image_Factory {
    public static function get_client( $provider ) {
        $options = get_option( 'aab_settings' );

        switch ( $provider ) {
            case 'unsplash':
                $key = isset( $options['unsplash_access_key'] ) ? $options['unsplash_access_key'] : '';
                return new AAB_Unsplash_Client( $key );
            case 'pexels':
                $key = isset( $options['pexels_api_key'] ) ? $options['pexels_api_key'] : '';
                return new AAB_Pexels_Client( $key );
            case 'pixabay':
                $key = isset( $options['pixabay_api_key'] ) ? $options['pixabay_api_key'] : '';
                return new AAB_Pixabay_Client( $key );
            default:
                return new WP_Error( 'invalid_provider', 'Invalid Image Provider' );
        }
    }
}
