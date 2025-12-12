<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface AAB_Image_Client {
    /**
     * Search for images.
     *
     * @param string $query The search query.
     * @param int    $count Number of images to fetch.
     * @return array|WP_Error Array of images with 'url', 'photographer', 'photographer_url', 'site_name', or WP_Error.
     */
    public function search_images( $query, $count );
}
