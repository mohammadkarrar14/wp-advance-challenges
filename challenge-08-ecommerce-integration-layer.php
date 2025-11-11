<?php
/**
 * Challenge 8: Advanced E-commerce Integration Layer
 * 
 * Problem Statement:
 * Create a comprehensive e-commerce integration system that extends
 * WooCommerce with advanced features including:
 * 
 * 1. Multi-provider payment gateway integration with fallback support
 * 2. Subscription management with recurring billing and dunning management
 * 3. Advanced tax calculation engine with multi-jurisdiction support
 * 4. Order fulfillment workflow with shipping provider integration
 * 5. Inventory synchronization across multiple sales channels
 * 6. Customer loyalty and rewards program integration
 * 7. Advanced analytics and reporting dashboard
 * 8. Abandoned cart recovery and marketing automation
 * 
 * @package wp-advance-challange
 * @version 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Check if WooCommerce is active.
if ( ! class_exists( 'WooCommerce' ) ) {
    return;
}

/**
 * Advanced E-commerce Integration Manager
 * 
 * Extends WooCommerce with advanced payment, subscription,
 * tax, and fulfillment features.
 * 
 * @since 1.0.0
 */
class Advanced_Ecommerce_Integration_Manager {

    /**
     * Payment gateway manager instance
     *
     * @var Payment_Gateway_Manager
     */
    private $payment_manager;

    /**
     * Subscription manager instance
     *
     * @var Subscription_Manager
     */
    private $subscription_manager;

    /**
     * Tax calculator instance
     *
     * @var Advanced_Tax_Calculator
     */
    private $tax_calculator;

    /**
     * Fulfillment manager instance
     *
     * @var Fulfillment_Manager
     */
    private $fulfillment_manager;

    /**
     * Analytics manager instance
     *
     * @var Analytics_Manager
     */
    private $analytics_manager;

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->payment_manager     = new Payment_Gateway_Manager();
        $this->subscription_manager = new Subscription_Manager();
        $this->tax_calculator      = new Advanced_Tax_Calculator();
        $this->fulfillment_manager = new Fulfillment_Manager();
        $this->analytics_manager   = new Analytics_Manager();

