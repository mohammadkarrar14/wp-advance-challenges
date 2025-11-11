<?php
/**
 * Challenge 7: Advanced User Role and Capability System
 * 
 * Problem Statement:
 * Create a comprehensive user role management system that extends
 * WordPress capabilities with:
 * 
 * 1. Granular custom capabilities for specific functionality
 * 2. Role inheritance and hierarchy management
 * 3. Temporary permissions and time-based access
 * 4. Comprehensive audit logging for user actions
 * 5. Bulk user management and role assignment
 * 6. Custom capability groups and permission sets
 * 7. User role conflict detection and resolution
 * 8. Integration with WooCommerce and other plugins
 * 
 * @package wp-advance-challange
 * @version 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Advanced User Role Manager
 * 
 * Extends WordPress user roles with advanced capabilities,
 * inheritance, and comprehensive management features.
 * 
 * @since 1.0.0
 */
class Advanced_User_Role_Manager {

    /**
     * Custom capabilities registry
     *
     * @var array
     */
    private $custom_capabilities = array();

    /**
     * Role inheritance rules
     *
     * @var array
     */
    private $role_inheritance = array();

    /**
     * Temporary permissions store
     *
     * @var array
     */
    private $temporary_permissions = array();

    /**
     * Audit logger instance
     *
     * @var User_Audit_Logger
     */
    private $audit_logger;

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->audit_logger = new User_Audit_Logger();
        
        add_action( 'init', array( $this, 'register_custom_capabilities' ) );
        add_action( 'user_has_cap', array( $this, 'check_temporary_permissions' ), 10, 4 );
        add_action( 'set_user_role', array( $this, 'log_role_change' ), 10, 3 );
        
