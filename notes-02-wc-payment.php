<?php
/**
 * Plugin Name: Dummy Bank Payment Gateway
 * Description: Fully working fake bank payment gateway for WooCommerce testing/interviews/training.
 * Author: Your Name
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'plugins_loaded', 'dbank_gateway_init', 11 );

function dbank_gateway_init() {

    if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

    class WC_Gateway_DummyBank extends WC_Payment_Gateway {

        public function __construct() {

            $this->id                 = 'dummy_bank';
            $this->method_title       = 'Dummy Bank Gateway';
            $this->method_description = 'Simulated bank payment gateway for WooCommerce testing.';
            $this->icon               = ''; 
            $this->has_fields         = false;

            // Load settings
            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );
            $this->api_key     = $this->get_option( 'api_key' );

            // Save admin options
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id,
                array($this, 'process_admin_options')
            );

            // Listen for callback
            add_action( 'init', array( $this, 'dbank_callback_handler' ) );
        }

        public function init_form_fields() {

            $this->form_fields = array(
                'enabled' => array(
                    'title'   => 'Enable',
                    'type'    => 'checkbox',
                    'label'   => 'Enable Dummy Bank Gateway',
                    'default' => 'yes'
                ),
                'title' => array(
                    'title'   => 'Title',
                    'type'    => 'text',
                    'default' => 'Dummy Bank Payment'
                ),
                'description' => array(
                    'title'   => 'Description',
                    'type'    => 'textarea',
                    'default' => 'Pay using Dummy Bank'
                ),
                'api_key' => array(
                    'title'   => 'API Key (Fake)',
                    'type'    => 'text',
                    'default' => 'test123'
                ),
            );
        }

        // Step 1: On place order → Redirect to bank simulation page
        public function process_payment( $order_id ) {

            $order = wc_get_order( $order_id );

            return array(
                'result'   => 'success',
                'redirect' => add_query_arg(
                    array(
                        'dbank_payment' => '1',
                        'order_id'      => $order_id,
                        'key'           => $order->get_order_key()
                    ),
                    wc_get_checkout_url()
                )
            );
        }

        // Step 2: Display dummy bank page
        public function dbank_callback_handler() {

            if ( isset($_GET['dbank_payment']) ) {

                $order_id = intval( $_GET['order_id'] );
                $order    = wc_get_order( $order_id );

                if ( ! $order ) wp_die('Order not found.');

                echo "<h2>Dummy Bank Payment</h2>";
                echo "<p>Order #{$order_id}</p>";
                echo "<p>Amount: " . wc_price( $order->get_total() ) . "</p>";
                echo "<p>Simulate Result:</p>";

                echo '<a href="' . esc_url( $this->return_url($order, true) ) . '" style="margin-right:20px;padding:12px;background:#28a745;color:#fff;text-decoration:none;">Success</a>';

                echo '<a href="' . esc_url( $this->return_url($order, false) ) . '" style="padding:12px;background:#dc3545;color:#fff;text-decoration:none;">Failed</a>';

                exit;
            }

            // Step 3: Bank returns success/fail
            if ( isset($_GET['dbank_return']) ) {

                $order_id = intval( $_GET['order_id'] );
                $status   = sanitize_text_field( $_GET['dbank_return'] );
                $order    = wc_get_order( $order_id );

                if ( $status == 'success' ) {
                    $order->payment_complete();
                } else {
                    $order->update_status('failed', 'Dummy Bank Payment Failed');
                }

                wp_redirect( $order->get_checkout_order_received_url() );
                exit;
            }
        }

        // Helper URL builder
        private function return_url( $order, $success = true ) {
            return add_query_arg(
                array(
                    'dbank_return' => $success ? 'success' : 'fail',
                    'order_id'     => $order->get_id()
                ),
                home_url('/')
            );
        }
    }
}

// Register gateway
add_filter( 'woocommerce_payment_gateways', function( $methods ) {
    $methods[] = 'WC_Gateway_DummyBank';
    return $methods;
});
