<?php
/**
 * Challenge 11: Advanced Plugin Dependency Management System
 * 
 * Problem Statement:
 * Create a comprehensive plugin dependency management system that:
 * 
 * 1. Handles automatic dependency resolution and installation
 * 2. Manages version compatibility checking and conflicts
 * 3. Provides safe automatic updates with rollback capability
 * 4. Implements plugin conflict detection and resolution
 * 5. Offers safe uninstallation with dependency checking
 * 6. Provides dependency visualization and reporting
 * 7. Handles circular dependency detection
 * 8. Integrates with WordPress plugin repository and custom sources
 * 
 * @package WordPressCodingChallenge
 * @version 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin Dependency Manager
 * 
 * Manages plugin dependencies, conflicts, and version compatibility
 * with automatic resolution and safe operations.
 * 
 * @since 1.0.0
 */
class Plugin_Dependency_Manager {

    /**
     * Dependency registry
     *
     * @var array
     */
    private $dependency_registry = array();

    /**
     * Conflict registry
     *
     * @var array
     */
    private $conflict_registry = array();

    /**
     * Version constraints
     *
     * @var array
     */
    private $version_constraints = array();

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        add_action( 'admin_init', array( $this, 'check_dependencies' ) );
        add_filter( 'upgrader_pre_install', array( $this, 'pre_install_check' ), 10, 2 );
        add_filter( 'upgrader_post_install', array( $this, 'post_install_verify' ), 10, 3 );
        add_action( 'deactivated_plugin', array( $this, 'handle_plugin_deactivation' ) );
    }

    /**
     * Register plugin dependencies
     *
     * @param string $plugin_slug    Plugin slug.
     * @param array  $dependencies   Required dependencies.
     * @param array  $conflicts      Conflicting plugins.
     * @param array  $version_reqs   Version requirements.
     * @since 1.0.0
     */
    public function register_dependencies( $plugin_slug, $dependencies = array(), $conflicts = array(), $version_reqs = array() ) {
        $this->dependency_registry[ $plugin_slug ] = $dependencies;
        
        if ( ! empty( $conflicts ) ) {
            $this->conflict_registry[ $plugin_slug ] = $conflicts;
        }
        
        if ( ! empty( $version_reqs ) ) {
            $this->version_constraints[ $plugin_slug ] = $version_reqs;
        }

        /**
         * Fires after plugin dependencies are registered
         *
         * @param string $plugin_slug Plugin slug.
         * @param array  $dependencies Plugin dependencies.
         * @since 1.0.0
         */
        do_action( 'plugin_dependencies_registered', $plugin_slug, $dependencies );
    }

    /**
     * Check all plugin dependencies
     *
     * @since 1.0.0
     */
    public function check_dependencies() {
        $active_plugins = get_option( 'active_plugins', array() );
        
        foreach ( $active_plugins as $plugin_file ) {
            $plugin_slug = $this->get_plugin_slug( $plugin_file );
            $this->validate_plugin_dependencies( $plugin_slug );
        }
    }

    /**
     * Validate plugin dependencies and conflicts
     *
     * @param string $plugin_slug Plugin slug.
     * @return bool True if dependencies are satisfied.
     * @since 1.0.0
     */
    public function validate_plugin_dependencies( $plugin_slug ) {
        $errors = new WP_Error();

        // Check required dependencies.
        $missing_deps = $this->get_missing_dependencies( $plugin_slug );
        if ( ! empty( $missing_deps ) ) {
            foreach ( $missing_deps as $dep ) {
                $errors->add( 
                    'missing_dependency',
                    sprintf( 
                        __( 'Required plugin %s is missing for %s', 'wordpress-coding-challenge' ),
                        $dep,
                        $plugin_slug
                    )
                );
            }
        }

        // Check version constraints.
        $version_issues = $this->check_version_constraints( $plugin_slug );
        if ( ! empty( $version_issues ) ) {
            foreach ( $version_issues as $issue ) {
                $errors->add( 'version_conflict', $issue );
            }
        }

        // Check plugin conflicts.
        $conflicts = $this->get_active_conflicts( $plugin_slug );
        if ( ! empty( $conflicts ) ) {
            foreach ( $conflicts as $conflict ) {
                $errors->add( 
                    'plugin_conflict',
                    sprintf( 
                        __( 'Plugin %s conflicts with %s', 'wordpress-coding-challenge' ),
                        $conflict,
                        $plugin_slug
                    )
                );
            }
        }

        // Check circular dependencies.
        $circular = $this->detect_circular_dependencies( $plugin_slug );
        if ( ! empty( $circular ) ) {
            $errors->add( 
                'circular_dependency',
                sprintf( 
                    __( 'Circular dependency detected: %s', 'wordpress-coding-challenge' ),
                    implode( ' -> ', $circular )
                )
            );
        }

        if ( $errors->has_errors() ) {
            $this->handle_dependency_errors( $plugin_slug, $errors );
            return false;
        }

        return true;
    }

    /**
     * Get missing dependencies for a plugin
     *
     * @param string $plugin_slug Plugin slug.
     * @return array Missing dependencies.
     * @since 1.0.0
     */
    private function get_missing_dependencies( $plugin_slug ) {
        $missing = array();
        
        if ( ! isset( $this->dependency_registry[ $plugin_slug ] ) ) {
            return $missing;
        }

        $active_plugins = $this->get_active_plugin_slugs();
        
        foreach ( $this->dependency_registry[ $plugin_slug ] as $dependency ) {
            if ( ! in_array( $dependency, $active_plugins, true ) ) {
                $missing[] = $dependency;
            }
        }

        return $missing;
    }

    /**
     * Check version constraints for dependencies
     *
     * @param string $plugin_slug Plugin slug.
     * @return array Version issues.
     * @since 1.0.0
     */
    private function check_version_constraints( $plugin_slug ) {
        $issues = array();
        
        if ( ! isset( $this->version_constraints[ $plugin_slug ] ) ) {
            return $issues;
        }

        foreach ( $this->version_constraints[ $plugin_slug ] as $dep_slug => $constraint ) {
            $dep_version = $this->get_plugin_version( $dep_slug );
            
            if ( $dep_version && ! $this->version_satisfies_constraint( $dep_version, $constraint ) ) {
                $issues[] = sprintf( 
                    __( '%s requires %s version %s, found %s', 'wordpress-coding-challenge' ),
                    $plugin_slug,
                    $dep_slug,
                    $constraint,
                    $dep_version
                );
            }
        }

        return $issues;
    }

    /**
     * Get active conflicts for a plugin
     *
     * @param string $plugin_slug Plugin slug.
     * @return array Active conflicts.
     * @since 1.0.0
     */
    private function get_active_conflicts( $plugin_slug ) {
        $conflicts = array();
        
        if ( ! isset( $this->conflict_registry[ $plugin_slug ] ) ) {
            return $conflicts;
        }

        $active_plugins = $this->get_active_plugin_slugs();
        
        foreach ( $this->conflict_registry[ $plugin_slug ] as $conflict ) {
            if ( in_array( $conflict, $active_plugins, true ) ) {
                $conflicts[] = $conflict;
            }
        }

        return $conflicts;
    }

    /**
     * Detect circular dependencies
     *
     * @param string $plugin_slug Plugin slug.
     * @return array Circular dependency chain.
     * @since 1.0.0
     */
    private function detect_circular_dependencies( $plugin_slug ) {
        return $this->find_circular_dependency( $plugin_slug, array() );
    }

    /**
     * Recursive function to find circular dependencies
     *
     * @param string $current_plugin Current plugin being checked.
     * @param array  $visited        Visited plugins.
     * @return array Circular chain if found.
     * @since 1.0.0
     */
    private function find_circular_dependency( $current_plugin, $visited ) {
        if ( in_array( $current_plugin, $visited, true ) ) {
            return $visited;
        }

        $visited[] = $current_plugin;

        if ( ! isset( $this->dependency_registry[ $current_plugin ] ) ) {
            return array();
        }

        foreach ( $this->dependency_registry[ $current_plugin ] as $dependency ) {
            $chain = $this->find_circular_dependency( $dependency, $visited );
            if ( ! empty( $chain ) ) {
                return $chain;
            }
        }

        return array();
    }

    /**
     * Check version against constraint
     *
     * @param string $version    Version number.
     * @param string $constraint Version constraint.
     * @return bool True if version satisfies constraint.
     * @since 1.0.0
     */
    private function version_satisfies_constraint( $version, $constraint ) {
        // Simple version comparison for common operators.
        if ( preg_match( '/^([>=<]=?)\s*([\d.]+)$/', $constraint, $matches ) ) {
            $operator = $matches[1];
            $required = $matches[2];
            
            switch ( $operator ) {
                case '>=':
                    return version_compare( $version, $required, '>=' );
                case '<=':
                    return version_compare( $version, $required, '<=' );
                case '>':
                    return version_compare( $version, $required, '>' );
                case '<':
                    return version_compare( $version, $required, '<' );
                case '=':
                default:
                    return version_compare( $version, $required, '=' );
            }
        }

        // Default to exact match.
        return version_compare( $version, $constraint, '=' );
    }

    /**
     * Get plugin version
     *
     * @param string $plugin_slug Plugin slug.
     * @return string|false Plugin version or false if not found.
     * @since 1.0.0
     */
    private function get_plugin_version( $plugin_slug ) {
        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_file = $this->find_plugin_file( $plugin_slug );
        
        if ( $plugin_file ) {
            $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );
            return $plugin_data['Version'] ?? false;
        }

        return false;
    }

    /**
     * Find plugin file by slug
     *
     * @param string $plugin_slug Plugin slug.
     * @return string|false Plugin file path or false if not found.
     * @since 1.0.0
     */
    private function find_plugin_file( $plugin_slug ) {
        $plugins = get_plugins();
        
        foreach ( $plugins as $plugin_file => $plugin_data ) {
            if ( $this->get_plugin_slug( $plugin_file ) === $plugin_slug ) {
                return $plugin_file;
            }
        }

        return false;
    }

    /**
     * Extract plugin slug from file path
     *
     * @param string $plugin_file Plugin file path.
     * @return string Plugin slug.
     * @since 1.0.0
     */
    private function get_plugin_slug( $plugin_file ) {
        return dirname( $plugin_file );
    }

    /**
     * Get active plugin slugs
     *
     * @return array Active plugin slugs.
     * @since 1.0.0
     */
    private function get_active_plugin_slugs() {
        $active_plugins = get_option( 'active_plugins', array() );
        $slugs = array();
        
        foreach ( $active_plugins as $plugin_file ) {
            $slugs[] = $this->get_plugin_slug( $plugin_file );
        }
        
        return $slugs;
    }

    /**
     * Handle dependency errors
     *
     * @param string   $plugin_slug Plugin slug.
     * @param WP_Error $errors      Dependency errors.
     * @since 1.0.0
     */
    private function handle_dependency_errors( $plugin_slug, $errors ) {
        // Deactivate the plugin if it's active.
        $plugin_file = $this->find_plugin_file( $plugin_slug );
        
        if ( $plugin_file && is_plugin_active( $plugin_file ) ) {
            deactivate_plugins( $plugin_file );
            
            add_action( 'admin_notices', function() use ( $plugin_slug, $errors ) {
                ?>
                <div class="error notice">
                    <p>
                        <strong><?php echo esc_html( $plugin_slug ); ?></strong> 
                        <?php esc_html_e( 'has been deactivated due to dependency issues:', 'wordpress-coding-challenge' ); ?>
                    </p>
                    <ul>
                        <?php foreach ( $errors->get_error_messages() as $message ) : ?>
                            <li><?php echo esc_html( $message ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php
            } );
        }

        /**
         * Fires when dependency errors are detected
         *
         * @param string   $plugin_slug Plugin slug.
         * @param WP_Error $errors      Dependency errors.
         * @since 1.0.0
         */
        do_action( 'plugin_dependency_errors', $plugin_slug, $errors );
    }

    /**
     * Pre-install dependency check
     *
     * @param bool  $response Installation response.
     * @param array $hook_extra Extra hook arguments.
     * @return bool|WP_Error Response or error.
     * @since 1.0.0
     */
    public function pre_install_check( $response, $hook_extra ) {
        if ( ! isset( $hook_extra['plugin'] ) ) {
            return $response;
        }

        $plugin_slug = $this->get_plugin_slug( $hook_extra['plugin'] );
        
        // Check if installing this plugin would break dependencies.
        $dependent_plugins = $this->get_dependent_plugins( $plugin_slug );
        
        foreach ( $dependent_plugins as $dependent ) {
            if ( ! $this->validate_plugin_dependencies( $dependent ) ) {
                return new WP_Error( 
                    'would_break_dependencies',
                    sprintf( 
                        __( 'Cannot install %s: it would break dependencies for %s', 'wordpress-coding-challenge' ),
                        $plugin_slug,
                        $dependent
                    )
                );
            }
        }

        return $response;
    }

    /**
     * Post-install verification
     *
     * @param bool  $response Installation response.
     * @param array $hook_extra Extra hook arguments.
     * @param array $result    Installation result.
     * @return bool Installation response.
     * @since 1.0.0
     */
    public function post_install_verify( $response, $hook_extra, $result ) {
        if ( ! isset( $hook_extra['plugin'] ) ) {
            return $response;
        }

        $plugin_slug = $this->get_plugin_slug( $hook_extra['plugin'] );
        
        // Verify the installed plugin's dependencies.
        $this->validate_plugin_dependencies( $plugin_slug );

        return $response;
    }

    /**
     * Handle plugin deactivation
     *
     * @param string $plugin_file Deactivated plugin file.
     * @since 1.0.0
     */
    public function handle_plugin_deactivation( $plugin_file ) {
        $plugin_slug = $this->get_plugin_slug( $plugin_file );
        $dependent_plugins = $this->get_dependent_plugins( $plugin_slug );

        foreach ( $dependent_plugins as $dependent ) {
            if ( is_plugin_active( $this->find_plugin_file( $dependent ) ) ) {
                // Deactivate dependent plugins.
                deactivate_plugins( $this->find_plugin_file( $dependent ) );
                
                add_action( 'admin_notices', function() use ( $dependent, $plugin_slug ) {
                    ?>
                    <div class="error notice">
                        <p>
                            <strong><?php echo esc_html( $dependent ); ?></strong> 
                            <?php esc_html_e( 'has been deactivated because it depends on', 'wordpress-coding-challenge' ); ?>
                            <strong><?php echo esc_html( $plugin_slug ); ?></strong>
                        </p>
                    </div>
                    <?php
                } );
            }
        }
    }

    /**
     * Get plugins that depend on a specific plugin
     *
     * @param string $plugin_slug Plugin slug.
     * @return array Dependent plugins.
     * @since 1.0.0
     */
    private function get_dependent_plugins( $plugin_slug ) {
        $dependents = array();
        
        foreach ( $this->dependency_registry as $dependent => $dependencies ) {
            if ( in_array( $plugin_slug, $dependencies, true ) ) {
                $dependents[] = $dependent;
            }
        }

        return $dependents;
    }

    /**
     * Get dependency report
     *
     * @return array Dependency report.
     * @since 1.0.0
     */
    public function get_dependency_report() {
        $report = array(
            'plugins' => array(),
            'issues'  => array(),
            'graph'   => array(),
        );

        $active_plugins = $this->get_active_plugin_slugs();
        
        foreach ( $active_plugins as $plugin_slug ) {
            $plugin_data = array(
                'slug'          => $plugin_slug,
                'dependencies'  => $this->dependency_registry[ $plugin_slug ] ?? array(),
                'conflicts'     => $this->conflict_registry[ $plugin_slug ] ?? array(),
                'version_reqs'  => $this->version_constraints[ $plugin_slug ] ?? array(),
                'status'        => $this->validate_plugin_dependencies( $plugin_slug ) ? 'healthy' : 'issues',
            );

            $report['plugins'][ $plugin_slug ] = $plugin_data;
            
            // Build dependency graph.
            if ( ! empty( $plugin_data['dependencies'] ) ) {
                $report['graph'][ $plugin_slug ] = $plugin_data['dependencies'];
            }
        }

        return $report;
    }
}

// Initialize the plugin dependency manager.
$plugin_dependency_manager = new Plugin_Dependency_Manager();

// Example usage:
// $plugin_dependency_manager->register_dependencies(
//     'my-plugin',
//     array( 'woocommerce', 'advanced-custom-fields' ),
//     array( 'old-plugin' ),
//     array( 'woocommerce' => '>=5.0.0' )
// );