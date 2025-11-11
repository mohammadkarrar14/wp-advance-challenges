<?php
/**
 * Challenge 14: Comprehensive Security Hardening Suite
 * 
 * Problem Statement:
 * Create a comprehensive security hardening system that provides:
 * 
 * 1. Two-factor authentication with multiple methods
 * 2. Advanced login attempt limiting and IP blocking
 * 3. Security headers implementation and management
 * 4. File integrity monitoring and change detection
 * 5. Comprehensive security event logging and alerts
 * 6. Malware scanning and vulnerability detection
 * 7. Database security and SQL injection prevention
 * 8. Security policy enforcement and compliance
 * 
 * @package WordPressCodingChallenge
 * @version 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Security Hardening Suite
 * 
 * Provides comprehensive security features including 2FA,
 * login protection, security headers, and monitoring.
 * 
 * @since 1.0.0
 */
class Security_Hardening_Suite {

    /**
     * Two-factor authentication manager
     *
     * @var Two_Factor_Authentication
     */
    private $two_factor;

    /**
     * Login protection manager
     *
     * @var Login_Protection
     */
    private $login_protection;

    /**
     * Security headers manager
     *
     * @var Security_Headers_Manager
     */
    private $security_headers;

    /**
     * File integrity monitor
     *
     * @var File_Integrity_Monitor
     */
    private $file_monitor;

    /**
     * Security event logger
     *
     * @var Security_Event_Logger
     */
    private $event_logger;

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->two_factor = new Two_Factor_Authentication();
        $this->login_protection = new Login_Protection();
        $this->security_headers = new Security_Headers_Manager();
        $this->file_monitor = new File_Integrity_Monitor();
        $this->event_logger = new Security_Event_Logger();