        add_action( 'woocommerce_init', array( $this, 'init_integrations' ) );
        add_action( 'wp_ajax_nopriv_handle_webhook', array( $this, 'handle_webhook' ) );
        add_action( 'wp_ajax_handle_webhook', array( $this, 'handle_webhook' ) );
    }

    /**
     * Initialize all e-commerce integrations
     *
     * @since 1.0.0
     */
    public function init_integrations() {
        // Register custom payment gateways.
        add_filter( 'woocommerce_payment_gateways', array( $this->payment_manager, 'register_gateways' ) );

        // Hook into order lifecycle.
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'handle_new_order' ), 10, 3 );
        add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_change' ), 10, 4 );

        // Tax calculation hooks.
        add_action( 'woocommerce_calculate_totals', array( $this->tax_calculator, 'calculate_taxes' ) );
        add_filter( 'woocommerce_apply_base_tax_for_local_pickup', '__return_false' );

        // Subscription hooks.
        add_action( 'woocommerce_scheduled_subscription_payment', array( $this->subscription_manager, 'process_renewal_payment' ), 10, 2 );
        add_action( 'woocommerce_subscription_status_updated', array( $this->subscription_manager, 'handle_subscription_status_change' ), 10, 3 );

        // Analytics hooks.
        add_action( 'woocommerce_new_order', array( $this->analytics_manager, 'track_order_created' ) );
        add_action( 'woocommerce_order_status_completed', array( $this->analytics_manager, 'track_order_completed' ) );
    }

    /**
     * Handle new order creation
     *
     * @param int    $order_id Order ID.
     * @param array  $posted_data Posted checkout data.
     * @param object $order Order object.
     * @since 1.0.0
     */
    public function handle_new_order( $order_id, $posted_data, $order ) {
        // Process payment with fallback logic.
        $this->payment_manager->process_order_payment( $order );

        // Initialize subscription if applicable.
        if ( $this->subscription_manager->order_contains_subscription( $order ) ) {
            $this->subscription_manager->create_subscription_from_order( $order );
        }

        // Queue fulfillment processing.
        $this->fulfillment_manager->queue_order_fulfillment( $order );

        // Track order in analytics.
        $this->analytics_manager->track_order_created( $order_id );
    }

    /**
     * Handle order status changes
     *
     * @param int      $order_id Order ID.
     * @param string   $old_status Old status.
     * @param string   $new_status New status.
     * @param WC_Order $order Order object.
     * @since 1.0.0
     */
    public function handle_order_status_change( $order_id, $old_status, $new_status, $order ) {
        // Handle fulfillment when order is processing.
        if ( 'processing' === $new_status ) {
            $this->fulfillment_manager->process_order_fulfillment( $order );
        }

        // Handle completed order analytics.
        if ( 'completed' === $new_status ) {
            $this->analytics_manager->track_order_completed( $order_id );
        }

        // Handle cancellation and refunds.
        if ( 'cancelled' === $new_status ) {
            $this->payment_manager->handle_order_cancellation( $order );
        }
    }

    /**
     * Handle incoming webhooks from payment providers and other services
     *
     * @since 1.0.0
     */
    public function handle_webhook() {
        $payload = file_get_contents( 'php://input' );
        $headers = getallheaders();

        // Verify webhook signature.
        if ( ! $this->verify_webhook_signature( $headers, $payload ) ) {
            wp_die( 'Invalid webhook signature', 401 );
        }

        $data = json_decode( $payload, true );

        if ( JSON_ERROR_NONE !== json_last_error() ) {
            wp_die( 'Invalid JSON payload', 400 );
        }

        // Route webhook to appropriate handler.
        $event_type = $data['type'] ?? '';

        switch ( $event_type ) {
            case 'payment_intent.succeeded':
                $this->payment_manager->handle_payment_success( $data );
                break;

            case 'payment_intent.failed':
                $this->payment_manager->handle_payment_failure( $data );
                break;

            case 'charge.refunded':
                $this->payment_manager->handle_refund_webhook( $data );
                break;

            case 'subscription.updated':
                $this->subscription_manager->handle_subscription_webhook( $data );
                break;

            case 'fulfillment.shipped':
                $this->fulfillment_manager->handle_shipment_webhook( $data );
                break;

            default:
                // Allow other plugins to handle custom webhook types.
                do_action( 'advanced_ecommerce_webhook_' . $event_type, $data, $headers );
                break;
        }

        wp_die( 'Webhook processed', 200 );
    }

    /**
     * Verify webhook signature for security
     *
     * @param array  $headers HTTP headers.
     * @param string $payload Webhook payload.
     * @return bool True if signature is valid.
     * @since 1.0.0
     */
    private function verify_webhook_signature( $headers, $payload ) {
        $signature = $headers['Stripe-Signature'] ?? $headers['X-Signature'] ?? '';

        if ( empty( $signature ) ) {
            return false;
        }

        $secret = get_option( 'webhook_signing_secret' );

        if ( empty( $secret ) ) {
            return false;
        }

        $expected_signature = hash_hmac( 'sha256', $payload, $secret );

        return hash_equals( $expected_signature, $signature );
    }

    /**
     * Get integration status report
     *
     * @return array Integration status data.
     * @since 1.0.0
     */
    public function get_integration_status() {
        return array(
            'payment_gateways' => $this->payment_manager->get_gateway_status(),
            'subscriptions'    => $this->subscription_manager->get_subscription_stats(),
            'tax_calculation'  => $this->tax_calculator->get_tax_config_status(),
            'fulfillment'      => $this->fulfillment_manager->get_fulfillment_status(),
            'analytics'        => $this->analytics_manager->get_analytics_status(),
        );
    }
}

/**
 * Payment Gateway Manager
 * 
 * Manages multiple payment gateways with fallback support
 * and advanced features.
 * 
 * @since 1.0.0
 */
class Payment_Gateway_Manager {

    /**
     * Registered payment gateways
     *
     * @var array
     */
    private $gateways = array();

