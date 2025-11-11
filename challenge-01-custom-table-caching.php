<?php
/**
 * Challenge 1: Custom Database Table with Caching System
 * 
 * Problem Statement:
 * Create a product inventory management system that uses a custom database table
 * with proper caching mechanisms. The system should:
 * 
 * 1. Create a custom 'wp_product_inventory' table with proper schema design
 * 2. Implement CRUD operations with parameterized queries
 * 3. Add multi-layer caching (object cache) for performance
 * 4. Handle bulk operations with transaction support
 * 5. Implement proper error handling and database versioning
 * 6. Follow WordPress coding standards and security best practices
 * 
 * @package wp-advance-challange
 * @version 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Product Inventory Manager
 * 
 * Handles custom product inventory table operations with caching
 * and transaction support.
 * 
 * @since 1.0.0
 */
class Product_Inventory_Manager {

    /**
     * Database table name
     *
     * @var string
     */
    private $table_name;

    /**
     * Database version for schema updates
     *
     * @var string
     */
    private $db_version = '1.0.0';

    /**
     * Cache group name
     *
     * @var string
     */
    private $cache_group = 'product_inventory';

    /**
     * Constructor - initializes the table name
     *
     * @since 1.0.0
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'product_inventory';
        
        add_action( 'plugins_loaded', array( $this, 'check_db_version' ) );
    }

    /**
     * Check database version and update if needed
     *
     * @since 1.0.0
     */
    public function check_db_version() {
        $current_version = get_option( 'product_inventory_db_version', '0' );
        
        if ( version_compare( $current_version, $this->db_version, '<' ) ) {
            $this->create_table();
            update_option( 'product_inventory_db_version', $this->db_version );
        }
    }

    /**
     * Create custom database table
     *
     * @since 1.0.0
     */
    private function create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            product_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(200) NOT NULL,
            sku VARCHAR(100) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT '0.00',
            stock_quantity INT(11) NOT NULL DEFAULT 0,
            status ENUM('active','inactive','archived') DEFAULT 'active',
            created_datetime DATETIME DEFAULT CURRENT_TIMESTAMP,
            modified_datetime DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (product_id),
            UNIQUE KEY sku (sku),
            KEY status (status),
            KEY sku_status (sku, status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Insert a new product into inventory
     *
     * @param array $product_data Product data array.
     * @return int|false Product ID on success, false on failure.
     * @since 1.0.0
     */
    public function insert_product( $product_data ) {
        global $wpdb;

        // Validate required fields.
        if ( empty( $product_data['name'] ) || empty( $product_data['sku'] ) ) {
            return false;
        }

        $defaults = array(
            'price'         => 0.00,
            'stock_quantity' => 0,
            'status'        => 'active',
        );

        $product_data = wp_parse_args( $product_data, $defaults );

        // Sanitize data.
        $insert_data = array(
            'name'          => sanitize_text_field( $product_data['name'] ),
            'sku'           => sanitize_text_field( $product_data['sku'] ),
            'price'         => floatval( $product_data['price'] ),
            'stock_quantity' => absint( $product_data['stock_quantity'] ),
            'status'        => in_array( $product_data['status'], array( 'active', 'inactive', 'archived' ), true ) 
                                ? $product_data['status'] 
                                : 'active',
        );

        $result = $wpdb->insert(
            $this->table_name,
            $insert_data,
            array( '%s', '%s', '%f', '%d', '%s' )
        );

        if ( false === $result ) {
            return false;
        }

        $product_id = $wpdb->insert_id;

        // Clear any related caches.
        $this->clear_product_cache( $product_id );
        wp_cache_delete( 'all_products', $this->cache_group );

        /**
         * Fires after a product is inserted
         *
         * @param int   $product_id   The product ID.
         * @param array $product_data The product data.
         * @since 1.0.0
         */
        do_action( 'product_inventory_inserted', $product_id, $product_data );

        return $product_id;
    }

    /**
     * Get product by ID with caching
     *
     * @param int $product_id Product ID.
     * @return array|false Product data array or false if not found.
     * @since 1.0.0
     */
    public function get_product( $product_id ) {
        $product_id = absint( $product_id );
        
        if ( 0 === $product_id ) {
            return false;
        }

        $cache_key  = "product_{$product_id}";
        $cached_product = wp_cache_get( $cache_key, $this->cache_group );

        if ( false !== $cached_product ) {
            return $cached_product;
        }

        global $wpdb;

        $product = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE product_id = %d AND status != 'archived'",
                $product_id
            ),
            ARRAY_A
        );

        if ( is_null( $product ) ) {
            return false;
        }

        // Cache the product for 1 hour.
        wp_cache_set( $cache_key, $product, $this->cache_group, HOUR_IN_SECONDS );