        add_action( 'init', array( $this, 'init_security_features' ) );
        add_action( 'admin_init', array( $this, 'check_security_status' ) );
    }

    /**
     * Initialize security features
     *
     * @since 1.0.0
     */
    public function init_security_features() {
        // Enable security headers.
        $this->security_headers->enable_headers();

        // Monitor file integrity.
        if ( $this->should_monitor_files() ) {
            $this->file_monitor->scan_files();
        }

        // Check for security issues.
        $this->run_security_checks();
    }

    /**
     * Check security status and report issues
     *
     * @since 1.0.0
     */
    public function check_security_status() {
        $security_report = $this->generate_security_report();
        
        if ( ! $security_report['overall_secure'] ) {
            $this->display_security_notice( $security_report );
        }
    }

    /**
     * Generate security status report
     *
     * @return array Security report.
     * @since 1.0.0
     */
    public function generate_security_report() {
        $report = array(
            'overall_secure' => true,
            'checks' => array(),
            'score'  => 0,
            'total_checks' => 0,
            'passed_checks' => 0,
        );

        // Check two-factor status.
        $two_factor_status = $this->two_factor->get_status();
        $report['checks']['two_factor'] = $two_factor_status;
        
        if ( ! $two_factor_status['enabled'] ) {
            $report['overall_secure'] = false;
        }

        // Check login protection.
        $login_protection_status = $this->login_protection->get_status();
        $report['checks']['login_protection'] = $login_protection_status;

        // Check security headers.
        $headers_status = $this->security_headers->get_status();
        $report['checks']['security_headers'] = $headers_status;

        // Check file integrity.
        $file_integrity_status = $this->file_monitor->get_status();
        $report['checks']['file_integrity'] = $file_integrity_status;

        // Calculate security score.
        $report['total_checks'] = count( $report['checks'] );
        $report['passed_checks'] = count( array_filter( $report['checks'], function( $check ) {
            return $check['passed'] ?? false;
        } ) );

        if ( $report['total_checks'] > 0 ) {
            $report['score'] = ( $report['passed_checks'] / $report['total_checks'] ) * 100;
        }

        return $report;
    }

    /**
     * Display security notice in admin
     *
     * @param array $report Security report.
     * @since 1.0.0
     */
    private function display_security_notice( $report ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        add_action( 'admin_notices', function() use ( $report ) {
            ?>
            <div class="notice notice-warning">
                <h3><?php esc_html_e( 'Security Issues Detected', 'wordpress-coding-challenge' ); ?></h3>
                <p><?php esc_html_e( 'Your site has security issues that need attention:', 'wordpress-coding-challenge' ); ?></p>
                <ul>
                    <?php foreach ( $report['checks'] as $check_name => $check_data ) : ?>
                        <?php if ( ! $check_data['passed'] ) : ?>
                            <li><?php echo esc_html( $check_data['message'] ); ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
                <p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=security-settings' ) ); ?>" class="button button-primary">
                        <?php esc_html_e( 'Review Security Settings', 'wordpress-coding-challenge' ); ?>
                    </a>
                </p>
            </div>
            <?php
        } );
    }

    /**
     * Run comprehensive security checks
     *
     * @since 1.0.0
     */
    private function run_security_checks() {
        $checks = array(
            'wp_version'      => array( $this, 'check_wp_version' ),
            'php_version'     => array( $this, 'check_php_version' ),
            'db_security'     => array( $this, 'check_database_security' ),
            'file_permissions' => array( $this, 'check_file_permissions' ),
            'user_security'   => array( $this, 'check_user_security' ),
        );

        foreach ( $checks as $check_name => $check_callback ) {
            $result = call_user_func( $check_callback );
            
            if ( ! $result['passed'] ) {
                $this->event_logger->log_security_event( 
                    'security_check_failed',
                    $result['message'],
                    array( 'check' => $check_name )
                );
            }
        }
    }

    /**
     * Check WordPress version security
     *
     * @return array Check result.
     * @since 1.0.0
     */
    private function check_wp_version() {
        global $wp_version;
        
        $latest_version = get_transient( 'latest_wp_version' );
        
        if ( ! $latest_version ) {
            $response = wp_remote_get( 'https://api.wordpress.org/core/version-check/1.7/' );
            
            if ( ! is_wp_error( $response ) ) {
                $data = json_decode( wp_remote_retrieve_body( $response ), true );
                $latest_version = $data['offers'][0]['version'] ?? $wp_version;
                set_transient( 'latest_wp_version', $latest_version, 12 * HOUR_IN_SECONDS );
            } else {
                $latest_version = $wp_version;
            }
        }

        $is_latest = version_compare( $wp_version, $latest_version, '>=' );
        
        return array(
            'passed'  => $is_latest,
            'message' => $is_latest 
                ? 'WordPress is up to date' 
                : 'WordPress update available: ' . $latest_version,
        );
    }

    /**
     * Check PHP version security
     *
     * @return array Check result.
     * @since 1.0.0
     */
    private function check_php_version() {
        $current_php = phpversion();
        $min_php = '7.4';
        $recommended_php = '8.0';

        $is_supported = version_compare( $current_php, $min_php, '>=' );
        $is_recommended = version_compare( $current_php, $recommended_php, '>=' );

        return array(
            'passed'  => $is_supported,
            'message' => $is_recommended 
                ? 'PHP version is secure' 
                : 'Upgrade PHP to version ' . $recommended_php . ' or higher',
        );
    }

    /**
     * Check database security
     *
     * @return array Check result.
     * @since 1.0.0
     */
    private function check_database_security() {
        global $wpdb, $table_prefix;

        $issues = array();

        // Check table prefix.
        if ( 'wp_' === $table_prefix ) {
            $issues[] = 'Default database table prefix detected';
        }

        // Check database user permissions.
        $result = $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.user_privileges WHERE grantee LIKE CONCAT('''', USER(), '''%') AND privilege_type = 'FILE'" );
        if ( $result > 0 ) {
            $issues[] = 'Database user has FILE privilege';
        }

        return array(
            'passed'  => empty( $issues ),
            'message' => empty( $issues ) ? 'Database security is good' : implode( ', ', $issues ),
        );
    }

    /**
     * Check file permissions
     *
     * @return array Check result.
     * @since 1.0.0
     */
    private function check_file_permissions() {
        $issues = array();
        $important_files = array(
            ABSPATH . 'wp-config.php' => 400,
            ABSPATH . '.htaccess'     => 404,
        );

        foreach ( $important_files as $file => $recommended_perms ) {
            if ( file_exists( $file ) ) {
                $actual_perms = fileperms( $file ) & 0777;
                
                if ( $actual_perms !== $recommended_perms ) {
                    $issues[] = basename( $file ) . ' has insecure permissions: ' . decoct( $actual_perms );
                }
            }
        }

        return array(
            'passed'  => empty( $issues ),
            'message' => empty( $issues ) ? 'File permissions are secure' : implode( ', ', $issues ),
        );
    }

    /**
     * Check user security
     *
     * @return array Check result.
     * @since 1.0.0
     */
    private function check_user_security() {
        $issues = array();

        // Check for users with username 'admin'.
        $admin_user = get_user_by( 'login', 'admin' );
        if ( $admin_user ) {
            $issues[] = 'User with username "admin" exists';
        }

        // Check for users with weak passwords.
        $users = get_users( array( 'role__in' => array( 'administrator', 'editor' ) ) );
        
        foreach ( $users as $user ) {
            if ( $this->is_weak_password( $user->ID ) ) {
                $issues[] = 'User "' . $user->user_login . '" has weak password';
            }
        }

        return array(
            'passed'  => empty( $issues ),
            'message' => empty( $issues ) ? 'User security is good' : implode( ', ', $issues ),
        );
    }

    /**
     * Check if user has weak password
     *
     * @param int $user_id User ID.
     * @return bool True if password is weak.
     * @since 1.0.0
     */
    private function is_weak_password( $user_id ) {
        // This is a simplified check. In practice, you'd use a proper password strength checker.
        $user_data = get_userdata( $user_id );
        return false; // Placeholder for actual password strength check.
    }

    /**
     * Determine if file monitoring should run
     *
     * @return bool True if monitoring should run.
     * @since 1.0.0
     */
    private function should_monitor_files() {
        return defined( 'WP_DEBUG' ) && WP_DEBUG || current_user_can( 'manage_options' );
    }
}

