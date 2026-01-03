<?php
/**
 * TIMU Secure Vault Component
 *
 * This class handles the security and integrity of original image backups. 
 * It manages the hashed directory structure and ensures that backups are 
 * protected from public access and search engine indexing.
 *
 * @package     TIMU_Core
 * @version     1.26010212
 * 
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TIMU_Vault_v1 {

    /** @var object Reference to the main Core instance. */
    private $core;

    /**
     * Constructor
     *
     * @param object $core The main TIMU_Core_v1 instance.
     */
    public function __construct( $core ) {
        $this->core = $core;
    }

    /**
     * Generates and secures a vault path for original image backups.
     *
     * This method creates a hashed folder name based on the site's unique salt
     * to prevent unauthorized users from guessing the backup location.
     *
     * @param string $original_full_path The current full system path to the image.
     * @return string The new destination path within the secure vault.
     */
    public function get_vault_path( $original_full_path ) {
        $upload_dir = wp_upload_dir();
        
        // Create a unique, non-guessable folder name.
        $vault_hash = substr( wp_hash( AUTH_SALT ), 0, 8 );
        $base_vault = $upload_dir['basedir'] . '/timu-backups-' . $vault_hash;
        
        // Ensure the vault root exists and is secured.
        if ( ! file_exists( $base_vault ) ) {
            wp_mkdir_p( $base_vault );
            
            // Block direct HTTP access and directory indexing.
            $this->secure_directory( $base_vault );
        }

        /**
         * Determine the relative path from the standard uploads folder.
         * We recreate the year/month structure inside the vault to avoid filename collisions.
         */
        $relative_path = str_replace( $upload_dir['basedir'], '', $original_full_path );
        $vault_target  = $base_vault . $relative_path;

        // Ensure the sub-folder structure (e.g., /2026/01/) exists inside the vault.
        wp_mkdir_p( dirname( $vault_target ) );

        return $vault_target;
    }

    /**
     * Secures a directory with .htaccess and index.php.
     *
     * @param string $path The full system path to secure.
     */
    private function secure_directory( $path ) {
        $htaccess_content = "Deny from all\nOptions -Indexes";
        $index_content    = "<?php // Silence is golden.";

        file_put_contents( trailingslashit( $path ) . '.htaccess', $htaccess_content );
        file_put_contents( trailingslashit( $path ) . 'index.php', $index_content );
    }

    /**
     * Moves a file to the secure vault.
     *
     * @param string $source      The current file path.
     * @param string $destination The vault destination path.
     * @return bool Success or failure.
     */
    public function move_to_vault( $source, $destination ) {
        $fs = $this->core->init_fs();
        
        if ( ! file_exists( $source ) ) {
            return false;
        }

        return $fs->move( $source, $destination, true );
    }

    /**
     * Recovers a file from the secure vault.
     *
     * @param string $vault_path  The path inside the backup folder.
     * @param string $live_path   The path where the image should be restored to.
     * @return bool Success or failure.
     */
    public function recover_from_vault( $vault_path, $live_path ) {
        $fs = $this->core->init_fs();

        if ( ! file_exists( $vault_path ) ) {
            return false;
        }

        // We use overwrite = true to replace the optimized file currently at the live path.
        return $fs->move( $vault_path, $live_path, true );
    }

    /**
     * Verifies if the vault is healthy and writable.
     *
     * @return bool
     */
    public function check_vault_health() {
        $upload_dir = wp_upload_dir();
        return wp_is_writable( $upload_dir['basedir'] );
    }
}
