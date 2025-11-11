<?php
/**
 * Challenge 13: Advanced Search Engine with Elasticsearch Integration
 * 
 * Problem Statement:
 * Create an advanced search system that extends WordPress search with:
 * 
 * 1. Elasticsearch integration for high-performance searching
 * 2. Fuzzy matching and typo tolerance
 * 3. Faceted search with multiple filter categories
 * 4. Search result ranking and relevance scoring
 * 5. Search analytics and query performance tracking
 * 6. Search suggestions and autocomplete
 * 7. Multi-language and synonym support
 * 8. Real-time search index updates
 * 
 * @package WordPressCodingChallenge
 * @version 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Advanced Search Engine
 * 
 * Extends WordPress search with Elasticsearch integration,
 * fuzzy matching, faceted search, and advanced analytics.
 * 
 * @since 1.0.0
 */
class Advanced_Search_Engine {

    /**
     * Elasticsearch client instance
     *
     * @var object
     */
    private $elasticsearch_client;

    /**
     * Search index name
     *
     * @var string
     */
    private $index_name;

    /**
     * Search analytics tracker
     *
     * @var Search_Analytics
     */
    private $analytics;

    /**
     * Facet configurations
     *
     * @var array
     */
    private $facets = array();

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->index_name = $this->get_index_name();
        $this->analytics = new Search_Analytics();
        
