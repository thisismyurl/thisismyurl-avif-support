<?php
/**
 * TIMU Image Processor Component
 *
 * This class handles the "Muscle" of the plugin suite: Imagick conversions,
 * secure vault path generation, and WordPress thumbnail regeneration.
 *
 * @package     TIMU_Core
 * @version     1.26010212
 * 
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TIMU_Processor_v1 {

    /** @var object Reference to the main Core instance. */
    private $core;

    /**
     * Constructor
     * * @param object $core The main TIMU_Core_v1 instance.
     */
    public function __construct( $core ) {
        $this->core = $core;
    }

    /**
     * 1. SECURE VAULT PATH GENERATOR
     * * Creates a unique, non-indexed folder structure to store original images.
     *
     * @param string $original_full_path The current full system path to the image.
     * @return string The destination path within the secure vault.
     */
    public function get_vault_path( $original_full_path ) {
        $upload_dir = wp_upload_dir();
        // Create a hashed folder name based on the AUTH_SALT for non-guessability.
        $vault_name = 'timu-backups-' . substr( wp_hash( AUTH_SALT ), 0, 8 );
        $base_vault = trailingslashit( $upload_dir['basedir'] ) . $vault_name;
        
        // Ensure vault root is secure.
        if ( ! file_exists( $base_vault ) ) {
            wp_mkdir_p( $base_vault );
            // Protect against indexing and direct browser access.
            file_put_contents( $base_vault . '/.htaccess', "Deny from all\nOptions -Indexes" );
            file_put_contents( $base_vault . '/index.php', "<?php // Silence" );
        }

        // Map the original folder structure (e.g. 2026/01/) into the vault.
        $relative_path = str_replace( $upload_dir['basedir'], '', $original_full_path );
        $vault_target  = $base_vault . $relative_path;

        wp_mkdir_p( dirname( $vault_target ) );

        return $vault_target;
    }

    /**
     * 2. IMAGICK CONVERSION ENGINE
     * * Performs the actual file compression and format conversion.
     *
     * @param array  $upload     WordPress upload array containing 'file' and 'url'.
     * @param string $target_ext The desired extension (webp, avif).
     * @param int    $quality    Compression quality (1-100).
     * @return array Modified upload array.
     */
    public function process_image_conversion( $upload, $target_ext, $quality = 80 ) {
        if ( ! class_exists( 'Imagick' ) ) {
            return $upload;
        }

        $file_path = $upload['file'];
        $info      = pathinfo( $file_path );
        // We create the new file in the same directory as the source.
        $new_path  = trailingslashit( $info['dirname'] ) . $info['filename'] . '.' . $target_ext;

        try {
            // Memory management for large images.
            if ( method_exists( 'Imagick', 'setResourceLimit' ) ) {
                Imagick::setResourceLimit( Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024 );
            }

            $image = new Imagick( $file_path );
            
            // Flatten transparent layers for formats that might not support it (SVG fallback logic).
            if ( $image->getImageAlphaChannel() ) {
                $image->setImageBackgroundColor( 'white' );
                $image = $image->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
            }

            $image->setImageFormat( $target_ext );
            $image->setImageCompressionQuality( (int) $quality );
            $image->writeImage( $new_path );
            
            // Resource cleanup.
            $image->clear();
            $image->destroy();

            // Only update the path if the new file actually exists.
            if ( file_exists( $new_path ) ) {
                $upload['file'] = $new_path;
                $upload['type'] = 'image/' . $target_ext;
            }

        } catch ( Exception $e ) {
            error_log( 'TIMU Processor Error: ' . $e->getMessage() );
        }

        return $upload;
    }

    /**
     * 3. THUMBNAIL REGENERATION
     * * Rebuilds all WordPress image sizes (medium, large, etc.) and updates metadata.
     *
     * @param int $attachment_id The ID of the image to process.
     * @return bool Success or failure.
     */
    public function regenerate_attachment_thumbnails( $attachment_id ) {
        $file_path = get_attached_file( $attachment_id );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return false;
        }

        // Required WordPress admin files for image manipulation.
        require_once( ABSPATH . 'wp-admin/includes/image.php' );

        // Generate new metadata from the current file on disk.
        $metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );

        if ( ! is_wp_error( $metadata ) && ! empty( $metadata ) ) {
            return wp_update_attachment_metadata( $attachment_id, $metadata );
        }

        return false;
    }

    /**
     * 4. DATABASE SYNC
     * * Updates the global WordPress references for an attachment.
     *
     * @param int    $id            Attachment ID.
     * @param string $new_file_path Path to the new optimized file.
     * @param string $target_format Format string (webp/avif).
     */
    public function update_attachment_references( $id, $new_file_path, $target_format ) {
        // Update the core post record.
        wp_update_post( array(
            'ID'             => $id,
            'post_mime_type' => 'image/' . $target_format,
        ) );

        // Update the absolute path record.
        update_attached_file( $id, $new_file_path );

        // Kick off metadata and thumbnail rebuild.
        $this->regenerate_attachment_thumbnails( $id );
    }
}