        // WP-CLI commands.
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'user-role bulk-assign', array( $this, 'cli_bulk_assign_roles' ) );
            WP_CLI::add_command( 'user-role audit-report', array( $this, 'cli_audit_report' ) );
        }
    }

    /**
     * Register custom capabilities for the site
     *
     * @since 1.0.0
     */
    public function register_custom_capabilities() {
        $this->custom_capabilities = array(
            // Content Management.
            'manage_news' => array(
                'label'       => __( 'Manage News', 'wordpress-coding-challenge' ),
                'description' => __( 'Can create, edit, and publish news articles', 'wordpress-coding-challenge' ),
                'group'       => 'content',
            ),
            'manage_events' => array(
                'label'       => __( 'Manage Events', 'wordpress-coding-challenge' ),
                'description' => __( 'Can create, edit, and publish events', 'wordpress-coding-challenge' ),
                'group'       => 'content',
            ),

            // E-commerce.
            'manage_shop_settings' => array(
                'label'       => __( 'Manage Shop Settings', 'wordpress-coding-challenge' ),
                'description' => __( 'Can modify e-commerce settings', 'wordpress-coding-challenge' ),
                'group'       => 'ecommerce',
            ),
            'view_sales_reports' => array(
                'label'       => __( 'View Sales Reports', 'wordpress-coding-challenge' ),
                'description' => __( 'Can access and view sales analytics', 'wordpress-coding-challenge' ),
                'group'       => 'ecommerce',
            ),

            // System.
            'manage_security' => array(
                'label'       => __( 'Manage Security', 'wordpress-coding-challenge' ),
                'description' => __( 'Can configure security settings', 'wordpress-coding-challenge' ),
                'group'       => 'system',
            ),
            'view_audit_logs' => array(
                'label'       => __( 'View Audit Logs', 'wordpress-coding-challenge' ),
                'description' => __( 'Can access system audit logs', 'wordpress-coding-challenge' ),
                'group'       => 'system',
            ),
        );

        /**
         * Filter to modify custom capabilities before registration
         *
         * @param array $custom_capabilities Custom capabilities array.
         * @since 1.0.0
         */
        $this->custom_capabilities = apply_filters( 'advanced_user_roles_custom_capabilities', $this->custom_capabilities );

        // Register capabilities with roles.
        $this->assign_capabilities_to_roles();
    }

    /**
     * Assign custom capabilities to appropriate roles
     *
     * @since 1.0.0
     */
    private function assign_capabilities_to_roles() {
        global $wp_roles;

        if ( ! isset( $wp_roles ) ) {
            $wp_roles = new WP_Roles();
        }

        // Administrator gets all capabilities.
        foreach ( $this->custom_capabilities as $capability => $cap_data ) {
            $wp_roles->add_cap( 'administrator', $capability );
        }

        // Editor gets content management capabilities.
        $editor_caps = array_filter( $this->custom_capabilities, function( $cap_data ) {
            return 'content' === $cap_data['group'];
        } );

        foreach ( array_keys( $editor_caps ) as $capability ) {
            $wp_roles->add_cap( 'editor', $capability );
        }

        // Shop Manager gets e-commerce capabilities.
        if ( $wp_roles->is_role( 'shop_manager' ) ) {
            $ecommerce_caps = array_filter( $this->custom_capabilities, function( $cap_data ) {
                return 'ecommerce' === $cap_data['group'];
            } );

            foreach ( array_keys( $ecommerce_caps ) as $capability ) {
                $wp_roles->add_cap( 'shop_manager', $capability );
            }
        }
    }

    /**
     * Create a new custom role with specific capabilities
     *
     * @param string $role_slug       Role slug.
     * @param string $role_name       Display name.
     * @param array  $capabilities    Array of capabilities.
     * @param string $parent_role     Parent role for inheritance.
     * @return bool True if role was created.
     * @since 1.0.0
     */
    public function create_custom_role( $role_slug, $role_name, $capabilities = array(), $parent_role = '' ) {
        if ( empty( $role_slug ) || empty( $role_name ) ) {
            return false;
        }

        // Check if role already exists.
        if ( $this->role_exists( $role_slug ) ) {
            return false;
        }

        $default_capabilities = array(
            'read' => true,
        );

        // Inherit capabilities from parent role if specified.
        if ( ! empty( $parent_role ) && $this->role_exists( $parent_role ) ) {
            $parent_role_obj = get_role( $parent_role );
            $default_capabilities = array_merge( $default_capabilities, $parent_role_obj->capabilities );
        }

        $role_capabilities = array_merge( $default_capabilities, $capabilities );

        // Create the role.
        $result = add_role( $role_slug, $role_name, $role_capabilities );

        if ( null !== $result ) {
            // Store inheritance relationship.
            if ( ! empty( $parent_role ) ) {
                $this->role_inheritance[ $role_slug ] = $parent_role;
            }

            $this->audit_logger->log_event(
                'role_created',
                sprintf( 'Custom role "%s" created', $role_name ),
                array( 'role_slug' => $role_slug, 'capabilities' => array_keys( $capabilities ) )
            );

            return true;
        }

        return false;
    }

    /**
     * Grant temporary permission to a user
     *
     * @param int    $user_id     User ID.
     * @param string $capability  Capability to grant.
     * @param int    $expires_in  Time until expiration in seconds.
     * @param string $reason      Reason for temporary permission.
     * @return bool True if permission was granted.
     * @since 1.0.0
     */
    public function grant_temporary_permission( $user_id, $capability, $expires_in = 3600, $reason = '' ) {
        $user_id    = absint( $user_id );
        $expires_at = time() + absint( $expires_in );

        if ( ! $user_id || ! $capability ) {
            return false;
        }

        $permission_key = "temp_perm_{$user_id}_{$capability}";

        $this->temporary_permissions[ $permission_key ] = array(
            'user_id'     => $user_id,
            'capability'  => $capability,
            'expires_at'  => $expires_at,
            'granted_at'  => time(),
            'reason'      => sanitize_text_field( $reason ),
        );

        // Store in user meta for persistence.
        update_user_meta( $user_id, '_temporary_permissions', $this->temporary_permissions );

        $this->audit_logger->log_event(
            'temporary_permission_granted',
            sprintf( 'Temporary permission "%s" granted to user %d', $capability, $user_id ),
            array(
                'user_id'    => $user_id,
                'capability' => $capability,
                'expires_in' => $expires_in,
                'reason'     => $reason,
            )
        );

        return true;
    }

    /**
     * Check temporary permissions during capability check
     *
     * @param array   $allcaps All capabilities of the user.
     * @param array   $caps    Actual capabilities being checked.
     * @param array   $args    Additional arguments.
     * @param WP_User $user    User object.
     * @return array Modified capabilities array.
     * @since 1.0.0
     */
    public function check_temporary_permissions( $allcaps, $caps, $args, $user ) {
        if ( empty( $user->ID ) || empty( $this->temporary_permissions ) ) {
            return $allcaps;
        }

        // Load temporary permissions from user meta if not loaded.
        if ( empty( $this->temporary_permissions ) ) {
            $this->temporary_permissions = get_user_meta( $user->ID, '_temporary_permissions', true ) ?: array();
        }

        $current_time = time();

        foreach ( $this->temporary_permissions as $key => $permission ) {
            // Remove expired permissions.
            if ( $permission['expires_at'] < $current_time ) {
                unset( $this->temporary_permissions[ $key ] );
                continue;
            }

            // Grant the temporary capability.
            if ( $permission['user_id'] === $user->ID ) {
                $allcaps[ $permission['capability'] ] = true;
            }
        }

        // Update stored permissions if any were removed.
        if ( count( $this->temporary_permissions ) !== count( $this->temporary_permissions ) ) {
            update_user_meta( $user->ID, '_temporary_permissions', $this->temporary_permissions );
        }

        return $allcaps;
    }

    /**
     * Bulk assign roles to users based on criteria
     *
     * @param array $criteria    User selection criteria.
     * @param string $new_role   Role to assign.
     * @param string $operation  Operation type ('add', 'replace', 'remove').
     * @return array Results of bulk operation.
     * @since 1.0.0
     */
    public function bulk_assign_roles( $criteria, $new_role, $operation = 'replace' ) {
        $default_criteria = array(
            'role'         => '',
            'role__in'     => array(),
            'role__not_in' => array(),
            'meta_query'   => array(),
            'date_query'   => array(),
            'number'       => -1,
        );

        $criteria = wp_parse_args( $criteria, $default_criteria );

        // Validate the target role.
        if ( ! empty( $new_role ) && ! $this->role_exists( $new_role ) ) {
            return array(
                'success' => false,
                'error'   => 'Invalid target role',
            );
        }

        $user_query = new WP_User_Query( $criteria );
        $users      = $user_query->get_results();
        $results    = array(
            'total_users' => count( $users ),
            'updated'     => 0,
            'failed'      => 0,
            'errors'      => array(),
        );

        foreach ( $users as $user ) {
            $result = $this->update_user_role( $user, $new_role, $operation );

            if ( $result['success'] ) {
                $results['updated']++;
            } else {
                $results['failed']++;
                $results['errors'][] = $result['error'];
            }
        }

        $this->audit_logger->log_event(
            'bulk_role_assignment',
            sprintf( 'Bulk role assignment completed: %d users updated', $results['updated'] ),
            array(
                'criteria'  => $criteria,
                'new_role'  => $new_role,
                'operation' => $operation,
                'results'   => $results,
            )
        );

        return $results;
    }

    /**
     * Update user role with proper validation
     *
     * @param WP_User $user      User object.
     * @param string  $new_role  New role to assign.
     * @param string  $operation Operation type.
     * @return array Update result.
     * @since 1.0.0
     */
    private function update_user_role( $user, $new_role, $operation ) {
        $current_roles = $user->roles;

        switch ( $operation ) {
            case 'add':
                if ( ! in_array( $new_role, $current_roles, true ) ) {
                    $user->add_role( $new_role );
                }
                break;

            case 'remove':
                $user->remove_role( $new_role );
                break;

            case 'replace':
            default:
                // Store current roles for audit logging.
                $old_roles = $current_roles;
                
                // Remove all current roles.
                foreach ( $current_roles as $role ) {
                    $user->remove_role( $role );
                }
                
                // Add new role.
                if ( ! empty( $new_role ) ) {
                    $user->add_role( $new_role );
                }
                break;
        }

        // Check if update was successful.
        $updated_roles = $user->roles;

        if ( 'replace' === $operation && ! empty( $new_role ) ) {
            $success = in_array( $new_role, $updated_roles, true );
        } else {
            $success = true; // For add/remove, we assume success if no exception.
        }

        if ( $success ) {
            $this->audit_logger->log_event(
                'user_role_updated',
                sprintf( 'User %d roles updated', $user->ID ),
                array(
                    'user_id'    => $user->ID,
                    'old_roles'  => $old_roles ?? $current_roles,
                    'new_roles'  => $updated_roles,
                    'operation'  => $operation,
                )
            );

            return array( 'success' => true );
        } else {
            return array(
                'success' => false,
                'error'   => 'Failed to update user roles',
            );
        }
    }

    /**
     * Log role changes
     *
     * @param int    $user_id   User ID.
     * @param string $new_role  New role.
     * @param array  $old_roles Old roles.
     * @since 1.0.0
     */
    public function log_role_change( $user_id, $new_role, $old_roles ) {
        $this->audit_logger->log_event(
            'role_changed',
            sprintf( 'User %d role changed', $user_id ),
            array(
                'user_id'   => $user_id,
                'old_roles' => $old_roles,
                'new_role'  => $new_role,
            )
        );
    }

    /**
     * Check if a role exists
     *
     * @param string $role_slug Role slug.
     * @return bool True if role exists.
     * @since 1.0.0
     */
    public function role_exists( $role_slug ) {
        $roles = wp_roles();
        return isset( $roles->roles[ $role_slug ] );
    }

    /**
     * Get all custom capabilities
     *
     * @return array Custom capabilities.
     * @since 1.0.0
     */
    public function get_custom_capabilities() {
        return $this->custom_capabilities;
    }

    /**
     * Get user capability report
     *
     * @param int $user_id User ID.
     * @return array User capabilities report.
     * @since 1.0.0
     */
    public function get_user_capability_report( $user_id ) {
        $user = get_user_by( 'id', $user_id );
        
        if ( ! $user ) {
            return array();
        }

        $all_capabilities = array();
        $roles = wp_roles();

        // Get capabilities from roles.
        foreach ( $user->roles as $role_slug ) {
            $role = $roles->get_role( $role_slug );
            if ( $role ) {
                $all_capabilities = array_merge( $all_capabilities, $role->capabilities );
            }
        }

        // Get temporary permissions.
        $temporary_permissions = get_user_meta( $user_id, '_temporary_permissions', true ) ?: array();
        $current_time = time();

        $active_temporary = array();
        foreach ( $temporary_permissions as $perm ) {
            if ( $perm['expires_at'] > $current_time ) {
                $active_temporary[ $perm['capability'] ] = $perm;
            }
        }

        return array(
            'user_roles'           => $user->roles,
            'all_capabilities'     => $all_capabilities,
            'temporary_permissions' => $active_temporary,
            'effective_capabilities' => array_merge(
                $all_capabilities,
                array_fill_keys( array_keys( $active_temporary ), true )
            ),
        );
    }
}

