<?php
/**
 * TIMU AVIF Support Plugin
 *
 * This plugin facilitates the secure handling and optimization of AVIF images within 
 * the WordPress Media Library. It leverages the TIMU Shared Core to provide centralized 
 * settings management and cross-plugin rasterization logic (AVIF to WebP).
 *
 * @package    TIMU_AVIF_Support
 * @author     Christopher Ross <https://thisismyurl.com/>
 * @version    1.260102
 * @license    GPL-2.0+
 */

/**
 * Security: Prevent direct file access to mitigate path traversal or unauthorized execution.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Version-aware Core Loader
 *
 * Loads the base TIMU_Core_v1 class. The use of class_exists is a defensive programming 
 * measure to ensure the shared library is initialized only once, even if multiple plugins 
 * in the suite attempt to load it.
 */
function timu_avif_support_load_core() {
	$core_path = plugin_dir_path( __FILE__ ) . 'core/class-timu-core.php';
	if ( ! class_exists( 'TIMU_Core_v1' ) ) {
		require_once $core_path;
	}
}
timu_avif_support_load_core();

/**
 * Class TIMU_AVIF_Support
 *
 * Extends the shared core to provide AVIF-specific functionality. It manages the 
 * registration of MIME types and intercepts the upload lifecycle to apply 
 * compression or conversion routines.
 */
class TIMU_AVIF_Support extends TIMU_Core_v1 {

	/**
	 * Constructor: Initializes the plugin structure and WordPress hooks.
	 *
	 * Passes configuration parameters to the parent core constructor to establish 
	 * uniform admin enqueuing and settings groups.
	 */
	public function __construct() {
		parent::__construct(
			'thisismyurl-avif-support',      // Unique plugin slug.
			plugin_dir_url( __FILE__ ),       // Base URL for enqueuing assets.
			'timu_as_settings_group',         // Settings API group name.
			'',                               // Custom icon URL (optional).
			'tools.php'                       // Admin menu parent location.
		);

		/**
		 * Hook: Initialize the configuration blueprint for the settings generator.
		 */
		add_action( 'init', array( $this, 'setup_plugin' ) );

		/**
		 * Filters: Lifecycle hooks for expanding MIME support and processing uploads.
		 */
		add_filter( 'upload_mimes', array( $this, 'add_avif_mime_types' ) );
		add_filter( 'wp_handle_upload', array( $this, 'process_avif_upload' ) );

		/**
		 * Action: Dedicated management page registration under the Tools menu.
		 */
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

		/**
		 * Activation: Ensure baseline defaults are registered in the options table.
		 */
		register_activation_hook( __FILE__, array( $this, 'activate_plugin_defaults' ) );
	}

	/**
	 * Configuration Blueprint
	 *
	 * Defines the settings schema for the Core's automated UI generator. 
	 * Implements dynamic awareness of sibling plugins (WebP) to adjust UI options.
	 */
	public function setup_plugin() {
		/** @var bool $webp_active Dependency check for sibling WebP plugin. */
		$webp_active = class_exists( 'TIMU_WebP_Support' );

		/**
		 * Build the radio options dynamically based on the current plugin ecosystem.
		 */
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
					'enabled'       => array(
						'type'      => 'switch',
						'label'     => __( 'Enable AVIF File Uploads', 'thisismyurl-avif-support' ),
						'desc'      => __( 'Allows .avif files to be uploaded to the Media Library.', 'thisismyurl-avif-support' ),
						'is_parent' => true, // Triggers cascading visibility in shared-admin.js.
						'default'   => 1,
					),
					'handling_mode' => array(
						'type'    => 'radio',
						'label'   => __( 'AVIF Handling Mode', 'thisismyurl-avif-support' ),
						'parent'  => 'enabled', // Subordinate to the main enable switch.
						'options' => $handling_options,
						'default' => 'asis',
						'desc'    => $webp_active
							? __( 'Choose how to handle image uploads for .avif compatibility.', 'thisismyurl-avif-support' )
							: __( 'WebP conversion requires the <a href="https://thisismyurl.com/thisismyurl-webp-support/">WebP Support plugin</a>.', 'thisismyurl-avif-support' ),
					),
					'quality'       => array(
						'type'    => 'number',
						'label'   => __( 'Compression Quality', 'thisismyurl-avif-support' ),
						'desc'    => __( 'Set image quality from 1-100 (Default: 80).', 'thisismyurl-avif-support' ),
						'parent'  => 'enabled',
						'min'     => 1,
						'max'     => 100,
						'default' => 80,
					),
				),
			),
		);

		/**
		 * Pass the blueprint configuration to the Core Settings Generator.
		 */
		$this->init_settings_generator( $blueprint );
	}

	/**
	 * Default Option Initialization
	 *
	 * Uses the register_activation_hook to ensure the plugin has a functional state 
	 * upon installation without overriding existing user data.
	 */
	public function activate_plugin_defaults() {
		$option_name = "{$this->plugin_slug}_options";
		if ( false === get_option( $option_name ) ) {
			update_option( $option_name, array(
				'enabled'       => 1,
				'handling_mode' => 'asis',
				'quality'       => 80,
			) );
		}
	}

	/**
	 * Admin Menu Entry
	 *
	 * Hooks into the WordPress Tools menu (tools.php).
	 */
	public function add_admin_menu() {
		add_management_page(
			__( 'AVIF Support Settings', 'thisismyurl-avif-support' ),
			__( 'AVIF Support', 'thisismyurl-avif-support' ),
			'manage_options',
			$this->plugin_slug,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * MIME Type Support
	 *
	 * Modifies the WordPress allowed MIME types list to permit .avif uploads.
	 *
	 * @param array $mimes Current allowed MIME types.
	 * @return array Modified MIME types list.
	 */
	public function add_avif_mime_types( $mimes ) {
		if ( 1 === (int) $this->get_plugin_option( 'enabled', 1 ) ) {
			$mimes['avif'] = 'image/avif';
		}
		return $mimes;
	}

	/**
	 * Image Processing Orchestrator
	 *
	 * Intercepts native AVIF uploads to either apply compression (asis) or 
	 * convert the file to WebP format using the shared core utility.
	 *
	 * @param array $upload The standard WordPress upload result array.
	 * @return array The processed upload result.
	 */
	public function process_avif_upload( $upload ) {
		/**
		 * Safety check: Exit early if the plugin is disabled or the file 
		 * is not of the AVIF MIME type.
		 */
		if ( 1 !== (int) $this->get_plugin_option( 'enabled', 1 ) || 'image/avif' !== $upload['type'] ) {
			return $upload;
		}

		/**
		 * Determine processing parameters based on user configuration.
		 */
		$mode    = $this->get_plugin_option( 'handling_mode', 'asis' );
		$quality = (int) $this->get_plugin_option( 'quality', 80 );

		/**
		 * Case 1: Convert AVIF to WebP. 
		 * Only executes if the sibling WebP plugin is detected.
		 */
		if ( 'webp' === $mode && class_exists( 'TIMU_WebP_Support' ) ) {
			return $this->process_image_conversion( $upload, 'webp', $quality );
		}

		/**
		 * Case 2: Apply compression to the native AVIF file (asis).
		 */
		return $this->process_image_conversion( $upload, 'avif', $quality );
	}
}

/**
 * Initialize the AVIF Support plugin instance.
 */
new TIMU_AVIF_Support();