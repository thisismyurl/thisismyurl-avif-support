<?php
/**
 * TIMU AJAX Handlers Component
 *
 * This class handles all asynchronous requests for the TIMU plugin suite, including
 * the batch conversion of images, individual restoration, and bulk vault recovery.
 *
 * @package     TIMU_Core
 * @version     1.26010212
 * 
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TIMU_Ajax_v1 {

    /** @var object Reference to the main Core instance. */
    private $core;

    /**
     * Constructor
     * * @param object $core The main TIMU_Core_v1 instance.
     */
    public function __construct( $core ) {
        $this->core = $core;

        // Register AJAX actions for logged-in users
        add_action( 'wp_ajax_timu_process_single', array( $this, 'ajax_process_single_image' ) );
        add_action( 'wp_ajax_timu_run_bulk_process', array( $this, 'ajax_run_bulk_process' ) );
        add_action( 'wp_ajax_timu_restore_single', array( $this, 'ajax_restore_single_image' ) );
        add_action( 'wp_ajax_timu_undo_bulk_changes', array( $this, 'ajax_restore_all_images' ) );
        
        // Sibling tool installer
        add_action( 'wp_ajax_timu_install_tool', array( $this->core, 'ajax_install_plugin' ) );

        add_action( 'wp_ajax_timu_verify_license', array( $this, 'ajax_verify_license' ) ); //
    }

    /**
     * AJAX: Process a single image from the Unprocessed list.
     */
    public function ajax_process_single_image() {
        check_ajax_referer( 'timu_install_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Permissions error.', 'timu' ) );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;
        if ( ! $attachment_id ) {
            wp_send_json_error( __( 'Invalid attachment ID.', 'timu' ) );
        }

        $result = $this->core->run_conversion_logic( $attachment_id );

        if ( $result['success'] ) {
            wp_send_json_success( $result['message'] );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    /**
     * AJAX: Bulk Process (Iterative).
     * Processes a small batch and tells the JS to continue if more remain.
     */
    public function ajax_run_bulk_process() {
        check_ajax_referer( 'timu_install_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Permissions error.', 'timu' ) );
        }

        $prefix = $this->get_internal_prefix();
        $savings_key = "_{$prefix}_savings";

        // Find images that haven't been processed by THIS specific plugin yet
        $attachments = get_posts( array(
            'post_type'      => 'attachment',
            'post_mime_type' => array( 'image/jpeg', 'image/png' ),
            'post_status'    => 'inherit',
            'posts_per_page' => 5, // Keep batches small to prevent server timeouts
            'meta_query'     => array(
                array(
                    'key'     => $savings_key,
                    'compare' => 'NOT EXISTS',
                ),
            ),
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );

        if ( empty( $attachments ) ) {
            wp_send_json_success( array( 'done' => true ) );
        }

        $count = 0;
        foreach ( $attachments as $id ) {
            $this->core->run_conversion_logic( $id );
            $count++;
        }

        wp_send_json_success( array(
            'done'  => false,
            'count' => $count,
            'message' => sprintf( __( 'Processed %d images...', 'timu' ), $count )
        ) );
    }

    /**
     * AJAX: Restore a single image from the Secure Vault.
     */
    public function ajax_restore_single_image() {
        check_ajax_referer( 'timu_install_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Permissions error.', 'timu' ) );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;
        $prefix        = $this->get_internal_prefix();
        
        $vault_path   = get_post_meta( $attachment_id, "_{$prefix}_original_path", true );
        $current_path = get_attached_file( $attachment_id );

        if ( ! $vault_path || ! file_exists( $vault_path ) ) {
            wp_send_json_error( __( 'Original backup not found in vault.', 'timu' ) );
        }

        $fs = $this->core->init_fs();

        if ( $fs->move( $vault_path, $current_path, true ) ) {
            
            // Determine original MIME type based on file extension
            $ext = pathinfo( $current_path, PATHINFO_EXTENSION );
            $mime = ( strtolower( $ext ) === 'png' ) ? 'image/png' : 'image/jpeg';
            
            wp_update_post( array(
                'ID'             => $attachment_id,
                'post_mime_type' => $mime
            ) );

            // Rebuild thumbnails and metadata for the restored file
            $this->core->regenerate_attachment_thumbnails( $attachment_id );

            // Cleanup plugin-specific tracking data
            delete_post_meta( $attachment_id, "_{$prefix}_original_path" );
            delete_post_meta( $attachment_id, "_{$prefix}_savings" );

            wp_send_json_success( __( 'Image restored successfully.', 'timu' ) );
        }

        wp_send_json_error( __( 'Failed to move file from vault.', 'timu' ) );
    }

    /**
     * AJAX: Bulk Restore All Vaulted Images.
     */
    public function ajax_restore_all_images() {
        check_ajax_referer( 'timu_install_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Permissions error.', 'timu' ) );
        }

        $prefix      = $this->get_internal_prefix();
        $savings_key = "_{$prefix}_savings";

        $attachments = get_posts( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1, // Restoration is usually faster than conversion
            'meta_query'     => array(
                array(
                    'key'     => $savings_key,
                    'compare' => 'EXISTS',
                ),
            ),
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );

        if ( empty( $attachments ) ) {
            wp_send_json_error( __( 'No vaulted images found to restore.', 'timu' ) );
        }

        $fs = $this->core->init_fs();
        $count = 0;

        foreach ( $attachments as $id ) {
            $vault_path   = get_post_meta( $id, "_{$prefix}_original_path", true );
            $current_path = get_attached_file( $id );

            if ( $vault_path && file_exists( $vault_path ) ) {
                if ( $fs->move( $vault_path, $current_path, true ) ) {
                    $ext = pathinfo( $current_path, PATHINFO_EXTENSION );
                    wp_update_post( array(
                        'ID'             => $id,
                        'post_mime_type' => ( strtolower( $ext ) === 'png' ) ? 'image/png' : 'image/jpeg'
                    ) );

                    $this->core->regenerate_attachment_thumbnails( $id );
                    delete_post_meta( $id, "_{$prefix}_original_path" );
                    delete_post_meta( $id, $savings_key );
                    $count++;
                }
            }
        }

        wp_send_json_success( sprintf( __( 'Successfully restored %d images.', 'timu' ), $count ) );
    }

    


    /**
     * Internal: Resolve metadata prefix based on plugin context.
     */
    private function get_internal_prefix() {
        if ( strpos( $this->core->plugin_slug, 'webp' ) !== false ) return 'webp';
        if ( strpos( $this->core->plugin_slug, 'heic' ) !== false ) return 'heic';
        if ( strpos( $this->core->plugin_slug, 'avif' ) !== false ) return 'avif';
        return 'timu';
    }
}