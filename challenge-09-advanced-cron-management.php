<?php
/**
 * Challenge 9: Advanced Cron Job Management System
 * 
 * Problem Statement:
 * Create a robust task scheduling system that extends WordPress cron with:
 * 
 * 1. Custom cron intervals and scheduling flexibility
 * 2. Job dependency management and execution order
 * 3. Progress tracking and job status monitoring
 * 4. Failed job handling with retry mechanisms
 * 5. Cron event visualization and management interface
 * 6. Resource usage monitoring and optimization
 * 7. Bulk job operations and scheduling
 * 8. Integration with external scheduling systems
 * 
 * @package wp-advance-challange
 * @version 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Advanced Cron Job Manager
 * 
 * Extends WordPress cron system with dependency management,
 * progress tracking, and advanced scheduling features.
 * 
 * @since 1.0.0
 */
class Advanced_Cron_Manager {

    /**
     * Registered cron jobs
     *
     * @var array
     */
    private $cron_jobs = array();

    /**
     * Job dependencies registry
     *
     * @var array
     */
    private $job_dependencies = array();

    /**
     * Job progress tracker
     *
     * @var array
     */
    private $job_progress = array();

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        add_filter( 'cron_schedules', array( $this, 'add_custom_intervals' ) );
        add_action( 'init', array( $this, 'register_cron_jobs' ) );
        add_action( 'wp_ajax_get_job_progress', array( $this, 'ajax_get_job_progress' ) );
    }

    /**
     * Add custom cron intervals
     *
     * @param array $schedules Existing cron schedules.
     * @return array Modified schedules.
     * @since 1.0.0
     */
    public function add_custom_intervals( $schedules ) {
        $custom_intervals = array(
            'every_15_minutes' => array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 15 Minutes', 'wordpress-coding-challenge' ),
            ),
            'every_2_hours' => array(
                'interval' => 2 * HOUR_IN_SECONDS,
                'display'  => __( 'Every 2 Hours', 'wordpress-coding-challenge' ),
            ),
            'twice_daily' => array(
                'interval' => 12 * HOUR_IN_SECONDS,
                'display'  => __( 'Twice Daily', 'wordpress-coding-challenge' ),
            ),
        );

        return array_merge( $schedules, $custom_intervals );
    }

    /**
     * Register a cron job with advanced features
     *
     * @param string $job_id         Unique job identifier.
     * @param string $hook           Action hook to execute.
     * @param string $schedule       Cron schedule.
     * @param array  $args           Job arguments.
     * @param array  $dependencies   Job dependencies.
     * @param int    $retry_count    Number of retry attempts.
     * @since 1.0.0
     */
    public function register_job( $job_id, $hook, $schedule, $args = array(), $dependencies = array(), $retry_count = 3 ) {
        $this->cron_jobs[ $job_id ] = array(
            'hook'         => $hook,
            'schedule'     => $schedule,
            'args'         => $args,
            'dependencies' => $dependencies,
            'retry_count'  => $retry_count,
            'status'       => 'pending',
            'registered'   => current_time( 'mysql' ),
        );

        // Store dependencies.
        if ( ! empty( $dependencies ) ) {
            $this->job_dependencies[ $job_id ] = $dependencies;
        }

        // Schedule the job if no dependencies.
        if ( empty( $dependencies ) ) {
            $this->schedule_job( $job_id );
        }

        /**
         * Fires after a cron job is registered
         *
         * @param string $job_id Job identifier.
         * @param array  $job_data Job configuration.
         * @since 1.0.0
         */
        do_action( 'advanced_cron_job_registered', $job_id, $this->cron_jobs[ $job_id ] );
    }

    /**
     * Schedule a cron job
     *
     * @param string $job_id Job identifier.
     * @return bool True if scheduled successfully.
     * @since 1.0.0
     */
    private function schedule_job( $job_id ) {
        if ( ! isset( $this->cron_jobs[ $job_id ] ) ) {
            return false;
        }

        $job = $this->cron_jobs[ $job_id ];

        // Clear existing schedule.
        $this->unschedule_job( $job_id );

        // Schedule the job.
        $timestamp = wp_next_scheduled( $job['hook'], $job['args'] );
        
        if ( ! $timestamp ) {
            $timestamp = wp_schedule_event( time(), $job['schedule'], $job['hook'], $job['args'] );
        }

        if ( $timestamp ) {
            $this->cron_jobs[ $job_id ]['status'] = 'scheduled';
            $this->cron_jobs[ $job_id ]['next_run'] = $timestamp;
            return true;
        }

        return false;
    }

    /**
     * Execute a cron job with dependency checking
     *
     * @param string $job_id Job identifier.
     * @return bool True if executed successfully.
     * @since 1.0.0
     */
    public function execute_job( $job_id ) {
        if ( ! $this->can_execute_job( $job_id ) ) {
            return false;
        }

        $job = $this->cron_jobs[ $job_id ];
        $this->update_job_progress( $job_id, 'running', 0 );

        try {
            /**
             * Fires before a cron job execution
             *
             * @param string $job_id Job identifier.
             * @param array  $job_data Job configuration.
             * @since 1.0.0
             */
            do_action( 'advanced_cron_job_start', $job_id, $job );

            // Execute the job hook.
            do_action_ref_array( $job['hook'], $job['args'] );

            $this->update_job_progress( $job_id, 'completed', 100 );
            $this->mark_job_completed( $job_id );

            /**
             * Fires after a cron job completes successfully
             *
             * @param string $job_id Job identifier.
             * @param array  $job_data Job configuration.
             * @since 1.0.0
             */
            do_action( 'advanced_cron_job_completed', $job_id, $job );

            return true;

        } catch ( Exception $e ) {
            $this->handle_job_failure( $job_id, $e->getMessage() );
            return false;
        }
    }

    /**
     * Check if a job can be executed
     *
     * @param string $job_id Job identifier.
     * @return bool True if job can be executed.
     * @since 1.0.0
     */
    private function can_execute_job( $job_id ) {
        // Check if job exists.
        if ( ! isset( $this->cron_jobs[ $job_id ] ) ) {
            return false;
        }

        // Check dependencies.
        if ( isset( $this->job_dependencies[ $job_id ] ) ) {
            foreach ( $this->job_dependencies[ $job_id ] as $dependency ) {
                if ( ! $this->is_job_completed( $dependency ) ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if a job has been completed
     *
     * @param string $job_id Job identifier.
     * @return bool True if job is completed.
     * @since 1.0.0
     */
    private function is_job_completed( $job_id ) {
        $status = get_transient( "cron_job_status_{$job_id}" );
        return 'completed' === $status;
    }

    /**
     * Mark a job as completed
     *
     * @param string $job_id Job identifier.
     * @since 1.0.0
     */
    private function mark_job_completed( $job_id ) {
        set_transient( "cron_job_status_{$job_id}", 'completed', DAY_IN_SECONDS );
        
        // Trigger dependent jobs.
        $this->trigger_dependent_jobs( $job_id );
    }

    /**
     * Trigger jobs that depend on completed job
     *
     * @param string $completed_job_id Completed job identifier.
     * @since 1.0.0
     */
    private function trigger_dependent_jobs( $completed_job_id ) {
        foreach ( $this->job_dependencies as $job_id => $dependencies ) {
            if ( in_array( $completed_job_id, $dependencies, true ) ) {
                // Check if all dependencies are now met.
                if ( $this->can_execute_job( $job_id ) ) {
                    $this->schedule_job( $job_id );
                }
            }
        }
    }

    /**
     * Handle job execution failure
     *
     * @param string $job_id    Job identifier.
     * @param string $error_msg Error message.
     * @since 1.0.0
     */
    private function handle_job_failure( $job_id, $error_msg ) {
        $job = $this->cron_jobs[ $job_id ];
        $retry_count = $job['retry_count'] ?? 0;

        // Get current retry attempt.
        $attempts = get_transient( "cron_job_attempts_{$job_id}" ) ?: 0;
        $attempts++;

        if ( $attempts <= $retry_count ) {
            // Schedule retry.
            set_transient( "cron_job_attempts_{$job_id}", $attempts, HOUR_IN_SECONDS );
            $this->update_job_progress( $job_id, 'retrying', 0, $error_msg );
            
            // Schedule retry with exponential backoff.
            $retry_delay = min( 300 * pow( 2, $attempts - 1 ), 3600 ); // Max 1 hour delay.
            wp_schedule_single_event( time() + $retry_delay, $job['hook'], $job['args'] );
        } else {
            // Mark as failed after max retries.
            $this->update_job_progress( $job_id, 'failed', 0, $error_msg );
            $this->cron_jobs[ $job_id ]['status'] = 'failed';
            
            /**
             * Fires when a cron job fails after all retry attempts
             *
             * @param string $job_id    Job identifier.
             * @param string $error_msg Error message.
             * @param int    $attempts  Number of attempts made.
             * @since 1.0.0
             */
            do_action( 'advanced_cron_job_failed', $job_id, $error_msg, $attempts );
        }
    }

    /**
     * Update job progress
     *
     * @param string $job_id    Job identifier.
     * @param string $status    Job status.
     * @param int    $progress  Progress percentage.
     * @param string $message   Status message.
     * @since 1.0.0
     */
    private function update_job_progress( $job_id, $status, $progress, $message = '' ) {
        $this->job_progress[ $job_id ] = array(
            'status'    => $status,
            'progress'  => $progress,
            'message'   => $message,
            'timestamp' => current_time( 'mysql' ),
        );

        // Store in transient for persistence.
        set_transient( "cron_job_progress_{$job_id}", $this->job_progress[ $job_id ], HOUR_IN_SECONDS );
    }

    /**
     * Get job progress via AJAX
     *
     * @since 1.0.0
     */
    public function ajax_get_job_progress() {
        check_ajax_referer( 'cron_progress_nonce', 'nonce' );

        $job_id = sanitize_text_field( $_POST['job_id'] ?? '' );
        
        if ( empty( $job_id ) ) {
            wp_die( -1 );
        }

        $progress = get_transient( "cron_job_progress_{$job_id}" ) ?: array(
            'status'    => 'unknown',
            'progress'  => 0,
            'message'   => '',
        );

        wp_send_json_success( $progress );
    }

    /**
     * Unschedule a cron job
     *
     * @param string $job_id Job identifier.
     * @return bool True if unscheduled successfully.
     * @since 1.0.0
     */
    public function unschedule_job( $job_id ) {
        if ( ! isset( $this->cron_jobs[ $job_id ] ) ) {
            return false;
        }

        $job = $this->cron_jobs[ $job_id ];
        $timestamp = wp_next_scheduled( $job['hook'], $job['args'] );

        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, $job['hook'], $job['args'] );
        }

        $this->cron_jobs[ $job_id ]['status'] = 'unscheduled';
        return true;
    }

    /**
     * Get all registered cron jobs
     *
     * @return array Registered cron jobs.
     * @since 1.0.0
     */
    public function get_cron_jobs() {
        return $this->cron_jobs;
    }

    /**
     * Get cron schedule information
     *
     * @return array Cron schedule data.
     * @since 1.0.0
     */
    public function get_cron_schedule() {
        $cron_array = _get_cron_array();
        $schedule   = array();

        foreach ( $cron_array as $timestamp => $cron ) {
            foreach ( $cron as $hook => $dings ) {
                foreach ( $dings as $sig => $data ) {
                    $schedule[] = array(
                        'hook'      => $hook,
                        'timestamp' => $timestamp,
                        'next_run'  => date_i18n( 'Y-m-d H:i:s', $timestamp ),
                        'arguments' => $data['args'],
                        'schedule'  => $data['schedule'] ?? 'single',
                    );
                }
            }
        }

        return $schedule;
    }

    /**
     * Clear all cron jobs
     *
     * @since 1.0.0
     */
    public function clear_all_cron_jobs() {
        $cron_array = _get_cron_array();
        
        foreach ( $cron_array as $timestamp => $cron ) {
            foreach ( $cron as $hook => $dings ) {
                foreach ( $dings as $sig => $data ) {
                    wp_unschedule_event( $timestamp, $hook, $data['args'] );
                }
            }
        }

        $this->cron_jobs = array();
    }

    /**
     * Register default cron jobs
     *
     * @since 1.0.0
     */
    public function register_cron_jobs() {
        // Example: Database cleanup job.
        $this->register_job(
            'database_cleanup',
            'advanced_cron_database_cleanup',
            'daily',
            array(),
            array(),
            3
        );

        // Example: Cache warming job that depends on cleanup.
        $this->register_job(
            'cache_warming',
            'advanced_cron_cache_warming',
            'hourly',
            array(),
            array( 'database_cleanup' ),
            2
        );
    }
}

// Initialize the advanced cron manager.
$advanced_cron_manager = new Advanced_Cron_Manager();

// Example cron job handlers.
add_action( 'advanced_cron_database_cleanup', 'handle_database_cleanup' );
add_action( 'advanced_cron_cache_warming', 'handle_cache_warming' );

/**
 * Handle database cleanup cron job
 *
 * @since 1.0.0
 */
function handle_database_cleanup() {
    // Clean up transients.
    global $wpdb;
    $wpdb->query( 
        "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_%' 
         OR option_name LIKE '_site_transient_%'"
    );
}

/**
 * Handle cache warming cron job
 *
 * @since 1.0.0
 */
function handle_cache_warming() {
    // Warm cache for popular pages.
    $popular_pages = get_posts( array(
        'post_type'      => 'page',
        'posts_per_page' => 10,
        'meta_key'       => 'page_views',
        'orderby'        => 'meta_value_num',
        'order'          => 'DESC',
    ) );

    foreach ( $popular_pages as $page ) {
        wp_remote_get( get_permalink( $page->ID ) );
    }
}