        return $product;
    }

    /**
     * Update product stock quantity
     *
     * @param int $product_id     Product ID.
     * @param int $new_quantity   New stock quantity.
     * @return bool True on success, false on failure.
     * @since 1.0.0
     */
    public function update_stock( $product_id, $new_quantity ) {
        global $wpdb;

        $product_id   = absint( $product_id );
        $new_quantity = absint( $new_quantity );

        if ( 0 === $product_id ) {
            return false;
        }

        $result = $wpdb->update(
            $this->table_name,
            array( 
                'stock_quantity' => $new_quantity,
                'modified_datetime' => current_time( 'mysql' ),
            ),
            array( 'product_id' => $product_id ),
            array( '%d', '%s' ),
            array( '%d' )
        );

        if ( false === $result ) {
            return false;
        }

        $this->clear_product_cache( $product_id );

        /**
         * Fires after product stock is updated
         *
         * @param int $product_id   Product ID.
         * @param int $new_quantity New stock quantity.
         * @since 1.0.0
         */
        do_action( 'product_inventory_stock_updated', $product_id, $new_quantity );

        return true;
    }

    /**
     * Bulk update stock quantities with transaction support
     *
     * @param array $stock_updates Array of product_id => new_quantity pairs.
     * @return bool True on success, false on failure.
     * @since 1.0.0
     */
    public function bulk_update_stock( $stock_updates ) {
        global $wpdb;

        if ( ! is_array( $stock_updates ) || empty( $stock_updates ) ) {
            return false;
        }

        // Start transaction.
        $wpdb->query( 'START TRANSACTION' );

        try {
            foreach ( $stock_updates as $product_id => $new_quantity ) {
                $product_id   = absint( $product_id );
                $new_quantity = absint( $new_quantity );

                if ( 0 === $product_id ) {
                    throw new Exception( 'Invalid product ID' );
                }

                $result = $wpdb->update(
                    $this->table_name,
                    array( 
                        'stock_quantity'  => $new_quantity,
                        'modified_datetime' => current_time( 'mysql' ),
                    ),
                    array( 'product_id' => $product_id ),
                    array( '%d', '%s' ),
                    array( '%d' )
                );

                if ( false === $result ) {
                    throw new Exception( 'Failed to update product stock' );
                }

                // Clear individual product cache.
                $this->clear_product_cache( $product_id );
            }

            // Clear all products cache.
            wp_cache_delete( 'all_products', $this->cache_group );

            // Commit transaction.
            $wpdb->query( 'COMMIT' );

            /**
             * Fires after bulk stock update
             *
             * @param array $stock_updates Stock updates array.
             * @since 1.0.0
             */
            do_action( 'product_inventory_bulk_stock_updated', $stock_updates );

            return true;

        } catch ( Exception $e ) {
            // Rollback transaction on error.
            $wpdb->query( 'ROLLBACK' );
            error_log( 'Bulk stock update failed: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Get low stock products (quantity below threshold)
     *
     * @param int $threshold Stock threshold.
     * @return array Array of low stock products.
     * @since 1.0.0
     */
    public function get_low_stock_products( $threshold = 10 ) {
        $threshold = absint( $threshold );
        $cache_key = "low_stock_{$threshold}";

        $cached = wp_cache_get( $cache_key, $this->cache_group );
        
        if ( false !== $cached ) {
            return $cached;
        }

        global $wpdb;

        $products = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} 
                 WHERE stock_quantity <= %d 
                 AND stock_quantity > 0 
                 AND status = 'active' 
                 ORDER BY stock_quantity ASC",
                $threshold
            ),
            ARRAY_A
        );

        wp_cache_set( $cache_key, $products, $this->cache_group, 15 * MINUTE_IN_SECONDS );

        return $products;
    }

    /**
     * Clear product cache
     *
     * @param int $product_id Product ID.
     * @since 1.0.0
     */
    private function clear_product_cache( $product_id ) {
        $product_id = absint( $product_id );
        
        wp_cache_delete( "product_{$product_id}", $this->cache_group );
        wp_cache_delete( 'all_products', $this->cache_group );
        
        // Clear low stock cache variants.
        $cache_keys = array( 'low_stock_5', 'low_stock_10', 'low_stock_20' );
        foreach ( $cache_keys as $key ) {
            wp_cache_delete( $key, $this->cache_group );
        }
    }

    /**
     * Get all active products with caching
     *
     * @return array Array of active products.
     * @since 1.0.0
     */
    public function get_all_active_products() {
        $cache_key = 'all_products';
        $cached    = wp_cache_get( $cache_key, $this->cache_group );

        if ( false !== $cached ) {
            return $cached;
        }

        global $wpdb;

        $products = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} WHERE status = 'active' ORDER BY name ASC",
            ARRAY_A
        );

        wp_cache_set( $cache_key, $products, $this->cache_group, 30 * MINUTE_IN_SECONDS );

        return $products;
    }
}

// Initialize the product inventory manager.
$product_inventory_manager = new Product_Inventory_Manager();