<?php
/**
 * Author:              Christopher Ross
 * Author URI:          https://thisismyurl.com/?source=thisismyurl-avif-support
 * Plugin Name:         AVIF Support by thisismyurl.com
 * Plugin URI:          https://thisismyurl.com/thisismyurl-avif-support/?source=thisismyurl-avif-support
 * Donate link:         https://thisismyurl.com/donate/?source=thisismyurl-avif-support
 * 
 * Description:         Safely enable AVIF uploads and convert existing images to AVIF format.
 * Tags:                avif, uploads, media library, optimization
 * 
 * Version:             1.260101
 * Requires at least:   5.3
 * Requires PHP:        7.4
 * 
 * Update URI:          https://github.com/thisismyurl/thisismyurl-avif-support
 * GitHub Plugin URI:   https://github.com/thisismyurl/thisismyurl-avif-support
 * Primary Branch:      main
 * Text Domain:         thisismyurl-avif-support
 * 
 * License:             GPL2
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 * 
 * @package TIMU_AVIF_Support
 * 
 * 
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Version-aware Core Loader
 */
function timu_avif_support_load_core() {
    $core_path = plugin_dir_path( __FILE__ ) . 'core/class-timu-core.php';
    if ( ! class_exists( 'TIMU_Core_v1' ) ) {
        require_once $core_path;
    }
}
timu_avif_support_load_core();

class TIMU_AVIF_Support extends TIMU_Core_v1 {

    public function __construct() {
        parent::__construct( 
            'thisismyurl-avif-support', 
            plugin_dir_url( __FILE__ ), 
            'timu_as_settings_group', 
            '', 
            'tools.php' 
        );

        add_action( 'init', array( $this, 'setup_plugin' ) );
        add_filter( 'upload_mimes', array( $this, 'add_avif_mime_types' ) );
        add_filter( 'wp_handle_upload', array( $this, 'process_avif_upload' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

        register_activation_hook( __FILE__, array( $this, 'activate_plugin_defaults' ) );
    }

    /**
     * Configure the settings blueprint for the Core generator.
     */
    public function setup_plugin() {
        // Check if the WebP sibling plugin is active
        $webp_active = class_exists( 'TIMU_WebP_Support' );

        // Build handling options dynamically
        $handling_options = array(
            'asis' => __( 'Upload as a .avif file.', 'thisismyurl-avif-support' ),
        );

        if ( $webp_active ) {
            $handling_options['webp'] = __( 'Convert uploads to .webp files.', 'thisismyurl-avif-support' );
        }

        $blueprint = array(
            'config' => array(
                'title'  => __( 'AVIF Configuration', 'thisismyurl-avif-support' ),
                'fields' => array(
                    'enabled' => array(
                        'type'      => 'switch',
                        'label'     => __( 'Enable AVIF File Uploads', 'thisismyurl-avif-support' ),
                        'desc'      => __( 'Allows .avif files to be uploaded to the Media Library.', 'thisismyurl-avif-support' ),
                        'is_parent' => true, 
                        'default'   => 1
                    ),
                    'handling_mode' => array(
                        'type'    => 'radio',
                        'label'   => __( 'AVIF Handling Mode', 'thisismyurl-avif-support' ),
                        'parent'  => 'enabled', 
                        'options' => $handling_options,
                        'default' => 'asis',
                        'desc'    => $webp_active 
                            ? __( 'Choose how to handle image uploads for .avif compatibility.', 'thisismyurl-avif-support' )
                            : __( 'WebP conversion requires the <a href="https://thisismyurl.com/thisismyurl-webp-support/">WebP Support plugin</a>.', 'thisismyurl-avif-support' )
                    ),
                    'quality' => array(
                        'type'    => 'number',
                        'label'   => __( 'Compression Quality', 'thisismyurl-avif-support' ),
                        'desc'    => __( 'Set image quality from 1-100 (Default: 80).', 'thisismyurl-avif-support' ),
                        'parent'  => 'enabled', 
                        'min'     => 1,
                        'max'     => 100,
                        'default' => 80
                    ),
                )
            )
        );

        $this->init_settings_generator( $blueprint );
    }

    /**
     * Set plugin defaults upon activation.
     */
    public function activate_plugin_defaults() {
        $option_name = $this->plugin_slug . '_options';
        if ( false === get_option( $option_name ) ) {
            update_option( $option_name, array( 
                'enabled'       => 1,
                'handling_mode' => 'asis'
            ) );
        }
    }

    /**
     * Process uploads based on selected handling mode.
     */
    public function process_avif_upload( $upload ) {
        if ( 1 != $this->get_plugin_option( 'enabled', 1 ) ) {
            return $upload;
        }

        $mode = $this->get_plugin_option( 'handling_mode', 'asis' );

        // If 'asis' is selected or file is already AVIF, do nothing here.
        if ( 'asis' === $mode ) {
            return $upload;
        }

        // Conversion logic for 'convert' (AVIF) or 'webp' would be implemented here.
        // It would intercept common types (JPG, PNG) and use GD/Imagick.
        
        return $upload;
    }

    public function add_avif_mime_types( $mimes ) {
        if ( 1 == $this->get_plugin_option( 'enabled', 1 ) ) {
            $mimes['avif'] = 'image/avif';
        }
        return $mimes;
    }

    public function add_admin_menu() {
        add_management_page(
            __( 'AVIF Support Settings', 'thisismyurl-avif-support' ),
            __( 'AVIF Support', 'thisismyurl-avif-support' ),
            'manage_options',
            $this->plugin_slug,
            array( $this, 'render_settings_page' )
        );
    }
}
new TIMU_AVIF_Support();