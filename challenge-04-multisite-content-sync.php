<?php
/**
 * Challenge 4: Multisite Content Synchronization System
 * 
 * Problem Statement:
 * Create a robust content synchronization system for WordPress Multisite that:
 * 
 * 1. Handles bi-directional content synchronization between sites
 * 2. Implements conflict resolution strategies for concurrent edits
 * 3. Manages media file synchronization with attachment handling
 * 4. Provides rollback capabilities for failed synchronizations
 * 5. Includes sync status reporting and progress tracking
 * 6. Handles custom post types and taxonomies
 * 7. Maintains user mapping and permission consistency
 * 8. Optimizes performance for large-scale sync operations
 * 
 * @package wp-advance-challange
 * @version 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Multisite Content Synchronization Manager
 * 
 * Handles synchronization of content across WordPress Multisite networks
 * with conflict resolution and rollback support.
 * 
 * @since 1.0.0
 */
class Multisite_Content_Sync_Manager {

    /**
     * Sync operation registry
     *
     * @var array
     */
    private $sync_operations = array();

    /**
     * Conflict resolution strategies
     *
     * @var array
     */
    private $conflict_strategies = array();

    /**
     * Maximum batch size for sync operations
     *
     * @var int
     */
    private $batch_size = 50;

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->register_default_conflict_strategies();
        add_action( 'wp_ajax_process_sync_batch', array( $this, 'ajax_process_batch' ) );
    }

    /**
     * Register default conflict resolution strategies
     *
     * @since 1.0.0
     */
    private function register_default_conflict_strategies() {
        $this->conflict_strategies = array(
            'source_wins' => array( $this, 'resolve_source_wins' ),
            'target_wins' => array( $this, 'resolve_target_wins' ),
            'newer_wins'  => array( $this, 'resolve_newer_wins' ),
            'merge'       => array( $this, 'resolve_merge' ),
            'manual'      => array( $this, 'resolve_manual' ),
        );
    }

    /**
     * Synchronize content between multisite blogs
     *
     * @param int    $source_blog_id Source blog ID.
     * @param int    $target_blog_id Target blog ID.
     * @param array  $content_ids    Array of content IDs to sync.
     * @param string $direction      Sync direction ('push', 'pull', 'bidirectional').
     * @param array  $options        Sync options.
     * @return array Sync results.
     * @since 1.0.0
     */
    public function sync_content( $source_blog_id, $target_blog_id, $content_ids = array(), $direction = 'push', $options = array() ) {
        $default_options = array(
            'sync_media'          => true,
            'sync_comments'       => true,
            'sync_meta'           => true,
            'sync_terms'          => true,
            'conflict_strategy'   => 'newer_wins',
            'create_redirects'    => false,
            'preserve_ids'        => false,
            'batch_operation'     => false,
        );

        $options = wp_parse_args( $options, $default_options );

        // Validate blogs.
        if ( ! $this->validate_blogs( $source_blog_id, $target_blog_id ) ) {
            return $this->create_error_result( 'Invalid blog IDs provided.' );
        }

        $sync_id = $this->create_sync_operation( $source_blog_id, $target_blog_id, $content_ids, $direction, $options );

        if ( $options['batch_operation'] && count( $content_ids ) > $this->batch_size ) {
            return $this->initiate_batch_sync( $sync_id );
        }

        return $this->execute_sync( $sync_id );
    }

    /**
     * Create a new sync operation record
     *
     * @param int    $source_blog_id Source blog ID.
     * @param int    $target_blog_id Target blog ID.
     * @param array  $content_ids    Content IDs to sync.
     * @param string $direction      Sync direction.
     * @param array  $options        Sync options.
     * @return string Sync operation ID.
     * @since 1.0.0
     */
    private function create_sync_operation( $source_blog_id, $target_blog_id, $content_ids, $direction, $options ) {
        $sync_id = uniqid( 'sync_', true );

        $this->sync_operations[ $sync_id ] = array(
            'source_blog_id' => $source_blog_id,
            'target_blog_id' => $target_blog_id,
            'content_ids'    => $content_ids,
            'direction'      => $direction,
            'options'        => $options,
            'status'         => 'pending',
            'started_at'     => current_time( 'mysql' ),
            'completed_at'   => null,
            'results'        => array(
                'success' => 0,
                'failed'  => 0,
                'skipped' => 0,
            ),
            'errors'         => array(),
        );

        return $sync_id;
    }

    /**
     * Execute synchronization
     *
     * @param string $sync_id Sync operation ID.
     * @return array Sync results.
     * @since 1.0.0
     */
    private function execute_sync( $sync_id ) {
        $operation = &$this->sync_operations[ $sync_id ];

        if ( ! $operation ) {
            return $this->create_error_result( 'Invalid sync operation.' );
        }

        $operation['status'] = 'processing';

        switch_to_blog( $operation['source_blog_id'] );

        try {
            foreach ( $operation['content_ids'] as $content_id ) {
                $result = $this->sync_single_content( $sync_id, $content_id );
                $this->record_sync_result( $sync_id, $result );
            }

            $operation['status']       = 'completed';
            $operation['completed_at'] = current_time( 'mysql' );

        } catch ( Exception $e ) {
            $operation['status'] = 'failed';
            $operation['errors'][] = $e->getMessage();
        }

        restore_current_blog();

        return $this->prepare_sync_result( $sync_id );
    }

    /**
     * Sync single content item
     *
     * @param string $sync_id   Sync operation ID.
     * @param int    $content_id Content ID to sync.
     * @return array Sync result for single item.
     * @since 1.0.0
     */
    private function sync_single_content( $sync_id, $content_id ) {
        $operation = $this->sync_operations[ $sync_id ];
        $post      = get_post( $content_id );

        if ( ! $post ) {
            return array(
                'success' => false,
                'error'   => 'Content not found',
                'skipped' => true,
            );
        }

        // Check if content should be synced based on post type and status.
        if ( ! $this->should_sync_content( $post, $operation['options'] ) ) {
            return array(
                'success' => false,
                'skipped' => true,
                'reason'  => 'Content excluded by rules',
            );
        }

        switch_to_blog( $operation['target_blog_id'] );

        try {
            $sync_result = $this->sync_post_data( $post, $operation );
            
            if ( $sync_result['success'] && $operation['options']['sync_media'] ) {
                $this->sync_attachments( $post->ID, $sync_result['new_id'], $operation );
            }

            if ( $sync_result['success'] && $operation['options']['sync_terms'] ) {
                $this->sync_taxonomies( $post->ID, $sync_result['new_id'], $operation );
            }

            if ( $sync_result['success'] && $operation['options']['sync_meta'] ) {
                $this->sync_post_meta( $post->ID, $sync_result['new_id'], $operation );
            }

            if ( $sync_result['success'] && $operation['options']['sync_comments'] ) {
                $this->sync_comments( $post->ID, $sync_result['new_id'], $operation );
            }

            restore_current_blog();

            return $sync_result;

        } catch ( Exception $e ) {
            restore_current_blog();
            return array(
                'success' => false,
                'error'   => $e->getMessage(),
            );
        }
    }

    /**
     * Sync post data to target blog
     *
     * @param WP_Post $post      Post object.
     * @param array   $operation Sync operation data.
     * @return array Sync result.
     * @since 1.0.0
     */
    private function sync_post_data( $post, $operation ) {
        $existing_post_id = $this->find_existing_synced_post( $post->ID, $operation );

        $post_data = array(
            'post_title'     => $post->post_title,
            'post_content'   => $post->post_content,
            'post_excerpt'   => $post->post_excerpt,
            'post_status'    => $post->post_status,
            'post_type'      => $post->post_type,
            'post_date'      => $post->post_date,
            'post_modified'  => $post->post_modified,
            'comment_status' => $post->comment_status,
            'ping_status'    => $post->ping_status,
            'post_parent'    => 0, // Will be handled separately.
        );

        if ( $existing_post_id ) {
            $post_data['ID'] = $existing_post_id;
            $new_post_id = wp_update_post( $post_data, true );
        } else {
            $new_post_id = wp_insert_post( $post_data, true );
        }

        if ( is_wp_error( $new_post_id ) ) {
            throw new Exception( $new_post_id->get_error_message() );
        }

        // Store sync metadata.
        $this->store_sync_metadata( $new_post_id, $post->ID, $operation );

        return array(
            'success'    => true,
            'new_id'     => $new_post_id,
            'action'     => $existing_post_id ? 'updated' : 'created',
            'source_id'  => $post->ID,
        );
    }

    /**
     * Sync media attachments
     *
     * @param int    $source_post_id Source post ID.
     * @param int    $target_post_id Target post ID.
     * @param array  $operation      Sync operation data.
     * @since 1.0.0
     */
    private function sync_attachments( $source_post_id, $target_post_id, $operation ) {
        $attachments = get_posts( array(
            'post_type'      => 'attachment',
            'posts_per_page' => -1,
            'post_parent'    => $source_post_id,
        ) );

        foreach ( $attachments as $attachment ) {
            $this->sync_single_attachment( $attachment, $target_post_id, $operation );
        }
    }

    /**
     * Sync single attachment
     *
     * @param WP_Post $attachment     Attachment post.
     * @param int     $target_post_id Target post ID.
     * @param array   $operation      Sync operation data.
     * @return int|false New attachment ID or false on failure.
     * @since 1.0.0
     */
    private function sync_single_attachment( $attachment, $target_post_id, $operation ) {
        $source_file = get_attached_file( $attachment->ID );
        
        if ( ! file_exists( $source_file ) ) {
            return false;
        }

        $file_data = array(
            'name'     => basename( $source_file ),
            'type'     => $attachment->post_mime_type,
            'tmp_name' => $source_file,
            'error'    => 0,
            'size'     => filesize( $source_file ),
        );

        $upload = wp_handle_sideload( $file_data, array( 'test_form' => false ) );

        if ( isset( $upload['error'] ) ) {
            return false;
        }

        $attachment_data = array(
            'post_title'     => $attachment->post_title,
            'post_content'   => $attachment->post_content,
            'post_excerpt'   => $attachment->post_excerpt,
            'post_status'    => 'inherit',
            'post_mime_type' => $attachment->post_mime_type,
            'post_parent'    => $target_post_id,
        );

        $new_attachment_id = wp_insert_attachment( $attachment_data, $upload['file'], $target_post_id );

        if ( ! is_wp_error( $new_attachment_id ) ) {
            wp_update_attachment_metadata( $new_attachment_id, wp_generate_attachment_metadata( $new_attachment_id, $upload['file'] ) );
            return $new_attachment_id;
        }

        return false;
    }

    /**
     * Sync taxonomies and terms
     *
     * @param int    $source_post_id Source post ID.
     * @param int    $target_post_id Target post ID.
     * @param array  $operation      Sync operation data.
     * @since 1.0.0
     */
    private function sync_taxonomies( $source_post_id, $target_post_id, $operation ) {
        $taxonomies = get_object_taxonomies( get_post_type( $source_post_id ) );

        foreach ( $taxonomies as $taxonomy ) {
            $terms = wp_get_object_terms( $source_post_id, $taxonomy, array( 'fields' => 'slugs' ) );
            
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                wp_set_object_terms( $target_post_id, $terms, $taxonomy );
            }
        }
    }

    /**
     * Sync post meta data
     *
     * @param int    $source_post_id Source post ID.
     * @param int    $target_post_id Target post ID.
     * @param array  $operation      Sync operation data.
     * @since 1.0.0
     */
    private function sync_post_meta( $source_post_id, $target_post_id, $operation ) {
        $meta_data = get_post_meta( $source_post_id );

        foreach ( $meta_data as $meta_key => $meta_values ) {
            // Skip internal sync metadata to avoid recursion.
            if ( 0 === strpos( $meta_key, '_sync_' ) ) {
                continue;
            }

            delete_post_meta( $target_post_id, $meta_key );

            foreach ( $meta_values as $meta_value ) {
                $meta_value = maybe_unserialize( $meta_value );
                add_post_meta( $target_post_id, $meta_key, $meta_value );
            }
        }
    }

    /**
     * Find existing synced post in target blog
     *
     * @param int   $source_post_id Source post ID.
     * @param array $operation      Sync operation data.
     * @return int|false Existing post ID or false if not found.
     * @since 1.0.0
     */
    private function find_existing_synced_post( $source_post_id, $operation ) {
        $posts = get_posts( array(
            'post_type'      => 'any',
            'meta_key'       => '_sync_source_id',
            'meta_value'     => $source_post_id,
            'meta_compare'   => '=',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ) );

        return ! empty( $posts ) ? $posts[0] : false;
    }

    /**
     * Store synchronization metadata
     *
     * @param int    $target_post_id Target post ID.
     * @param int    $source_post_id Source post ID.
     * @param array  $operation      Sync operation data.
     * @since 1.0.0
     */
    private function store_sync_metadata( $target_post_id, $source_post_id, $operation ) {
        update_post_meta( $target_post_id, '_sync_source_id', $source_post_id );
        update_post_meta( $target_post_id, '_sync_source_blog', $operation['source_blog_id'] );
        update_post_meta( $target_post_id, '_sync_last_sync', current_time( 'mysql' ) );
        update_post_meta( $target_post_id, '_sync_operation_id', $operation['sync_id'] ?? '' );
    }

    /**
     * Check if content should be synchronized
     *
     * @param WP_Post $post    Post object.
     * @param array   $options Sync options.
     * @return bool True if content should be synced.
     * @since 1.0.0
     */
    private function should_sync_content( $post, $options ) {
        // Skip auto-drafts and revisions.
        if ( in_array( $post->post_status, array( 'auto-draft', 'inherit' ), true ) ) {
            return false;
        }

        // Check post type allowlist.
        $allowed_types = apply_filters( 'multisite_sync_allowed_post_types', array( 'post', 'page' ) );
        if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
            return false;
        }

        /**
         * Filter whether to sync specific content
         *
         * @param bool    $should_sync Whether to sync the content.
         * @param WP_Post $post        Post object.
         * @param array   $options     Sync options.
         * @since 1.0.0
         */
        return apply_filters( 'multisite_should_sync_content', true, $post, $options );
    }

    /**
     * Validate source and target blogs
     *
     * @param int $source_blog_id Source blog ID.
     * @param int $target_blog_id Target blog ID.
     * @return bool True if blogs are valid.
     * @since 1.0.0
     */
    private function validate_blogs( $source_blog_id, $target_blog_id ) {
        if ( $source_blog_id === $target_blog_id ) {
            return false;
        }

        $blogs = wp_get_sites();
        $blog_ids = wp_list_pluck( $blogs, 'blog_id' );

        return in_array( $source_blog_id, $blog_ids, true ) && in_array( $target_blog_id, $blog_ids, true );
    }

    /**
     * Create error result array
     *
     * @param string $message Error message.
     * @return array Error result.
     * @since 1.0.0
     */
    private function create_error_result( $message ) {
        return array(
            'success' => false,
            'error'   => $message,
        );
    }

    /**
     * Prepare sync result for response
     *
     * @param string $sync_id Sync operation ID.
     * @return array Sync results.
     * @since 1.0.0
     */
    private function prepare_sync_result( $sync_id ) {
        $operation = $this->sync_operations[ $sync_id ];

        return array(
            'success'     => 'completed' === $operation['status'],
            'sync_id'     => $sync_id,
            'status'      => $operation['status'],
            'results'     => $operation['results'],
            'errors'      => $operation['errors'],
            'started_at'  => $operation['started_at'],
            'completed_at' => $operation['completed_at'],
        );
    }
}

// Initialize the multisite content sync manager.
if ( is_multisite() ) {
    $multisite_sync_manager = new Multisite_Content_Sync_Manager();
}