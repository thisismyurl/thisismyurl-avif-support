<?php
/**
 * TIMU Admin UI Component
 *
 * This class handles all aspects of the WordPress Admin experience, including
 * the Settings API registration, card-based UI rendering, and the paginated logs.
 *
 * @package     TIMU_Core
 * @version     1.26010212
 * 
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TIMU_Admin_v1 {

    /** @var object Reference to the main Core instance for shared helpers. */
    private $core;

    /**
     * Constructor
     * @param object $core The main TIMU_Core_v1 instance.
     */
   public function __construct( $core ) {
    $this->core = $core;

    // Initialize Settings API registration
    add_action( 'admin_init', array( $this, 'register_settings_api' ) );
    
    // Corrected Sidebar Hook: Point directly to $this
    add_action( 'timu_sidebar_under_banner', array( $this, 'render_default_sidebar_actions' ) );
}
    /**
     * 1. SETTINGS API REGISTRATION
     * Queues the registration logic for the admin_init hook.
     */
    public function register_settings_api() {
        // Ensure we are inside the admin_init hook context
        if ( ! did_action( 'admin_init' ) ) {
            add_action( 'admin_init', array( $this, 'register_settings_api' ) );
            return;
        }

        register_setting( $this->core->options_group, $this->core->plugin_slug . '_options', array(
            'sanitize_callback' => array( $this->core, 'sanitize_core_options' ),
        ) );

        if ( empty( $this->core->settings_blueprint ) ) {
            return;
        }

        foreach ( $this->core->settings_blueprint as $section_id => $section ) {
            add_settings_section( $section_id, $section['title'] ?? '', null, $this->core->plugin_slug );

            if ( empty( $section['fields'] ) ) {
                continue;
            }

            foreach ( (array)$section['fields'] as $field_id => $args ) {
                add_settings_field(
                    $field_id,
                    $args['label'] ?? '',
                    array( $this, 'render_generated_field' ),
                    $this->core->plugin_slug,
                    $section_id,
                    array_merge( $args, array( 'id' => $field_id ) )
                );
            }
        }
    }

    
