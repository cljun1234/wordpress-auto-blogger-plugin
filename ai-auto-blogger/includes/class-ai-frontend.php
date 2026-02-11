<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AAB_Frontend {

    public function __construct() {
        add_action( 'wp_head', array( $this, 'output_schema_json' ) );
    }

    public function output_schema_json() {
        if ( ! is_single() ) {
            return;
        }

        $post_id = get_the_ID();
        $schema_json = get_post_meta( $post_id, '_aab_schema_json', true );

        if ( ! empty( $schema_json ) ) {
            // Check if it's already a valid JSON string or if it needs encoding
            // The AI usually returns a JSON string, but we should be safe.
            // If it starts with { or [, assume it's JSON.
            $schema_json = trim( $schema_json );
             // Remove markdown if present (```json ... ```)
            $schema_json = preg_replace( '/^```json/', '', $schema_json );
            $schema_json = preg_replace( '/```$/', '', $schema_json );
            $schema_json = trim( $schema_json );

            echo '<script type="application/ld+json">' . $schema_json . '</script>';
        }
    }
}
