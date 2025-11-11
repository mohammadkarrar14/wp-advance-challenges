<?php
/**
 * Challenge 2: Secure REST API with Advanced Validation
 * 
 * Problem Statement:
 * Create a secure REST API endpoint for product management that includes:
 * 
 * 1. JWT authentication for secure API access
 * 2. Comprehensive input validation and sanitization
 * 3. Request rate limiting to prevent abuse
 * 4. File upload handling with security checks
 * 5. Proper error handling with HTTP status codes
 * 6. Webhook notifications for important events
 * 7. API documentation and schema validation
 * 8. Nonce verification and capability checks
 * 
 * @package wp-advance-challange
 * @version 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Product REST API Controller
 * 
 * Handles secure product management via REST API with
 * comprehensive validation and rate limiting.
 * 
 * @since 1.0.0
 */
class Product_REST_API_Controller {

    /**
     * REST API namespace
     *
     * @var string
     */
    private $namespace = 'wcch/v1';

    /**
     * Rate limit store
     *
     * @var array
     */
    private $rate_limits = array();

    /**
     * Initialize REST API routes
     *
     * @since 1.0.0
     */
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register REST API routes
     *
     * @since 1.0.0
     */
    public function register_routes() {
        // Products collection endpoint.
        register_rest_route(
            $this->namespace,
            '/products',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_products' ),
                    'permission_callback' => array( $this, 'get_products_permissions_check' ),
                    'args'                => $this->get_collection_params(),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_product' ),
                    'permission_callback' => array( $this, 'create_product_permissions_check' ),
                    'args'                => $this->get_product_schema(),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );

        // Single product endpoint.
        register_rest_route(
            $this->namespace,
            '/products/(?P<id>[\d]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_product' ),
                    'permission_callback' => array( $this, 'get_product_permissions_check' ),
                    'args'                => array(
                        'context' => $this->get_context_param( array( 'default' => 'view' ) ),
                    ),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_product' ),
                    'permission_callback' => array( $this, 'update_product_permissions_check' ),
                    'args'                => $this->get_product_schema(),
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_product' ),
                    'permission_callback' => array( $this, 'delete_product_permissions_check' ),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );

        // Product image upload endpoint.
        register_rest_route(
            $this->namespace,
            '/products/(?P<id>[\d]+)/image',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'upload_product_image' ),
                    'permission_callback' => array( $this, 'upload_image_permissions_check' ),
                ),
            )
        );
    }

    /**
     * Check if a given request has access to read products
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
     * @since 1.0.0
     */
    public function get_products_permissions_check( $request ) {
        // Apply rate limiting.
        $rate_limit_check = $this->check_rate_limit( $request, 'read_products' );
        if ( is_wp_error( $rate_limit_check ) ) {
            return $rate_limit_check;
        }

        // Check if user can read products.
        if ( ! current_user_can( 'edit_posts' ) ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'Sorry, you are not allowed to view products.', 'wordpress-coding-challenge' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        return true;
    }

    /**
     * Check if a given request has access to create a product
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return true|WP_Error True if the request has access to create items, WP_Error object otherwise.
     * @since 1.0.0
     */
    public function create_product_permissions_check( $request ) {
        // Apply rate limiting.
        $rate_limit_check = $this->check_rate_limit( $request, 'create_product' );
        if ( is_wp_error( $rate_limit_check ) ) {
            return $rate_limit_check;
        }

        // Verify nonce for state-changing operations.
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error(
                'invalid_nonce',
                __( 'Security verification failed.', 'wordpress-coding-challenge' ),
                array( 'status' => 403 )
            );
        }

        // Check if user can create products.
        if ( ! current_user_can( 'publish_posts' ) ) {
            return new WP_Error(
                'rest_cannot_create',
                __( 'Sorry, you are not allowed to create products.', 'wordpress-coding-challenge' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        return true;
    }

    /**
     * Check rate limits for API requests
     *
     * @param WP_REST_Request $request Request object.
     * @param string          $action  Action being performed.
     * @return true|WP_Error True if within limits, WP_Error if rate limited.
     * @since 1.0.0
     */
    private function check_rate_limit( $request, $action ) {
        $user_id    = get_current_user_id();
        $ip_address = $this->get_client_ip();
        $limit_key  = "{$action}_{$user_id}_{$ip_address}";

        $current_time = time();
        $window       = 60; // 1 minute window.

        // Initialize or get existing rate limit data.
        if ( ! isset( $this->rate_limits[ $limit_key ] ) ) {
            $this->rate_limits[ $limit_key ] = array(
                'count'      => 0,
                'start_time' => $current_time,
            );
        }

        $rate_data = &$this->rate_limits[ $limit_key ];

        // Reset counter if outside time window.
        if ( ( $current_time - $rate_data['start_time'] ) > $window ) {
            $rate_data['count']      = 0;
            $rate_data['start_time'] = $current_time;
        }

        // Define rate limits per action.
        $limits = array(
            'read_products'  => 100, // 100 reads per minute.
            'create_product' => 10,  // 10 creates per minute.
            'upload_image'   => 5,   // 5 uploads per minute.
        );

        $limit = isset( $limits[ $action ] ) ? $limits[ $action ] : 50;

        // Check if limit exceeded.
        if ( $rate_data['count'] >= $limit ) {
            return new WP_Error(
                'rate_limit_exceeded',
                __( 'API rate limit exceeded. Please try again later.', 'wordpress-coding-challenge' ),
                array( 
                    'status' => 429,
                    'retry_after' => $rate_data['start_time'] + $window - $current_time,
                )
            );
        }

        $rate_data['count']++;

        return true;
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
                
                // Handle multiple IPs in X_FORWARDED_FOR.
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
     * Retrieve a collection of products
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
     * @since 1.0.0
     */
    public function get_products( $request ) {
        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $request['per_page'] ?? 10,
            'paged'          => $request['page'] ?? 1,
        );

        // Add search filter.
        if ( ! empty( $request['search'] ) ) {
            $args['s'] = sanitize_text_field( $request['search'] );
        }

        // Add category filter.
        if ( ! empty( $request['category'] ) ) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'slug',
                    'terms'    => sanitize_text_field( $request['category'] ),
                ),
            );
        }

        // Add price range filter.
        if ( ! empty( $request['min_price'] ) || ! empty( $request['max_price'] ) ) {
            $args['meta_query'] = array( 'relation' => 'AND' );

            if ( ! empty( $request['min_price'] ) ) {
                $args['meta_query'][] = array(
                    'key'     => '_price',
                    'value'   => floatval( $request['min_price'] ),
                    'compare' => '>=',
                    'type'    => 'NUMERIC',
                );
            }

            if ( ! empty( $request['max_price'] ) ) {
                $args['meta_query'][] = array(
                    'key'     => '_price',
                    'value'   => floatval( $request['max_price'] ),
                    'compare' => '<=',
                    'type'    => 'NUMERIC',
                );
            }
        }

        $query = new WP_Query( $args );

        $products = array();
        foreach ( $query->posts as $post ) {
            $products[] = $this->prepare_product_for_response( $post, $request );
        }

        $response = rest_ensure_response( $products );

        // Add pagination headers.
        $total_posts = $query->found_posts;
        $max_pages   = ceil( $total_posts / $args['posts_per_page'] );

        $response->header( 'X-WP-Total', $total_posts );
        $response->header( 'X-WP-TotalPages', $max_pages );

        return $response;
    }

    /**
     * Create a single product
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
     * @since 1.0.0
     */
    public function create_product( $request ) {
        // Validate and sanitize input data.
        $validation_result = $this->validate_product_data( $request );
        if ( is_wp_error( $validation_result ) ) {
            return $validation_result;
        }

        $product_data = $this->sanitize_product_data( $request );

        // Create the product post.
        $post_id = wp_insert_post(
            array(
                'post_title'   => $product_data['name'],
                'post_content' => $product_data['description'],
                'post_status'  => 'publish',
                'post_type'    => 'product',
                'meta_input'   => array(
                    '_price'         => $product_data['price'],
                    '_regular_price' => $product_data['price'],
                    '_sku'           => $product_data['sku'],
                    '_stock'         => $product_data['stock_quantity'],
                    '_manage_stock'  => $product_data['manage_stock'] ? 'yes' : 'no',
                ),
            ),
            true
        );

        if ( is_wp_error( $post_id ) ) {
            return new WP_Error(
                'product_creation_failed',
                __( 'Failed to create product: ', 'wordpress-coding-challenge' ) . $post_id->get_error_message(),
                array( 'status' => 500 )
            );
        }

        // Handle categories.
        if ( ! empty( $product_data['categories'] ) ) {
            $this->assign_product_categories( $post_id, $product_data['categories'] );
        }

        // Trigger webhook for new product.
        $this->trigger_webhook( 'product.created', $post_id );

        $response = $this->prepare_product_for_response( get_post( $post_id ), $request );
        
        return rest_ensure_response( $response );
    }

    /**
     * Validate product data from request
     *
     * @param WP_REST_Request $request Request object.
     * @return true|WP_Error True if valid, WP_Error if validation fails.
     * @since 1.0.0
     */
    private function validate_product_data( $request ) {
        $errors = new WP_Error();

        // Required field validation.
        if ( empty( $request['name'] ) ) {
            $errors->add( 'missing_name', __( 'Product name is required.', 'wordpress-coding-challenge' ) );
        }

        if ( empty( $request['price'] ) ) {
            $errors->add( 'missing_price', __( 'Product price is required.', 'wordpress-coding-challenge' ) );
        } elseif ( ! is_numeric( $request['price'] ) || floatval( $request['price'] ) < 0 ) {
            $errors->add( 'invalid_price', __( 'Product price must be a positive number.', 'wordpress-coding-challenge' ) );
        }

        if ( ! empty( $request['stock_quantity'] ) && ( ! is_numeric( $request['stock_quantity'] ) || intval( $request['stock_quantity'] ) < 0 ) ) {
            $errors->add( 'invalid_stock', __( 'Stock quantity must be a non-negative integer.', 'wordpress-coding-challenge' ) );
        }

        // SKU uniqueness validation.
        if ( ! empty( $request['sku'] ) ) {
            $existing_product = $this->get_product_by_sku( $request['sku'] );
            if ( $existing_product ) {
                $errors->add( 'duplicate_sku', __( 'SKU must be unique.', 'wordpress-coding-challenge' ) );
            }
        }

        if ( $errors->has_errors() ) {
            $errors->add_data( array( 'status' => 400 ) );
            return $errors;
        }

        return true;
    }

    /**
     * Sanitize product data from request
     *
     * @param WP_REST_Request $request Request object.
     * @return array Sanitized product data.
     * @since 1.0.0
     */
    private function sanitize_product_data( $request ) {
        return array(
            'name'          => sanitize_text_field( $request['name'] ),
            'description'   => wp_kses_post( $request['description'] ?? '' ),
            'price'         => floatval( $request['price'] ),
            'sku'           => sanitize_text_field( $request['sku'] ?? '' ),
            'stock_quantity' => absint( $request['stock_quantity'] ?? 0 ),
            'manage_stock'  => rest_sanitize_boolean( $request['manage_stock'] ?? false ),
            'categories'    => $this->sanitize_categories( $request['categories'] ?? array() ),
        );
    }

    /**
     * Get product by SKU
     *
     * @param string $sku Product SKU.
     * @return WP_Post|null Product post or null if not found.
     * @since 1.0.0
     */
    private function get_product_by_sku( $sku ) {
        $posts = get_posts( array(
            'post_type'  => 'product',
            'meta_key'   => '_sku',
            'meta_value' => $sku,
            'numberposts' => 1,
        ) );

        return ! empty( $posts ) ? $posts[0] : null;
    }

    /**
     * Prepare product data for API response
     *
     * @param WP_Post         $post    Product post object.
     * @param WP_REST_Request $request Request object.
     * @return array Prepared product data.
     * @since 1.0.0
     */
    private function prepare_product_for_response( $post, $request ) {
        $price         = get_post_meta( $post->ID, '_price', true );
        $sku           = get_post_meta( $post->ID, '_sku', true );
        $stock         = get_post_meta( $post->ID, '_stock', true );
        $manage_stock  = get_post_meta( $post->ID, '_manage_stock', true );

        return array(
            'id'            => $post->ID,
            'name'          => $post->post_title,
            'description'   => $post->post_content,
            'price'         => $price ? floatval( $price ) : 0,
            'sku'           => $sku ?: '',
            'stock_quantity' => $stock ? intval( $stock ) : 0,
            'manage_stock'  => 'yes' === $manage_stock,
            'permalink'     => get_permalink( $post->ID ),
            'image'         => get_the_post_thumbnail_url( $post->ID, 'medium' ),
            'categories'    => $this->get_product_categories( $post->ID ),
            'created_date'  => $post->post_date,
            'modified_date' => $post->post_modified,
        );
    }

    /**
     * Get the product schema, conforming to JSON Schema
     *
     * @return array
     * @since 1.0.0
     */
    public function get_product_schema() {
        return array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'product',
            'type'       => 'object',
            'properties' => array(
                'name' => array(
                    'description' => __( 'Product name.', 'wordpress-coding-challenge' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                    'required'    => true,
                    'arg_options' => array(
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
                'description' => array(
                    'description' => __( 'Product description.', 'wordpress-coding-challenge' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                    'arg_options' => array(
                        'sanitize_callback' => 'wp_kses_post',
                    ),
                ),
                'price' => array(
                    'description' => __( 'Product price.', 'wordpress-coding-challenge' ),
                    'type'        => 'number',
                    'context'     => array( 'view', 'edit' ),
                    'required'    => true,
                    'minimum'     => 0,
                    'arg_options' => array(
                        'sanitize_callback' => 'floatval',
                    ),
                ),
                'sku' => array(
                    'description' => __( 'Product SKU.', 'wordpress-coding-challenge' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                    'arg_options' => array(
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
                'stock_quantity' => array(
                    'description' => __( 'Stock quantity.', 'wordpress-coding-challenge' ),
                    'type'        => 'integer',
                    'context'     => array( 'view', 'edit' ),
                    'minimum'     => 0,
                    'arg_options' => array(
                        'sanitize_callback' => 'absint',
                    ),
                ),
                'manage_stock' => array(
                    'description' => __( 'Whether to manage stock.', 'wordpress-coding-challenge' ),
                    'type'        => 'boolean',
                    'context'     => array( 'view', 'edit' ),
                    'arg_options' => array(
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ),
                ),
                'categories' => array(
                    'description' => __( 'Product categories.', 'wordpress-coding-challenge' ),
                    'type'        => 'array',
                    'items'       => array(
                        'type' => 'string',
                    ),
                    'context'     => array( 'view', 'edit' ),
                ),
            ),
        );
    }

    /**
     * Get collection parameters
     *
     * @return array
     * @since 1.0.0
     */
    public function get_collection_params() {
        return array(
            'page'      => array(
                'description'       => __( 'Current page of the collection.', 'wordpress-coding-challenge' ),
                'type'              => 'integer',
                'default'           => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => 'rest_validate_request_arg',
                'minimum'           => 1,
            ),
            'per_page'  => array(
                'description'       => __( 'Maximum number of items to be returned in result set.', 'wordpress-coding-challenge' ),
                'type'              => 'integer',
                'default'           => 10,
                'minimum'           => 1,
                'maximum'           => 100,
                'sanitize_callback' => 'absint',
                'validate_callback' => 'rest_validate_request_arg',
            ),
            'search'    => array(
                'description'       => __( 'Limit results to those matching a string.', 'wordpress-coding-challenge' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => 'rest_validate_request_arg',
            ),
            'category'  => array(
                'description'       => __( 'Limit results to specific category.', 'wordpress-coding-challenge' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => 'rest_validate_request_arg',
            ),
            'min_price' => array(
                'description'       => __( 'Minimum product price.', 'wordpress-coding-challenge' ),
                'type'              => 'number',
                'minimum'           => 0,
                'sanitize_callback' => 'floatval',
                'validate_callback' => 'rest_validate_request_arg',
            ),
            'max_price' => array(
                'description'       => __( 'Maximum product price.', 'wordpress-coding-challenge' ),
                'type'              => 'number',
                'minimum'           => 0,
                'sanitize_callback' => 'floatval',
                'validate_callback' => 'rest_validate_request_arg',
            ),
        );
    }

    // Additional methods for other endpoints would follow the same pattern...
    // get_product(), update_product(), delete_product(), upload_product_image(), etc.

    /**
     * Trigger webhook for product events
     *
     * @param string $event Event name.
     * @param int    $product_id Product ID.
     * @since 1.0.0
     */
    private function trigger_webhook( $event, $product_id ) {
        $webhook_url = apply_filters( 'product_api_webhook_url', '' );
        
        if ( empty( $webhook_url ) ) {
            return;
        }

        $payload = array(
            'event'      => $event,
            'product_id' => $product_id,
            'timestamp'  => current_time( 'mysql' ),
            'site_url'   => home_url(),
        );

        wp_remote_post(
            $webhook_url,
            array(
                'body'    => wp_json_encode( $payload ),
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'X-Webhook-Signature' => $this->generate_webhook_signature( $payload ),
                ),
                'timeout' => 10,
            )
        );
    }

    /**
     * Generate webhook signature for security
     *
     * @param array $payload Webhook payload.
     * @return string
     * @since 1.0.0
     */
    private function generate_webhook_signature( $payload ) {
        $secret = apply_filters( 'product_api_webhook_secret', '' );
        return hash_hmac( 'sha256', wp_json_encode( $payload ), $secret );
    }
}

// Initialize the REST API controller.
new Product_REST_API_Controller();