<?php
/**
 * TIMU Shared Core Library - Controller
 *
 * This class serves as the central hub for the TIMU plugin suite. 
 * It coordinates sub-modules and handles global licensing/filtering.
 *
 * @package     TIMU_Core
 * @version     1.26010212
 * 
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'TIMU_Core_v1' ) ) {

    abstract class TIMU_Core_v1 {

        public $plugin_slug;
        public $plugin_url;
        public $options_group;
        public $plugin_icon;
        public $menu_parent = 'options-general.php';
        public $license_message = '';
        public $settings_blueprint = array();
        public $fs;

        /** @var object Component Instances */
        public $admin;
        public $ajax;
        public $processor;
        public $vault;

        public function __construct( $slug, $url, $group, $icon = '', $parent = 'options-general.php' ) {
            $this->plugin_slug   = $slug;
            $this->plugin_url    = $url;
            $this->options_group = $group;
            $this->plugin_icon   = $icon;
            $this->menu_parent   = $parent;

            $this->load_components();

            // Dashboard Hooks
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_core_assets' ) );
            add_filter( "plugin_action_links_{$this->plugin_slug}/{$this->plugin_slug}.php", array( $this, 'add_plugin_action_links' ) );
            add_action( 'admin_init', array( $this, 'handle_core_activation_redirect' ) );
            
            /**
             * Updated Hook: Point to the admin component instead of $this.
             */
            if ( isset( $this->admin ) ) {
                add_action( 'timu_sidebar_under_banner', array( $this->admin, 'render_default_sidebar_actions' ) );
            }

            add_filter( 'the_content', array( $this, 'filter_content_images' ), 99 );

            if ( method_exists( $this, 'init_updater' ) ) {
                add_action( 'plugins_loaded', array( $this, 'init_updater' ) );
            }

            add_filter( 'attachment_fields_to_edit', array( $this, 'add_media_sidebar_actions' ), 10, 2 );

        }

        private function load_components() {
            require_once 'class-timu-vault.php';
            $this->vault = new TIMU_Vault_v1( $this );

            if ( is_admin() || wp_doing_ajax() ) {
                require_once 'class-timu-admin.php';
                require_once 'class-timu-ajax.php';
                require_once 'class-timu-processor.php';

                $this->processor = new TIMU_Processor_v1( $this );
                $this->ajax      = new TIMU_Ajax_v1( $this );
                $this->admin     = new TIMU_Admin_v1( $this );
            }
        }

        /* -----------------------------------------------------------------------
           UI BRIDGE METHODS (Delegates to Admin Component)
        ----------------------------------------------------------------------- */

        public function init_settings_generator( $blueprint ) {
            $this->settings_blueprint = $blueprint;
            if ( isset( $this->admin ) ) {
                $this->admin->register_settings_api();
            }
        }

        public function render_settings_page() {
            if ( isset( $this->admin ) ) {
                $this->admin->render_settings_page();
            }
        }

        public function render_core_header() { 
            if ( isset( $this->admin ) ) $this->admin->render_core_header(); 
        }

        public function render_core_footer() { 
            if ( isset( $this->admin ) ) $this->admin->render_core_footer(); 
        }

        public function render_core_sidebar( $extra_content = '' ) { 
            if ( isset( $this->admin ) ) $this->admin->render_core_sidebar( $extra_content ); 
        }

        public function render_registration_field() { 
            if ( isset( $this->admin ) ) $this->admin->render_registration_field(); 
        }

        public function render_default_sidebar_actions() {
            if ( isset( $this->admin ) ) {
                $this->admin->render_default_sidebar_actions();
            }
        }

        /* -----------------------------------------------------------------------
           CORE LOGIC & HELPERS
        ----------------------------------------------------------------------- */

        public function run_conversion_logic( $id ) {
            $prefix      = $this->get_data_prefix();
            $savings_key = "_{$prefix}_savings";
            $file_path   = get_attached_file( $id );

            if ( ! $file_path || ! file_exists( $file_path ) ) return array( 'success' => false, 'message' => 'File not found.' );

            $old_size   = filesize( $file_path );
            $vault_path = $this->vault->get_vault_path( $file_path );

            if ( ! $this->vault->move_to_vault( $file_path, $vault_path ) ) {
                return array( 'success' => false, 'message' => 'Vaulting failed.' );
            }

            $quality = (int) $this->get_plugin_option( 'quality', 80 );
            $target  = ( strpos( $this->plugin_slug, 'avif' ) !== false ) ? 'avif' : 'webp';

            $result = $this->processor->process_image_conversion( 
                array( 'file' => $vault_path, 'url' => wp_get_attachment_url( $id ) ), 
                $target, 
                $quality 
            );

            if ( isset( $result['file'] ) && file_exists( $result['file'] ) ) {
                $this->processor->update_attachment_references( $id, $result['file'], $target );
                update_post_meta( $id, "_{$prefix}_original_path", $vault_path );
                update_post_meta( $id, $savings_key, ( $old_size - filesize( $result['file'] ) ) );
                return array( 'success' => true, 'message' => 'Optimized.' );
            }

            $this->vault->recover_from_vault( $vault_path, $file_path );
            return array( 'success' => false, 'message' => 'Optimization failed.' );
        }

        public function get_data_prefix() {
            if ( strpos( $this->plugin_slug, 'webp' ) !== false ) return 'webp';
            if ( strpos( $this->plugin_slug, 'heic' ) !== false ) return 'heic';
            if ( strpos( $this->plugin_slug, 'avif' ) !== false ) return 'avif';
            return 'timu';
        }

        public function get_plugin_option( $key = '', $default = '' ) {
            $options = get_option( $this->plugin_slug . '_options', array() );
            return ( empty( $key ) ) ? $options : ( isset( $options[ $key ] ) ? $options[ $key ] : $default );
        }

        public function init_fs() {
            if ( null === $this->fs ) {
                global $wp_filesystem;
                if ( empty( $wp_filesystem ) ) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    WP_Filesystem();
                }
                $this->fs = $wp_filesystem;
            }
            return $this->fs;
        }

        public function format_bytes( $bytes, $precision = 2 ) {
            $units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
            $bytes = max( (int)$bytes, 0 );
            $pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
            $pow   = min( $pow, count( $units ) - 1 );
            $bytes /= pow( 1024, $pow );
            return round( $bytes, $precision ) . ' ' . $units[ $pow ];
        }

        public function calculate_total_savings( $savings_key ) {
            global $wpdb;
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = %s", $savings_key ) );
        }

        public function handle_core_activation_redirect() {
            if ( get_transient( "{$this->plugin_slug}_activation_redirect" ) ) {
                delete_transient( "{$this->plugin_slug}_activation_redirect" );
                if ( ! is_network_admin() && ! isset( $_GET['activate-multi'] ) ) {
                    wp_safe_redirect( admin_url( $this->menu_parent . '?page=' . $this->plugin_slug ) );
                    exit;
                }
            }
        }

 
        public function enqueue_core_assets( $hook ) {


            wp_enqueue_style( 'timu-core-css', $this->plugin_url . 'core/assets/shared-admin.css', array(), '1.1.4' );

            if ( strpos( $hook, $this->plugin_slug ) === false ) return;

            // Load Basic UI Script on all plugin pages
            wp_enqueue_script( 'timu-core-ui', $this->plugin_url . 'core/assets/shared-admin.js', array( 'jquery' ), '1.26', true );

            // Only load the heavy Bulk/AJAX script on the specific settings page
            if ( isset( $_GET['page'] ) && $_GET['page'] === $this->plugin_slug ) {
                wp_enqueue_script( 'timu-core-bulk', $this->plugin_url . 'core/assets/shared-bulk.js', array( 'jquery', 'timu-core-ui' ), '1.26', true );

                // Required for Color Picker
                wp_enqueue_style( 'wp-color-picker' );
                wp_enqueue_script( 'wp-color-picker' );

                // Required for Media Uploader
                wp_enqueue_media();
            }
            

            wp_localize_script( 'timu-core-ui', 'timu_core_vars', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'timu_install_nonce' )
            ) );
        }

        
        /**
         * Adds custom action links to the plugin entry on the Plugins page.
         * 
         *  @param array $links Existing links.
         *  @return array Modified links.
         * 
         */
        public function add_plugin_action_links( $links ) {
            $settings_url = admin_url( $this->menu_parent . '?page=' . $this->plugin_slug );
            $links[] = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'timu' ) . '</a>';

            // The check now strictly follows the API response
            if ( $this->is_licensed() ) {
                $label  = __( 'Support', 'timu' );
                $anchor = '#support';
            } else {
                $label  = __( 'Register', 'timu' );
                $anchor = '#register';
            }

            $links[] = sprintf(
                '<a href="https://thisismyurl.com/%s/%s" target="_blank">%s</a>',
                esc_attr( $this->plugin_slug ),
                esc_attr( $anchor ),
                esc_html( $label )
            );

            return $links;
        }

        public function filter_content_images( $content ) {
            if ( is_admin() || empty( $content ) ) return $content;
            $prefix = $this->get_data_prefix();
            $target_ext = ( 'avif' === $prefix ) ? 'avif' : 'webp';
            $pattern = '/(href|src|srcset)=["\']([^"\']+\.(jpe?g|png))["\']/i';
            return preg_replace_callback( $pattern, function( $m ) use ( $prefix, $target_ext ) {
                $id = attachment_url_to_postid( $m[2] );
                if ( $id && get_post_meta( $id, "_{$prefix}_savings", true ) ) {
                    return $m[1] . '="' . preg_replace( '/\.(jpe?g|png)$/i', '.' . $target_ext, $m[2] ) . '"';
                }
                return $m[0];
            }, $content );
        }

        /**
         * Checks if the plugin is currently licensed and registered for this site.
         * * @return bool True if a valid license is detected.
         */
        public function is_licensed() {
            $key = $this->get_plugin_option( 'registration_key', '' );

            if ( empty( $key ) ) {
                $this->license_message = __( 'Unregistered', 'timu' );
                return false;
            }

            $cache_key = $this->plugin_slug . '_license_status';
            $cached_status = get_transient( $cache_key );

            if ( 'active' === $cached_status ) {
                $this->license_message = __( 'Active', 'timu' );
                return true;
            }

            // Prepare the API URL dynamically
            $api_url = add_query_arg( array(
                'url'  => get_site_url(),
                'item' => $this->plugin_slug,
                'key'  => $key
            ), 'https://thisismyurl.com/wp-json/license-manager/v1/check/' );

            $response = wp_remote_get( $api_url, array( 'timeout' => 15 ) );

            if ( is_wp_error( $response ) ) {
                $this->license_message = __( 'Connection Error', 'timu' );
                return false;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            // Strictly check for the "active" status from your JSON response
            if ( isset( $body['status'] ) && 'active' === $body['status'] ) {
                set_transient( $cache_key, 'active', 12 * HOUR_IN_SECONDS );
                $this->license_message = __( 'Active', 'timu' );
                return true;
            }

            // Handle the "invalid" status and display the specific message from the API
            $this->license_message = isset( $body['message'] ) ? esc_html( $body['message'] ) : __( 'Invalid License', 'timu' );
            return false;
        }

        public function sanitize_core_options( $input ) {
            delete_transient( $this->plugin_slug . '_license_status' );
            $input['enabled'] = isset( $input['enabled'] ) ? 1 : 0;
            if ( isset( $input['registration_key'] ) ) $input['registration_key'] = sanitize_text_field( $input['registration_key'] );
            return $input;
        }

    } // End Class
}