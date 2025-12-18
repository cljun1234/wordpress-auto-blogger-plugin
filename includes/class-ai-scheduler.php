<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AAB_Scheduler {

    public function __construct() {
        add_action( 'init', array( $this, 'register_cpt' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_meta_boxes' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // Priority 11 should place it after Templates (default 10) but before Settings (which I will move to 20 or check)
        // Actually, user wants Scheduler ABOVE Settings.
        // Generator UI is top level.
        // Let's register submenu with default priority but ensure Settings is registered *later* or with higher priority number in add_action.
        // AAB_Settings adds its submenu on 'admin_menu' action with default priority 10.
        // If I use priority 9 for Scheduler, it should come before Settings (10).
        add_action( 'admin_menu', array( $this, 'add_submenu' ), 9 );

        // AJAX for Generating Topics
        add_action( 'wp_ajax_aab_generate_topics', array( $this, 'ajax_generate_topics' ) );
    }

    public function register_cpt() {
        $labels = array(
            'name'                  => _x( 'Schedules', 'Post Type General Name', 'ai-auto-blogger' ),
            'singular_name'         => _x( 'Schedule', 'Post Type Singular Name', 'ai-auto-blogger' ),
            'menu_name'             => __( 'Schedules', 'ai-auto-blogger' ),
            'all_items'             => __( 'All Schedules', 'ai-auto-blogger' ),
            'add_new_item'          => __( 'Add New Schedule', 'ai-auto-blogger' ),
            'add_new'               => __( 'Add New', 'ai-auto-blogger' ),
            'new_item'              => __( 'New Schedule', 'ai-auto-blogger' ),
            'edit_item'             => __( 'Edit Schedule', 'ai-auto-blogger' ),
            'update_item'           => __( 'Update Schedule', 'ai-auto-blogger' ),
            'view_item'             => __( 'View Schedule', 'ai-auto-blogger' ),
            'search_items'          => __( 'Search Schedule', 'ai-auto-blogger' ),
        );
        $args = array(
            'label'                 => __( 'Schedule', 'ai-auto-blogger' ),
            'labels'                => $labels,
            'supports'              => array( 'title' ),
            'hierarchical'          => false,
            'public'                => false,
            'show_ui'               => true,
            'show_in_menu'          => false, // Hidden main menu, attached as submenu
            'menu_position'         => 20,
            'show_in_admin_bar'     => false,
            'show_in_nav_menus'     => false,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'capability_type'       => 'post',
        );
        register_post_type( 'ai_schedule', $args );
    }

    public function add_submenu() {
        add_submenu_page(
            'ai-auto-blogger',
            'Scheduler',
            'Scheduler',
            'manage_options',
            'edit.php?post_type=ai_schedule'
        );

        add_submenu_page(
            'ai-auto-blogger',
            'Show Tasks', // Page Title
            'Show Tasks', // Menu Title
            'manage_options',
            'aab-show-tasks', // Slug
            array( $this, 'render_tasks_page' ) // Callback
        );
    }

    public function render_tasks_page() {
        if ( class_exists( 'AAB_Tasks_List' ) ) {
            AAB_Tasks_List::render();
        } else {
            echo '<div class="wrap"><p>Error: Tasks List class not found.</p></div>';
        }
    }

    public function enqueue_scripts( $hook ) {
        global $post;
        if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
            return;
        }
        if ( 'ai_schedule' !== $post->post_type ) {
            return;
        }

        wp_enqueue_script( 'aab-scheduler-js', AAB_URL . 'admin/js/scheduler.js', array( 'jquery' ), AAB_VERSION, true );
        wp_localize_script( 'aab-scheduler-js', 'aab_vars', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'aab_scheduler_nonce' ),
        ) );

        wp_enqueue_style( 'aab-admin-css', AAB_URL . 'admin/css/admin.css', array(), AAB_VERSION );
    }

    public function add_meta_boxes() {
        add_meta_box( 'aab_schedule_config', 'Schedule Configuration', array( $this, 'render_config_meta_box' ), 'ai_schedule', 'normal', 'high' );
        add_meta_box( 'aab_schedule_queue', 'Topic Queue', array( $this, 'render_queue_meta_box' ), 'ai_schedule', 'normal', 'high' );
    }

    public function render_config_meta_box( $post ) {
        wp_nonce_field( 'aab_save_schedule_data', 'aab_schedule_nonce' );

        $data = get_post_meta( $post->ID, '_aab_schedule_config', true );
        $templates = get_posts( array( 'post_type' => 'ai_template', 'numberposts' => -1 ) );

        $defaults = array(
            'template_id' => '',
            'broad_topic' => '',
            'frequency' => 'daily', // daily, hourly, twice_daily
            'time' => '09:00',
            'mode' => 'manual', // manual, infinite
            'status' => 'active', // active, paused
            'output_status' => 'draft', // draft, publish
            'email_notify' => 'no',
            'provider' => 'openai', // Default provider
            'model' => 'gpt-4o',   // Default model
        );
        $data = wp_parse_args( $data, $defaults );
        ?>
        <div class="aab-meta-box">
             <style>
                .aab-row { margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 15px; }
                .aab-row label { display: block; font-weight: bold; margin-bottom: 5px; }
                .aab-row input[type="text"], .aab-row select { width: 100%; max-width: 400px; }
            </style>

            <div class="aab-row">
                <label>Broad Topic / Niche</label>
                <input type="text" name="aab_sched[broad_topic]" value="<?php echo esc_attr( $data['broad_topic'] ); ?>" placeholder="e.g. Artificial Intelligence News" />
                <p class="description">Used for context when generating new topics.</p>
            </div>

            <div class="aab-row">
                <label>Select Template</label>
                <select name="aab_sched[template_id]">
                    <option value="">-- Choose Template --</option>
                    <?php foreach ( $templates as $t ) : ?>
                        <option value="<?php echo $t->ID; ?>" <?php selected( $data['template_id'], $t->ID ); ?>><?php echo esc_html( $t->post_title ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

             <div class="aab-row">
                <label>AI Provider & Model</label>
                <select name="aab_sched[provider]" id="aab_sched_provider">
                    <option value="openai" <?php selected( $data['provider'], 'openai' ); ?>>OpenAI</option>
                    <option value="gemini" <?php selected( $data['provider'], 'gemini' ); ?>>Google Gemini</option>
                    <option value="deepseek" <?php selected( $data['provider'], 'deepseek' ); ?>>DeepSeek</option>
                </select>
                <select name="aab_sched[model]" id="aab_sched_model">
                    <!-- Populated by JS, but set initial value via value attr for JS to pick up -->
                    <option value="<?php echo esc_attr( $data['model'] ); ?>" selected><?php echo esc_html( $data['model'] ); ?></option>
                </select>
            </div>

            <div class="aab-row">
                <label>Schedule Status</label>
                <select name="aab_sched[status]">
                    <option value="active" <?php selected( $data['status'], 'active' ); ?>>Active</option>
                    <option value="paused" <?php selected( $data['status'], 'paused' ); ?>>Paused</option>
                    <option value="finished" <?php selected( $data['status'], 'finished' ); ?>>Finished</option>
                </select>
            </div>

            <div class="aab-row">
                <label>Frequency</label>
                <select name="aab_sched[frequency]">
                    <option value="hourly" <?php selected( $data['frequency'], 'hourly' ); ?>>Hourly</option>
                    <option value="twice_daily" <?php selected( $data['frequency'], 'twice_daily' ); ?>>Twice Daily</option>
                    <option value="daily" <?php selected( $data['frequency'], 'daily' ); ?>>Daily</option>
                    <option value="weekly" <?php selected( $data['frequency'], 'weekly' ); ?>>Weekly</option>
                </select>
            </div>

            <div class="aab-row">
                <label>Execution Time (Site Timezone)</label>
                <input type="time" name="aab_sched[time]" value="<?php echo esc_attr( $data['time'] ); ?>" />
            </div>

            <div class="aab-row">
                <label>Execution Mode</label>
                <select name="aab_sched[mode]">
                    <option value="manual" <?php selected( $data['mode'], 'manual' ); ?>>Queue Mode (Stop when empty)</option>
                    <option value="infinite" <?php selected( $data['mode'], 'infinite' ); ?>>Infinite Mode (Auto-generate if empty)</option>
                </select>
                <p class="description">Queue Mode: Only processes topics listed below. Infinite Mode: Automatically invents a new topic if the queue is empty.</p>
            </div>

            <div class="aab-row">
                <label>Post Status</label>
                <select name="aab_sched[output_status]">
                    <option value="draft" <?php selected( $data['output_status'], 'draft' ); ?>>Draft</option>
                    <option value="publish" <?php selected( $data['output_status'], 'publish' ); ?>>Publish Immediately</option>
                </select>
            </div>

            <div class="aab-row">
                <label>
                    <input type="checkbox" name="aab_sched[email_notify]" value="yes" <?php checked( $data['email_notify'], 'yes' ); ?> />
                    Send Email Notification upon completion
                </label>
            </div>

            <div class="aab-row">
                 <p><strong>Next Run:</strong>
                 <?php
                    $next_run = get_post_meta( $post->ID, '_aab_next_run', true );
                    echo $next_run ? date_i18n( 'Y-m-d H:i:s', $next_run ) : 'Pending calculation';
                 ?>
                 </p>
            </div>
        </div>
        <?php
    }

    public function render_queue_meta_box( $post ) {
        $queue = get_post_meta( $post->ID, '_aab_queue', true );
        if ( ! is_array( $queue ) ) $queue = array();
        ?>
        <div id="aab-queue-container">
            <p>
                <label>Number of Ideas to Generate: <input type="number" id="aab_gen_count" value="10" style="width:60px;"></label>
                <label style="margin-left:15px;">Check Duplicates from Last (Days): <input type="number" id="aab_gen_days" value="30" style="width:60px;"></label>
            </p>
            <p>
                <button type="button" id="aab-generate-topics-btn" class="button button-secondary">Generate Topics Idea</button>
                <span class="spinner" style="float:none;"></span>
                <span id="aab-topic-msg" style="margin-left:10px;"></span>
            </p>
            <p class="description">Broad Topic must be saved before generating ideas.</p>

            <ul id="aab-queue-list" style="margin-top: 15px; background: #fff; border: 1px solid #ddd;">
                <?php if ( empty( $queue ) ) : ?>
                    <li class="no-items" style="padding: 10px;">Queue is empty. Add topics manually or generate them.</li>
                <?php else : ?>
                    <?php foreach ( $queue as $index => $item ) : ?>
                        <li style="padding: 10px; border-bottom: 1px solid #eee; display: flex; align-items: center;">
                            <span class="dashicons dashicons-sort" style="color:#ccc; margin-right:10px; cursor:move;"></span>
                            <input type="text" name="aab_queue[]" value="<?php echo esc_attr( $item ); ?>" style="flex-grow:1; margin-right: 10px;" />
                            <button type="button" class="button-link aab-remove-topic" style="color: #a00;">Remove</button>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
             <p><button type="button" id="aab-add-manual-topic" class="button">Add Topic Manually</button></p>
        </div>
        <?php
    }

    public function save_meta_boxes( $post_id ) {
        if ( ! isset( $_POST['aab_schedule_nonce'] ) || ! wp_verify_nonce( $_POST['aab_schedule_nonce'], 'aab_save_schedule_data' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( isset( $_POST['aab_sched'] ) ) {
            $data = array_map( 'sanitize_text_field', $_POST['aab_sched'] );
            if ( ! isset( $data['email_notify'] ) ) $data['email_notify'] = 'no';
            update_post_meta( $post_id, '_aab_schedule_config', $data );

            // Calculate Next Run if not set or status changed to active
            // OR if the user manually saved settings, we should re-calculate to ensure new time/frequency applies immediately.
            // We always recalculate if status is active to pick up time changes.
            if ( $data['status'] === 'active' ) {
                $this->schedule_next_run( $post_id, $data );
            }
        }

        if ( isset( $_POST['aab_queue'] ) ) {
            $queue = array_map( 'sanitize_text_field', $_POST['aab_queue'] );
            // Filter empty
            $queue = array_filter( $queue );
            update_post_meta( $post_id, '_aab_queue', array_values( $queue ) );
        } else {
             update_post_meta( $post_id, '_aab_queue', array() );
        }
    }

    public function schedule_next_run( $post_id, $data ) {
        // Use WP Timezone object
        $tz = wp_timezone();
        $now = new DateTime( 'now', $tz );

        // Parse the target "Execution Time" (e.g. 09:00) in the Site's Timezone
        $target_time = DateTime::createFromFormat( 'H:i', $data['time'], $tz );

        // Ensure the date part is today
        $target_time->setDate( $now->format('Y'), $now->format('m'), $now->format('d') );

        $next_timestamp = 0;

        if ( $data['frequency'] === 'hourly' ) {
             // For hourly, just add 1 hour from now.
             // Aligning to the start of the next hour is cleaner.
             $now->modify( '+1 hour' );
             $now->setTime( $now->format('H'), 0, 0 );
             $next_timestamp = $now->getTimestamp();
        }
        elseif ( $data['frequency'] === 'twice_daily' ) {
            // Target time, then Target time + 12h
            // Find the next occurrence
            $option1 = clone $target_time;
            $option2 = clone $target_time;
            $option2->modify( '+12 hours' );

            // If option 1 is in future, use it. Else check option 2.
            if ( $option1 > $now ) {
                $next_timestamp = $option1->getTimestamp();
            } elseif ( $option2 > $now ) {
                $next_timestamp = $option2->getTimestamp();
            } else {
                // Both passed today, so use option 1 tomorrow
                $option1->modify( '+1 day' );
                $next_timestamp = $option1->getTimestamp();
            }
        }
        elseif ( $data['frequency'] === 'weekly' ) {
            // If passed today, move to next week
            if ( $target_time <= $now ) {
                 $target_time->modify( '+1 week' );
            }
            $next_timestamp = $target_time->getTimestamp();
        }
        else {
             // Daily (Default)
             if ( $target_time <= $now ) {
                 $target_time->modify( '+1 day' );
             }
             $next_timestamp = $target_time->getTimestamp();
        }

        update_post_meta( $post_id, '_aab_next_run', $next_timestamp );
    }

    public function ajax_generate_topics() {
        check_ajax_referer( 'aab_scheduler_nonce', 'security' );

        $broad_topic = sanitize_text_field( $_POST['broad_topic'] );
        $provider = sanitize_text_field( $_POST['provider'] );
        $model = sanitize_text_field( $_POST['model'] );
        $count = isset( $_POST['count'] ) ? intval( $_POST['count'] ) : 10;
        $days = isset( $_POST['days'] ) ? intval( $_POST['days'] ) : 30;

        // Limit defaults if crazy values
        if ( $count < 1 ) $count = 10;
        if ( $count > 50 ) $count = 50;
        if ( $days < 1 ) $days = 30;

        if ( empty( $broad_topic ) ) {
            wp_send_json_error( 'Please save a Broad Topic first.' );
        }

        // 1. Get History (Filtered by date)
        $args = array(
            'numberposts' => -1, // Get all from that period to ensure no dupes
            'post_status' => 'publish',
            'fields' => 'ids',
            'date_query' => array(
                array(
                    'after' => $days . ' days ago',
                ),
            ),
        );

        $recent_posts = get_posts( $args );

        $titles = array();
        // Limit history size for prompt context window optimization
        // If we have 500 posts in last 30 days, we might blow up the prompt.
        // Let's take the most recent 50-100.
        $recent_posts = array_slice( $recent_posts, 0, 100 );

        foreach ( $recent_posts as $pid ) {
            $titles[] = get_the_title( $pid );
        }
        $history_list = implode( "\n- ", $titles );

        // 2. Build Prompt
        $prompt = "I need $count blog post topic ideas for the niche: '$broad_topic'.\n";
        $prompt .= "Here are the topics I have ALREADY covered in the last $days days (DO NOT REPEAT THESE):\n- $history_list\n\n";
        $prompt .= "Generate $count NEW, unique, click-worthy titles. Return ONLY the titles as a simple list (one per line). Do not number them.";

        // 3. Call API
         try {
            $client = AAB_API_Factory::get_client( $provider );
            if ( is_wp_error( $client ) ) {
                wp_send_json_error( $client->get_error_message() );
            }

            // System prompt can be simple
            $system = "You are a helpful content strategist.";
            $content = $client->generate_content( $system, $prompt, $model );

            if ( is_wp_error( $content ) ) {
                 wp_send_json_error( $content->get_error_message() );
            }

            // Parse lines
            $lines = explode( "\n", trim( $content ) );
            $clean_lines = array();
            foreach ( $lines as $line ) {
                $l = trim( $line );
                $l = ltrim( $l, '- ' );
                $l = ltrim( $l, '0..9. ' );
                if ( ! empty( $l ) ) {
                    $clean_lines[] = $l;
                }
            }

            // Slice to exact count requested if AI over-generated
            $clean_lines = array_slice( $clean_lines, 0, $count );

            wp_send_json_success( $clean_lines );

        } catch ( Exception $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    /**
     * Main Scheduler Logic called by Cron.
     */
    public function run_pending_jobs() {
        // Query active schedules
        $args = array(
            'post_type' => 'ai_schedule',
            'meta_query' => array(
                array(
                    'key' => '_aab_schedule_config',
                    'value' => 'active',
                    'compare' => 'LIKE'
                )
            ),
            'numberposts' => -1,
            'post_status' => 'any'
        );

        $schedules = get_posts( $args );

        // FIX: Compare UTC timestamp (from DB) with UTC time() (from Server)
        // get_post_meta returns timestamp which is UTC based on how we generated it with $dateTime->getTimestamp()
        $now_utc = time();

        foreach ( $schedules as $sched ) {
            // Verify status strictly
            $config = get_post_meta( $sched->ID, '_aab_schedule_config', true );
            if ( ! isset( $config['status'] ) || $config['status'] !== 'active' ) {
                continue;
            }

            $next_run = get_post_meta( $sched->ID, '_aab_next_run', true );

            // Check if it's time to run
            if ( ! $next_run || $now_utc < $next_run ) {
                continue;
            }

            // IT IS TIME TO RUN
            $this->execute_job( $sched->ID, $config );

            // Update Next Run - FIX: Use consistent scheduling based on target time, not just 'now'
            $this->update_next_run_time( $sched->ID, $config, $next_run );
        }
    }

    private function execute_job( $sched_id, $config ) {
        $queue = get_post_meta( $sched_id, '_aab_queue', true );
        if ( ! is_array( $queue ) ) $queue = array();

        $topic = '';

        // 1. Determine Topic
        if ( ! empty( $queue ) ) {
            // Pop the top
            $topic = array_shift( $queue );
            // Update queue
            update_post_meta( $sched_id, '_aab_queue', $queue );
        } elseif ( $config['mode'] === 'infinite' ) {
            // Auto-generate 1 topic
            $topic = $this->auto_generate_single_topic( $config['broad_topic'], $config['provider'], $config['model'] );
        } else {
            // Queue empty and Manual mode -> Finish or Pause
            $config['status'] = 'finished'; // Or paused
            update_post_meta( $sched_id, '_aab_schedule_config', $config );
            // Log
            AAB_Logger::log( $sched_id, "Scheduler paused: Queue empty and mode is manual.", 'warning' );
            return;
        }

        if ( empty( $topic ) ) {
             AAB_Logger::log( $sched_id, "Scheduler failed: Could not determine topic.", 'error' );
             return;
        }

        // 2. Generate
        $engine = new AAB_Engine();
        $post_id = $engine->generate_post( $topic, $config['template_id'], $config['provider'], $config['model'] );

        if ( is_wp_error( $post_id ) ) {
             AAB_Logger::log( $sched_id, "Scheduler generation failed: " . $post_id->get_error_message(), 'error' );
             return;
        }

        // 3. Handle Status
        if ( isset( $config['output_status'] ) && $config['output_status'] === 'publish' ) {
            wp_publish_post( $post_id );
        }

        // 4. Notify
        if ( isset( $config['email_notify'] ) && $config['email_notify'] === 'yes' ) {
            $admin_email = get_option( 'admin_email' );
            $subject = "AI Auto Blogger: New Post Generated";
            $message = "A new post has been generated by your schedule '" . get_the_title( $sched_id ) . "'.\n\n";
            $message .= "Title: $topic\n";
            $message .= "Edit Link: " . get_edit_post_link( $post_id, '' );

            wp_mail( $admin_email, $subject, $message );
        }

        AAB_Logger::log( $sched_id, "Scheduler successfully ran job for topic: $topic", 'success' );
    }

    private function update_next_run_time( $sched_id, $config, $last_run_timestamp ) {
        // Recalculate based on the original target time to avoid drift
        // We use the same logic as schedule_next_run but based on 'now' to find the NEXT slot
        // This ensures if we ran at 9:05 (scheduled for 9:00), the next one is tomorrow at 9:00, not 9:05.

        $this->schedule_next_run( $sched_id, $config );
    }

    private function auto_generate_single_topic( $broad_topic, $provider, $model ) {
        // Similar to ajax_generate_topics but returns just one string
         $recent_posts = get_posts( array( 'numberposts' => 20, 'post_status' => 'publish', 'fields' => 'ids' ) );
        $titles = array();
        foreach ( $recent_posts as $pid ) {
            $titles[] = get_the_title( $pid );
        }
        $history_list = implode( "\n- ", $titles );

        $prompt = "I need exactly ONE blog post topic idea for the niche: '$broad_topic'.\n";
        $prompt .= "Do not repeat these:\n- $history_list\n\n";
        $prompt .= "Return ONLY the title string. No quotes, no numbering.";

        try {
            $client = AAB_API_Factory::get_client( $provider );
            if ( is_wp_error( $client ) ) return '';

            $content = $client->generate_content( "You are a helpful assistant.", $prompt, $model );
            if ( is_wp_error( $content ) ) return '';

            return trim( str_replace( '"', '', $content ) );
        } catch ( Exception $e ) {
            return '';
        }
    }
}
