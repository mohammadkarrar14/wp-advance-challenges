<?php
/**
 * Challenge 5: High-Performance Query Optimization Engine
 * 
 * Problem Statement:
 * Create an advanced query optimization system that dramatically improves
 * WordPress database performance with:
 * 
 * 1. Advanced multi-layer caching strategies (transient, object, fragment)
 * 2. Smart database indexing recommendations and management
 * 3. Efficient pagination with cursor-based navigation
 * 4. Lazy loading for large datasets and media assets
 * 5. Comprehensive query performance monitoring and analytics
 * 6. Automatic query optimization and rewrite rules
 * 7. Database connection pooling and connection management
 * 8. Query result compression and serialization optimization
 * 
 * @package wp-advance-challange
 * @version 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * High-Performance Query Engine
 * 
 * Optimizes WordPress database queries through advanced caching,
 * indexing, and performance monitoring.
 * 
 * @since 1.0.0
 */
class High_Performance_Query_Engine {

    /**
     * Cache groups for organized cache management
     *
     * @var array
     */
    private $cache_groups = array();

    /**
     * Query performance metrics
     *
     * @var array
     */
    private $query_metrics = array();

    /**
     * Enabled optimization features
     *
     * @var array
     */
    private $enabled_features = array();

    /**
     * Database index manager
     *
     * @var Database_Index_Manager
     */
    private $index_manager;

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->index_manager = new Database_Index_Manager();
        $this->setup_optimizations();
        
