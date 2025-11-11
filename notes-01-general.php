<?php
/**
 * WP Coding Notes & Examples
 *
 * A single-file reference that follows WordPress Coding Standards (WPCS),
 * PHP_CodeSniffer (PHPCS) friendly docblock style and demonstrates best
 * practices: sanitization, escaping, nonces, i18n, enqueueing, REST, CPT,
 * shortcodes, AJAX, and more.
 *
 * USAGE:
 * - Drop this file into a plugin or theme (as an include) for quick reference.
 * - This is documentation + runnable example code. Read comments; adapt to your project.
 *
 * @package WordPressNotes
 * @version 1.0.0
 *
 * Phpcs / WPCS notes:
 * - This file is formatted to be compatible with WPCS rules.
 * - When copying snippets into production, run PHPCS with the WordPress ruleset:
 *     phpcs --standard=WordPress path/to/file.php
 *
 * Tip: If you keep many of these snippets, consider splitting into well-named files
 * (enqueue.php, rest.php, cpt.php, ajax.php, helpers.php) for clarity and autoloading.
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Prevent direct access.
	exit;
}

/**
 * --------------------------------------------------------------------------
 * 1) TEXTDOMAIN
 * --------------------------------------------------------------------------
 *
 * Use a consistent textdomain for translations. For plugins this is usually the
 * plugin slug. For themes, the theme slug or get_template() is common.
 *
 * Example:
 * esc_html__( 'Hello, world!', 'my-plugin-textdomain' );
 */

/**
 * --------------------------------------------------------------------------
 * 2) ENQUEUE SCRIPTS & STYLES (frontend)
 * --------------------------------------------------------------------------
 *
 * Properly register and enqueue assets, use versioning, dependencies, and
 * conditional loading. Always use wp_add_inline_script/styles sparingly.
 *
 * @return void
 */
function wpcn_enqueue_assets() {
	$version = '1.0.0';

	wp_register_style(
		'wpcn-style',
		plugins_url( 'assets/css/wpcn-style.css', __FILE__ ),
		array(),
		$version
	);

	wp_register_script(
		'wpcn-script',
		plugins_url( 'assets/js/wpcn-script.js', __FILE__ ),
		array( 'jquery' ),
		$version,
		true
	);

	// Localize data for JS - use a single object per script.
	wp_localize_script(
		'wpcn-script',
		'wpcnData',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'wpcn-ajax-nonce' ),
		)
	);

	wp_enqueue_style( 'wpcn-style' );
	wp_enqueue_script( 'wpcn-script' );
}
add_action( 'wp_enqueue_scripts', 'wpcn_enqueue_assets' );

/**
 * --------------------------------------------------------------------------
 * 3) REGISTER A CUSTOM POST TYPE (CPT) - Example 'service'
 * --------------------------------------------------------------------------
 *
 * Keep labels translatable, use supports selectively, and set 'show_in_rest' for Gutenberg.
 *
 * @return void
 */
function wpcn_register_service_cpt() {
	$labels = array(
		'name'               => __( 'Services', 'wpcn' ),
		'singular_name'      => __( 'Service', 'wpcn' ),
		'add_new_item'       => __( 'Add New Service', 'wpcn' ),
		'edit_item'          => __( 'Edit Service', 'wpcn' ),
		'new_item'           => __( 'New Service', 'wpcn' ),
		'view_item'          => __( 'View Service', 'wpcn' ),
		'search_items'       => __( 'Search Services', 'wpcn' ),
		'not_found'          => __( 'No services found', 'wpcn' ),
		'not_found_in_trash' => __( 'No services found in Trash', 'wpcn' ),
	);

	$args = array(
		'labels'             => $labels,
		'public'             => true,
		'show_in_rest'       => true,
		'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
		'has_archive'        => true,
		'rewrite'            => array( 'slug' => 'services' ),
		'menu_position'      => 20,
		'menu_icon'          => 'dashicons-hammer',
	);

	register_post_type( 'service', $args );
}
add_action( 'init', 'wpcn_register_service_cpt' );