    /**
     * Active gateway configuration
     *
     * @var array
     */
    private $active_gateways = array();

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->register_gateways();
        $this->load_active_gateways();
    }

    /**
     * Register custom payment gateways
     *
     * @param array $gateways Existing gateways.
     * @return array Modified gateways array.
     * @since 1.0.0
     */
    public function register_gateways( $gateways = array() ) {
        $custom_gateways = array(
            'WC_Stripe_Advanced_Gateway',
            'WC_PayPal_Pro_Gateway',
            'WC_Authorize_Net_Gateway',
            'WC_Square_Gateway',
        );

        foreach ( $custom_gateways as $gateway_class ) {
            if ( class_exists( $gateway_class ) && ! in_array( $gateway_class, $gateways, true ) ) {
                $gateways[] = $gateway_class;
            }
        }

        return $gateways;
    }

    /**
     * Load active gateways configuration
     *
     * @since 1.0.0
     */
    private function load_active_gateways() {
        $available_gateways = WC()->payment_gateways->payment_gateways();

        foreach ( $available_gateways as $id => $gateway ) {
            if ( 'yes' === $gateway->enabled ) {
                $this->active_gateways[ $id ] = $gateway;
            }
        }

        // Sort by priority.
        uasort( $this->active_gateways, function( $a, $b ) {
            return $a->priority - $b->priority;
        } );
    }

    /**
     * Process order payment with fallback support
     *
     * @param WC_Order $order Order object.
     * @return bool True if payment was successful.
     * @since 1.0.0
     */
    public function process_order_payment( $order ) {
        $payment_method = $order->get_payment_method();

        // Try primary payment method first.
        if ( isset( $this->active_gateways[ $payment_method ] ) ) {
            $result = $this->process_with_gateway( $this->active_gateways[ $payment_method ], $order );

            if ( $result['success'] ) {
                $this->log_payment_success( $order, $payment_method, $result );
                return true;
            }

            $this->log_payment_failure( $order, $payment_method, $result['error'] );
        }

        // Fallback to secondary payment methods.
        foreach ( $this->active_gateways as $gateway_id => $gateway ) {
            if ( $gateway_id === $payment_method ) {
                continue; // Skip the failed primary method.
            }

            if ( $this->is_fallback_gateway( $gateway_id ) ) {
                $result = $this->process_with_gateway( $gateway, $order );

                if ( $result['success'] ) {
                    $order->set_payment_method( $gateway_id );
                    $order->save();

                    $this->log_payment_fallback_success( $order, $gateway_id, $result );
                    return true;
                }
            }
        }

        // All payment attempts failed.
        $order->update_status( 'failed', __( 'All payment attempts failed.', 'wordpress-coding-challenge' ) );
        return false;
    }

    /**
     * Process payment with specific gateway
     *
     * @param WC_Payment_Gateway $gateway Payment gateway.
     * @param WC_Order           $order   Order object.
     * @return array Payment result.
     * @since 1.0.0
     */
    private function process_with_gateway( $gateway, $order ) {
        try {
            $result = $gateway->process_payment( $order->get_id() );

            if ( isset( $result['result'] ) && 'success' === $result['result'] ) {
                return array(
                    'success'      => true,
                    'transaction_id' => $result['transaction_id'] ?? '',
                    'redirect'     => $result['redirect'] ?? '',
                );
            }

            return array(
                'success' => false,
                'error'   => $result['message'] ?? 'Payment processing failed',
            );

        } catch ( Exception $e ) {
            return array(
                'success' => false,
                'error'   => $e->getMessage(),
            );
        }
    }

    /**
     * Check if gateway is configured as fallback
     *
     * @param string $gateway_id Gateway ID.
     * @return bool True if gateway is fallback.
     * @since 1.0.0
     */
    private function is_fallback_gateway( $gateway_id ) {
        $fallback_gateways = get_option( 'payment_fallback_gateways', array() );
        return in_array( $gateway_id, $fallback_gateways, true );
    }

    /**
     * Handle payment success webhook
     *
     * @param array $data Webhook data.
     * @since 1.0.0
     */
    public function handle_payment_success( $data ) {
        $order_id = $data['data']['object']['metadata']['order_id'] ?? 0;

        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            
            if ( $order ) {
                $order->payment_complete( $data['data']['object']['id'] );
                $this->log_webhook_payment_success( $order, $data );
            }
        }
    }

    /**
     * Get gateway status report
     *
     * @return array Gateway status data.
     * @since 1.0.0
     */
    public function get_gateway_status() {
        $status = array();

        foreach ( $this->active_gateways as $gateway_id => $gateway ) {
            $status[ $gateway_id ] = array(
                'title'     => $gateway->title,
                'enabled'   => true,
                'test_mode' => $gateway->testmode ?? false,
                'currency'  => $gateway->get_selected_currency(),
            );
        }

        return $status;
    }
}

// Initialize the e-commerce integration manager.
$advanced_ecommerce_integration = new Advanced_Ecommerce_Integration_Manager();