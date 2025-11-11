<?php
/**
 * Challenge 3: Advanced Modular Hook System
 * 
 * Problem Statement:
 * Create an advanced hook system that extends WordPress core functionality with:
 * 
 * 1. Priority-based hook execution with dependency resolution
 * 2. Dependency injection container for better testability
 * 3. Conditional hook registration based on context
 * 4. Hook debugging and performance monitoring
 * 5. Async hook processing for long-running operations
 * 6. Hook dependency management and conflict resolution
 * 7. Comprehensive hook documentation and introspection
 * 8. Safe hook removal and modification system
 * 
 * @package wp-advance-challange
 * @version 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Dependency Injection Container
 * 
 * Manages class dependencies and provides dependency injection
 * for hook callbacks and services.
 * 
 * @since 1.0.0
 */
class Dependency_Container {

    /**
     * Registered services and their definitions
     *
     * @var array
     */
    private $services = array();

    /**
     * Shared service instances
     *
     * @var array
     */
    private $instances = array();

    /**
     * Register a service with the container
     *
     * @param string   $service_name Service name.
     * @param callable $factory      Factory function that returns the service instance.
     * @param bool     $shared       Whether to share the instance (singleton).
     * @since 1.0.0
     */
    public function register( $service_name, $factory, $shared = true ) {
        $this->services[ $service_name ] = array(
            'factory' => $factory,
            'shared'  => $shared,
        );
    }

    /**
     * Get a service from the container
     *
     * @param string $service_name Service name.
     * @return mixed Service instance.
     * @throws Exception If service is not found.
     * @since 1.0.0
     */
    public function get( $service_name ) {
        if ( ! isset( $this->services[ $service_name ] ) ) {
            throw new Exception( sprintf( 'Service %s not found in container.', $service_name ) );
        }

        $service = $this->services[ $service_name ];

        if ( $service['shared'] && isset( $this->instances[ $service_name ] ) ) {
            return $this->instances[ $service_name ];
        }

        $instance = call_user_func( $service['factory'], $this );

        if ( $service['shared'] ) {
            $this->instances[ $service_name ] = $instance;
        }

        return $instance;
    }

    /**
     * Check if a service exists in the container
     *
     * @param string $service_name Service name.
     * @return bool True if service exists.
     * @since 1.0.0
     */
    public function has( $service_name ) {
        return isset( $this->services[ $service_name ] );
    }
}

/**
 * Advanced Hook Manager
 * 
 * Extends WordPress hooks with dependency injection,
 * conditional execution, and performance monitoring.
 * 
 * @since 1.0.0
 */
class Advanced_Hook_Manager {

    /**
     * Dependency container instance
     *
     * @var Dependency_Container
     */
    private $container;

    /**
     * Registered hooks with metadata
     *
     * @var array
     */
    private $hooks = array();

    /**
     * Performance metrics
     *
     * @var array
     */
    private $performance_metrics = array();

    /**
     * Debug mode flag
     *
     * @var bool
     */
    private $debug_mode = false;

    /**
     * Constructor
     *
     * @param Dependency_Container $container Dependency container.
     * @since 1.0.0
     */
    public function __construct( Dependency_Container $container ) {
        $this->container = $container;
        
        // Enable debug mode if WP_DEBUG is enabled.
        $this->debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;
        
        add_action( 'shutdown', array( $this, 'log_performance_metrics' ) );
    }

    /**
     * Register a hook with advanced features
     *
     * @param string   $hook_name      Hook name.
     * @param callable $callback       Callback function.
     * @param int      $priority       Execution priority.
     * @param int      $accepted_args  Number of accepted arguments.
     * @param array    $dependencies   Service dependencies for DI.
     * @param callable $condition      Conditional execution check.
     * @param bool     $async          Whether to execute asynchronously.
     * @since 1.0.0
     */
    public function register_hook( $hook_name, $callback, $priority = 10, $accepted_args = 1, $dependencies = array(), $condition = null, $async = false ) {
        $hook_id = $this->generate_hook_id( $hook_name, $callback, $priority );

        // Store hook metadata.
        $this->hooks[ $hook_id ] = array(
            'hook_name'     => $hook_name,
            'callback'      => $callback,
            'priority'      => $priority,
            'accepted_args' => $accepted_args,
            'dependencies'  => $dependencies,
            'condition'     => $condition,
            'async'         => $async,
            'registered'    => current_time( 'mysql' ),
        );

        // Register with WordPress.
        add_filter( $hook_name, array( $this, 'execute_hook' ), $priority, $accepted_args );

        /**
         * Fires after a hook is registered with the advanced system
         *
         * @param string $hook_id Hook identifier.
         * @param array  $hook_data Hook configuration data.
         * @since 1.0.0
         */
        do_action( 'advanced_hook_registered', $hook_id, $this->hooks[ $hook_id ] );
    }