/**
 * Two-Factor Authentication Manager
 * 
 * Handles two-factor authentication with multiple methods.
 * 
 * @since 1.0.0
 */
class Two_Factor_Authentication {

    /**
     * Available 2FA methods
     *
     * @var array
     */
    private $methods = array();

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->setup_methods();
        add_action( 'wp_login', array( $this, 'require_2fa' ), 10, 2 );
        add_action( 'show_user_profile', array( $this, 'show_2fa_settings' ) );
        add_action( 'edit_user_profile', array( $this, 'show_2fa_settings' ) );
    }

    /**
     * Setup available 2FA methods
     *
     * @since 1.0.0
     */
    private function setup_methods() {
        $this->methods = array(
            'totp' => array(
                'name'   => 'Time-based One-Time Password',
                'class'  => 'TOTP_Authenticator',
                'enabled' => true,
            ),
            'email' => array(
                'name'   => 'Email Verification',
                'class'  => 'Email_Authenticator',
                'enabled' => true,
            ),
            'backup_codes' => array(
                'name'   => 'Backup Codes',
                'class'  => 'Backup_Code_Authenticator',
                'enabled' => true,
            ),
        );
    }

    /**
     * Require 2FA after login
     *
     * @param string  $user_login Username.
     * @param WP_User $user       User object.
     * @since 1.0.0
     */
    public function require_2fa( $user_login, $user ) {
        if ( ! $this->is_2fa_required( $user ) || $this->is_2fa_completed( $user ) ) {
            return;
        }

        // Store user in session for 2FA.
        $_SESSION['2fa_user_id'] = $user->ID;
        
        // Redirect to 2FA verification.
        wp_redirect( home_url( '/wp-login.php?action=2fa' ) );
        exit;
    }

    /**
     * Check if 2FA is required for user
     *
     * @param WP_User $user User object.
     * @return bool True if 2FA is required.
     * @since 1.0.0
     */
    private function is_2fa_required( $user ) {
        $required_roles = get_option( '2fa_required_roles', array( 'administrator', 'editor' ) );
        $user_roles = $user->roles;

        return ! empty( array_intersect( $required_roles, $user_roles ) );
    }

    /**
     * Check if user has completed 2FA
     *
     * @param WP_User $user User object.
     * @return bool True if 2FA is completed.
     * @since 1.0.0
     */
    private function is_2fa_completed( $user ) {
        return get_user_meta( $user->ID, '2fa_enabled', true ) && 
               get_user_meta( $user->ID, '2fa_verified', true );
    }

    /**
     * Show 2FA settings in user profile
     *
     * @param WP_User $user User object.
     * @since 1.0.0
     */
    public function show_2fa_settings( $user ) {
        if ( ! current_user_can( 'edit_user', $user->ID ) ) {
            return;
        }

        $is_enabled = get_user_meta( $user->ID, '2fa_enabled', true );
        $is_verified = get_user_meta( $user->ID, '2fa_verified', true );
        ?>
        <h3><?php esc_html_e( 'Two-Factor Authentication', 'wordpress-coding-challenge' ); ?></h3>
        
        <table class="form-table">
            <tr>
                <th><?php esc_html_e( '2FA Status', 'wordpress-coding-challenge' ); ?></th>
                <td>
                    <?php if ( $is_enabled && $is_verified ) : ?>
                        <span style="color: green;"><?php esc_html_e( 'Enabled and Verified', 'wordpress-coding-challenge' ); ?></span>
                    <?php elseif ( $is_enabled && ! $is_verified ) : ?>
                        <span style="color: orange;"><?php esc_html_e( 'Enabled but Not Verified', 'wordpress-coding-challenge' ); ?></span>
                    <?php else : ?>
                        <span style="color: red;"><?php esc_html_e( 'Disabled', 'wordpress-coding-challenge' ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            
            <?php if ( ! $is_enabled ) : ?>
                <tr>
                    <th><?php esc_html_e( 'Enable 2FA', 'wordpress-coding-challenge' ); ?></th>
                    <td>
                        <button type="button" class="button" id="enable-2fa">
                            <?php esc_html_e( 'Enable Two-Factor Authentication', 'wordpress-coding-challenge' ); ?>
                        </button>
                    </td>
                </tr>
            <?php else : ?>
                <tr>
                    <th><?php esc_html_e( 'Disable 2FA', 'wordpress-coding-challenge' ); ?></th>
                    <td>
                        <button type="button" class="button button-secondary" id="disable-2fa">
                            <?php esc_html_e( 'Disable Two-Factor Authentication', 'wordpress-coding-challenge' ); ?>
                        </button>
                    </td>
                </tr>
            <?php endif; ?>
        </table>
        <?php
    }

    /**
     * Get 2FA status
     *
     * @return array Status information.
     * @since 1.0.0
     */
    public function get_status() {
        $users_with_2fa = get_users( array(
            'meta_key' => '2fa_enabled',
            'meta_value' => '1',
            'fields' => 'ID',
        ) );

        $total_admin_users = count( get_users( array(
            'role'   => 'administrator',
            'fields' => 'ID',
        ) ) );

        $admin_2fa_coverage = $total_admin_users > 0 ? ( count( $users_with_2fa ) / $total_admin_users ) * 100 : 0;

        return array(
            'enabled' => $admin_2fa_coverage > 0,
            'passed'  => $admin_2fa_coverage >= 80, // At least 80% of admins have 2FA.
            'message' => sprintf( '2FA coverage: %.1f%% of administrators', $admin_2fa_coverage ),
        );
    }
}

