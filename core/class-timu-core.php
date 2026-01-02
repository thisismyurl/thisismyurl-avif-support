<?php
/**
 * TIMU Shared Core Library
 * Version: 1.260101
 * Author: thisismyurl.com
 */

if ( ! class_exists( 'TIMU_Core_v1' ) ) {

    abstract class TIMU_Core_v1 {
        protected $plugin_slug;
        protected $plugin_url;
        protected $options_group;
        protected $plugin_icon;
        protected $menu_parent = 'options-general.php';
        protected $license_message = '';
        protected $settings_blueprint = []; 
        public static $version = '1.260101';

        public function __construct( $slug, $url, $group, $icon = '', $parent = 'options-general.php' ) {
            $this->plugin_slug   = $slug;
            $this->plugin_url    = $url;
            $this->options_group = $group;
            $this->plugin_icon   = $icon;
            $this->menu_parent   = $parent;

            add_action( 'admin_init', array( $this, 'register_core_settings' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_core_assets' ) );
            
            if ( method_exists( $this, 'init_updater' ) ) {
                add_action( 'plugins_loaded', array( $this, 'init_updater' ) );
            }

            add_filter( "plugin_action_links_" . $this->plugin_slug . '/' . $this->plugin_slug . '.php', array( $this, 'add_plugin_action_links' ) );
            add_action( 'wp_ajax_timu_install_tool', array( $this, 'ajax_install_plugin' ) );
        }

        public function init_fs() {
            global $wp_filesystem;
            if ( empty( $wp_filesystem ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }
            return $wp_filesystem;
        }

        public function sanitize_core_options( $input ) {
            delete_transient( $this->plugin_slug . '_license_status' );
            delete_transient( $this->plugin_slug . '_license_msg' );
            if ( isset( $input['registration_key'] ) ) {
                $input['registration_key'] = sanitize_text_field( $input['registration_key'] );
            }
            return $input;
        }

        public function ajax_install_plugin() {
            check_ajax_referer( 'timu_install_nonce', 'nonce' );
            if ( ! current_user_can( 'install_plugins' ) ) {
                wp_send_json_error( __( 'Permissions error.', 'timu' ) );
            }

            $download_url = esc_url_raw( $_POST['download_url'] );
            if ( strpos( $download_url, 'github.com' ) !== false && strpos( $download_url, '.zip' ) === false ) {
                $download_url = preg_replace( '/\/releases\/latest\/?$/', '', $download_url );
                $download_url = rtrim( $download_url, '/' ) . '/archive/refs/heads/main.zip';
            }

            $this->init_fs();
            include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

            $upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
            $result   = $upgrader->install( $download_url );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( esc_html( $result->get_error_message() ) );
            }
            wp_send_json_success();
        }

        public function init_updater() {
            $updater_path = WP_PLUGIN_DIR . '/' . $this->plugin_slug . '/updater.php';
            if ( file_exists( $updater_path ) ) {
                require_once $updater_path;
                if ( class_exists( 'FWO_GitHub_Updater' ) ) {
                    new FWO_GitHub_Updater( array(
                        'slug'               => $this->plugin_slug,
                        'proper_folder_name' => $this->plugin_slug,
                        'api_url'            => 'https://api.github.com/repos/thisismyurl/' . $this->plugin_slug . '/releases/latest',
                        'github_url'         => 'https://github.com/thisismyurl/' . $this->plugin_slug,
                        'plugin_file'        => WP_PLUGIN_DIR . '/' . $this->plugin_slug . '/' . $this->plugin_slug . '.php',
                    ) );
                }
            }
        }

        public function add_plugin_action_links( $links ) {
            $is_valid = $this->is_licensed();
            $settings_url = admin_url( $this->menu_parent . '?page=' . $this->plugin_slug );
            $action_links[] = '<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', 'timu' ) . '</a>';

            if ( $is_valid ) {
                $action_links[] = '<a href="https://thisismyurl.com/support/" target="_blank" style="font-weight: bold; color: #46b450;">' . __( 'Support', 'timu' ) . '</a>';
            } else {
                $donate_url = 'https://thisismyurl.com/donate/?source=' . urlencode( $this->plugin_slug );
                $action_links[] = '<a href="' . esc_url( $donate_url ) . '" target="_blank" style="font-weight: bold;">' . __( 'Donate', 'timu' ) . '</a>';
            }
            return array_merge( $action_links, $links );
        }

        protected function get_plugin_option( $key = '', $default = '' ) {
            $options = get_option( $this->plugin_slug . '_options', array() );
            return ( empty( $key ) ) ? $options : ( isset( $options[$key] ) ? $options[$key] : $default );
        }

        public function register_core_settings() {
            register_setting( $this->options_group, $this->plugin_slug . '_options', array( 'sanitize_callback' => array( $this, 'sanitize_core_options' ) ) );
        }

        public function enqueue_core_assets( $hook ) {
            if ( strpos( $hook, $this->plugin_slug ) === false ) return;
            wp_enqueue_media();
            wp_enqueue_style( 'wp-color-picker' );
            wp_enqueue_script( 'wp-color-picker' );
            wp_enqueue_style( 'timu-core-css', $this->plugin_url . 'core/assets/shared-admin.css', array(), '1.1.4' );
            wp_enqueue_script( 'timu-core-js', $this->plugin_url . 'core/assets/shared-admin.js', array( 'jquery', 'wp-color-picker' ), '1.1.3', true );
            wp_localize_script( 'timu-core-js', 'timu_core_vars', array( 
                'ajax_url' => admin_url( 'admin-ajax.php' ), 
                'nonce'    => wp_create_nonce( 'timu_install_nonce' ) 
            ) );
        }

        protected function render_registration_field() {
            $key      = $this->get_plugin_option( 'registration_key', '' );
            $is_valid = $this->is_licensed();
            $status_color = $is_valid ? '#46b450' : '#d63638';
            ?>
            <div class="timu-card">
                <div class="timu-card-header"><?php esc_html_e( 'Plugin Registration', 'timu' ); ?></div>
                <div class="timu-card-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Registration Key', 'timu' ); ?></th>
                            <td>
                                <input type="text" name="<?php echo esc_attr($this->plugin_slug); ?>_options[registration_key]" value="<?php echo esc_attr( $key ); ?>" class="regular-text" style="font-family: monospace;">
                                <p class="description" style="margin-top: 8px;">
                                    <?php printf( esc_html__( 'Enter your key from %s to unlock developer support.', 'timu' ), '<a href="https://thisismyurl.com" target="_blank">thisismyurl.com</a>' ); ?>
                                </p>
                                <?php if ( ! empty( $key ) ) : ?>
                                    <p class="description" style="color: <?php echo esc_attr($status_color); ?>; font-weight: 600; margin-top: 8px;">
                                        <?php echo esc_html__( 'Status:', 'timu' ) . ' ' . esc_html( $this->license_message ); ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            <?php
        }

        protected function render_core_header() {
    $fs = $this->init_fs();
    $icon_rel = 'assets/icon.png';
    $icon_path = WP_PLUGIN_DIR . '/' . $this->plugin_slug . '/' . $icon_rel;
    $icon_url = '';

    // 1. Check for custom icon provided in constructor
    if ( ! empty( $this->plugin_icon ) ) {
        $icon_url = $this->plugin_icon;
    } 
    // 2. Check for icon.png in the specific plugin's assets folder
    elseif ( $fs->exists( $icon_path ) ) {
        $icon_url = $this->plugin_url . $icon_rel;
    } 
    // 3. Fallback to the shared core default icon
    elseif ( $fs->exists( WP_PLUGIN_DIR . '/' . $this->plugin_slug . '/core/assets/default-icon.png' ) ) {
        $icon_url = $this->plugin_url . 'core/assets/default-icon.png';
    }

    $donate_url = 'https://thisismyurl.com/donate/?source=' . urlencode( $this->plugin_slug );
    ?>
    <div class="timu-header">
        <?php if ( ! empty( $icon_url ) ) : ?>
            <img src="<?php echo esc_url( $icon_url ); ?>" alt="<?php esc_attr_e( 'Plugin Icon', 'timu' ); ?>">
        <?php endif; ?>
        <h1>
            <?php echo esc_html( get_admin_page_title() ); ?> 
            <span class="agency-by">
                <?php esc_html_e( 'by', 'timu' ); ?> 
                <a href="<?php echo esc_url( $donate_url ); ?>" target="_blank" style="text-decoration: none; color: #888;">thisismyurl.com</a>
            </span>
        </h1>
    </div>
    <?php
}

        protected function render_core_sidebar( $extra_content = '' ) {
            $fs = $this->init_fs();
            $tools = $this->fetch_other_tools();
            $banner_rel = 'assets/banner.png';
            $banner_path = WP_PLUGIN_DIR . '/' . $this->plugin_slug . '/' . $banner_rel;
            $banner_url = $this->plugin_url . $banner_rel;
            ?>
            <div id="postbox-container-1" class="postbox-container timu-marketing-sidebar" style="width: 280px; float: right; margin-left: 20px;">
                <?php if ( $fs->exists( $banner_path ) ) : ?>
                    <div class="postbox">
                        <img src="<?php echo esc_url($banner_url); ?>" style="width:100%; height:auto; display:block;">
                        <?php if ( ! empty( $extra_content ) ) : ?>
                            <div class="inside"><?php echo wp_kses_post( $extra_content ); ?></div>
                        <?php endif; ?>
                    </div>
                <?php elseif ( ! empty( $extra_content ) ) : ?>
                    <div class="postbox">
                        <div class="inside"><?php echo wp_kses_post( $extra_content ); ?></div>
                    </div>
                <?php endif; ?>

                <div class="postbox">
                    <h2 class="hndle"><span><?php esc_html_e( 'Other Tools', 'timu' ); ?></span></h2>
                    <div class="inside">
                        <?php if ( is_array($tools) ) : foreach ( array_slice($tools, 0, 5) as $tool ) : ?>
                            <?php 
                                if ( ! is_array($tool) || empty($tool['slug']) ) continue;
                                $status = $this->get_plugin_status($tool['slug']); 
                                $plugin_file = $tool['slug'] . '/' . $tool['slug'] . '.php';
                                $activate_url = wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . urlencode( $plugin_file ) ), 'activate-plugin_' . $plugin_file );
                            ?>
                            <div class="timu-tool-item">
                                <img src="<?php echo esc_url($tool['icon'] ?: $this->plugin_url . 'core/assets/default-icon.png'); ?>" alt="<?php echo esc_attr($tool['name']); ?>">
                                <div>
                                    <h4 style="margin-bottom:2px;"><?php echo esc_html($tool['name']); ?></h4>
                                    <?php if ( ! empty($tool['excerpt']) ) : ?><p><?php echo esc_html($tool['excerpt']); ?></p><?php endif; ?>
                                    <?php if ( $status['installed'] ) : ?>
                                        <?php if ( $status['active'] ) : ?><span style="font-size:11px; color:#646970;"><?php esc_html_e( 'Active', 'timu' ); ?></span><?php else : ?>
                                            <a href="<?php echo esc_url($activate_url); ?>" style="font-size:11px; color:#2271b1;"><?php esc_html_e( 'Activate Now &rarr;', 'timu' ); ?></a><?php endif; ?>
                                    <?php else : ?><a href="#" class="timu-install-btn" data-slug="<?php echo esc_attr($tool['slug']); ?>" data-url="<?php echo esc_url($tool['url']); ?>" style="font-size:11px;"><?php esc_html_e( 'Install Now &rarr;', 'timu' ); ?></a><?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                        <p style="text-align:center;"><a href="https://thisismyurl.com" style="font-size:10px; color:#999;" target="_blank"><?php esc_html_e( 'See More', 'timu' ); ?></a></p>
                    </div>
                </div>
            </div>
            <?php
        }

        protected function get_plugin_status( $slug ) {
            if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $all = get_plugins(); 
            $path = $slug . '/' . $slug . '.php';
            $inst = isset( $all[$path] );
            $options = get_option( $slug . '_options' );
            return array( 'installed' => $inst, 'active' => ($inst && is_plugin_active( $path )), 'registered' => ! empty( $options['registration_key'] ) );
        }

        private function fetch_other_tools() {
            if ( isset($_GET['timu_refresh_tools']) ) delete_transient( 'timu_tools_cache' );
            $tools = get_transient( 'timu_tools_cache' );
            if ( is_array($tools) ) return $tools;
            
            $res = wp_remote_get( 'https://thisismyurl.com/wp-json/api/v1/plugins/', array( 'timeout' => 8 ) );
            if ( is_wp_error( $res ) ) return array();
            
            $data = json_decode( wp_remote_retrieve_body( $res ), true );
            if ( ! is_array($data) ) return array();
            
            $tools = array_values( array_filter($data, function($i) { return is_array($i) && isset($i['slug']) && $i['slug'] !== $this->plugin_slug; }) );
            set_transient( 'timu_tools_cache', $tools, 12 * HOUR_IN_SECONDS );
            return $tools;
        }

        protected function is_licensed() {
            $key = $this->get_plugin_option( 'registration_key', '' );
            if ( empty( $key ) ) { $this->license_message = __( 'Key missing.', 'timu' ); return false; }
            
            $cached = get_transient( $this->plugin_slug . '_license_status' );
            if ( false !== $cached ) { $this->license_message = get_transient( $this->plugin_slug . '_license_msg' ); return ($cached === 'valid'); }
            
            $url = add_query_arg( array( 'registration_key' => $key, 'site_url' => home_url(), 'plugin_slug' => $this->plugin_slug ), 'https://thisismyurl.com/wp-json/license-manager/v1/check/' );
            $res = wp_remote_get( $url, array( 'timeout' => 15 ) );
            if ( is_wp_error( $res ) ) { $this->license_message = __( 'Server error.', 'timu' ); return false; }
            
            $data = json_decode( wp_remote_retrieve_body( $res ), true );
            $is_valid = ( isset( $data['status'] ) && $data['status'] === 'valid' );
            $this->license_message = $data['message'] ?? ($is_valid ? __( 'Active', 'timu' ) : __( 'Invalid', 'timu' ));
            
            set_transient( $this->plugin_slug . '_license_status', $is_valid ? 'valid' : 'invalid', DAY_IN_SECONDS );
            set_transient( $this->plugin_slug . '_license_msg', $this->license_message, DAY_IN_SECONDS );
            return $is_valid;
        }

        protected function render_core_footer() {
            ?>
            <div class="clear"></div>
            <div class="timu-footer-links" style="margin-top: 50px; border-top: 1px solid #ddd; padding-top: 20px; color: #999; font-size: 11px;">
                &copy; <?php echo esc_html( date('Y') ); ?> <a href="https://thisismyurl.com/" target="_blank" style="color: #999;">thisismyurl.com</a>
            </div>
            <?php
        }

        /**
         * Centralized Settings Registration
         */
        protected function init_settings_generator( $blueprint ) {
            $this->settings_blueprint = $blueprint;

            add_action( 'admin_init', function() {
                register_setting( $this->options_group, $this->plugin_slug . '_options', [
                    'sanitize_callback' => [ $this, 'sanitize_core_options' ]
                ]);

                foreach ( $this->settings_blueprint as $section_id => $section ) {
                    // Register the section
                    add_settings_section( 
                        $section_id, 
                        $section['title'] ?? '', 
                        null, 
                        $this->plugin_slug 
                    );

                    // SAFETY: Ensure the 'fields' key exists and is an array before looping
                    if ( ! isset( $section['fields'] ) || ! is_array( $section['fields'] ) ) {
                        continue;
                    }

                    // Iterate ONLY over the fields within this section
                    foreach ( $section['fields'] as $field_id => $args ) {
                        add_settings_field(
                            $field_id,
                            $args['label'] ?? '', // Safety for missing labels
                            [ $this, 'render_generated_field' ],
                            $this->plugin_slug,
                            $section_id,
                            array_merge( $args, [ 'id' => $field_id ] )
                        );
                    }
                }
            });
        }

        /**
         * Universal Field Renderer
         */
        public function render_generated_field( $args ) {
    // Safety check: If no type is provided, we can't render the field
    if ( empty( $args['type'] ) ) {
        return;
    }

    $options = $this->get_plugin_option();
    $value   = $options[ $args['id'] ] ?? ( $args['default'] ?? '' );
    $name    = $this->plugin_slug . "_options[" . $args['id'] . "]";
    
    $dependency_data = '';
    $classes = 'timu-field-wrapper';
    
    if ( ! empty( $args['parent'] ) ) {
        $classes .= ' timu-child-field';
        $dependency_data = ' data-parent="' . esc_attr( $args['parent'] ) . '"';
        if ( isset( $args['parent_value'] ) ) {
            $dependency_data .= ' data-parent-value="' . esc_attr( $args['parent_value'] ) . '"';
        }
    }

    if ( ! empty( $args['is_parent'] ) ) {
        $classes .= ' timu-parent-control';
    }

    echo '<div class="' . esc_attr( $classes ) . '"' . $dependency_data . '>';

            switch ( $args['type'] ) {
                case 'switch':
                    echo '<label class="timu-switch">';
                    echo '<input type="checkbox" name="'.esc_attr($name).'" id="'.esc_attr($args['id']).'" value="1" '.checked(1, $value, false).' />';
                    echo '<span class="timu-slider"></span></label>';
                    break;
                case 'radio':
                    if ( ! empty( $args['options'] ) ) {
                        foreach ( $args['options'] as $opt_val => $opt_label ) {
                            echo '<label style="display:block; margin-bottom:5px;">';
                            echo '<input type="radio" name="'.esc_attr($name).'" value="'.esc_attr($opt_val).'" '.checked($opt_val, $value, false).' /> ';
                            echo esc_html($opt_label) . '</label>';
                        }
                    }
                    break;
                case 'select':
                    echo '<select name="'.esc_attr($name).'" class="regular-text">';
                    foreach ( $args['options'] as $opt_val => $opt_label ) {
                        echo '<option value="'.esc_attr($opt_val).'" '.selected($opt_val, $value, false).'>'.esc_html($opt_label).'</option>';
                    }
                    echo '</select>';
                    break;
                case 'textarea':
                    echo '<textarea name="'.esc_attr($name).'" class="large-text" rows="5" placeholder="'.esc_attr($args['placeholder'] ?? '').'">'.esc_textarea($value).'</textarea>';
                    break;
                case 'number':
                    echo '<input type="number" name="'.esc_attr($name).'" value="'.esc_attr($value).'" step="'.esc_attr($args['step'] ?? '1').'" min="'.esc_attr($args['min'] ?? '0').'" max="'.esc_attr($args['max'] ?? '').'" class="small-text" />';
                    break;
                case 'color':
                    echo '<input type="text" name="'.esc_attr($name).'" value="'.esc_attr($value).'" class="timu-color-picker" data-default-color="'.esc_attr($args['default'] ?? '#ffffff').'" />';
                    break;
                case 'text':
                default:
                    echo '<input type="text" name="'.esc_attr($name).'" value="'.esc_attr($value).'" class="regular-text" placeholder="'.esc_attr($args['placeholder'] ?? '').'" />';
                    break;
            }

            if ( ! empty( $args['desc'] ) ) {
                echo '<p class="description">' . wp_kses_post( $args['desc'] ) . '</p>';
            }
            echo '</div>';
        }

        /**
         * Standardized Page Renderer
         */
        public function render_settings_page() {
            ?>
            <div class="wrap timu-admin-wrap">
                <?php $this->render_core_header(); ?>
                <form method="post" action="options.php">
                    <?php settings_fields( $this->options_group ); ?>
                    <div id="poststuff">
                        <div id="post-body" class="metabox-holder columns-2">
                            <div id="post-body-content">
                                <?php foreach ( $this->settings_blueprint as $section_id => $section ) : ?>
                                    <div class="timu-card">
                                        <div class="timu-card-header"><?php echo esc_html( $section['title'] ); ?></div>
                                        <div class="timu-card-body">
                                            <table class="form-table">
                                                <?php do_settings_fields( $this->plugin_slug, $section_id ); ?>
                                            </table>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php $this->render_registration_field(); ?>
                                <?php submit_button( null, 'primary large' ); ?>
                            </div>
                            <?php $this->render_core_sidebar(); ?>
                        </div>
                    </div>
                </form>
                <?php $this->render_core_footer(); ?>
            </div>
            <?php
        }
    }
}