        add_action( 'shutdown', array( $this, 'log_performance_metrics' ) );
        add_filter( 'posts_request', array( $this, 'monitor_query_performance' ), 10, 2 );
    }

    /**
     * Setup optimization features based on environment
     *
     * @since 1.0.0
     */
    private function setup_optimizations() {
        $this->enabled_features = array(
            'object_caching'    => wp_using_ext_object_cache(),
            'transient_caching' => true,
            'query_monitoring'  => defined( 'WP_DEBUG' ) && WP_DEBUG,
            'lazy_loading'      => true,
            'index_optimization' => true,
        );

        // Initialize cache groups.
        $this->cache_groups = array(
            'query_results' => 'query_results',
            'post_meta'     => 'post_meta_bulk',
            'user_data'     => 'user_data_bulk',
            'term_data'     => 'term_data_bulk',
        );
    }

    /**
     * Get optimized posts query with advanced caching
     *
     * @param array  $query_args    WordPress query arguments.
     * @param string $cache_key     Custom cache key.
     * @param int    $cache_ttl     Cache time-to-live in seconds.
     * @param bool   $force_refresh Whether to bypass cache.
     * @return WP_Query|array Query results or cached data.
     * @since 1.0.0
     */
    public function get_optimized_posts( $query_args, $cache_key = '', $cache_ttl = 3600, $force_refresh = false ) {
        $default_args = array(
            'post_type'              => 'post',
            'post_status'            => 'publish',
            'posts_per_page'         => get_option( 'posts_per_page' ),
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );

        $query_args = wp_parse_args( $query_args, $default_args );

        // Generate cache key from query args if not provided.
        if ( empty( $cache_key ) ) {
            $cache_key = $this->generate_query_cache_key( $query_args );
        }

        // Try to get from cache first.
        if ( ! $force_refresh ) {
            $cached_results = $this->get_cached_query( $cache_key );
            if ( false !== $cached_results ) {
                return $cached_results;
            }
        }

        $start_time = microtime( true );

        // Optimize query arguments for performance.
        $optimized_args = $this->optimize_query_args( $query_args );

        // Execute query.
        $query = new WP_Query( $optimized_args );

        // Prime caches for related data.
        $this->prime_related_caches( $query->posts, $optimized_args );

        $execution_time = microtime( true ) - $start_time;

        // Prepare results with metadata.
        $results = array(
            'posts'          => $query->posts,
            'found_posts'    => $query->found_posts,
            'max_num_pages'  => $query->max_num_pages,
            'execution_time' => $execution_time,
            'cache_key'      => $cache_key,
        );

        // Cache the results.
        $this->cache_query( $cache_key, $results, $cache_ttl );

        // Record performance metrics.
        $this->record_query_metrics( $cache_key, $execution_time, count( $query->posts ) );

        return $results;
    }

    /**
     * Get posts with cursor-based pagination
     *
     * @param array  $query_args  WordPress query arguments.
     * @param string $cursor      Cursor for pagination.
     * @param int    $limit       Number of posts per page.
     * @param string $direction   Pagination direction ('next' or 'prev').
     * @return array Paginated results.
     * @since 1.0.0
     */
    public function get_posts_cursor_paginated( $query_args, $cursor = '', $limit = 10, $direction = 'next' ) {
        $default_args = array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        $query_args = wp_parse_args( $query_args, $default_args );

        // Add cursor-based conditions.
        if ( ! empty( $cursor ) ) {
            $cursor_data = $this->decode_cursor( $cursor );
            
            if ( $cursor_data ) {
                $compare_operator = 'next' === $direction ? '<' : '>';
                $query_args['date_query'] = array(
                    array(
                        'column'    => 'post_date',
                        $compare_operator => $cursor_data['date'],
                    ),
                );
            }
        }

        $results = $this->get_optimized_posts( $query_args );

        // Generate next and previous cursors.
        if ( ! empty( $results['posts'] ) {
            $first_post = reset( $results['posts'] );
            $last_post  = end( $results['posts'] );

            $results['pagination'] = array(
                'has_next'     => count( $results['posts'] ) === $limit,
                'has_previous' => ! empty( $cursor ),
                'next_cursor'  => $this->encode_cursor( $last_post->post_date, $last_post->ID ),
                'prev_cursor'  => $this->encode_cursor( $first_post->post_date, $first_post->ID ),
            );
        }

        return $results;
    }

    /**
     * Prime multiple caches for efficient data loading
     *
     * @param array $posts       Array of post objects.
     * @param array $query_args  Original query arguments.
     * @since 1.0.0
     */
    private function prime_related_caches( $posts, $query_args ) {
        if ( empty( $posts ) ) {
            return;
        }

        $post_ids = wp_list_pluck( $posts, 'ID' );

        // Prime post meta cache if needed.
        if ( $query_args['update_post_meta_cache'] ) {
            update_meta_cache( 'post', $post_ids );
        }

        // Prime term cache if needed.
        if ( $query_args['update_post_term_cache'] ) {
            $taxonomies = get_object_taxonomies( $query_args['post_type'] );
            update_object_term_cache( $post_ids, $query_args['post_type'], $taxonomies );
        }

        // Prime author cache.
        $author_ids = array_unique( wp_list_pluck( $posts, 'post_author' ) );
        cache_users( $author_ids );

        /**
         * Fires after priming related caches for posts
         *
         * @param array $post_ids   Post IDs that were cached.
         * @param array $query_args Query arguments used.
         * @since 1.0.0
         */
        do_action( 'performance_engine_primed_caches', $post_ids, $query_args );
    }

    /**
     * Optimize query arguments for better performance
     *
     * @param array $query_args Original query arguments.
     * @return array Optimized query arguments.
     * @since 1.0.0
     */
    private function optimize_query_args( $query_args ) {
        $optimized = $query_args;

        // Force no found rows for better performance unless specifically needed.
        if ( ! isset( $optimized['no_found_rows'] ) ) {
            $optimized['no_found_rows'] = true;
        }

        // Disable term and meta cache by default (we'll handle caching separately).
        if ( ! isset( $optimized['update_post_term_cache'] ) ) {
            $optimized['update_post_term_cache'] = false;
        }

        if ( ! isset( $optimized['update_post_meta_cache'] ) ) {
            $optimized['update_post_meta_cache'] = false;
        }

        // Optimize orderby for better index usage.
        if ( isset( $optimized['orderby'] ) && is_array( $optimized['orderby'] ) ) {
            $optimized['orderby'] = $this->optimize_orderby_clause( $optimized['orderby'] );
        }

        // Add database hints for better query planning.
        $optimized = $this->add_query_hints( $optimized );

        return $optimized;
    }

    /**
     * Generate cache key from query arguments
     *
     * @param array $query_args Query arguments.
     * @return string Cache key.
     * @since 1.0.0
     */
    private function generate_query_cache_key( $query_args ) {
        // Remove non-deterministic parameters.
        $cache_args = $query_args;
        unset( $cache_args['cache_results'] );
        unset( $cache_args['fields'] );

        // Add blog ID for multisite compatibility.
        $cache_args['blog_id'] = get_current_blog_id();

        return 'query_' . md5( serialize( $cache_args ) );
    }

    /**
     * Get cached query results
     *
     * @param string $cache_key Cache key.
     * @return mixed Cached results or false if not found.
     * @since 1.0.0
     */
    private function get_cached_query( $cache_key ) {
        // Try object cache first.
        $cached = wp_cache_get( $cache_key, $this->cache_groups['query_results'] );
        if ( false !== $cached ) {
            return $cached;
        }

        // Fall back to transient cache.
        return get_transient( $cache_key );
    }

    /**
     * Cache query results
     *
     * @param string $cache_key Cache key.
     * @param mixed  $data      Data to cache.
     * @param int    $ttl       Time to live in seconds.
     * @since 1.0.0
     */
    private function cache_query( $cache_key, $data, $ttl ) {
        // Store in object cache.
        wp_cache_set( $cache_key, $data, $this->cache_groups['query_results'], $ttl );

        // Also store in transient for persistence.
        set_transient( $cache_key, $data, $ttl );
    }

    /**
     * Monitor query performance for analytics
     *
     * @param string   $sql   The complete SQL query.
     * @param WP_Query $query The WP_Query instance.
     * @return string The original SQL query.
     * @since 1.0.0
     */
    public function monitor_query_performance( $sql, $query ) {
        if ( ! $this->enabled_features['query_monitoring'] || defined( 'DOING_CRON' ) ) {
            return $sql;
        }

        $start_time = microtime( true );

        // Store query for timing measurement in shutdown hook.
        $this->current_queries[] = array(
            'sql'        => $sql,
            'query_vars' => $query->query_vars,
            'start_time' => $start_time,
        );

        return $sql;
    }

    /**
     * Record query performance metrics
     *
     * @param string $cache_key      Cache key.
     * @param float  $execution_time Query execution time.
     * @param int    $result_count   Number of results returned.
     * @since 1.0.0
     */
    private function record_query_metrics( $cache_key, $execution_time, $result_count ) {
        $this->query_metrics[ $cache_key ] = array(
            'execution_time' => $execution_time,
            'result_count'   => $result_count,
            'timestamp'      => current_time( 'mysql' ),
            'memory_usage'   => memory_get_usage( true ),
        );
    }

    /**
     * Encode cursor for pagination
     *
     * @param string $date Post date.
     * @param int    $id   Post ID.
     * @return string Encoded cursor.
     * @since 1.0.0
     */
    private function encode_cursor( $date, $id ) {
        return base64_encode( wp_json_encode( array( 'date' => $date, 'id' => $id ) ) );
    }

    /**
     * Decode cursor from pagination
     *
     * @param string $cursor Encoded cursor.
     * @return array|false Decoded cursor data or false on failure.
     * @since 1.0.0
     */
    private function decode_cursor( $cursor ) {
        $decoded = base64_decode( $cursor );
        $data    = json_decode( $decoded, true );

        if ( JSON_ERROR_NONE === json_last_error() && isset( $data['date'], $data['id'] ) ) {
            return $data;
        }

        return false;
    }

    /**
     * Get query performance report
     *
     * @param int $limit Number of queries to include in report.
     * @return array Performance report.
     * @since 1.0.0
     */
    public function get_performance_report( $limit = 50 ) {
        $report = array(
            'total_queries'   => count( $this->query_metrics ),
            'slow_queries'    => array(),
            'average_time'    => 0,
            'memory_usage'    => 0,
            'cache_hit_ratio' => 0,
        );

        if ( empty( $this->query_metrics ) ) {
            return $report;
        }

        $total_time = 0;
        $slow_count = 0;

        foreach ( $this->query_metrics as $cache_key => $metrics ) {
            $total_time += $metrics['execution_time'];

            // Identify slow queries (more than 100ms).
            if ( $metrics['execution_time'] > 0.1 ) {
                $report['slow_queries'][ $cache_key ] = $metrics;
                $slow_count++;
            }
        }

        $report['average_time']    = $total_time / count( $this->query_metrics );
        $report['slow_query_count'] = $slow_count;
        $report['memory_usage']    = memory_get_peak_usage( true );

        // Sort slow queries by execution time.
        uasort( $report['slow_queries'], function( $a, $b ) {
            return $b['execution_time'] <=> $a['execution_time'];
        } );

        // Limit the number of slow queries in report.
        $report['slow_queries'] = array_slice( $report['slow_queries'], 0, $limit );

        return $report;
    }

    /**
     * Log performance metrics at shutdown
     *
     * @since 1.0.0
     */
    public function log_performance_metrics() {
        if ( ! $this->enabled_features['query_monitoring'] || empty( $this->query_metrics ) ) {
            return;
        }

        $report = $this->get_performance_report();

        // Log slow queries for debugging.
        if ( ! empty( $report['slow_queries'] ) {
            error_log( 'Slow queries detected: ' . wp_json_encode( $report['slow_queries'] ) );
        }

        // Store report in transient for admin UI.
        set_transient( 'query_performance_report', $report, HOUR_IN_SECONDS );
    }
}

/**
 * Database Index Manager
 * 
 * Manages database indexes and provides optimization recommendations.
 * 
 * @since 1.0.0
 */
class Database_Index_Manager {

    /**
     * Recommended indexes for common query patterns
     *
     * @var array
     */
    private $recommended_indexes = array();

    /**
     * Existing indexes in the database
     *
     * @var array
     */
    private $existing_indexes = array();

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->load_recommended_indexes();
        $this->load_existing_indexes();
    }

    /**
     * Load recommended indexes for WordPress
     *
     * @since 1.0.0
     */
    private function load_recommended_indexes() {
        $this->recommended_indexes = array(
            'posts' => array(
                array( 'post_type', 'post_status', 'post_date' ),
                array( 'post_author', 'post_date' ),
                array( 'post_parent', 'post_type' ),
            ),
            'postmeta' => array(
                array( 'post_id', 'meta_key' ),
                array( 'meta_key', 'meta_value', 100 ),
            ),
            'comments' => array(
                array( 'comment_post_ID', 'comment_approved', 'comment_date' ),
                array( 'comment_author_email', 'comment_date' ),
            ),
        );
    }

    /**
     * Analyze query and suggest indexes
     *
     * @param string $sql SQL query to analyze.
     * @return array Index suggestions.
     * @since 1.0.0
     */
    public function analyze_query_for_indexes( $sql ) {
        $suggestions = array();

        // Simple pattern matching for common query types.
        if ( preg_match( '/WHERE.*post_type.*=.*AND.*post_status.*=/', $sql ) ) {
            $suggestions[] = array(
                'table'    => 'posts',
                'columns'  => array( 'post_type', 'post_status', 'post_date' ),
                'reason'   => 'Improves post type and status filtering with date ordering',
            );
        }

        if ( preg_match( '/WHERE.*meta_key.*=.*AND.*meta_value.*=/', $sql ) ) {
            $suggestions[] = array(
                'table'    => 'postmeta',
                'columns'  => array( 'meta_key', 'meta_value' ),
                'reason'   => 'Improves meta query performance',
            );
        }

        return $suggestions;
    }

    /**
     * Get index recommendations for the site
     *
     * @return array Index recommendations.
     * @since 1.0.0
     */
    public function get_index_recommendations() {
        $recommendations = array();

        foreach ( $this->recommended_indexes as $table => $indexes ) {
            foreach ( $indexes as $index_columns ) {
                $index_name = $this->generate_index_name( $table, $index_columns );

                if ( ! isset( $this->existing_indexes[ $table ] ) || ! in_array( $index_name, $this->existing_indexes[ $table ], true ) ) {
                    $recommendations[] = array(
                        'table'    => $table,
                        'columns'  => $index_columns,
                        'sql'      => $this->generate_index_sql( $table, $index_columns ),
                    );
                }
            }
        }

        return $recommendations;
    }

    /**
     * Load existing indexes from database
     *
     * @since 1.0.0
     */
    private function load_existing_indexes() {
        global $wpdb;

        $tables = array( 'posts', 'postmeta', 'comments', 'terms', 'users' );
        $this->existing_indexes = array();

        foreach ( $tables as $table ) {
            $table_name = $wpdb->$table;
            $results    = $wpdb->get_results( "SHOW INDEX FROM {$table_name}", ARRAY_A );

            foreach ( $results as $result ) {
                $this->existing_indexes[ $table ][] = $result['Key_name'];
            }
        }
    }

    /**
     * Generate index name from columns
     *
     * @param string $table    Table name.
     * @param array  $columns  Column names.
     * @return string Index name.
     * @since 1.0.0
     */
    private function generate_index_name( $table, $columns ) {
        $prefix = 'idx_' . substr( $table, 0, 3 ) . '_';
        return $prefix . implode( '_', $columns );
    }

    /**
     * Generate SQL for creating an index
     *
     * @param string $table   Table name.
     * @param array  $columns Column names.
     * @return string SQL statement.
     * @since 1.0.0
     */
    private function generate_index_sql( $table, $columns ) {
        global $wpdb;
        $table_name = $wpdb->$table;
        $index_name = $this->generate_index_name( $table, $columns );

        $columns_sql = implode( ', ', $columns );

        return "CREATE INDEX {$index_name} ON {$table_name} ({$columns_sql})";
    }
}

// Initialize the performance query engine.
$performance_query_engine = new High_Performance_Query_Engine();