/**
 * Login Protection Manager
 * 
 * Handles login attempt limiting and IP blocking.
 * 
 * @since 1.0.0
 */
class Login_Protection {

    /**
     * Maximum login attempts
     *
     * @var int
     */
    private $max_attempts = 5;

    /**
     * Lockout duration in minutes
     *
     * @var int
     */
    private $lockout_duration = 30;

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        add_filter( 'authenticate', array( $this, 'check_login_attempts' ), 30, 3 );
        add_action( 'wp_login_failed', array( $this, 'track_failed_login' ) );
    }

    /**
     * Check login attempts before authentication
     *
     * @param WP_User|WP_Error $user     User object or error.
     * @param string           $username Username.
     * @param string           $password Password.
     * @return WP_User|WP_Error User object or error.
     * @since 1.0.0
     */
    public function check_login_attempts( $user, $username, $password ) {
        $ip_address = $this->get_client_ip();
        
        if ( $this->is_ip_blocked( $ip_address ) ) {
            return new WP_Error( 
                'ip_blocked',
                __( 'Your IP address has been temporarily blocked due to too many failed login attempts.', 'wordpress-coding-challenge' )
            );
        }

        if ( $this->has_too_many_attempts( $ip_address, $username ) ) {
            $this->block_ip( $ip_address );
            return new WP_Error( 
                'too_many_attempts',
                __( 'Too many failed login attempts. Your IP has been temporarily blocked.', 'wordpress-coding-challenge' )
            );
        }

        return $user;
    }

    /**
     * Track failed login attempt
     *
     * @param string $username Username that failed login.
     * @since 1.0.0
     */
    public function track_failed_login( $username ) {
        $ip_address = $this->get_client_ip();
        $this->record_login_attempt( $ip_address, $username, false );
    }

    /**
     * Check if IP is blocked
     *
     * @param string $ip_address IP address.
     * @return bool True if IP is blocked.
     * @since 1.0.0
     */
    private function is_ip_blocked( $ip_address ) {
        $blocked_until = get_transient( "login_blocked_{$ip_address}" );
        return $blocked_until && time() < $blocked_until;
    }

    /**
     * Check if there are too many login attempts
     *
     * @param string $ip_address IP address.
     * @param string $username   Username.
     * @return bool True if too many attempts.
     * @since 1.0.0
     */
    private function has_too_many_attempts( $ip_address, $username ) {
        $attempts = $this->get_login_attempts( $ip_address, $username );
        $recent_attempts = array_filter( $attempts, function( $attempt ) {
            return $attempt['timestamp'] > ( time() - ( 15 * MINUTE_IN_SECONDS ) );
        } );

        return count( $recent_attempts ) >= $this->max_attempts;
    }

    /**
     * Block an IP address
     *
     * @param string $ip_address IP address.
     * @since 1.0.0
     */
    private function block_ip( $ip_address ) {
        $block_until = time() + ( $this->lockout_duration * MINUTE_IN_SECONDS );
        set_transient( "login_blocked_{$ip_address}", $block_until, $this->lockout_duration * MINUTE_IN_SECONDS );
    }

    /**
     * Record a login attempt
     *
     * @param string $ip_address IP address.
     * @param string $username   Username.
     * @param bool   $success    Whether login was successful.
     * @since 1.0.0
     */
    private function record_login_attempt( $ip_address, $username, $success ) {
        $attempts = $this->get_login_attempts( $ip_address, $username );
        
        $attempts[] = array(
            'timestamp' => time(),
            'success'   => $success,
            'ip'        => $ip_address,
            'username'  => $username,
        );

        // Keep only recent attempts.
        $attempts = array_filter( $attempts, function( $attempt ) {
            return $attempt['timestamp'] > ( time() - ( 24 * HOUR_IN_SECONDS ) );
        } );

        $key = "login_attempts_{$ip_address}_{$username}";
        set_transient( $key, $attempts, 24 * HOUR_IN_SECONDS );
    }

    /**
     * Get login attempts for IP and username
     *
     * @param string $ip_address IP address.
     * @param string $username   Username.
     * @return array Login attempts.
     * @since 1.0.0
     */
    private function get_login_attempts( $ip_address, $username ) {
        $key = "login_attempts_{$ip_address}_{$username}";
        return get_transient( $key ) ?: array();
    }

    /**
     * Get client IP address
     *
     * @return string IP address.
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

                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Get login protection status
     *
     * @return array Status information.
     * @since 1.0.0
     */
    public function get_status() {
        $recent_blocks = $this->get_recent_blocks();
        
        return array(
            'enabled' => true,
            'passed'  => count( $recent_blocks ) < 10, // Not too many recent blocks.
            'message' => sprintf( 'Login protection active (%d recent blocks)', count( $recent_blocks ) ),
        );
    }

    /**
     * Get recently blocked IPs
     *
     * @return array Blocked IPs.
     * @since 1.0.0
     */
    private function get_recent_blocks() {
        // This would query the database for recent blocks.
        return array();
    }
}

// Initialize the security hardening suite.
$security_hardening_suite = new Security_Hardening_Suite();