    /**
     * Execute hook with dependency injection and condition checking
     *
     * @param mixed $value Original filter value.
     * @return mixed Modified value.
     * @since 1.0.0
     */
    public function execute_hook( $value ) {
        $current_filter = current_filter();
        $args           = func_get_args();
        $hook_id        = $this->find_hook_id( $current_filter, $args );

        if ( ! $hook_id ) {
            return $value;
        }

        $hook_data = $this->hooks[ $hook_id ];

        // Check condition if provided.
        if ( is_callable( $hook_data['condition'] ) && ! call_user_func( $hook_data['condition'] ) ) {
            return $value;
        }

        // Handle async execution.
        if ( $hook_data['async'] ) {
            $this->execute_async( $hook_id, $args );
            return $value;
        }

        // Execute synchronously with performance monitoring.
        $start_time = microtime( true );

        try {
            $callback = $this->prepare_callback( $hook_data['callback'], $hook_data['dependencies'] );
            $result   = call_user_func_array( $callback, $args );
        } catch ( Exception $e ) {
            $this->log_hook_error( $hook_id, $e );
            return $value;
        }

        $execution_time = microtime( true ) - $start_time;

        // Store performance metrics.
        $this->record_performance( $hook_id, $execution_time );

        return $result;
    }

    /**
     * Prepare callback with dependency injection
     *
     * @param callable $callback     Original callback.
     * @param array    $dependencies Service dependencies.
     * @return callable Prepared callback.
     * @since 1.0.0
     */
    private function prepare_callback( $callback, $dependencies ) {
        if ( empty( $dependencies ) ) {
            return $callback;
        }

        return function () use ( $callback, $dependencies ) {
            $args = func_get_args();
            
            // Inject dependencies.
            foreach ( $dependencies as $dependency ) {
                if ( $this->container->has( $dependency ) ) {
                    $args[] = $this->container->get( $dependency );
                }
            }

            return call_user_func_array( $callback, $args );
        };
    }

    /**
     * Execute hook asynchronously
     *
     * @param string $hook_id Hook identifier.
     * @param array  $args    Hook arguments.
     * @since 1.0.0
     */
    private function execute_async( $hook_id, $args ) {
        $data = array(
            'hook_id' => $hook_id,
            'args'    => $args,
            'time'    => time(),
        );

        // Store async task in database.
        $task_id = wp_insert_post( array(
            'post_type'    => 'async_task',
            'post_status'  => 'pending',
            'post_content' => wp_json_encode( $data ),
            'post_title'   => sprintf( 'Async hook: %s', $hook_id ),
        ) );

        if ( $task_id ) {
            // Schedule immediate background processing.
            wp_schedule_single_event( time() + 1, 'process_async_hook', array( $task_id ) );
        }
    }

    /**
     * Process async hook execution
     *
     * @param int $task_id Async task post ID.
     * @since 1.0.0
     */
    public function process_async_hook( $task_id ) {
        $task = get_post( $task_id );
        
        if ( ! $task || 'async_task' !== $task->post_type ) {
            return;
        }

        $data = json_decode( $task->post_content, true );
        
        if ( ! isset( $data['hook_id'], $data['args'] ) ) {
            return;
        }

        $hook_data = $this->hooks[ $data['hook_id'] ] ?? null;
        
        if ( ! $hook_data ) {
            return;
        }

        try {
            $callback = $this->prepare_callback( $hook_data['callback'], $hook_data['dependencies'] );
            call_user_func_array( $callback, $data['args'] );
            
            // Mark task as completed.
            wp_update_post( array(
                'ID'          => $task_id,
                'post_status' => 'completed',
            ) );
        } catch ( Exception $e ) {
            $this->log_hook_error( $data['hook_id'], $e );
            
            wp_update_post( array(
                'ID'          => $task_id,
                'post_status' => 'failed',
            ) );
        }
    }

    /**
     * Remove a hook safely
     *
     * @param string   $hook_name Hook name.
     * @param callable $callback  Callback function.
     * @param int      $priority  Execution priority.
     * @return bool True if hook was removed.
     * @since 1.0.0
     */
    public function remove_hook( $hook_name, $callback, $priority = 10 ) {
        $hook_id = $this->generate_hook_id( $hook_name, $callback, $priority );

        if ( ! isset( $this->hooks[ $hook_id ] ) ) {
            return false;
        }

        // Remove from WordPress.
        $result = remove_filter( $hook_name, array( $this, 'execute_hook' ), $priority );

        if ( $result ) {
            unset( $this->hooks[ $hook_id ] );
            
            /**
             * Fires after a hook is removed
             *
             * @param string $hook_id Hook identifier.
             * @since 1.0.0
             */
            do_action( 'advanced_hook_removed', $hook_id );
        }

        return $result;
    }

    /**
     * Get hook performance statistics
     *
     * @param string $hook_id Hook identifier.
     * @return array Performance data.
     * @since 1.0.0
     */
    public function get_hook_performance( $hook_id ) {
        return $this->performance_metrics[ $hook_id ] ?? array(
            'count'          => 0,
            'total_time'     => 0,
            'average_time'   => 0,
            'last_execution' => null,
        );
    }

