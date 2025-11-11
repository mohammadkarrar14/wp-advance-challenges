<?php
/**
 * Challenge 6: Advanced Custom Gutenberg Blocks Development
 * 
 * Problem Statement:
 * Create a suite of dynamic, interactive Gutenberg blocks with:
 * 
 * 1. Custom dynamic product display block with real-time data
 * 2. Interactive block patterns and layout variations
 * 3. Server-side rendering for complex data processing
 * 4. Advanced block controls and inspector panels
 * 5. Block transformations and style variations
 * 6. Internationalization and accessibility compliance
 * 7. Block templates and locking features
 * 8. Performance optimization for block rendering
 * 
 * @package wp-advance-challange
 * @version 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Advanced Gutenberg Blocks Manager
 * 
 * Manages registration and functionality of custom Gutenberg blocks
 * with advanced features and optimizations.
 * 
 * @since 1.0.0
 */
class Advanced_Gutenberg_Blocks_Manager {

    /**
     * Registered blocks configuration
     *
     * @var array
     */
    private $blocks = array();

    /**
     * Block categories
     *
     * @var array
     */
    private $block_categories = array();

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        add_action( 'init', array( $this, 'register_blocks' ) );
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_filter( 'block_categories_all', array( $this, 'register_block_categories' ) );
    }

    /**
     * Register custom block categories
     *
     * @param array $categories Existing block categories.
     * @return array Modified block categories.
     * @since 1.0.0
     */
    public function register_block_categories( $categories ) {
        return array_merge(
            $categories,
            array(
                array(
                    'slug'  => 'advanced-blocks',
                    'title' => __( 'Advanced Blocks', 'wordpress-coding-challenge' ),
                    'icon'  => 'admin-customizer',
                ),
                array(
                    'slug'  => 'ecommerce-blocks',
                    'title' => __( 'E-Commerce', 'wordpress-coding-challenge' ),
                    'icon'  => 'cart',
                ),
            )
        );
    }

    /**
     * Register all custom blocks
     *
     * @since 1.0.0
     */
    public function register_blocks() {
        $this->register_product_display_block();
        $this->register_interactive_cta_block();
        $this->register_dynamic_pricing_block();
        $this->register_testimonial_slider_block();
    }

    /**
     * Register Product Display Block
     *
     * @since 1.0.0
     */
    private function register_product_display_block() {
        register_block_type( 'advanced-blocks/product-display', array(
            'api_version'       => 2,
            'title'             => __( 'Product Display', 'wordpress-coding-challenge' ),
            'description'       => __( 'Display products with advanced filtering and layout options.', 'wordpress-coding-challenge' ),
            'category'          => 'ecommerce-blocks',
            'icon'              => 'products',
            'keywords'          => array( 'products', 'ecommerce', 'shop' ),
            'supports'          => array(
                'html'        => false,
                'align'       => array( 'wide', 'full' ),
                'spacing'     => true,
                'typography'  => true,
            ),
            'attributes'        => array(
                'productIds' => array(
                    'type'    => 'array',
                    'default' => array(),
                ),
                'category' => array(
                    'type'    => 'string',
                    'default' => '',
                ),
                'layout' => array(
                    'type'    => 'string',
                    'default' => 'grid',
                ),
                'columns' => array(
                    'type'    => 'number',
                    'default' => 3,
                ),
                'showPrice' => array(
                    'type'    => 'boolean',
                    'default' => true,
                ),
                'showRating' => array(
                    'type'    => 'boolean',
                    'default' => true,
                ),
                'showButton' => array(
                    'type'    => 'boolean',
                    'default' => true,
                ),
                'align' => array(
                    'type'    => 'string',
                    'default' => 'none',
                ),
            ),
            'render_callback'   => array( $this, 'render_product_display_block' ),
            'variations'        => array(
                array(
                    'name'        => 'featured-products',
                    'title'       => __( 'Featured Products', 'wordpress-coding-challenge' ),
                    'description' => __( 'Display featured products in a grid layout.', 'wordpress-coding-challenge' ),
                    'attributes'  => array(
                        'layout' => 'grid',
                        'columns' => 3,
                    ),
                ),
                array(
                    'name'        => 'product-carousel',
                    'title'       => __( 'Product Carousel', 'wordpress-coding-challenge' ),
                    'description' => __( 'Display products in a carousel slider.', 'wordpress-coding-challenge' ),
                    'attributes'  => array(
                        'layout' => 'carousel',
                    ),
                ),
            ),
        ) );
    }

    /**
     * Render callback for Product Display Block
     *
     * @param array  $attributes Block attributes.
     * @param string $content    Block content.
     * @return string Rendered block HTML.
     * @since 1.0.0
     */
    public function render_product_display_block( $attributes, $content ) {
        $args = array(
            'post_type'      => 'product',
            'posts_per_page' => 12,
            'post_status'    => 'publish',
        );

        // Filter by product IDs if specified.
        if ( ! empty( $attributes['productIds'] ) ) {
            $args['post__in'] = array_map( 'absint', $attributes['productIds'] );
            $args['orderby']  = 'post__in';
        }

        // Filter by category if specified.
        if ( ! empty( $attributes['category'] ) ) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'slug',
                    'terms'    => sanitize_text_field( $attributes['category'] ),
                ),
            );
        }

        $products_query = new WP_Query( $args );
        $wrapper_classes = array( 'wp-block-advanced-blocks-product-display' );

        // Add layout class.
        if ( ! empty( $attributes['layout'] ) ) {
            $wrapper_classes[] = 'product-layout-' . sanitize_html_class( $attributes['layout'] );
        }

        // Add alignment class.
        if ( ! empty( $attributes['align'] ) ) {
            $wrapper_classes[] = 'align' . sanitize_html_class( $attributes['align'] );
        }

        ob_start();

        if ( $products_query->have_posts() ) {
            ?>
            <div class="<?php echo esc_attr( implode( ' ', $wrapper_classes ) ); ?>">
                <div class="products-grid columns-<?php echo absint( $attributes['columns'] ); ?>">
                    <?php
                    while ( $products_query->have_posts() ) {
                        $products_query->the_post();
                        global $product;

                        if ( ! $product ) {
                            continue;
                        }
                        ?>
                        <div class="product-card">
                            <div class="product-image">
                                <a href="<?php the_permalink(); ?>">
                                    <?php echo $product->get_image( 'medium' ); ?>
                                </a>
                            </div>
                            
                            <div class="product-details">
                                <h3 class="product-title">
                                    <a href="<?php the_permalink(); ?>">
                                        <?php the_title(); ?>
                                    </a>
                                </h3>

                                <?php if ( $attributes['showRating'] ) : ?>
                                    <div class="product-rating">
                                        <?php echo wc_get_rating_html( $product->get_average_rating() ); ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ( $attributes['showPrice'] ) : ?>
                                    <div class="product-price">
                                        <?php echo $product->get_price_html(); ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ( $attributes['showButton'] ) : ?>
                                    <div class="product-actions">
                                        <a href="<?php echo esc_url( $product->add_to_cart_url() ); ?>" 
                                           class="button add-to-cart-button">
                                            <?php echo esc_html( $product->add_to_cart_text() ); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php
                    }
                    wp_reset_postdata();
                    ?>
                </div>
            </div>
            <?php
        } else {
            ?>
            <div class="wp-block-advanced-blocks-product-display no-products">
                <p><?php esc_html_e( 'No products found.', 'wordpress-coding-challenge' ); ?></p>
            </div>
            <?php
        }

        return ob_get_clean();
    }

    /**
     * Register Interactive CTA Block
     *
     * @since 1.0.0
     */
    private function register_interactive_cta_block() {
        register_block_type( 'advanced-blocks/interactive-cta', array(
            'api_version'     => 2,
            'title'           => __( 'Interactive CTA', 'wordpress-coding-challenge' ),
            'description'     => __( 'A call-to-action block with interactive features.', 'wordpress-coding-challenge' ),
            'category'        => 'advanced-blocks',
            'icon'            => 'megaphone',
            'keywords'        => array( 'cta', 'button', 'action' ),
            'supports'        => array(
                'html'        => false,
                'align'       => true,
                'spacing'     => true,
                'color'       => true,
                'typography'  => true,
            ),
            'attributes'      => array(
                'title' => array(
                    'type'    => 'string',
                    'default' => __( 'Ready to Get Started?', 'wordpress-coding-challenge' ),
                ),
                'description' => array(
                    'type'    => 'string',
                    'default' => __( 'Join thousands of satisfied customers today.', 'wordpress-coding-challenge' ),
                ),
                'buttonText' => array(
                    'type'    => 'string',
                    'default' => __( 'Get Started', 'wordpress-coding-challenge' ),
                ),
                'buttonUrl' => array(
                    'type'    => 'string',
                    'default' => '',
                ),
                'style' => array(
                    'type'    => 'string',
                    'default' => 'primary',
                ),
                'openInNewTab' => array(
                    'type'    => 'boolean',
                    'default' => false,
                ),
                'backgroundImage' => array(
                    'type'    => 'object',
                ),
                'overlayOpacity' => array(
                    'type'    => 'number',
                    'default' => 0.5,
                ),
            ),
            'render_callback' => array( $this, 'render_interactive_cta_block' ),
            'styles'          => array(
                array(
                    'name'  => 'primary',
                    'label' => __( 'Primary', 'wordpress-coding-challenge' ),
                ),
                array(
                    'name'  => 'secondary',
                    'label' => __( 'Secondary', 'wordpress-coding-challenge' ),
                ),
                array(
                    'name'  => 'minimal',
                    'label' => __( 'Minimal', 'wordpress-coding-challenge' ),
                ),
            ),
        ) );
    }

    /**
     * Render callback for Interactive CTA Block
     *
     * @param array  $attributes Block attributes.
     * @param string $content    Block content.
     * @return string Rendered block HTML.
     * @since 1.0.0
     */
    public function render_interactive_cta_block( $attributes, $content ) {
        $wrapper_style = '';
        $overlay_style = '';

        // Handle background image.
        if ( ! empty( $attributes['backgroundImage'] ) ) {
            $background_url = $attributes['backgroundImage']['url'] ?? '';
            if ( $background_url ) {
                $wrapper_style = sprintf(
                    'background-image: url(%s);',
                    esc_url( $background_url )
                );
            }
        }

        // Handle overlay opacity.
        if ( ! empty( $attributes['overlayOpacity'] ) ) {
            $overlay_style = sprintf(
                'opacity: %s;',
                floatval( $attributes['overlayOpacity'] )
            );
        }

        $button_target = $attributes['openInNewTab'] ? '_blank' : '_self';
        $button_rel    = $attributes['openInNewTab'] ? 'noopener noreferrer' : '';

        ob_start();
        ?>
        <div class="wp-block-advanced-blocks-interactive-cta cta-style-<?php echo esc_attr( $attributes['style'] ); ?>" 
             style="<?php echo esc_attr( $wrapper_style ); ?>">
            
            <div class="cta-overlay" style="<?php echo esc_attr( $overlay_style ); ?>"></div>
            
            <div class="cta-content">
                <?php if ( ! empty( $attributes['title'] ) ) : ?>
                    <h3 class="cta-title"><?php echo esc_html( $attributes['title'] ); ?></h3>
                <?php endif; ?>
                
                <?php if ( ! empty( $attributes['description'] ) ) : ?>
                    <p class="cta-description"><?php echo esc_html( $attributes['description'] ); ?></p>
                <?php endif; ?>
                
                <?php if ( ! empty( $attributes['buttonText'] ) && ! empty( $attributes['buttonUrl'] ) ) : ?>
                    <div class="cta-button-wrapper">
                        <a href="<?php echo esc_url( $attributes['buttonUrl'] ); ?>" 
                           class="cta-button" 
                           target="<?php echo esc_attr( $button_target ); ?>" 
                           rel="<?php echo esc_attr( $button_rel ); ?>">
                            <?php echo esc_html( $attributes['buttonText'] ); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Enqueue block editor assets
     *
     * @since 1.0.0
     */
    public function enqueue_editor_assets() {
        $asset_file = include plugin_dir_path( __FILE__ ) . 'build/editor.asset.php';

        wp_enqueue_script(
            'advanced-blocks-editor',
            plugins_url( 'build/editor.js', __FILE__ ),
            $asset_file['dependencies'],
            $asset_file['version'],
            true
        );

        wp_enqueue_style(
            'advanced-blocks-editor',
            plugins_url( 'build/editor.css', __FILE__ ),
            array( 'wp-edit-blocks' ),
            $asset_file['version']
        );

        // Localize script with data for blocks.
        wp_localize_script(
            'advanced-blocks-editor',
            'advancedBlocksData',
            array(
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'advanced_blocks_nonce' ),
                'siteUrl'   => home_url(),
                'restUrl'   => rest_url( 'wp/v2/' ),
            )
        );
    }

    /**
     * Enqueue frontend assets
     *
     * @since 1.0.0
     */
    public function enqueue_frontend_assets() {
        if ( has_block( 'advanced-blocks/product-display' ) || 
             has_block( 'advanced-blocks/interactive-cta' ) ) {

            $asset_file = include plugin_dir_path( __FILE__ ) . 'build/frontend.asset.php';

            wp_enqueue_style(
                'advanced-blocks-frontend',
                plugins_url( 'build/frontend.css', __FILE__ ),
                array(),
                $asset_file['version']
            );

            wp_enqueue_script(
                'advanced-blocks-frontend',
                plugins_url( 'build/frontend.js', __FILE__ ),
                $asset_file['dependencies'],
                $asset_file['version'],
                true
            );
        }
    }

    /**
     * Register block patterns
     *
     * @since 1.0.0
     */
    public function register_block_patterns() {
        register_block_pattern(
            'advanced-blocks/hero-with-product-grid',
            array(
                'title'       => __( 'Hero Section with Product Grid', 'wordpress-coding-challenge' ),
                'description' => _x( 'A hero section followed by a grid of featured products.', 'Block pattern description', 'wordpress-coding-challenge' ),
                'categories'  => array( 'hero', 'products' ),
                'content'     => '<!-- Block pattern content would go here -->',
            )
        );
    }

    /**
     * Register block styles
     *
     * @since 1.0.0
     */
    public function register_block_styles() {
        // Product Display Block Styles.
        register_block_style(
            'advanced-blocks/product-display',
            array(
                'name'  => 'modern-card',
                'label' => __( 'Modern Card', 'wordpress-coding-challenge' ),
            )
        );

        register_block_style(
            'advanced-blocks/product-display',
            array(
                'name'  => 'minimal-list',
                'label' => __( 'Minimal List', 'wordpress-coding-challenge' ),
            )
        );

        // Interactive CTA Block Styles.
        register_block_style(
            'advanced-blocks/interactive-cta',
            array(
                'name'  => 'gradient-background',
                'label' => __( 'Gradient Background', 'wordpress-coding-challenge' ),
            )
        );
    }
}

// Initialize the advanced Gutenberg blocks manager.
$advanced_gutenberg_blocks = new Advanced_Gutenberg_Blocks_Manager();