/**
 * User Audit Logger
 * 
 * Handles comprehensive logging of user actions and role changes.
 * 
 * @since 1.0.0
 */
class User_Audit_Logger {

    /**
     * Log table name
     *
     * @var string
     */
    private $log_table;

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        global $wpdb;
        $this->log_table = $wpdb->prefix . 'user_audit_logs';

        add_action( 'init', array( $this, 'create_log_table' ) );
    }

    /**
     * Create audit log table if it doesn't exist
     *
     * @since 1.0.0
     */
    public function create_log_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->log_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(100) NOT NULL,
            event_description TEXT NOT NULL,
            user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            user_ip VARCHAR(45) DEFAULT NULL,
            user_agent TEXT,
            event_data LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Log an audit event
     *
     * @param string $event_type        Type of event.
     * @param string $description       Event description.
     * @param array  $data              Additional event data.
     * @param int    $user_id           User ID responsible for event.
     * @since 1.0.0
     */
    public function log_event( $event_type, $description, $data = array(), $user_id = null ) {
        global $wpdb;

        if ( null === $user_id ) {
            $user_id = get_current_user_id();
        }

        $log_data = array(
            'event_type'        => sanitize_text_field( $event_type ),
            'event_description' => sanitize_text_field( $description ),
            'user_id'           => $user_id ? absint( $user_id ) : null,
            'user_ip'           => $this->get_client_ip(),
            'user_agent'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
            'event_data'        => $data ? wp_json_encode( $data ) : '',
        );

        $result = $wpdb->insert( $this->log_table, $log_data );

        if ( ! $result ) {
            error_log( 'Failed to log audit event: ' . $wpdb->last_error );
        }
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
     * Get audit logs with filtering
     *
     * @param array $args Query arguments.
     * @return array Audit logs.
     * @since 1.0.0
     */
    public function get_audit_logs( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'event_type' => '',
            'user_id'    => 0,
            'start_date' => '',
            'end_date'   => '',
            'per_page'   => 50,
            'page'       => 1,
        );

        $args = wp_parse_args( $args, $defaults );

        $where_conditions = array( '1=1' );
        $query_params     = array();

        if ( ! empty( $args['event_type'] ) ) {
            $where_conditions[] = 'event_type = %s';
            $query_params[]     = $args['event_type'];
        }

        if ( ! empty( $args['user_id'] ) ) {
            $where_conditions[] = 'user_id = %d';
            $query_params[]     = absint( $args['user_id'] );
        }

        if ( ! empty( $args['start_date'] ) ) {
            $where_conditions[] = 'created_at >= %s';
            $query_params[]     = sanitize_text_field( $args['start_date'] );
        }

        if ( ! empty( $args['end_date'] ) ) {
            $where_conditions[] = 'created_at <= %s';
            $query_params[]     = sanitize_text_field( $args['end_date'] );
        }

        $where_sql = implode( ' AND ', $where_conditions );

        $offset = ( $args['page'] - 1 ) * $args['per_page'];

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->log_table} 
             WHERE {$where_sql} 
             ORDER BY created_at DESC 
             LIMIT %d, %d",
            array_merge( $query_params, array( $offset, $args['per_page'] ) )
        );

        $logs = $wpdb->get_results( $sql, ARRAY_A );

        // Decode event data.
        foreach ( $logs as &$log ) {
            if ( ! empty( $log['event_data'] ) ) {
                $log['event_data'] = json_decode( $log['event_data'], true );
            }
        }

        return $logs;
    }
}

// Initialize the advanced user role manager.
$advanced_user_role_manager = new Advanced_User_Role_Manager();