/**
 * --------------------------------------------------------------------------
 * 4) SANITIZATION & ESCAPING - helpers
 * --------------------------------------------------------------------------
 */

/**
 * Sanitize an array of post meta fields.
 *
 * @param array $data Raw input.
 * @return array Sanitized data.
 */
function wpcn_sanitize_meta_fields( $data ) {
	$sanitized = array();

	if ( isset( $data['subtitle'] ) ) {
		$sanitized['subtitle'] = sanitize_text_field( wp_unslash( $data['subtitle'] ) );
	}

	if ( isset( $data['url'] ) ) {
		$sanitized['url'] = esc_url_raw( $data['url'] );
	}

	/* Add more fields as needed. */

	return $sanitized;
}

/**
 * Output an escaped heading (example of escaping before output).
 *
 * @param string $text Text to output.
 * @return void
 */
function wpcn_print_heading( $text ) {
	echo '<h2>' . esc_html( $text ) . '</h2>';
}

/**
 * --------------------------------------------------------------------------
 * 5) NONCES & FORM HANDLING (admin/save)
 * --------------------------------------------------------------------------
 *
 * Use wp_nonce_field in the form and check_admin_referer when saving.
 */

/**
 * Save meta for a 'service' CPT.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function wpcn_save_service_meta( $post_id ) {
	// Bail if doing autosave, revision, or user cannot edit.
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// Expect a nonce field named 'wpcn_service_nonce' in form.
	if ( ! isset( $_POST['wpcn_service_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['wpcn_service_nonce'] ), 'wpcn_save_service' ) ) {
		return;
	}

	$sanitized = wpcn_sanitize_meta_fields( $_POST ); // sanitize inside helper

	// Use update_post_meta safely.
	if ( isset( $sanitized['subtitle'] ) ) {
		update_post_meta( $post_id, '_wpcn_subtitle', $sanitized['subtitle'] );
	}

	if ( isset( $sanitized['url'] ) ) {
		update_post_meta( $post_id, '_wpcn_url', $sanitized['url'] );
	}
}
add_action( 'save_post_service', 'wpcn_save_service_meta' );

/**
 * --------------------------------------------------------------------------
 * 6) SHORTCODE (example)
 * --------------------------------------------------------------------------
 *
 * Shortcodes should be sanitized & escaped. Avoid heavy logic in shortcodes.
 *
 * @param array $atts Attributes.
 * @return string HTML output.
 */
function wpcn_services_grid_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'count' => 6,
		),
		$atts,
		'services_grid'
	);

	$limit = absint( $atts['count'] );

	$query = new WP_Query(
		array(
			'post_type'      => 'service',
			'posts_per_page' => $limit,
		)
	);

	ob_start();

	if ( $query->have_posts() ) {
		echo '<div class="wpcn-services-grid">';
		while ( $query->have_posts() ) {
			$query->the_post();
			// Use template tags that escape their output:
			echo '<article id="post-' . esc_attr( get_the_ID() ) . '" class="wpcn-service">';
			the_post_thumbnail( 'medium', array( 'alt' => esc_attr( get_the_title() ) ) ); // WP handles esc for img attrs.
			echo '<h3>' . esc_html( get_the_title() ) . '</h3>';
			echo '<div class="excerpt">' . wp_kses_post( wp_trim_words( wpautop( get_the_excerpt() ), 30, '...' ) ) . '</div>';
			echo '</article>';
		}
		echo '</div>';
		wp_reset_postdata();
	} else {
		/* translators: no services found message */
		echo '<p>' . esc_html__( 'No services found.', 'wpcn' ) . '</p>';
	}

	return ob_get_clean();
}
add_shortcode( 'services_grid', 'wpcn_services_grid_shortcode' );