public function render_generated_field( $args ) {
    $options = $this->core->get_plugin_option();
    $value   = $options[ $args['id'] ] ?? ( $args['default'] ?? '' );
    $name    = "{$this->core->plugin_slug}_options[{$args['id']}]";
    
    $is_master = ( 'enabled' === $args['id'] ) ? ' timu-master-toggle' : '';
    
    // Generate dynamic attributes for the JavaScript visibility controller
    $conditional_attrs = '';
    if ( ! empty( $args['show_if'] ) && is_array( $args['show_if'] ) ) {
        $conditional_attrs = ' data-show-if-field="' . esc_attr( $args['show_if']['field'] ) . '"';
        $conditional_attrs .= ' data-show-if-value="' . esc_attr( $args['show_if']['value'] ) . '"';
    }

    echo '<div class="timu-field-wrapper' . esc_attr( $is_master ) . '"' . $conditional_attrs . '>';
    
    switch ( $args['type'] ) {
        case 'switch':
            echo '<label class="timu-switch">';
            echo '<input type="checkbox" name="' . esc_attr( $name ) . '" id="' . esc_attr( $args['id'] ) . '" value="1" ' . checked( 1, (int)$value, false ) . ' />';
            echo '<span class="timu-slider"></span></label>';
            break;

        case 'range': // NEW: Interactive Slider for Quality
            echo '<div class="timu-range-container" style="display:flex; align-items:center; gap:12px;">';
            echo '<input type="range" name="' . esc_attr( $name ) . '" id="' . esc_attr( $args['id'] ) . '" value="' . esc_attr( $value ) . '" step="' . esc_attr( $args['step'] ?? '1' ) . '" min="' . esc_attr( $args['min'] ?? '0' ) . '" max="' . esc_attr( $args['max'] ?? '100' ) . '" oninput="this.nextElementSibling.value = this.value" style="flex-grow:1;" />';
            echo '<output style="font-weight:bold; min-width:30px;">' . esc_html( $value ) . '</output>%';
            echo '</div>';
            break;

        case 'select': // NEW: Dropdown for Handling Modes
            echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $args['id'] ) . '" class="regular-text">';
            foreach ( (array)$args['options'] as $opt_val => $opt_label ) {
                echo '<option value="' . esc_attr( $opt_val ) . '" ' . selected( $value, $opt_val, false ) . '>' . esc_html( $opt_label ) . '</option>';
            }
            echo '</select>';
            break;

        case 'multicheck': // NEW: For excluding specific image sizes
            echo '<div class="timu-multicheck-list" style="max-height:150px; overflow-y:auto; background:#f6f7f7; padding:10px; border:1px solid #dcdcde;">';
            foreach ( (array)$args['options'] as $opt_val => $opt_label ) {
                $checked = ( is_array( $value ) && in_array( $opt_val, $value ) ) ? 'checked' : '';
                echo '<label style="display:block; margin-bottom:5px;">';
                echo '<input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $opt_val ) . '" ' . $checked . ' /> ' . esc_html( $opt_label );
                echo '</label>';
            }
            echo '</div>';
            break;

        case 'media': // NEW: For Watermarking or Brand Logos
            echo '<div class="timu-media-uploader">';
            echo '<input type="text" name="' . esc_attr( $name ) . '" id="' . esc_attr( $args['id'] ) . '" value="' . esc_attr( $value ) . '" class="regular-text" style="display:block; margin-bottom:5px;" />';
            echo '<button type="button" class="button media_btn" data-target="#' . esc_attr( $args['id'] ) . '">' . esc_html__('Select Image', 'timu') . '</button>';
            echo '</div>';
            break;

        case 'radio':
            if ( ! empty( $args['options'] ) ) {
                foreach ( $args['options'] as $opt_val => $opt_label ) {
                    echo '<label style="display:block; margin-bottom:5px;">';
                    echo '<input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $opt_val ) . '" ' . checked( $opt_val, $value, false ) . ' class="timu-conditional-trigger" /> ';
                    echo esc_html( $opt_label ) . '</label>';
                }
            }
            break;
        case 'color': // NEW: WordPress Native Color Picker
            echo '<input type="text" name="' . esc_attr( $name ) . '" id="' . esc_attr( $args['id'] ) . '" value="' . esc_attr( $value ) . '" class="timu-color-picker" data-default-color="' . esc_attr( $args['default'] ?? '#2271b1' ) . '" />';
            break;

        case 'media': // NEW: WP Media Library Uploader
            echo '<div class="timu-media-control" style="display:flex; gap:10px; align-items:center;">';
            echo '<input type="text" name="' . esc_attr( $name ) . '" id="' . esc_attr( $args['id'] ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
            echo '<button type="button" class="button media_btn" data-target="#' . esc_attr( $args['id'] ) . '">' . esc_html__('Select File', 'timu') . '</button>';
            echo '</div>';
            if ( ! empty( $value ) ) {
                echo '<div class="timu-media-preview" style="margin-top:10px; max-width:150px; border:1px solid #ccd0d4; padding:5px; background:#fff;">';
                echo '<img src="' . esc_url( $value ) . '" style="max-width:100%; height:auto; display:block;" />';
                echo '</div>';
            }
            break;
           case 'date':
    echo '<input type="text" name="' . esc_attr( $name ) . '" id="' . esc_attr( $args['id'] ) . '" value="' . esc_attr( $value ) . '" class="timu-datepicker regular-text" autocomplete="off" />';
    break;
    
    case 'password':
    echo '<div class="timu-password-wrapper" style="position:relative; display:inline-block;">';
    echo '<input type="password" name="' . esc_attr( $name ) . '" id="' . esc_attr( $args['id'] ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
    echo '<button type="button" class="button timu-toggle-password" style="margin-left:5px;">' . esc_html__('Show', 'timu') . '</button>';
    echo '</div>';
    break;
case 'code':
    echo '<textarea name="' . esc_attr( $name ) . '" id="' . esc_attr( $args['id'] ) . '" class="timu-code-editor" rows="5" style="width:100%; font-family:monospace;">' . esc_textarea( $value ) . '</textarea>';
    break;

        default:
            echo '<input type="' . esc_attr( $args['type'] ) . '" name="' . esc_attr( $name ) . '" id="' . esc_attr( $args['id'] ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
            break;
    }

    if ( ! empty( $args['desc'] ) ) {
        echo '<p class="description">' . wp_kses_post( $args['desc'] ) . '</p>';
    }
    echo '</div>';
}


    public function render_progress_bar($percent = 0) {
    echo '<div class="timu-progress-wrap" style="background:#dcdcde; height:20px; border-radius:10px; overflow:hidden; margin:10px 0;">';
    echo '<div class="timu-progress-bar" style="width:' . (int)$percent . '%; background:#46b450; height:100%; transition: width 0.3s ease;"></div>';
    echo '</div>';
}

public function render_nav_tabs() {
    $current_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'general';
    $tabs = array(
        'general' => __('General Settings', 'timu'),
        'bulk'    => __('Bulk Operations', 'timu'),
        'tools'   => __('Advanced Tools', 'timu')
    );

    echo '<h2 class="nav-tab-wrapper">';
    foreach ( $tabs as $tab => $name ) {
        $active = ( $current_tab === $tab ) ? 'nav-tab-active' : '';
        echo '<a class="nav-tab ' . $active . '" href="?page=' . $this->core->plugin_slug . '&tab=' . $tab . '">' . esc_html($name) . '</a>';
    }
    echo '</h2>';
}

public function add_help_tabs() {
    $screen = get_current_screen();
    if ( $screen->id !== 'settings_page_' . $this->core->plugin_slug ) return;

    $screen->add_help_tab( array(
        'id'      => 'timu_general_help',
        'title'   => __('Optimization Help', 'timu'),
        'content' => '<p>' . __('Instructions for WebP/AVIF settings...', 'timu') . '</p>',
    ) );
}

public function render_admin_notices() {
    if ( isset( $_GET['settings-updated'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Settings saved successfully.', 'timu') . '</p></div>';
    }
}



public function add_media_sidebar_actions( $form_fields, $post ) {
    $form_fields['timu_optimization'] = array(
        'label' => __('Optimization', 'timu'),
        'input' => 'html',
        'html'  => '<button type="button" class="button timu-process-single" data-id="'.$post->ID.'">Re-optimize</button>',
    );
    return $form_fields;
}
    /**
     * 3. THE SETTINGS PAGE MAIN WRAPPER
     */
    public function render_settings_page() {
        ?>
        <div class="wrap timu-admin-wrap">
            <?php $this->render_core_header(); ?>
            <form method="post" action="options.php">
                <?php settings_fields( $this->core->options_group ); ?>
                <div id="poststuff">
                    <div id="post-body" class="metabox-holder columns-2">
                        <div id="post-body-content">
                            <?php foreach ( (array)$this->core->settings_blueprint as $section_id => $section ) : ?>
                                <div class="timu-card">
                                    <div class="timu-card-header"><?php echo esc_html( $section['title'] ); ?></div>
                                    <div class="timu-card-body">
                                        <table class="form-table"><?php do_settings_fields( $this->core->plugin_slug, $section_id ); ?></table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php $this->render_registration_field(); ?>
                            <?php submit_button( null, 'primary large' ); ?>
                            
                            <?php $this->render_unprocessed_log(); ?>
                            <?php $this->render_processing_log(); ?>
                        </div>
                        <?php $this->render_core_sidebar(); ?>
                    </div>
                </div>
            </form>
            <?php $this->render_core_footer(); ?>
        </div>
        <?php
    }

    /**
     * 4. LOG: UNPROCESSED IMAGES (PAGINATED)
     */
    public function render_unprocessed_log() {
        $prefix = $this->get_internal_prefix();
        $page = isset( $_GET['unpaged'] ) ? max( 1, intval( $_GET['unpaged'] ) ) : 1;
        
        $query = new WP_Query( array(
            'post_type'      => 'attachment',
            'post_mime_type' => array( 'image/jpeg', 'image/png' ),
            'posts_per_page' => 20,
            'paged'          => $page,
            'meta_query'     => array( array( 'key' => "_{$prefix}_savings", 'compare' => 'NOT EXISTS' ) )
        ) );

        echo '<div class="timu-card timu-unprocessed-log" style="margin-top:20px; border-left:4px solid #72aee6;">';
        echo '<div class="timu-card-header">' . esc_html__('Unprocessed Images', 'timu') . ' <span style="font-size:0.8em; font-weight:normal;">(' . $query->found_posts . ')</span></div>';
        echo '<div class="timu-log-container">';
        
        if ( $query->have_posts() ) {
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th style="width:65px;">Preview</th><th>Filename</th><th style="width:100px;">Actions</th></tr></thead><tbody>';
            foreach ( $query->posts as $img ) {
                echo '<tr>';
                echo '<td>' . wp_get_attachment_image( $img->ID, array(40,40) ) . '</td>';
                echo '<td><strong>' . esc_html( basename( get_attached_file( $img->ID ) ) ) . '</strong></td>';
                echo '<td><button type="button" class="button button-small timu-process-single" data-id="'.$img->ID.'">' . esc_html__('Process', 'timu') . '</button></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( array( 'base' => add_query_arg( 'unpaged', '%#%' ), 'total' => $query->max_num_pages, 'current' => $page ) ) . '</div></div>';
        } else {
            echo '<p style="padding:20px; color:#46b450; font-weight:600;">' . esc_html__('All images optimized!', 'timu') . '</p>';
        }
        echo '</div></div>';
        wp_reset_postdata();
    }

    /**
     * 5. LOG: PROCESSED IMAGES (PAGINATED)
     */
    public function render_processing_log() {
        $prefix = $this->get_internal_prefix();
        $savings_key = "_{$prefix}_savings";
        $page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        
        $query = new WP_Query( array(
            'post_type'      => 'attachment',
            'posts_per_page' => 20,
            'paged'          => $page,
            'meta_query'     => array( array( 'key' => $savings_key, 'compare' => 'EXISTS' ) )
        ) );

        echo '<div class="timu-card timu-processing-log" style="margin-top:20px;">';
        echo '<div class="timu-card-header">' . esc_html__('Converted Images', 'timu') . '</div>';
        echo '<div class="timu-log-container">';
        
        if ( $query->have_posts() ) {
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th style="width:65px;">Preview</th><th>Original</th><th>Savings</th><th style="width:100px;">Actions</th></tr></thead><tbody>';
            foreach ( $query->posts as $img ) {
                $orig = get_post_meta( $img->ID, "_{$prefix}_original_path", true );
                $save = (int)get_post_meta( $img->ID, $savings_key, true );
                echo '<tr>';
                echo '<td>' . wp_get_attachment_image( $img->ID, array(40,40) ) . '</td>';
                echo '<td><strong>' . esc_html( basename( $orig ?: get_attached_file( $img->ID ) ) ) . '</strong></td>';
                echo '<td style="color:#46b450; font-weight:600;">' . $this->core->format_bytes( $save ) . '</td>';
                echo '<td><button type="button" class="button button-small timu-restore-single" data-id="'.$img->ID.'">' . esc_html__('Restore', 'timu') . '</button></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'total' => $query->max_num_pages, 'current' => $page ) ) . '</div></div>';
        } else {
            echo '<p style="padding:20px;">' . esc_html__('No images processed yet.', 'timu') . '</p>';
        }
        echo '</div>';
        
        if ( $query->have_posts() ) {
            $total_saved = $this->core->calculate_total_savings( $savings_key );
            echo '<div class="timu-log-footer" style="background:#f6f7f7; padding:12px; border-top:1px solid #dcdcde; display:flex; justify-content:space-between; font-weight:bold;">';
            echo '<span>' . esc_html__('Total Space Saved:', 'timu') . '</span><span style="color:#46b450; font-size:1.2em;">' . $this->core->format_bytes( $total_saved ) . '</span></div>';
        }
        echo '</div>';
        wp_reset_postdata();
    }

    /**
     * 6. SHARED UI ELEMENTS (Moved from Core)
     */
    public function render_core_header() {
        $donate_url = 'https://thisismyurl.com/donate/?source=' . urlencode( (string)$this->core->plugin_slug );
        ?>
        <div class="timu-header">
            <h1><?php echo esc_html( get_admin_page_title() ); ?> 
                <span class="agency-by">by <a href="<?php echo esc_url( $donate_url ); ?>" target="_blank">thisismyurl.com</a></span>
            </h1>
        </div>
        <?php
    }

    public function render_core_footer() {
        ?>
        <div class="clear"></div>
        <div class="timu-footer-links" style="margin-top: 50px; border-top: 1px solid #dcdcde; padding-top: 20px; font-size: 11px; color: #646970;">
            <p>&copy; <?php echo esc_html( date( 'Y' ) ); ?> <a href="https://thisismyurl.com/" target="_blank" style="color: #646970; text-decoration: none;">thisismyurl.com</a></p>
        </div>
        <?php
    }

    public function render_core_sidebar( $extra_content = '' ) {
        $fs    = $this->core->init_fs();
        $tools = $this->fetch_other_tools();
        $banner_url = $this->core->plugin_url . 'assets/banner.png';
        ?>
        <div id="postbox-container-1" class="postbox-container timu-marketing-sidebar" style="width: 280px; float: right; margin-left: 20px;">
            <div class="postbox">
                <div class="inside">
                    <?php do_action( 'timu_sidebar_under_banner', $this->core->plugin_slug ); ?>
                    <?php if ( ! empty( $extra_content ) ) echo '<div style="margin-top:15px;">' . wp_kses_post( $extra_content ) . '</div>'; ?>
                </div>
            </div>
            <div class="postbox">
                <h2 class="hndle"><span><?php esc_html_e( 'Other Tools', 'timu' ); ?></span></h2>
                <div class="inside">
                    <?php if ( is_array( $tools ) ) : foreach ( array_slice( $tools, 0, 5 ) as $tool ) : ?>
                        <div class="timu-tool-item" style="margin-bottom:15px; border-bottom:1px solid #f0f0f1; padding-bottom:10px;">
                            <strong><?php echo esc_html( $tool['name'] ); ?></strong><br>
                            <a href="#" class="timu-install-btn" data-slug="<?php echo esc_attr( $tool['slug'] ); ?>" data-url="<?php echo esc_url( $tool['url'] ); ?>"><?php esc_html_e( 'Install Now &rarr;', 'timu' ); ?></a>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    ppublic function render_registration_field() {
    // Force a license check to update the message property
    $this->core->is_licensed();
    $key = $this->core->get_plugin_option( 'registration_key', '' );
    ?>
    <div class="timu-card">
        <div class="timu-card-header"><?php esc_html_e( 'Registration', 'timu' ); ?></div>
        <div class="timu-card-body">
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Key', 'timu' ); ?></th>
                    <td>
                        <input type="text" name="<?php echo esc_attr( $this->core->plugin_slug ); ?>_options[registration_key]" value="<?php echo esc_attr( $key ); ?>" class="regular-text">
                        <p class="description">Status: <strong><?php echo esc_html( $this->core->license_message ); ?></strong></p>
                    </td>
                </tr>
            </table>
        </div>
    </div>
    <?php
}

    /**
 * 6. SIDEBAR BULK ACTIONS
 * * Modified to prevent duplication by verifying the current page slug.
 * * @param string $current_slug The slug passed by the do_action call.
 */
public function render_default_sidebar_actions( $current_slug = '' ) {
    // SECURITY CHECK: Only render if the slug matches this specific plugin instance.
    if ( $current_slug !== $this->core->plugin_slug ) {
        return;
    }

    $prefix = $this->get_internal_prefix();
    $check_key = "_{$prefix}_savings";

    $has_meta = get_posts( array(
        'post_type'      => 'attachment',
        'posts_per_page' => 1,
        'meta_query'     => array( array( 'key' => $check_key, 'compare' => 'EXISTS' ) ),
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ) );

    $is_enabled  = (int) $this->core->get_plugin_option( 'enabled', 1 );
    $is_disabled = ( 1 !== $is_enabled );
    $btn_text    = $is_disabled ? __('Enable Plugin to Process', 'timu') : __('Bulk Process Library', 'timu');

    ?>
    <div class="timu-bulk-actions" style="padding: 10px 0; border-bottom: 1px solid #f0f0f1; margin-bottom: 10px;">
        <h4 style="margin: 0 0 10px;"><?php esc_html_e( 'Bulk Operations', 'timu' ); ?></h4>
        <button type="button" id="timu-run-bulk" class="button button-primary" style="width: 100%; margin-bottom: 8px;" <?php disabled($is_disabled); ?>>
            <?php echo esc_html($btn_text); ?>
        </button>

        <?php if ( ! empty( $has_meta ) ) : ?>
            <button type="button" id="timu-undo-bulk" class="button button-secondary" style="width: 100%;">
                <?php esc_html_e( 'Restore All', 'timu' ); ?>
            </button>
        <?php endif; ?>
    </div>
    <?php
}

    /**
     * 7. INTERNAL UTILITIES
     */
    private function fetch_other_tools() {
        $tools = get_transient( 'timu_global_tools_cache' );
        if ( is_array( $tools ) ) return $tools;
        
        $res = wp_remote_get( 'https://thisismyurl.com/wp-json/api/v1/plugins/', array( 'timeout' => 8 ) );
        if ( is_wp_error( $res ) ) return array();
        
        $data = json_decode( wp_remote_retrieve_body( $res ), true );
        $tools = array_filter( (array)$data, function( $i ) { 
            return isset( $i['slug'] ) && $i['slug'] !== $this->core->plugin_slug; 
        } );
        
        set_transient( 'timu_global_tools_cache', $tools, 12 * HOUR_IN_SECONDS );
        return $tools;
    }

    private function get_internal_prefix() {
        if ( strpos( $this->core->plugin_slug, 'webp' ) !== false ) return 'webp';
        if ( strpos( $this->core->plugin_slug, 'heic' ) !== false ) return 'heic';
        if ( strpos( $this->core->plugin_slug, 'avif' ) !== false ) return 'avif';
        return 'timu';
    }
}