        add_action( 'init', array( $this, 'init_search_engine' ) );
        add_filter( 'pre_get_posts', array( $this, 'enhance_search_query' ) );
        add_action( 'wp_ajax_search_autocomplete', array( $this, 'handle_autocomplete' ) );
        add_action( 'wp_ajax_nopriv_search_autocomplete', array( $this, 'handle_autocomplete' ) );
    }

    /**
     * Initialize search engine
     *
     * @since 1.0.0
     */
    public function init_search_engine() {
        $this->setup_elasticsearch();
        $this->setup_facets();
        
        if ( $this->is_elasticsearch_available() ) {
            add_filter( 'posts_search', array( $this, 'replace_wp_search' ), 10, 2 );
        }
    }

    /**
     * Setup Elasticsearch client
     *
     * @since 1.0.0
     */
    private function setup_elasticsearch() {
        $elasticsearch_host = get_option( 'elasticsearch_host', 'http://localhost:9200' );
        
        if ( class_exists( 'Elasticsearch\ClientBuilder' ) ) {
            $this->elasticsearch_client = Elasticsearch\ClientBuilder::create()
                ->setHosts( array( $elasticsearch_host ) )
                ->build();
        }
    }

    /**
     * Setup search facets
     *
     * @since 1.0.0
     */
    private function setup_facets() {
        $this->facets = array(
            'post_type' => array(
                'field'   => 'post_type',
                'label'   => __( 'Content Type', 'wordpress-coding-challenge' ),
                'type'    => 'terms',
                'size'    => 10,
            ),
            'category' => array(
                'field'   => 'categories.name',
                'label'   => __( 'Categories', 'wordpress-coding-challenge' ),
                'type'    => 'terms',
                'size'    => 15,
            ),
            'date' => array(
                'field'   => 'post_date',
                'label'   => __( 'Date', 'wordpress-coding-challenge' ),
                'type'    => 'date_histogram',
                'interval' => 'month',
            ),
            'author' => array(
                'field'   => 'author.name',
                'label'   => __( 'Author', 'wordpress-coding-challenge' ),
                'type'    => 'terms',
                'size'    => 10,
            ),
        );

        /**
         * Filter to modify search facets
         *
         * @param array $facets Search facet configurations.
         * @since 1.0.0
         */
        $this->facets = apply_filters( 'search_engine_facets', $this->facets );
    }

    /**
     * Check if Elasticsearch is available
     *
     * @return bool True if Elasticsearch is available.
     * @since 1.0.0
     */
    private function is_elasticsearch_available() {
        if ( ! $this->elasticsearch_client ) {
            return false;
        }

        try {
            $this->elasticsearch_client->ping();
            return true;
        } catch ( Exception $e ) {
            return false;
        }
    }

    /**
     * Replace WordPress default search with Elasticsearch
     *
     * @param string   $search SQL search clause.
     * @param WP_Query $query  WordPress query object.
     * @return string Modified search clause.
     * @since 1.0.0
     */
    public function replace_wp_search( $search, $query ) {
        if ( ! $query->is_search() || ! $query->is_main_query() ) {
            return $search;
        }

        $search_query = get_search_query();
        
        if ( empty( $search_query ) ) {
            return $search;
        }

        // Track search query.
        $this->analytics->track_search_query( $search_query );

        // Perform Elasticsearch search.
        $results = $this->elasticsearch_search( $search_query, $query->query_vars );

        if ( ! empty( $results ) ) {
            // Modify query to use post__in with Elasticsearch results.
            $query->set( 's', '' );
            $query->set( 'post__in', $results['post_ids'] );
            $query->set( 'orderby', 'post__in' );
            
            // Store facets for template use.
            $query->search_facets = $results['facets'];
            $query->search_stats = $results['stats'];
        }

        return $search;
    }

    /**
     * Perform Elasticsearch search
     *
     * @param string $search_query Search query string.
     * @param array  $query_vars   WordPress query variables.
     * @return array Search results.
     * @since 1.0.0
     */
    public function elasticsearch_search( $search_query, $query_vars = array() ) {
        $params = array(
            'index' => $this->index_name,
            'body'  => array(
                'query' => $this->build_search_query( $search_query, $query_vars ),
                'aggs'  => $this->build_facet_aggregations(),
                'size'  => $query_vars['posts_per_page'] ?? 10,
                'from'  => ( ( $query_vars['paged'] ?? 1 ) - 1 ) * ( $query_vars['posts_per_page'] ?? 10 ),
            ),
        );

        try {
            $response = $this->elasticsearch_client->search( $params );
            return $this->process_elasticsearch_response( $response );
        } catch ( Exception $e ) {
            error_log( 'Elasticsearch search error: ' . $e->getMessage() );
            return array();
        }
    }

    /**
     * Build Elasticsearch search query
     *
     * @param string $search_query Search query string.
     * @param array  $query_vars   WordPress query variables.
     * @return array Elasticsearch query.
     * @since 1.0.0
     */
    private function build_search_query( $search_query, $query_vars ) {
        $query = array(
            'bool' => array(
                'must'   => array(),
                'filter' => array(),
            ),
        );

        // Main search query with fuzzy matching.
        if ( ! empty( $search_query ) ) {
            $query['bool']['must'][] = array(
                'multi_match' => array(
                    'query'  => $search_query,
                    'fields' => array(
                        'post_title^3',
                        'post_content^2',
                        'post_excerpt^2',
                        'categories.name^1.5',
                        'tags.name^1.5',
                        'author.name^1',
                    ),
                    'fuzziness' => 'AUTO',
                    'operator'  => 'and',
                ),
            );
        }

        // Add filters from query vars.
        $filters = $this->build_filters_from_query_vars( $query_vars );
        if ( ! empty( $filters ) ) {
            $query['bool']['filter'] = $filters;
        }

        return $query;
    }

    /**
     * Build filters from WordPress query variables
     *
     * @param array $query_vars WordPress query variables.
     * @return array Elasticsearch filters.
     * @since 1.0.0
     */
    private function build_filters_from_query_vars( $query_vars ) {
        $filters = array();

        // Post type filter.
        if ( ! empty( $query_vars['post_type'] ) ) {
            $post_types = is_array( $query_vars['post_type'] ) ? $query_vars['post_type'] : array( $query_vars['post_type'] );
            $filters[] = array(
                'terms' => array( 'post_type' => $post_types ),
            );
        }

        // Category filter.
        if ( ! empty( $query_vars['category_name'] ) ) {
            $filters[] = array(
                'term' => array( 'categories.slug' => $query_vars['category_name'] ),
            );
        }

        // Author filter.
        if ( ! empty( $query_vars['author'] ) ) {
            $filters[] = array(
                'term' => array( 'author.id' => absint( $query_vars['author'] ) ),
            );
        }

        // Date filters.
        if ( ! empty( $query_vars['year'] ) ) {
            $filters[] = array(
                'range' => array(
                    'post_date' => array(
                        'gte' => $query_vars['year'] . '-01-01',
                        'lte' => $query_vars['year'] . '-12-31',
                    ),
                ),
            );
        }

        return $filters;
    }

    /**
     * Build facet aggregations
     *
     * @return array Elasticsearch aggregations.
     * @since 1.0.0
     */
    private function build_facet_aggregations() {
        $aggs = array();

        foreach ( $this->facets as $facet_name => $facet_config ) {
            switch ( $facet_config['type'] ) {
                case 'terms':
                    $aggs[ $facet_name ] = array(
                        'terms' => array(
                            'field' => $facet_config['field'],
                            'size'  => $facet_config['size'] ?? 10,
                        ),
                    );
                    break;

                case 'date_histogram':
                    $aggs[ $facet_name ] = array(
                        'date_histogram' => array(
                            'field'    => $facet_config['field'],
                            'interval' => $facet_config['interval'] ?? 'month',
                            'format'   => 'yyyy-MM',
                        ),
                    );
                    break;
            }
        }

        return $aggs;
    }

    /**
     * Process Elasticsearch response
     *
     * @param array $response Elasticsearch response.
     * @return array Processed results.
     * @since 1.0.0
     */
    private function process_elasticsearch_response( $response ) {
        $results = array(
            'post_ids' => array(),
            'facets'   => array(),
            'stats'    => array(
                'total' => $response['hits']['total']['value'] ?? 0,
                'took'  => $response['took'] ?? 0,
            ),
        );

        // Extract post IDs.
        foreach ( $response['hits']['hits'] as $hit ) {
            $results['post_ids'][] = $hit['_source']['post_id'];
        }

        // Process facets.
        if ( isset( $response['aggregations'] ) ) {
            $results['facets'] = $this->process_facets( $response['aggregations'] );
        }

        return $results;
    }

    /**
     * Process facet aggregations
     *
     * @param array $aggregations Elasticsearch aggregations.
     * @return array Processed facets.
     * @since 1.0.0
     */
    private function process_facets( $aggregations ) {
        $facets = array();

        foreach ( $this->facets as $facet_name => $facet_config ) {
            if ( ! isset( $aggregations[ $facet_name ] ) ) {
                continue;
            }

            $agg_data = $aggregations[ $facet_name ];
            $facets[ $facet_name ] = array(
                'label' => $facet_config['label'],
                'type'  => $facet_config['type'],
                'values' => array(),
            );

            switch ( $facet_config['type'] ) {
                case 'terms':
                    foreach ( $agg_data['buckets'] as $bucket ) {
                        $facets[ $facet_name ]['values'][] = array(
                            'value' => $bucket['key'],
                            'count' => $bucket['doc_count'],
                        );
                    }
                    break;

                case 'date_histogram':
                    foreach ( $agg_data['buckets'] as $bucket ) {
                        $facets[ $facet_name ]['values'][] = array(
                            'value' => $bucket['key_as_string'],
                            'count' => $bucket['doc_count'],
                        );
                    }
                    break;
            }
        }

        return $facets;
    }

    /**
     * Enhance WordPress search query with additional features
     *
     * @param WP_Query $query WordPress query object.
     * @since 1.0.0
     */
    public function enhance_search_query( $query ) {
        if ( ! $query->is_search() || ! $query->is_main_query() ) {
            return;
        }

        // Add post type support for search.
        if ( empty( $query->get( 'post_type' ) ) {
            $query->set( 'post_type', $this->get_searchable_post_types() );
        }

        // Add meta query for additional search fields.
        $meta_query = $query->get( 'meta_query' ) ?: array();
        
        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key'     => '_search_priority',
                'value'   => 0,
                'compare' => '>',
                'type'    => 'NUMERIC',
            ),
        );

        $query->set( 'meta_query', $meta_query );
    }

    /**
     * Get searchable post types
     *
     * @return array Searchable post types.
     * @since 1.0.0
     */
    private function get_searchable_post_types() {
        $post_types = get_post_types( array( 'exclude_from_search' => false ) );
        
        /**
         * Filter searchable post types
         *
         * @param array $post_types Searchable post types.
         * @since 1.0.0
         */
        return apply_filters( 'search_engine_post_types', $post_types );
    }

    /**
     * Handle search autocomplete
     *
     * @since 1.0.0
     */
    public function handle_autocomplete() {
        check_ajax_referer( 'search_autocomplete_nonce', 'nonce' );

        $query = sanitize_text_field( $_GET['q'] ?? '' );
        
        if ( empty( $query ) ) {
            wp_send_json_error( 'Empty query' );
        }

        $suggestions = $this->get_search_suggestions( $query );
        
        wp_send_json_success( array(
            'query'       => $query,
            'suggestions' => $suggestions,
        ) );
    }

    /**
     * Get search suggestions for autocomplete
     *
     * @param string $query Search query.
     * @return array Search suggestions.
     * @since 1.0.0
     */
    private function get_search_suggestions( $query ) {
        $suggestions = array();

        // Get popular searches.
        $popular_searches = $this->analytics->get_popular_searches( $query, 5 );
        
        foreach ( $popular_searches as $search ) {
            $suggestions[] = array(
                'type'  => 'popular',
                'value' => $search['query'],
                'count' => $search['count'],
            );
        }

        // Get content suggestions.
        $content_suggestions = $this->get_content_suggestions( $query );
        $suggestions = array_merge( $suggestions, $content_suggestions );

        return $suggestions;
    }

    /**
     * Get content-based suggestions
     *
     * @param string $query Search query.
     * @return array Content suggestions.
     * @since 1.0.0
     */
    private function get_content_suggestions( $query ) {
        $suggestions = array();

        // Search post titles.
        $posts = get_posts( array(
            's'              => $query,
            'post_type'      => $this->get_searchable_post_types(),
            'posts_per_page' => 5,
            'fields'         => 'ids',
        ) );

        foreach ( $posts as $post_id ) {
            $suggestions[] = array(
                'type'  => 'post',
                'value' => get_the_title( $post_id ),
                'url'   => get_permalink( $post_id ),
            );
        }

        // Search categories and tags.
        $terms = get_terms( array(
            'taxonomy'   => array( 'category', 'post_tag' ),
            'name__like' => $query,
            'number'     => 5,
            'hide_empty' => true,
        ) );

        foreach ( $terms as $term ) {
            $suggestions[] = array(
                'type'  => 'term',
                'value' => $term->name,
                'url'   => get_term_link( $term ),
            );
        }

        return $suggestions;
    }

    /**
     * Index a post in Elasticsearch
     *
     * @param int $post_id Post ID.
     * @since 1.0.0
     */
    public function index_post( $post_id ) {
        if ( ! $this->is_elasticsearch_available() ) {
            return;
        }

        $post = get_post( $post_id );
        
        if ( ! $post || ! in_array( $post->post_type, $this->get_searchable_post_types(), true ) ) {
            return;
        }

        $document = $this->prepare_post_document( $post );
        
        $params = array(
            'index' => $this->index_name,
            'id'    => $post_id,
            'body'  => $document,
        );

        try {
            $this->elasticsearch_client->index( $params );
        } catch ( Exception $e ) {
            error_log( 'Elasticsearch index error: ' . $e->getMessage() );
        }
    }

    /**
     * Prepare post document for indexing
     *
     * @param WP_Post $post Post object.
     * @return array Document data.
     * @since 1.0.0
     */
    private function prepare_post_document( $post ) {
        $document = array(
            'post_id'      => $post->ID,
            'post_title'   => $post->post_title,
            'post_content' => wp_strip_all_tags( $post->post_content ),
            'post_excerpt' => wp_strip_all_tags( $post->post_excerpt ),
            'post_type'    => $post->post_type,
            'post_status'  => $post->post_status,
            'post_date'    => $post->post_date,
            'post_modified' => $post->post_modified,
            'author'       => array(
                'id'   => $post->post_author,
                'name' => get_the_author_meta( 'display_name', $post->post_author ),
            ),
        );

        // Add categories.
        $categories = get_the_terms( $post->ID, 'category' );
        if ( $categories && ! is_wp_error( $categories ) ) {
            $document['categories'] = array();
            foreach ( $categories as $category ) {
                $document['categories'][] = array(
                    'id'   => $category->term_id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                );
            }
        }

        // Add tags.
        $tags = get_the_terms( $post->ID, 'post_tag' );
        if ( $tags && ! is_wp_error( $tags ) ) {
            $document['tags'] = array();
            foreach ( $tags as $tag ) {
                $document['tags'][] = array(
                    'id'   => $tag->term_id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                );
            }
        }

        /**
         * Filter document before indexing
         *
         * @param array   $document Document data.
         * @param WP_Post $post     Post object.
         * @since 1.0.0
         */
        return apply_filters( 'search_engine_document', $document, $post );
    }

    /**
     * Delete a post from Elasticsearch index
     *
     * @param int $post_id Post ID.
     * @since 1.0.0
     */
    public function delete_post( $post_id ) {
        if ( ! $this->is_elasticsearch_available() ) {
            return;
        }

        $params = array(
            'index' => $this->index_name,
            'id'    => $post_id,
        );

        try {
            $this->elasticsearch_client->delete( $params );
        } catch ( Exception $e ) {
            // Post might not exist in index, which is fine.
        }
    }

    /**
     * Get search index name
     *
     * @return string Index name.
     * @since 1.0.0
     */
    private function get_index_name() {
        $blog_id = get_current_blog_id();
        return 'wordpress-' . $blog_id . '-content';
    }

    /**
     * Create search index
     *
     * @since 1.0.0
     */
    public function create_index() {
        if ( ! $this->is_elasticsearch_available() ) {
            return false;
        }

        $params = array(
            'index' => $this->index_name,
            'body'  => array(
                'settings' => $this->get_index_settings(),
                'mappings' => $this->get_index_mappings(),
            ),
        );

        try {
            $this->elasticsearch_client->indices()->create( $params );
            return true;
        } catch ( Exception $e ) {
            error_log( 'Elasticsearch index creation error: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Get index settings
     *
     * @return array Index settings.
     * @since 1.0.0
     */
    private function get_index_settings() {
        return array(
            'analysis' => array(
                'analyzer' => array(
                    'default' => array(
                        'type'      => 'custom',
                        'tokenizer' => 'standard',
                        'filter'    => array( 'lowercase', 'asciifolding' ),
                    ),
                ),
            ),
        );
    }

    /**
     * Get index mappings
     *
     * @return array Index mappings.
     * @since 1.0.0
     */
    private function get_index_mappings() {
        return array(
            'properties' => array(
                'post_id' => array(
                    'type' => 'integer',
                ),
                'post_title' => array(
                    'type'   => 'text',
                    'fields' => array(
                        'keyword' => array(
                            'type' => 'keyword',
                        ),
                    ),
                ),
                'post_content' => array(
                    'type' => 'text',
                ),
                'post_excerpt' => array(
                    'type' => 'text',
                ),
                'post_type' => array(
                    'type' => 'keyword',
                ),
                'post_status' => array(
                    'type' => 'keyword',
                ),
                'post_date' => array(
                    'type' => 'date',
                ),
                'post_modified' => array(
                    'type' => 'date',
                ),
                'author' => array(
                    'properties' => array(
                        'id'   => array( 'type' => 'integer' ),
                        'name' => array( 'type' => 'text' ),
                    ),
                ),
                'categories' => array(
                    'type' => 'nested',
                    'properties' => array(
                        'id'   => array( 'type' => 'integer' ),
                        'name' => array( 'type' => 'text' ),
                        'slug' => array( 'type' => 'keyword' ),
                    ),
                ),
                'tags' => array(
                    'type' => 'nested',
                    'properties' => array(
                        'id'   => array( 'type' => 'integer' ),
                        'name' => array( 'type' => 'text' ),
                        'slug' => array( 'type' => 'keyword' ),
                    ),
                ),
            ),
        );
    }
}

/**
 * Search Analytics Tracker
 * 
 * Tracks search queries, performance, and user behavior.
 * 
 * @since 1.0.0
 */
class Search_Analytics {

    /**
     * Track a search query
     *
     * @param string $query Search query.
     * @since 1.0.0
     */
    public function track_search_query( $query ) {
        $query = trim( $query );
        
        if ( empty( $query ) ) {
            return;
        }

        $search_data = array(
            'query'      => $query,
            'timestamp'  => current_time( 'mysql' ),
            'user_id'    => get_current_user_id(),
            'results'    => 0, // Will be updated when results are known.
            'session_id' => $this->get_session_id(),
        );

        // Store in database.
        $this->store_search_log( $search_data );

        // Update popular searches cache.
        $this->update_popular_searches( $query );
    }

    /**
     * Store search log in database
     *
     * @param array $search_data Search data.
     * @since 1.0.0
     */
    private function store_search_log( $search_data ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'search_logs';
        
        $wpdb->insert( 
            $table_name,
            $search_data,
            array( '%s', '%s', '%d', '%d', '%s' )
        );
    }

    /**
     * Update popular searches tracking
     *
     * @param string $query Search query.
     * @since 1.0.0
     */
    private function update_popular_searches( $query ) {
        $popular_searches = get_transient( 'popular_searches' ) ?: array();
        
        if ( isset( $popular_searches[ $query ] ) ) {
            $popular_searches[ $query ]++;
        } else {
            $popular_searches[ $query ] = 1;
        }

        // Keep only top 100 searches.
        arsort( $popular_searches );
        $popular_searches = array_slice( $popular_searches, 0, 100, true );

        set_transient( 'popular_searches', $popular_searches, WEEK_IN_SECONDS );
    }

    /**
     * Get popular searches
     *
     * @param string $prefix Search prefix for filtering.
     * @param int    $limit  Number of results.
     * @return array Popular searches.
     * @since 1.0.0
     */
    public function get_popular_searches( $prefix = '', $limit = 10 ) {
        $popular_searches = get_transient( 'popular_searches' ) ?: array();
        
        if ( ! empty( $prefix ) ) {
            $popular_searches = array_filter( $popular_searches, function( $query ) use ( $prefix ) {
                return 0 === stripos( $query, $prefix );
            }, ARRAY_FILTER_USE_KEY );
        }

        arsort( $popular_searches );
        return array_slice( $popular_searches, 0, $limit, true );
    }

    /**
     * Get session ID for tracking
     *
     * @return string Session ID.
     * @since 1.0.0
     */
    private function get_session_id() {
        if ( ! isset( $_COOKIE['search_session'] ) ) {
            $session_id = wp_generate_uuid4();
            setcookie( 'search_session', $session_id, time() + HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
        } else {
            $session_id = sanitize_text_field( $_COOKIE['search_session'] );
        }

        return $session_id;
    }

    /**
     * Get search analytics report
     *
     * @param string $period Time period.
     * @return array Analytics report.
     * @since 1.0.0
     */
    public function get_analytics_report( $period = '7days' ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'search_logs';
        $date_condition = $this->get_date_condition( $period );

        $report = array(
            'total_searches' => 0,
            'unique_queries' => 0,
            'no_results'     => 0,
            'popular_queries' => array(),
        );

        // Total searches.
        $report['total_searches'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} WHERE 1=1 {$date_condition}"
        );

        // Unique queries.
        $report['unique_queries'] = $wpdb->get_var(
            "SELECT COUNT(DISTINCT query) FROM {$table_name} WHERE 1=1 {$date_condition}"
        );

        // No-result searches.
        $report['no_results'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} WHERE results = 0 {$date_condition}"
        );

        // Popular queries.
        $popular_queries = $wpdb->get_results(
            "SELECT query, COUNT(*) as count 
             FROM {$table_name} 
             WHERE 1=1 {$date_condition}
             GROUP BY query 
             ORDER BY count DESC 
             LIMIT 10",
            ARRAY_A
        );

        $report['popular_queries'] = $popular_queries;

        return $report;
    }

    /**
     * Get SQL date condition for period
     *
     * @param string $period Time period.
     * @return string SQL condition.
     * @since 1.0.0
     */
    private function get_date_condition( $period ) {
        $conditions = array(
            '1day'   => "AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
            '7days'  => "AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            '30days' => "AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            '90days' => "AND timestamp >= DATE_SUB(NOW(), INTERVAL 90 DAY)",
        );

        return $conditions[ $period ] ?? $conditions['7days'];
    }
}

// Initialize the advanced search engine.
$advanced_search_engine = new Advanced_Search_Engine();

// Hook into post lifecycle for index updates.
add_action( 'save_post', array( $advanced_search_engine, 'index_post' ) );
add_action( 'delete_post', array( $advanced_search_engine, 'delete_post' ) );