    /**
     * Get all registered hooks
     *
     * @return array Registered hooks data.
     * @since 1.0.0
     */
    public function get_registered_hooks() {
        return $this->hooks;
    }

    /**
     * Generate unique hook identifier
     *
     * @param string   $hook_name Hook name.
     * @param callable $callback  Callback function.
     * @param int      $priority  Execution priority.
     * @return string Hook identifier.
     * @since 1.0.0
     */
    private function generate_hook_id( $hook_name, $callback, $priority ) {
        $callback_hash = is_array( $callback ) 
            ? md5( get_class( $callback[0] ) . $callback[1] )
            : md5( $callback );

        return md5( $hook_name . $callback_hash . $priority );
    }

    /**
     * Find hook ID for current execution context
     *
     * @param string $hook_name Hook name.
     * @param array  $args      Hook arguments.
     * @return string|null Hook ID or null if not found.
     * @since 1.0.0
     */
    private function find_hook_id( $hook_name, $args ) {
        foreach ( $this->hooks as $hook_id => $hook_data ) {
            if ( $hook_data['hook_name'] === $hook_name ) {
                return $hook_id;
            }
        }
        return null;
    }

    /**
     * Record hook performance metrics
     *
     * @param string $hook_id        Hook identifier.
     * @param float  $execution_time Execution time in seconds.
     * @since 1.0.0
     */
    private function record_performance( $hook_id, $execution_time ) {
        if ( ! isset( $this->performance_metrics[ $hook_id ] ) ) {
            $this->performance_metrics[ $hook_id ] = array(
                'count'          => 0,
                'total_time'     => 0,
                'average_time'   => 0,
                'last_execution' => null,
            );
        }

        $metrics = &$this->performance_metrics[ $hook_id ];
        
        $metrics['count']++;
        $metrics['total_time'] += $execution_time;
        $metrics['average_time'] = $metrics['total_time'] / $metrics['count'];
        $metrics['last_execution'] = current_time( 'mysql' );
    }

    /**
     * Log hook execution error
     *
     * @param string    $hook_id Hook identifier.
     * @param Exception $error   Error object.
     * @since 1.0.0
     */
    private function log_hook_error( $hook_id, Exception $error ) {
        error_log( sprintf(
            'Hook execution error [%s]: %s in %s:%d',
            $hook_id,
            $error->getMessage(),
            $error->getFile(),
            $error->getLine()
        ) );

        if ( $this->debug_mode ) {
            /**
             * Fires when a hook execution error occurs in debug mode
             *
             * @param string    $hook_id Hook identifier.
             * @param Exception $error   Error object.
             * @since 1.0.0
             */
            do_action( 'advanced_hook_error', $hook_id, $error );
        }
    }

    /**
     * Log performance metrics at shutdown
     *
     * @since 1.0.0
     */
    public function log_performance_metrics() {
        if ( ! $this->debug_mode || empty( $this->performance_metrics ) ) {
            return;
        }

        $log_entry = array(
            'timestamp' => current_time( 'mysql' ),
            'metrics'   => $this->performance_metrics,
        );

        // Store in transient for debugging.
        set_transient( 'advanced_hook_performance_' . date( 'Y-m-d' ), $log_entry, DAY_IN_SECONDS );
    }
}

/**
 * Hook Condition Helpers
 * 
 * Provides common condition functions for conditional hook execution.
 * 
 * @since 1.0.0
 */
class Hook_Conditions {

    /**
     * Check if current user has specific capability
     *
     * @param string $capability Capability to check.
     * @return callable Condition function.
     * @since 1.0.0
     */
    public static function user_can( $capability ) {
        return function () use ( $capability ) {
            return current_user_can( $capability );
        };
    }

    /**
     * Check if current page is admin page
     *
     * @return callable Condition function.
     * @since 1.0.0
     */
    public static function is_admin_page() {
        return function () {
            return is_admin();
        };
    }

    /**
     * Check if current page is frontend
     *
     * @return callable Condition function.
     * @since 1.0.0
     */
    public static function is_frontend() {
        return function () {
            return ! is_admin();
        };
    }

    /**
     * Check if specific plugin is active
     *
     * @param string $plugin Plugin file.
     * @return callable Condition function.
     * @since 1.0.0
     */
    public static function plugin_active( $plugin ) {
        return function () use ( $plugin ) {
            return is_plugin_active( $plugin );
        };
    }

    /**
     * Check if current post type matches
     *
     * @param string $post_type Post type to check.
     * @return callable Condition function.
     * @since 1.0.0
     */
    public static function is_post_type( $post_type ) {
        return function () use ( $post_type ) {
            global $post;
            return $post && $post->post_type === $post_type;
        };
    }
}

// Initialize the advanced hook system.
$dependency_container = new Dependency_Container();
$advanced_hook_manager = new Advanced_Hook_Manager( $dependency_container );

// Example usage:
// $advanced_hook_manager->register_hook(
//     'the_content',
//     array( $content_processor, 'filter_content' ),
//     10,
//     1,
//     array( 'content_parser', 'asset_manager' ),
//     Hook_Conditions::is_frontend(),
//     false
// );