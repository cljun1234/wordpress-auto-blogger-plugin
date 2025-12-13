<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AAB_Logger {

    const LOG_META_KEY = '_aab_execution_log';

    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'add_log_meta_box' ) );
    }

    /**
     * Log a message to the post meta.
     *
     * @param int    $post_id
     * @param string $message
     * @param string $type    'info', 'success', 'error', 'warning'
     */
    public static function log( $post_id, $message, $type = 'info' ) {
        if ( ! $post_id ) {
            return;
        }

        $current_log = get_post_meta( $post_id, self::LOG_META_KEY, true );
        if ( ! is_array( $current_log ) ) {
            $current_log = array();
        }

        $entry = array(
            'time'    => current_time( 'mysql' ),
            'message' => $message,
            'type'    => $type,
        );

        $current_log[] = $entry;

        update_post_meta( $post_id, self::LOG_META_KEY, $current_log );
    }

    /**
     * Add Meta Box to display logs on Post Edit screen.
     */
    public function add_log_meta_box() {
        add_meta_box(
            'aab_execution_log',
            __( 'AI Auto Blogger Execution Log', 'ai-auto-blogger' ),
            array( $this, 'render_log_meta_box' ),
            'post',
            'normal',
            'low'
        );
    }

    /**
     * Render the log meta box.
     */
    public function render_log_meta_box( $post ) {
        $logs = get_post_meta( $post->ID, self::LOG_META_KEY, true );

        if ( empty( $logs ) || ! is_array( $logs ) ) {
            echo '<p>No logs found for this post.</p>';
            return;
        }

        echo '<div class="aab-log-viewer" style="max-height: 300px; overflow-y: auto; background: #f9f9f9; border: 1px solid #ddd; padding: 10px;">';
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr><th style="width: 150px;">Time</th><th>Type</th><th>Message</th></tr></thead>';
        echo '<tbody>';

        foreach ( array_reverse( $logs ) as $log ) {
            $style = '';
            if ( $log['type'] === 'error' ) $style = 'color: red;';
            if ( $log['type'] === 'success' ) $style = 'color: green;';
            if ( $log['type'] === 'warning' ) $style = 'color: orange;';

            echo '<tr>';
            echo '<td>' . esc_html( $log['time'] ) . '</td>';
            echo '<td style="' . $style . ' font-weight: bold;">' . esc_html( strtoupper( $log['type'] ) ) . '</td>';
            echo '<td>' . esc_html( $log['message'] ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }
}