/**
 * --------------------------------------------------------------------------
 * 7) AJAX HANDLER (frontend, non-privileged)
 * --------------------------------------------------------------------------
 *
 * Always check nonce for AJAX, escape output, and restrict capability for privileged actions.
 */

/**
 * AJAX callback for retrieving a simple list of services.
 *
 * @return void Outputs JSON and exits.
 */
function wpcn_ajax_get_services() {
	// Verify nonce.
	if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ), 'wpcn-ajax-nonce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid nonce', 'wpcn' ) ), 400 );
	}

	$query = new WP_Query(
		array(
			'post_type'      => 'service',
			'posts_per_page' => 10,
		)
	);

	$items = array();

	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();
			$items[] = array(
				'id'    => get_the_ID(),
				'title' => get_the_title(),
				'link'  => get_permalink(),
			);
		}
		wp_reset_postdata();
	}

	wp_send_json_success( $items );
}
add_action( 'wp_ajax_nopriv_wpcn_get_services', 'wpcn_ajax_get_services' );
add_action( 'wp_ajax_wpcn_get_services', 'wpcn_ajax_get_services' );

/**
 * --------------------------------------------------------------------------
 * 8) REST API - register simple endpoint returning services
 * --------------------------------------------------------------------------
 *
 * Use permission callbacks for WP REST. Return WP_Error for failures.
 */

/**
 * Register REST routes for services.
 *
 * @return void
 */
function wpcn_register_rest_routes() {
	register_rest_route(
		'wpcn/v1',
		'/services',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'wpcn_rest_get_services',
			'permission_callback' => '__return_true', // public; change if needed.
		)
	);
}
add_action( 'rest_api_init', 'wpcn_register_rest_routes' );

/**
 * REST callback.
 *
 * @param WP_REST_Request $request Request instance.
 * @return WP_REST_Response|WP_Error
 */
function wpcn_rest_get_services( WP_REST_Request $request ) {
	$limit = (int) $request->get_param( 'per_page' ) ?: 5;

	$query = new WP_Query(
		array(
			'post_type'      => 'service',
			'posts_per_page' => $limit,
		)
	);

	$services = array(); // prepare plain data for JSON.

	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();
			$services[] = array(
				'id'    => get_the_ID(),
				'title' => get_the_title(),
				'excerpt' => get_the_excerpt(),
				'link'  => get_permalink(),
			);
		}
		wp_reset_postdata();
	}

	return rest_ensure_response( $services );
}

/**
 * --------------------------------------------------------------------------
 * 9) EXAMPLE: ADMIN NOTICE (translatable & escaped)
 * --------------------------------------------------------------------------
 *
 * Use sanitize callbacks if storing user options.
 */

/**
 * Show a dismissible admin notice for the plugin.
 *
 * @return void
 */
function wpcn_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	/* translators: 1: plugin name */
	printf(
		'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
		esc_html__( 'WP Coding Notes loaded. Use it as a reference for best practices.', 'wpcn' )
	);
}
add_action( 'admin_notices', 'wpcn_admin_notice' );

/**
 * --------------------------------------------------------------------------
 * 10) UTILITIES & TESTS
 * --------------------------------------------------------------------------
 *
 * - Use unit tests (PHPUnit + WP_UnitTestCase) for critical logic.
 * - For integration tests, use WP-CLI + test suites.
 *
 * Example PHPCS suppression (rarely use):
 * // phpcs:ignore WordPress.Security.NonceVerification.Missing
 */

/**
 * --------------------------------------------------------------------------
 * 11) SECURITY CHECKLIST (short)
 * --------------------------------------------------------------------------
 *
 * - Escape all output. (esc_html, esc_attr, esc_url, wp_kses_post)
 * - Sanitize all inputs. (sanitize_text_field, absint, esc_url_raw)
 * - Validate capabilities. (current_user_can)
 * - Use nonces for form actions and AJAX.
 * - Least-privilege principle: do not grant more capabilities than needed.
 * - Avoid eval(), create_function(), or untrusted callbacks.
 * - Limit file uploads and scan file types if you allow uploads.
 */

