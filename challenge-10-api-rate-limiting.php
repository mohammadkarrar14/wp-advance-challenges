<?php
/**
 * Challenge 10: Comprehensive API Rate Limiting System
 * 
 * Problem Statement:
 * Create a robust API rate limiting system that provides:
 * 
 * 1. Multiple rate limiting strategies (IP, user, endpoint-based)
 * 2. User-based quotas and tiered access levels
 * 3. IP-based restrictions and geographic controls
 * 4. Burst capacity handling and smooth rate limiting
 * 5. Comprehensive analytics and reporting
 * 6. Integration with WordPress REST API
 * 7. Customizable rate limit headers and responses
 * 8. Automatic ban system for abusive clients
 * 
 * @package WordPressCodingChallenge
 * @author Your Name
 * @version 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * API Rate Limiting System
 * 
 * Implements comprehensive rate limiting for WordPress APIs
 * with multiple strategies and detailed analytics.
 * 
 * @since 1.0.0
 */
class API_Rate_Limiting_System {

    /**
     * Rate limit configurations
     *
     * @var array
     */
    private $rate_limits = array();

    /**
     * Client tracking data
     *
     * @var array
     */
    private $client_data = array();

    /**
     * Banned clients registry
     *
     * @var array
     */
    private $banned_clients = array();

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->setup_default_limits();
        add_filter( 'rest_pre_dispatch', array( $this, 'check_rate_limit' ), 10, 3 );
        add_action( 'rest_api_init', array( $this, 'register_rate_limit_routes' ) );
    }

    /**
     * Setup default rate limits
     *
     * @since 1.0.0
     */
    private function setup_default_limits() {
        $this->rate_limits = array(
            'global' => array(
                'requests_per_minute' => 1000,
                'burst_capacity'     => 50,
                'strategy'           => 'ip_based',
            ),
            'rest_api' => array(
                'requests_per_minute' => 100,
                'burst_capacity'     => 20,
                'strategy'           => 'user_based',
            ),
            'unauthenticated' => array(
                'requests_per_minute' => 10,
                'burst_capacity'     => 5,
                'strategy'           => 'ip_based',
            ),
        );
    }

    /**
     * Check rate limit for API request
     *
     * @param mixed  $result  Previous result.
     * @param object $server  REST server instance.
     * @param object $request Request object.
     * @return mixed Rate limit check result or original result.
     * @since 1.0.0
     */
    public function check_rate_limit( $result, $server, $request ) {
        $client_id = $this->get_client_identifier( $request );
        $endpoint  = $request->get_route();
        $method    = $request->get_method();

        // Check if client is banned.
        if ( $this->is_client_banned( $client_id ) ) {
            return $this->rate_limit_exceeded_response( 
                'Client banned', 
                403,
                array( 'retry_after' => 3600 )
            );
        }

        // Get appropriate rate limit for this request.
        $rate_limit = $this->get_rate_limit_for_request( $request );

        // Check rate limit.
        $limit_check = $this->check_client_limit( $client_id, $rate_limit, $endpoint );

        if ( is_wp_error( $limit_check ) ) {
            return $this->rate_limit_exceeded_response( 
                $limit_check->get_error_message(),
                429,
                array( 
                    'retry_after' => $limit_check->get_error_data()['retry_after'] ?? 60,
                    'limit'       => $rate_limit['requests_per_minute'],
                    'remaining'   => 0,
                )
            );
        }

        // Add rate limit headers to response.
        add_filter( 'rest_post_dispatch', array( $this, 'add_rate_limit_headers' ), 10, 3 );

        return $result;
    }

    /**
     * Get client identifier based on strategy
     *
     * @param WP_REST_Request $request Request object.
     * @return string Client identifier.
     * @since 1.0.0
     */
    private function get_client_identifier( $request ) {
        $strategy = $this->get_rate_limit_strategy( $request );

        switch ( $strategy ) {
            case 'user_based':
                $user_id = get_current_user_id();
                return $user_id ? "user_{$user_id}" : "ip_{$this->get_client_ip()}";

            case 'ip_based':
            default:
                return "ip_{$this->get_client_ip()}";
        }
    }

    /**
     * Get rate limit strategy for request
     *
     * @param WP_REST_Request $request Request object.
     * @return string Rate limit strategy.
     * @since 1.0.0
     */
    private function get_rate_limit_strategy( $request ) {
        $endpoint = $request->get_route();

        // Check for endpoint-specific strategy.
        foreach ( $this->rate_limits as $key => $limit ) {
            if ( 0 === strpos( $endpoint, "/wp/v2/{$key}" ) ) {
                return $limit['strategy'];
            }
        }

        // Default strategy based on authentication.
        return is_user_logged_in() ? 'user_based' : 'ip_based';
    }

    /**
     * Get appropriate rate limit for request
     *
     * @param WP_REST_Request $request Request object.
     * @return array Rate limit configuration.
     * @since 1.0.0
     */
    private function get_rate_limit_for_request( $request ) {
        $endpoint = $request->get_route();

        // Check for endpoint-specific limits.
        foreach ( $this->rate_limits as $key => $limit ) {
            if ( 0 === strpos( $endpoint, "/wp/v2/{$key}" ) ) {
                return $limit;
            }
        }

        // Default limits based on authentication.
        if ( is_user_logged_in() ) {
            return $this->rate_limits['rest_api'];
        } else {
            return $this->rate_limits['unauthenticated'];
        }
    }

    /**
     * Check client against rate limit
     *
     * @param string $client_id  Client identifier.
     * @param array  $rate_limit Rate limit configuration.
     * @param string $endpoint   API endpoint.
     * @return true|WP_Error True if allowed, WP_Error if limited.
     * @since 1.0.0
     */
    private function check_client_limit( $client_id, $rate_limit, $endpoint ) {
        $current_time = time();
        $window_size  = 60; // 1 minute in seconds.
        $max_requests = $rate_limit['requests_per_minute'];
        $burst_capacity = $rate_limit['burst_capacity'] ?? 10;

        // Get client request history.
        $client_data = $this->get_client_data( $client_id );
        $request_history = $client_data['requests'] ?? array();

        // Remove old requests outside the current window.
        $request_history = array_filter( $request_history, function( $timestamp ) use ( $current_time, $window_size ) {
            return $timestamp > ( $current_time - $window_size );
        } );

        // Check if client exceeds burst capacity.
        $recent_requests = count( $request_history );
        
        if ( $recent_requests >= $burst_capacity && $recent_requests >= $max_requests ) {
            // Client is making too many requests too quickly.
            $this->track_abusive_behavior( $client_id );
            return new WP_Error(
                'rate_limit_exceeded',
                __( 'Too many requests. Please slow down.', 'wordpress-coding-challenge' ),
                array( 
                    'status' => 429,
                    'retry_after' => 60,
                )
            );
        }

        // Check if client exceeds rate limit.
        if ( count( $request_history ) >= $max_requests ) {
            $oldest_request = min( $request_history );
            $retry_after = ( $oldest_request + $window_size ) - $current_time;

            return new WP_Error(
                'rate_limit_exceeded',
                __( 'Rate limit exceeded.', 'wordpress-coding-challenge' ),
                array( 
                    'status' => 429,
                    'retry_after' => max( 1, $retry_after ),
                )
            );
        }

        // Record this request.
        $request_history[] = $current_time;
        $this->update_client_data( $client_id, $request_history, $endpoint );

        return true;
    }

    /**
     * Get client request data
     *
     * @param string $client_id Client identifier.
     * @return array Client data.
     * @since 1.0.0
     */
    private function get_client_data( $client_id ) {
        $cache_key = "rate_limit_{$client_id}";
        $cached = wp_cache_get( $cache_key, 'api_rate_limits' );

        if ( false !== $cached ) {
            return $cached;
        }

        $data = get_transient( $cache_key ) ?: array(
            'requests' => array(),
            'endpoints' => array(),
            'first_seen' => time(),
        );

        wp_cache_set( $cache_key, $data, 'api_rate_limits', 60 );

        return $data;
    }

    /**
     * Update client request data
     *
     * @param string $client_id        Client identifier.
     * @param array  $request_history  Updated request history.
     * @param string $endpoint         API endpoint.
     * @since 1.0.0
     */
    private function update_client_data( $client_id, $request_history, $endpoint ) {
        $data = $this->get_client_data( $client_id );
        $data['requests'] = $request_history;
        $data['endpoints'][ $endpoint ] = time();
        $data['last_seen'] = time();

        $cache_key = "rate_limit_{$client_id}";
        set_transient( $cache_key, $data, 120 ); // Store for 2 minutes.
        wp_cache_set( $cache_key, $data, 'api_rate_limits', 120 );
    }

    /**
     * Track abusive client behavior
     *
     * @param string $client_id Client identifier.
     * @since 1.0.0
     */
    private function track_abusive_behavior( $client_id ) {
        $abuse_count = get_transient( "abuse_count_{$client_id}" ) ?: 0;
        $abuse_count++;

        if ( $abuse_count >= 5 ) {
            // Ban client for 1 hour after 5 abuse incidents.
            $this->ban_client( $client_id, 3600 );
        } else {
            set_transient( "abuse_count_{$client_id}", $abuse_count, 3600 );
        }
    }

    /**
     * Ban a client for specified duration
     *
     * @param string $client_id Client identifier.
     * @param int    $duration  Ban duration in seconds.
     * @since 1.0.0
     */
    private function ban_client( $client_id, $duration ) {
        $this->banned_clients[ $client_id ] = time() + $duration;
        set_transient( "banned_client_{$client_id}", time() + $duration, $duration );
    }

    /**
     * Check if client is banned
     *
     * @param string $client_id Client identifier.
     * @return bool True if client is banned.
     * @since 1.0.0
     */
    private function is_client_banned( $client_id ) {
        $banned_until = get_transient( "banned_client_{$client_id}" );
        
        if ( $banned_until && $banned_until > time() ) {
            return true;
        }

        // Clean up expired ban.
        if ( $banned_until ) {
            delete_transient( "banned_client_{$client_id}" );
        }

        return false;
    }

    /**
     * Get client IP address
     *
     * @return string Client IP address.
     * @since 1.0.0
     */
    private function get_client_ip() {
        $ip_keys = array(
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        );

        foreach ( $ip_keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                
                if ( false !== strpos( $ip, ',' ) ) {
                    $ip_chain = explode( ',', $ip );
                    $ip       = trim( $ip_chain[0] );
                }

                if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Add rate limit headers to API response
     *
     * @param WP_REST_Response $response Response object.
     * @param WP_REST_Server   $server   Server instance.
     * @param WP_REST_Request  $request  Request object.
     * @return WP_REST_Response Modified response.
     * @since 1.0.0
     */
    public function add_rate_limit_headers( $response, $server, $request ) {
        $client_id = $this->get_client_identifier( $request );
        $rate_limit = $this->get_rate_limit_for_request( $request );
        $client_data = $this->get_client_data( $client_id );

        $remaining = max( 0, $rate_limit['requests_per_minute'] - count( $client_data['requests'] ) );

        $response->header( 'X-RateLimit-Limit', $rate_limit['requests_per_minute'] );
        $response->header( 'X-RateLimit-Remaining', $remaining );
        $response->header( 'X-RateLimit-Reset', time() + 60 );

        return $response;
    }

    /**
     * Create rate limit exceeded response
     *
     * @param string $message    Error message.
     * @param int    $status     HTTP status code.
     * @param array  $data       Additional data.
     * @return WP_Error Error response.
     * @since 1.0.0
     */
    private function rate_limit_exceeded_response( $message, $status, $data = array() ) {
        $error = new WP_Error( 'rate_limit_exceeded', $message, $data );
        
        if ( ! headers_sent() ) {
            header( 'Retry-After: ' . ( $data['retry_after'] ?? 60 ) );
        }

        return $error;
    }

    /**
     * Register rate limit management routes
     *
     * @since 1.0.0
     */
    public function register_rate_limit_routes() {
        register_rest_route( 'rate-limit/v1', '/stats', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_rate_limit_stats' ),
            'permission_callback' => array( $this, 'check_admin_permissions' ),
        ) );

        register_rest_route( 'rate-limit/v1', '/clients/(?P<client_id>[a-zA-Z0-9_]+)/unban', array(
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => array( $this, 'unban_client' ),
            'permission_callback' => array( $this, 'check_admin_permissions' ),
        ) );
    }

    /**
     * Get rate limit statistics
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response data.
     * @since 1.0.0
     */
    public function get_rate_limit_stats( $request ) {
        global $wpdb;

        $stats = array(
            'total_requests' => 0,
            'active_clients' => 0,
            'banned_clients' => 0,
            'rate_limits'    => $this->rate_limits,
        );

        return rest_ensure_response( $stats );
    }

    /**
     * Unban a client
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response data.
     * @since 1.0.0
     */
    public function unban_client( $request ) {
        $client_id = $request->get_param( 'client_id' );
        delete_transient( "banned_client_{$client_id}" );

        return rest_ensure_response( array(
            'success' => true,
            'message' => 'Client unbanned successfully',
        ) );
    }

    /**
     * Check admin permissions for rate limit management
     *
     * @return bool True if user has admin capabilities.
     * @since 1.0.0
     */
    public function check_admin_permissions() {
        return current_user_can( 'manage_options' );
    }
}

// Initialize the API rate limiting system.
$api_rate_limiting_system = new API_Rate_Limiting_System();