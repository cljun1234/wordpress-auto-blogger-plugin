<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AAB_Tasks_List {

    /**
     * Renders the Tasks List page.
     */
    public static function render() {
        // Fetch all tasks
        $tasks = self::get_all_upcoming_tasks();

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Upcoming Tasks</h1>
            <p class="description">A projection of upcoming blog posts based on active schedules.</p>

            <table class="wp-list-table widefat fixed striped table-view-list">
                <thead>
                    <tr>
                        <th scope="col" class="manage-column column-primary">Blog Title / Topic</th>
                        <th scope="col" class="manage-column">Schedule Name</th>
                        <th scope="col" class="manage-column">Execution Time</th>
                        <th scope="col" class="manage-column">Timezone</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $tasks ) ) : ?>
                        <tr>
                            <td colspan="4">No active schedules or upcoming tasks found.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $tasks as $task ) : ?>
                            <tr>
                                <td class="title column-title has-row-actions column-primary" data-colname="Blog Title">
                                    <strong><?php echo esc_html( $task['title'] ); ?></strong>
                                    <?php if ( $task['is_projected'] ) : ?>
                                        <span style="color: #666; font-style: italic;">(Projected)</span>
                                    <?php endif; ?>
                                </td>
                                <td data-colname="Schedule Name">
                                    <a href="<?php echo get_edit_post_link( $task['schedule_id'] ); ?>">
                                        <?php echo esc_html( $task['schedule_name'] ); ?>
                                    </a>
                                </td>
                                <td data-colname="Execution Time">
                                    <?php echo date_i18n( 'Y-m-d H:i:s', $task['timestamp'] ); ?>
                                </td>
                                <td data-colname="Timezone">
                                    <?php echo esc_html( $task['timezone'] ); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Aggregates tasks from all active schedules.
     */
    private static function get_all_upcoming_tasks() {
        $tasks = array();
        $tz = wp_timezone();
        $tz_string = $tz->getName();
        if ( empty( $tz_string ) ) {
            // Fallback for offsets (e.g., +05:30)
             $tz_string = 'UTC ' . $tz->getOffset( new DateTime() ) / 3600;
        }

        // Get all active schedules
        $args = array(
            'post_type' => 'ai_schedule',
            'numberposts' => -1,
            'post_status' => 'any', // Schedules are 'private' or 'publish'? Code says 'public' => false.
            'meta_query' => array(
                array(
                    'key' => '_aab_schedule_config',
                    'value' => 'active',
                    'compare' => 'LIKE'
                )
            )
        );

        $schedules = get_posts( $args );

        foreach ( $schedules as $sched ) {
            $config = get_post_meta( $sched->ID, '_aab_schedule_config', true );

            // Double check active status just in case LIKE matched something else
            if ( ! isset( $config['status'] ) || $config['status'] !== 'active' ) {
                continue;
            }

            $next_run = get_post_meta( $sched->ID, '_aab_next_run', true );
            if ( ! $next_run ) continue; // Shouldn't happen if active, but safety first

            $frequency = isset( $config['frequency'] ) ? $config['frequency'] : 'daily';
            $mode = isset( $config['mode'] ) ? $config['mode'] : 'manual';
            $queue = get_post_meta( $sched->ID, '_aab_queue', true );
            if ( ! is_array( $queue ) ) $queue = array();

            // Calculate projection
            $current_run_time = $next_run;

            if ( $mode === 'manual' ) {
                // Iterate through queue
                foreach ( $queue as $topic ) {
                    $tasks[] = array(
                        'title' => $topic,
                        'schedule_name' => $sched->post_title,
                        'schedule_id' => $sched->ID,
                        'timestamp' => $current_run_time,
                        'timezone' => $tz_string,
                        'is_projected' => false
                    );

                    // Calculate next time for the *next* item
                    $current_run_time = self::calculate_next_time( $current_run_time, $frequency );
                }
            } elseif ( $mode === 'infinite' ) {
                // If queue exists, drain it first
                foreach ( $queue as $topic ) {
                    $tasks[] = array(
                        'title' => $topic,
                        'schedule_name' => $sched->post_title,
                        'schedule_id' => $sched->ID,
                        'timestamp' => $current_run_time,
                        'timezone' => $tz_string,
                        'is_projected' => false
                    );
                    $current_run_time = self::calculate_next_time( $current_run_time, $frequency );
                }

                // Then add placeholders
                // User requested "upcoming 5 blog task"
                $remaining_slots = 5 - count( $queue );
                if ( $remaining_slots < 0 ) $remaining_slots = 0; // If queue has > 5, we already showed them all (or should we limit queue display too? User said "if 10 blog going to run, show 10")

                // Logic: Show ALL queue items. Then show up to 5 *total* or just 5 *additional*?
                // User: "if its infinite, show probably upcoming 5 blog task"
                // Let's ensure we show at least 5 tasks total if queue is small.
                // If Queue has 2 items, we show 2 real + 3 projected.
                // If Queue has 10 items, we show 10 real.

                $projected_count = 0;
                $target_count = ( count( $tasks ) < 5 ) ? ( 5 - count( $tasks ) ) : 5; // Actually, if we have 10 real, do we show 5 projected *on top*?
                // "if its infinite, show probably upcoming 5 blog task"
                // I'll stick to: Always show 5 projected future runs after the queue is exhausted.

                for ( $i = 0; $i < 5; $i++ ) {
                     $tasks[] = array(
                        'title' => 'Auto-generated Topic',
                        'schedule_name' => $sched->post_title,
                        'schedule_id' => $sched->ID,
                        'timestamp' => $current_run_time,
                        'timezone' => $tz_string,
                        'is_projected' => true
                    );
                    $current_run_time = self::calculate_next_time( $current_run_time, $frequency );
                }
            }
        }

        // Sort by timestamp
        usort( $tasks, function( $a, $b ) {
            return $a['timestamp'] - $b['timestamp'];
        });

        return $tasks;
    }

    /**
     * Helper to add interval to timestamp
     */
    private static function calculate_next_time( $timestamp, $frequency ) {
        // We can use DateTime to be safe with DST, though simple addition works for fixed intervals mostly.
        // Best to use DateTime with the site timezone?
        // Actually, $timestamp is UTC (from time()).
        // But AAB_Scheduler logic is a bit mixed. It calculates $next_timestamp based on site timezone logic but stores it as a unix timestamp.

        $dt = new DateTime();
        $dt->setTimestamp( $timestamp );

        // Since we don't have the original timezone context of the timestamp here (it's just unix),
        // adding intervals is safe enough on UTC for "daily" (24h) etc.
        // EXCEPT for DST changes if we want to be super precise.
        // However, the Scheduler uses `modify('+1 day')` on a DateTime object.

        switch ( $frequency ) {
            case 'hourly':
                $dt->modify( '+1 hour' );
                break;
            case 'twice_daily':
                $dt->modify( '+12 hours' );
                break;
            case 'weekly':
                $dt->modify( '+1 week' );
                break;
            case 'daily':
            default:
                $dt->modify( '+1 day' );
                break;
        }

        return $dt->getTimestamp();
    }
}
