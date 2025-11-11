<?php
/**
 * Challenge 12: Advanced Real-time Notification System
 * 
 * Problem Statement:
 * Create a comprehensive real-time notification system that:
 * 
 * 1. Supports multiple delivery channels (WebSocket, email, push)
 * 2. Provides real-time updates with WebSocket fallback mechanisms
 * 3. Manages user notification preferences and delivery rules
 * 4. Tracks notification delivery status and engagement
 * 5. Supports scheduled and batched notifications
 * 6. Implements notification templates and personalization
 * 7. Provides analytics and reporting on notification performance
 * 8. Ensures scalability for high-volume notification delivery
 * 
 * @package WordPressCodingChallenge
 * @version 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Real-time Notification System
 * 
 * Handles real-time notifications with multiple delivery channels,
 * preference management, and comprehensive tracking.
 * 
 * @since 1.0.0
 */
class RealTime_Notification_System {

    /**
     * Notification channels
     *
     * @var array
     */
    private $channels = array();

    /**
     * Notification templates
     *
     * @var array
     */
    private $templates = array();

    /**
     * WebSocket server status
     *
     * @var bool
     */
    private $websocket_available = false;

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->setup_channels();
        $this->setup_templates();
        
        add_action( 'wp_ajax_send_notification', array( $this, 'ajax_send_notification' ) );
        add_action( 'wp_ajax_nopriv_poll_notifications', array( $this, 'ajax_poll_notifications' ) );
        add_action( 'init', array( $this, 'check_websocket_availability' ) );
    }

    /**
     * Setup notification channels
     *
     * @since 1.0.0
     */
    private function setup_channels() {
        $this->channels = array(
            'websocket' => array(
                'name'     => 'WebSocket',
                'enabled'  => true,
                'priority' => 10,
                'handler'  => array( $this, 'send_websocket_notification' ),
            ),
            'push' => array(
                'name'     => 'Push Notification',
                'enabled'  => true,
                'priority' => 20,
                'handler'  => array( $this, 'send_push_notification' ),
            ),
            'email' => array(
                'name'     => 'Email',
                'enabled'  => true,
                'priority' => 30,
                'handler'  => array( $this, 'send_email_notification' ),
            ),
            'in_app' => array(
                'name'     => 'In-App',
                'enabled'  => true,
                'priority' => 40,
                'handler'  => array( $this, 'store_in_app_notification' ),
            ),
        );

        /**
         * Filter to modify available notification channels
         *
         * @param array $channels Notification channels.
         * @since 1.0.0
         */
        $this->channels = apply_filters( 'notification_system_channels', $this->channels );
    }

    /**
     * Setup notification templates
     *
     * @since 1.0.0
     */
    private function setup_templates() {
        $this->templates = array(
            'new_message' => array(
                'title'   => __( 'New Message', 'wordpress-coding-challenge' ),
                'content' => __( 'You have a new message from {sender}', 'wordpress-coding-challenge' ),
                'channels' => array( 'websocket', 'push', 'in_app' ),
            ),
            'order_update' => array(
                'title'   => __( 'Order Update', 'wordpress-coding-challenge' ),
                'content' => __( 'Your order #{order_id} has been updated to {status}', 'wordpress-coding-challenge' ),
                'channels' => array( 'email', 'in_app' ),
            ),
            'system_alert' => array(
                'title'   => __( 'System Alert', 'wordpress-coding-challenge' ),
                'content' => __( 'System notice: {message}', 'wordpress-coding-challenge' ),
                'channels' => array( 'websocket', 'email', 'in_app' ),
            ),
        );

        /**
         * Filter to modify notification templates
         *
         * @param array $templates Notification templates.
         * @since 1.0.0
         */
        $this->templates = apply_filters( 'notification_system_templates', $this->templates );
    }

    /**
     * Check WebSocket server availability
     *
     * @since 1.0.0
     */
    public function check_websocket_availability() {
        $websocket_url = get_option( 'websocket_server_url' );
        
        if ( ! empty( $websocket_url ) ) {
            // Simple connectivity check.
            $response = wp_remote_get( $websocket_url, array( 'timeout' => 5 ) );
            $this->websocket_available = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );
        } else {
            $this->websocket_available = false;
        }
    }

    /**
     * Send notification to user(s)
     *
     * @param string $template    Template identifier.
     * @param array  $data        Template data.
     * @param mixed  $users       User ID, array of IDs, or 'all'.
     * @param array  $channels    Specific channels to use.
     * @param array  $options     Additional options.
     * @return array Delivery results.
     * @since 1.0.0
     */
    public function send_notification( $template, $data = array(), $users = 'all', $channels = array(), $options = array() ) {
        // Validate template.
        if ( ! isset( $this->templates[ $template ] ) ) {
            return array( 
                'success' => false,
                'error'   => 'Invalid notification template',
            );
        }

        $template_config = $this->templates[ $template ];
        $user_ids = $this->resolve_user_ids( $users );
        
        if ( empty( $user_ids ) ) {
            return array( 
                'success' => false,
                'error'   => 'No users specified',
            );
        }

        // Determine channels to use.
        $channels_to_use = empty( $channels ) ? $template_config['channels'] : $channels;
        $channels_to_use = $this->filter_channels_by_preference( $channels_to_use, $user_ids );

        $results = array(
            'total_users'   => count( $user_ids ),
            'deliveries'    => array(),
            'failed'        => array(),
        );

        foreach ( $user_ids as $user_id ) {
            $user_results = $this->deliver_to_user( $user_id, $template, $data, $channels_to_use, $options );
            
            if ( ! empty( $user_results['failed'] ) ) {
                $results['failed'][ $user_id ] = $user_results['failed'];
            }
            
            $results['deliveries'][ $user_id ] = $user_results;
        }

        // Track notification in analytics.
        $this->track_notification_delivery( $template, $user_ids, $results );

        return $results;
    }

    /**
     * Resolve user IDs from various input types
     *
     * @param mixed $users User specification.
     * @return array User IDs.
     * @since 1.0.0
     */
    private function resolve_user_ids( $users ) {
        if ( 'all' === $users ) {
            return get_users( array( 'fields' => 'ID' ) );
        }

        if ( is_numeric( $users ) ) {
            return array( absint( $users ) );
        }

        if ( is_array( $users ) ) {
            return array_map( 'absint', $users );
        }

        return array();
    }

    /**
     * Filter channels based on user preferences
     *
     * @param array $channels Proposed channels.
     * @param array $user_ids User IDs.
     * @return array Filtered channels.
     * @since 1.0.0
     */
    private function filter_channels_by_preference( $channels, $user_ids ) {
        $filtered_channels = array();
        
        foreach ( $channels as $channel ) {
            $enabled_for_all = true;
            
            foreach ( $user_ids as $user_id ) {
                $user_prefs = $this->get_user_notification_preferences( $user_id );
                
                if ( ! in_array( $channel, $user_prefs['enabled_channels'], true ) ) {
                    $enabled_for_all = false;
                    break;
                }
            }
            
            if ( $enabled_for_all ) {
                $filtered_channels[] = $channel;
            }
        }

        return $filtered_channels;
    }

    /**
     * Deliver notification to a single user
     *
     * @param int    $user_id   User ID.
     * @param string $template  Template identifier.
     * @param array  $data      Template data.
     * @param array  $channels  Delivery channels.
     * @param array  $options   Additional options.
     * @return array Delivery results.
     * @since 1.0.0
     */
    private function deliver_to_user( $user_id, $template, $data, $channels, $options ) {
        $results = array(
            'user_id'  => $user_id,
            'channels' => array(),
            'failed'   => array(),
        );

        $notification_id = $this->create_notification_record( $user_id, $template, $data, $channels );
        $processed_data = $this->process_template_data( $template, $data, $user_id );

        foreach ( $channels as $channel ) {
            if ( ! isset( $this->channels[ $channel ] ) || ! $this->channels[ $channel ]['enabled'] ) {
                continue;
            }

            $channel_result = $this->deliver_via_channel( $channel, $user_id, $template, $processed_data, $notification_id );
            
            if ( $channel_result['success'] ) {
                $results['channels'][ $channel ] = $channel_result;
                $this->update_channel_delivery_status( $notification_id, $channel, 'delivered' );
            } else {
                $results['failed'][ $channel ] = $channel_result;
                $this->update_channel_delivery_status( $notification_id, $channel, 'failed', $channel_result['error'] );
            }
        }

        return $results;
    }

    /**
     * Deliver notification via specific channel
     *
     * @param string $channel         Channel identifier.
     * @param int    $user_id         User ID.
     * @param string $template        Template identifier.
     * @param array  $data            Processed template data.
     * @param string $notification_id Notification ID.
     * @return array Delivery result.
     * @since 1.0.0
     */
    private function deliver_via_channel( $channel, $user_id, $template, $data, $notification_id ) {
        $channel_config = $this->channels[ $channel ];
        
        try {
            $result = call_user_func( $channel_config['handler'], $user_id, $template, $data, $notification_id );
            
            return array(
                'success' => true,
                'channel' => $channel,
                'result'  => $result,
            );
        } catch ( Exception $e ) {
            return array(
                'success' => false,
                'channel' => $channel,
                'error'   => $e->getMessage(),
            );
        }
    }

    /**
     * Send WebSocket notification
     *
     * @param int    $user_id         User ID.
     * @param string $template        Template identifier.
     * @param array  $data            Notification data.
     * @param string $notification_id Notification ID.
     * @return bool True if sent successfully.
     * @since 1.0.0
     */
    public function send_websocket_notification( $user_id, $template, $data, $notification_id ) {
        if ( ! $this->websocket_available ) {
            throw new Exception( 'WebSocket server not available' );
        }

        $websocket_url = get_option( 'websocket_server_url' );
        $payload = array(
            'user_id'         => $user_id,
            'template'        => $template,
            'data'            => $data,
            'notification_id' => $notification_id,
            'timestamp'       => time(),
        );

        $response = wp_remote_post( 
            $websocket_url . '/notify',
            array(
                'body'    => wp_json_encode( $payload ),
                'headers' => array( 'Content-Type' => 'application/json' ),
                'timeout' => 5,
            )
        );

        if ( is_wp_error( $response ) ) {
            throw new Exception( $response->get_error_message() );
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $response_code ) {
            throw new Exception( "WebSocket server returned status: {$response_code}" );
        }

        return true;
    }

    /**
     * Send push notification
     *
     * @param int    $user_id         User ID.
     * @param string $template        Template identifier.
     * @param array  $data            Notification data.
     * @param string $notification_id Notification ID.
     * @return bool True if sent successfully.
     * @since 1.0.0
     */
    public function send_push_notification( $user_id, $template, $data, $notification_id ) {
        $push_tokens = get_user_meta( $user_id, 'push_notification_tokens', true ) ?: array();
        
        if ( empty( $push_tokens ) ) {
            throw new Exception( 'No push tokens available for user' );
        }

        $service_url = get_option( 'push_service_url' );
        $api_key     = get_option( 'push_service_api_key' );

        if ( empty( $service_url ) || empty( $api_key ) ) {
            throw new Exception( 'Push service not configured' );
        }

        $payload = array(
            'tokens' => $push_tokens,
            'title'  => $data['title'] ?? '',
            'body'   => $data['content'] ?? '',
            'data'   => array(
                'template'        => $template,
                'notification_id' => $notification_id,
                'user_id'         => $user_id,
            ),
        );

        $response = wp_remote_post( 
            $service_url,
            array(
                'body'    => wp_json_encode( $payload ),
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ),
                'timeout' => 10,
            )
        );

        if ( is_wp_error( $response ) ) {
            throw new Exception( $response->get_error_message() );
        }

        return true;
    }

    /**
     * Send email notification
     *
     * @param int    $user_id         User ID.
     * @param string $template        Template identifier.
     * @param array  $data            Notification data.
     * @param string $notification_id Notification ID.
     * @return bool True if sent successfully.
     * @since 1.0.0
     */
    public function send_email_notification( $user_id, $template, $data, $notification_id ) {
        $user = get_user_by( 'id', $user_id );
        
        if ( ! $user ) {
            throw new Exception( 'User not found' );
        }

        $subject = $data['title'] ?? __( 'Notification', 'wordpress-coding-challenge' );
        $message = $this->render_email_template( $template, $data, $user );

        $result = wp_mail( $user->user_email, $subject, $message );

        if ( ! $result ) {
            throw new Exception( 'Failed to send email' );
        }

        return true;
    }

    /**
     * Store in-app notification
     *
     * @param int    $user_id         User ID.
     * @param string $template        Template identifier.
     * @param array  $data            Notification data.
     * @param string $notification_id Notification ID.
     * @return bool True if stored successfully.
     * @since 1.0.0
     */
    public function store_in_app_notification( $user_id, $template, $data, $notification_id ) {
        $notifications = get_user_meta( $user_id, 'in_app_notifications', true ) ?: array();
        
        $notifications[] = array(
            'id'        => $notification_id,
            'template'  => $template,
            'data'      => $data,
            'timestamp' => current_time( 'mysql' ),
            'read'      => false,
        );

        // Keep only recent notifications.
        if ( count( $notifications ) > 50 ) {
            $notifications = array_slice( $notifications, -50 );
        }

        return update_user_meta( $user_id, 'in_app_notifications', $notifications );
    }

    /**
     * Process template data with user-specific replacements
     *
     * @param string $template Template identifier.
     * @param array  $data     Template data.
     * @param int    $user_id  User ID.
     * @return array Processed data.
     * @since 1.0.0
     */
    private function process_template_data( $template, $data, $user_id ) {
        $template_config = $this->templates[ $template ];
        $user = get_user_by( 'id', $user_id );
        
        $processed = array(
            'title'   => $template_config['title'],
            'content' => $template_config['content'],
        );

        // Replace placeholders.
        foreach ( $data as $key => $value ) {
            $processed['title']   = str_replace( "{{$key}}", $value, $processed['title'] );
            $processed['content'] = str_replace( "{{$key}}", $value, $processed['content'] );
        }

        // Add user-specific replacements.
        if ( $user ) {
            $user_replacements = array(
                'user_name'  => $user->display_name,
                'user_email' => $user->user_email,
            );
            
            foreach ( $user_replacements as $key => $value ) {
                $processed['title']   = str_replace( "{{$key}}", $value, $processed['title'] );
                $processed['content'] = str_replace( "{{$key}}", $value, $processed['content'] );
            }
        }

        return $processed;
    }

    /**
     * Create notification record in database
     *
     * @param int    $user_id   User ID.
     * @param string $template  Template identifier.
     * @param array  $data      Template data.
     * @param array  $channels  Delivery channels.
     * @return string Notification ID.
     * @since 1.0.0
     */
    private function create_notification_record( $user_id, $template, $data, $channels ) {
        global $wpdb;

        $notification_id = wp_generate_uuid4();
        $table_name = $wpdb->prefix . 'notification_logs';

        $wpdb->insert( 
            $table_name,
            array(
                'notification_id' => $notification_id,
                'user_id'         => $user_id,
                'template'        => $template,
                'data'            => wp_json_encode( $data ),
                'channels'        => wp_json_encode( $channels ),
                'created_at'      => current_time( 'mysql' ),
                'status'          => 'pending',
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        return $notification_id;
    }

    /**
     * Update channel delivery status
     *
     * @param string $notification_id Notification ID.
     * @param string $channel         Channel identifier.
     * @param string $status          Delivery status.
     * @param string $error_message   Error message if failed.
     * @since 1.0.0
     */
    private function update_channel_delivery_status( $notification_id, $channel, $status, $error_message = '' ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'notification_deliveries';
        
        $wpdb->insert( 
            $table_name,
            array(
                'notification_id' => $notification_id,
                'channel'         => $channel,
                'status'          => $status,
                'error_message'   => $error_message,
                'delivered_at'    => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Get user notification preferences
     *
     * @param int $user_id User ID.
     * @return array User preferences.
     * @since 1.0.0
     */
    public function get_user_notification_preferences( $user_id ) {
        $defaults = array(
            'enabled_channels' => array_keys( $this->channels ),
            'quiet_hours'      => array(
                'start' => '22:00',
                'end'   => '08:00',
            ),
            'email_frequency'  => 'immediate',
        );

        $prefs = get_user_meta( $user_id, 'notification_preferences', true );
        
        return wp_parse_args( $prefs ?: array(), $defaults );
    }

    /**
     * Track notification delivery for analytics
     *
     * @param string $template Template identifier.
     * @param array  $user_ids User IDs.
     * @param array  $results  Delivery results.
     * @since 1.0.0
     */
    private function track_notification_delivery( $template, $user_ids, $results ) {
        $delivery_data = array(
            'template'    => $template,
            'user_count'  => count( $user_ids ),
            'sent_at'     => current_time( 'mysql' ),
            'success_rate' => 0,
        );

        $successful_deliveries = 0;
        $total_deliveries = 0;

        foreach ( $results['deliveries'] as $user_result ) {
            $total_deliveries += count( $user_result['channels'] );
            $successful_deliveries += count( $user_result['channels'] );
        }

        if ( $total_deliveries > 0 ) {
            $delivery_data['success_rate'] = ( $successful_deliveries / $total_deliveries ) * 100;
        }

        // Store in analytics.
        $analytics = get_option( 'notification_analytics', array() );
        $analytics[] = $delivery_data;
        
        // Keep only last 1000 records.
        if ( count( $analytics ) > 1000 ) {
            $analytics = array_slice( $analytics, -1000 );
        }

        update_option( 'notification_analytics', $analytics, false );
    }

    /**
     * Handle AJAX notification sending
     *
     * @since 1.0.0
     */
    public function ajax_send_notification() {
        check_ajax_referer( 'send_notification_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( -1, 403 );
        }

        $template = sanitize_text_field( $_POST['template'] ?? '' );
        $data     = wp_parse_args( $_POST['data'] ?? array(), array() );
        $users    = $_POST['users'] ?? 'all';
        $channels = $_POST['channels'] ?? array();

        $result = $this->send_notification( $template, $data, $users, $channels );

        wp_send_json_success( $result );
    }

    /**
     * Handle AJAX notification polling
     *
     * @since 1.0.0
     */
    public function ajax_poll_notifications() {
        $user_id = get_current_user_id();
        
        if ( ! $user_id ) {
            wp_die( -1, 401 );
        }

        $last_check = absint( $_POST['last_check'] ?? 0 );
        $notifications = get_user_meta( $user_id, 'in_app_notifications', true ) ?: array();

        // Filter notifications newer than last check.
        $new_notifications = array_filter( $notifications, function( $notification ) use ( $last_check ) {
            return strtotime( $notification['timestamp'] ) > $last_check;
        } );

        wp_send_json_success( array(
            'notifications' => array_values( $new_notifications ),
            'timestamp'     => time(),
        ) );
    }
}

// Initialize the real-time notification system.
$real_time_notification_system = new RealTime_Notification_System();