/**
 * --------------------------------------------------------------------------
 * 12) PERFORMANCE & BEST PRACTICES (short)
 * --------------------------------------------------------------------------
 *
 * - Cache expensive queries (transients or object cache).
 * - Use selective fields in WP_Query when you only need IDs.
 * - Defer/async non-critical JS.
 * - Use WP_Scripts/WP_Styles properly to avoid duplicate loads.
 * - Offload assets (CDN) for public-facing assets.
 */

/**
 * --------------------------------------------------------------------------
 * 13) ACCESSIBILITY (a11y)
 * --------------------------------------------------------------------------
 *
 * - Use proper ARIA roles & semantic HTML.
 * - Ensure interactive elements are keyboard accessible.
 * - Provide alt text for images.
 * - Use color contrast meeting WCAG standards.
 */

/**
 * --------------------------------------------------------------------------
 * 14) PHPCS / CI / DEV TOOLING
 * --------------------------------------------------------------------------
 *
 * - Use phpcs with WordPress ruleset in CI (GitHub Actions / GitLab CI).
 * - Optionally use phpstan or psalm for static analysis.
 * - Pre-commit hooks: run phpcs and tests.
 *
 * Example GH Actions step (conceptual):
 *   - name: Run PHPCS
 *     run: composer global require "squizlabs/php_codesniffer=*"
 *          phpcs --standard=WordPress path/to/plugin
 *
 * Consider maintaining a phpcs.xml.dist at project root to define custom rules.
 */

/**
 * --------------------------------------------------------------------------
 * 15) WHAT WE CAN ADD (discussion / roadmap)
 * --------------------------------------------------------------------------
 *
 * 1. Settings Page (Options API)
 *    - Add an admin settings page using Settings API with sanitization callbacks.
 *
 * 2. Unit & Integration Tests
 *    - PHPUnit tests for helpers and REST endpoints.
 *    - WP-CLI commands for testing and maintenance.
 *
 * 3. Coding Standards Automation
 *    - Composer dev dependencies (phpcs, phpunit, phpstan).
 *    - Pre-commit hooks (husky or git-hooks) to run linters and tests before push.
 *
 * 4. Template Parts & Block Patterns
 *    - Provide block patterns for Services (PHP & JSON).
 *    - Server-side rendered blocks if you need dynamic markup.
 *
 * 5. Full-featured CRUD UI (React/Gutenberg)
 *    - Build a Gutenberg-based UI for managing services with save flows and meta boxes.
 *
 * 6. Integration Tests / E2E
 *    - Cypress or Playwright for end-to-end tests of public UI.
 *
 * 7. Accessibility Audit
 *    - Run axe-core and fix a11y issues; include a11y unit tests for critical components.
 *
 * 8. Internationalization (L10n) Workflow
 *    - Integrate with a translation platform, generate POT files (makepot), and CI checks for missing translations.
 *
 * 9. Security Hardening
 *    - Content Security Policy (CSP) headers.
 *    - Rate limiting for public endpoints.
 *    - WAF / firewall integration suggestions.
 *
 * 10. Performance Observability
 *    - Add instrumentation for queries, object cache misses, and slow endpoints (NewRelic, Application Insights).
 *
 * 11. Composer Autoloading & Namespacing
 *    - Convert procedural functions to namespaced classes and PSR-4 autoloading via Composer for larger projects.
 *
 * 12. Example README & CONTRIBUTING
 *    - Add README.md with coding standards, how to run tests, and contribution guidelines.
 *
 * --------------------------------------------------------------------------
 * Quick checklist for shipping:
 * - Run PHPCS (fix warnings/errors).
 * - Run PHPStan / Psalm (fix critical issues).
 * - Add unit tests for any critical logic.
 * - Verify REST endpoints return expected JSON.
 * - Manual accessibility & security review.
 * --------------------------------------------------------------------------
 */

/* End of file. */
