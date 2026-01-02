<?php
/**
 * TIMU Shared Core Library
 *
 * This abstract class serves as the architectural foundation for the TIMU plugin suite. 
 * It adheres to WordPress Coding Standards by centralizing shared logic such as settings 
 * generation, secure image processing, and licensing to reduce memory overhead and ensure 
 * consistent behavior across sibling plugins.
 *
 * @package    TIMU_Core
 * @version    1.260102
 * @author     thisismyurl.com
 * @license    GPL-2.0+
 */

/**
 * Prevent direct access to the file for security.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TIMU_Core_v1' ) ) {

	/**
	 * Class TIMU_Core_v1
	 *
	 * An abstract base class that must be extended by individual plugins.
	 * It provides a standardized framework for admin UI and backend utilities.
	 */
	abstract class TIMU_Core_v1 {

		/** @var string Plugin slug used for unique identification and option names. */
		public $plugin_slug;

		/** @var string Base URL for plugin assets (CSS/JS). */
		public $plugin_url;

		/** @var string Settings group name for the register_setting() function. */
		public $options_group;

		/** @var string Optional URL to a custom plugin icon. */
		public $plugin_icon;

		/** @var string Parent slug for the WordPress admin menu location. */
		public $menu_parent = 'options-general.php';

		/** @var string Stores the dynamic status message for the licensing system. */
		public $license_message = '';

		/** @var array Holds the configuration for the settings page generator. */
		public $settings_blueprint = array();

		/** @var object|null Lazy-loaded WordPress Filesystem object for local file operations. */
		public $fs;

		/** @var string Semantic versioning for the core library itself. */
		public static $version = '1.260102';

		/**
		 * Constructor: Initializes core hooks and shared properties.
		 *
		 * @param string $slug   Unique plugin identifier.
		 * @param string $url    Base URL for assets.
		 * @param string $group  Setting group name.
		 * @param string $icon   Custom icon URL.
		 * @param string $parent Menu parent location.
		 */
		public function __construct( $slug, $url, $group, $icon = '', $parent = 'options-general.php' ) {
			$this->plugin_slug   = $slug;
			$this->plugin_url    = $url;
			$this->options_group = $group;
			$this->plugin_icon   = $icon;
			$this->menu_parent   = $parent;

			/**
			 * Enqueue CSS/JS only on the plugin's specific settings page to 
			 * minimize footprint across the admin dashboard.
			 */
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_core_assets' ) );

			/**
			 * Handles GitHub-based updates if the optional updater.php is present.
			 */
			if ( method_exists( $this, 'init_updater' ) ) {
				add_action( 'plugins_loaded', array( $this, 'init_updater' ) );
			}

			/**
			 * Filter: Adds 'Settings' and 'Donate/Support' links to the Plugins list table.
			 */
			add_filter( "plugin_action_links_{$this->plugin_slug}/{$this->plugin_slug}.php", array( $this, 'add_plugin_action_links' ) );

			/**
			 * AJAX: Provides a secure interface for installing sibling plugins from the sidebar.
			 */
			add_action( 'wp_ajax_timu_install_tool', array( $this, 'ajax_install_plugin' ) );
		}

		/**
		 * Initializes the WordPress Filesystem API.
		 *
		 * Using this abstraction layer ensures compatibility across various 
		 * server environments (direct, FTP, etc.) without hardcoding file paths.
		 *
		 * @return object The global $wp_filesystem object.
		 */
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

		/**
		 * Cleanses settings before they are saved to the database.
		 *
		 * Deletes license transients to force a re-check when the key is updated.
		 *
		 * @param array $input Raw data from the $_POST request.
		 * @return array Sanitized input data.
		 */
		public function sanitize_core_options( $input ) {
			delete_transient( $this->plugin_slug . '_license_status' );
			delete_transient( $this->plugin_slug . '_license_msg' );
			if ( isset( $input['registration_key'] ) ) {
				$input['registration_key'] = sanitize_text_field( $input['registration_key'] );
			}
			return $input;
		}

		/**
		 * AJAX handler for secure remote plugin installation.
		 *
		 * Includes security checks for nonces, capabilities, and allowed hostnames 
		 * to prevent unauthorized script execution or SSRF attacks.
		 */
		public function ajax_install_plugin() {
			check_ajax_referer( 'timu_install_nonce', 'nonce' );

			if ( ! current_user_can( 'install_plugins' ) ) {
				wp_send_json_error( __( 'Permissions error.', 'timu' ) );
			}

			$download_url = esc_url_raw( $_POST['download_url'] );

			/**
			 * Security: Only allow downloads from trusted sources to prevent
			 * malicious package injection.
			 */
			$allowed_hosts = array( 'github.com', 'thisismyurl.com' );
			$host          = wp_parse_url( $download_url, PHP_URL_HOST );
			if ( ! in_array( $host, $allowed_hosts, true ) ) {
				wp_send_json_error( __( 'Unauthorized download source.', 'timu' ) );
			}

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

		/**
		 * Standardized method to retrieve plugin settings.
		 *
		 * @param string $key     The specific option key.
		 * @param mixed  $default Fallback value if key is missing.
		 * @return mixed The option value or default.
		 */
		public function get_plugin_option( $key = '', $default = '' ) {
			$options = get_option( $this->plugin_slug . '_options', array() );
			return ( empty( $key ) ) ? $options : ( isset( $options[ $key ] ) ? $options[ $key ] : $default );
		}

		/**
		 * Enqueues CSS and JS assets for the admin settings page.
		 *
		 * @param string $hook The current admin page hook.
		 */
		public function enqueue_core_assets( $hook ) {
			if ( strpos( $hook, $this->plugin_slug ) === false ) {
				return;
			}
			wp_enqueue_media();
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'wp-color-picker' );
			wp_enqueue_style( 'timu-core-css', $this->plugin_url . 'core/assets/shared-admin.css', array(), '1.1.4' );
			wp_enqueue_script( 'timu-core-js', $this->plugin_url . 'core/assets/shared-admin.js', array( 'jquery', 'wp-color-picker' ), '1.1.3', true );
			wp_localize_script( 'timu-core-js', 'timu_core_vars', array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'timu_install_nonce' ),
			) );
		}

		/**
		 * Renders the primary settings page header with icon-existence checks.
		 */
		public function render_core_header() {
			$fs        = $this->init_fs();
			$icon_rel  = 'assets/icon.png';
			$icon_path = WP_PLUGIN_DIR . '/' . $this->plugin_slug . '/' . $icon_rel;
			$icon_url  = '';

			if ( ! empty( $this->plugin_icon ) ) {
				$icon_url = $this->plugin_icon;
			} elseif ( $fs->exists( $icon_path ) ) {
				$icon_url = $this->plugin_url . $icon_rel;
			} elseif ( $fs->exists( WP_PLUGIN_DIR . '/' . $this->plugin_slug . '/core/assets/default-icon.png' ) ) {
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

		/**
		 * Optimization: Uses a global transient shared by all plugins in the suite.
		 *
		 * @return array List of other tools from the API.
		 */
		private function fetch_other_tools() {
			if ( isset( $_GET['timu_refresh_tools'] ) ) {
				delete_transient( 'timu_global_tools_cache' );
			}
			$tools = get_transient( 'timu_global_tools_cache' );
			if ( is_array( $tools ) ) {
				return $tools;
			}

			$res = wp_remote_get( 'https://thisismyurl.com/wp-json/api/v1/plugins/', array( 'timeout' => 8 ) );
			if ( is_wp_error( $res ) ) {
				return array();
			}

			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( ! is_array( $data ) ) {
				return array();
			}

			$tools = array_values( array_filter( $data, function( $i ) {
				return is_array( $i ) && isset( $i['slug'] ) && $i['slug'] !== $this->plugin_slug;
			} ) );
			set_transient( 'timu_global_tools_cache', $tools, 12 * HOUR_IN_SECONDS );
			return $tools;
		}

		/**
		 * Automated Settings API registration from a configuration blueprint.
		 *
		 * @param array $blueprint Nested configuration array of fields.
		 */
		public function init_settings_generator( $blueprint ) {
			$this->settings_blueprint = $blueprint;

			add_action( 'admin_init', function() {
				register_setting( $this->options_group, $this->plugin_slug . '_options', array(
					'sanitize_callback' => array( $this, 'sanitize_core_options' ),
				) );

				foreach ( $this->settings_blueprint as $section_id => $section ) {
					add_settings_section( $section_id, $section['title'] ?? '', null, $this->plugin_slug );

					if ( ! isset( $section['fields'] ) || ! is_array( $section['fields'] ) ) {
						continue;
					}

					foreach ( $section['fields'] as $field_id => $args ) {
						add_settings_field(
							$field_id,
							$args['label'] ?? '',
							array( $this, 'render_generated_field' ),
							$this->plugin_slug,
							$section_id,
							array_merge( $args, array( 'id' => $field_id ) )
						);
					}
				}
			} );
		}

		/**
		 * WPCS-Compliant Field Renderer with cascading visibility logic.
		 *
		 * @param array $args Configuration for the field.
		 */
		public function render_generated_field( $args ) {
			if ( empty( $args['type'] ) ) {
				return;
			}

			$options = $this->get_plugin_option();
			$value   = $options[ $args['id'] ] ?? ( $args['default'] ?? '' );
			$name    = "{$this->plugin_slug}_options[{$args['id']}]";

			$wrapper_attribs = array( 'class' => array( 'timu-field-wrapper' ) );

			if ( ! empty( $args['parent'] ) ) {
				$wrapper_attribs['class'][]           = 'timu-child-field';
				$wrapper_attribs['data-parent']       = $args['parent'];
				if ( isset( $args['parent_value'] ) ) {
					$wrapper_attribs['data-parent-value'] = $args['parent_value'];
				}
			}

			if ( ! empty( $args['is_parent'] ) ) {
				$wrapper_attribs['class'][] = 'timu-parent-control';
			}

			$attrib_string = '';
			foreach ( $wrapper_attribs as $key => $val ) {
				$attrib_string .= sprintf( ' %s="%s"', $key, is_array( $val ) ? esc_attr( implode( ' ', $val ) ) : esc_attr( $val ) );
			}

			echo '<div' . $attrib_string . '>';

			switch ( $args['type'] ) {
				case 'switch':
					echo '<label class="timu-switch">';
					echo '<input type="checkbox" name="' . esc_attr( $name ) . '" id="' . esc_attr( $args['id'] ) . '" value="1" ' . checked( 1, $value, false ) . ' />';
					echo '<span class="timu-slider"></span></label>';
					break;
				case 'radio':
					if ( ! empty( $args['options'] ) ) {
						foreach ( $args['options'] as $opt_val => $opt_label ) {
							echo '<label style="display:block; margin-bottom:5px;">';
							echo '<input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $opt_val ) . '" ' . checked( $opt_val, $value, false ) . ' /> ';
							echo esc_html( $opt_label ) . '</label>';
						}
					}
					break;
				case 'number':
					echo '<input type="number" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" step="' . esc_attr( $args['step'] ?? '1' ) . '" min="' . esc_attr( $args['min'] ?? '0' ) . '" max="' . esc_attr( $args['max'] ?? '' ) . '" class="small-text" />';
					break;
				case 'text':
				default:
					echo '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
					break;
			}

			if ( ! empty( $args['desc'] ) ) {
				echo '<p class="description">' . wp_kses_post( $args['desc'] ) . '</p>';
			}
			echo '</div>';
		}
public function render_settings_page() { // Changed from protected to public
    ?>
    <div class="wrap timu-admin-wrap">
        <?php $this->render_core_header(); ?>
        <form method="post" action="options.php">
            <?php settings_fields( $this->options_group ); ?>
            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="post-body-content">
                        <?php 
                        // Iterate through the blueprint to render sections and fields.
                        foreach ( $this->settings_blueprint as $section_id => $section ) : 
                        ?>
                            <div class="timu-card">
                                <div class="timu-card-header">
                                    <?php echo esc_html( $section['title'] ); ?>
                                </div>
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
		/**
		 * Optimized Image Conversion with Imagick memory management.
		 *
		 * @param array  $upload     WordPress upload array.
		 * @param string $target_ext Target format.
		 * @param int    $quality    Compression quality.
		 */
		public function process_image_conversion( $upload, $target_ext, $quality = 80 ) {
			if ( ! class_exists( 'Imagick' ) ) {
				return $upload;
			}

			$file_path = $upload['file'];
			$info      = pathinfo( $file_path );
			$new_path  = "{$info['dirname']}/{$info['filename']}.{$target_ext}";

			try {
				Imagick::setResourceLimit( Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024 );

				$image = new Imagick( $file_path );
				$image->setImageFormat( $target_ext );
				$image->setImageCompressionQuality( (int) $quality );
				$image->writeImage( $new_path );
				$image->clear();
				$image->destroy();

				if ( file_exists( $file_path ) && $file_path !== $new_path ) {
					unlink( $file_path );
				}

				$upload['file'] = $new_path;
				$upload['url']  = str_replace( basename( $file_path ), basename( $new_path ), $upload['url'] );
				$upload['type'] = ( 'webp' === $target_ext ) ? 'image/webp' : 'image/avif';

			} catch ( Exception $e ) {
				error_log( 'TIMU Core Error: ' . $e->getMessage() );
			}

			return $upload;
		}

		/**
		 * Renders the Plugin Registration/Licensing field.
		 *
		 * This method is called internally by render_settings_page().
		 *
		 * @since 1.260102
		 */
		public function render_registration_field() {
			$key          = $this->get_plugin_option( 'registration_key', '' );
			$is_valid     = $this->is_licensed();
			$status_color = $is_valid ? '#46b450' : '#d63638';
			?>
			<div class="timu-card">
				<div class="timu-card-header"><?php esc_html_e( 'Plugin Registration', 'timu' ); ?></div>
				<div class="timu-card-body">
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Registration Key', 'timu' ); ?></th>
							<td>
								<input type="text" name="<?php echo esc_attr( $this->plugin_slug ); ?>_options[registration_key]" value="<?php echo esc_attr( $key ); ?>" class="regular-text" style="font-family: monospace;">
								<p class="description" style="margin-top: 8px;">
									<?php 
									printf( 
										/* translators: %s: Link to the agency website. */
										esc_html__( 'Enter your key from %s to unlock developer support.', 'timu' ), 
										'<a href="https://thisismyurl.com" target="_blank">thisismyurl.com</a>' 
									); 
									?>
								</p>
								<?php if ( ! empty( $key ) ) : ?>
									<p class="description" style="color: <?php echo esc_attr( $status_color ); ?>; font-weight: 600; margin-top: 8px;">
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

		protected function is_licensed() {
			// 1. Retrieve the saved key from the plugin options.
			$key = $this->get_plugin_option( 'registration_key', '' );

			// 2. Early exit: If no key is present, the license is inherently invalid.
			if ( empty( $key ) ) {
				$this->license_message = __( 'Key missing.', 'timu' );
				return false;
			}

			/**
			 * 3. Local Cache Check.
			 * We store the license status in a transient to prevent slowing down the 
			 * admin dashboard with a remote request on every page load.
			 */
			$cached_status = get_transient( "{$this->plugin_slug}_license_status" );
			if ( false !== $cached_status ) {
				$this->license_message = get_transient( "{$this->plugin_slug}_license_msg" );
				return ( 'valid' === $cached_status );
			}

			/**
				 * 4. Remote API Validation.
				 * If no cache exists, we perform a secure remote request.
				 */
				$validation_url = add_query_arg(
					array(
						'registration_key' => $key,
						'site_url'         => home_url(),
						'plugin_slug'      => $this->plugin_slug,
					),
					'https://thisismyurl.com/wp-json/license-manager/v1/check/'
				);

				// Perform the remote request with a 15-second timeout for server stability.
				$response = wp_remote_get( $validation_url, array( 'timeout' => 15 ) );

				// Handle connection failures gracefully.
				if ( is_wp_error( $response ) ) {
					$this->license_message = __( 'Server connection error.', 'timu' );
					return false;
				}

				// 5. Response Parsing and Transient Storage.
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				$is_valid = ( isset( $body['status'] ) && 'valid' === $body['status'] );

				/**
				 * translators: %s is the error or status message returned from the remote server.
				 */
				$this->license_message = isset( $body['message'] ) ? $body['message'] : ( $is_valid ? __( 'Active', 'timu' ) : __( 'Invalid', 'timu' ) );

				// Cache the result for 24 hours to optimize performance.
				set_transient( "{$this->plugin_slug}_license_status", $is_valid ? 'valid' : 'invalid', DAY_IN_SECONDS );
				set_transient( "{$this->plugin_slug}_license_msg", $this->license_message, DAY_IN_SECONDS );

				return $is_valid;
			}

			/**
 * Renders the standardized sidebar for the plugin settings page.
 *
 * This method generates a two-part sidebar:
 * 1. A dynamic banner area that only renders if 'assets/banner.png' exists.
 * 2. An 'Other Tools' section that fetches and displays sibling plugins from the API.
 *
 * @since 1.260102
 * @access protected
 * @param string $extra_content Optional HTML to inject into the top of the sidebar.
 */
protected function render_core_sidebar( $extra_content = '' ) {
	$fs    = $this->init_fs(); // Initialize WP_Filesystem.
	$tools = $this->fetch_other_tools(); // Retrieve the shared tools cache.
	
	/**
	 * Define paths for the promotional banner.
	 * WPCS Requirement: Always use specific absolute paths for filesystem checks.
	 */
	$banner_rel  = 'assets/banner.png';
	$banner_path = WP_PLUGIN_DIR . '/' . $this->plugin_slug . '/' . $banner_rel;
	$banner_url  = $this->plugin_url . $banner_rel;

	?>
	<div id="postbox-container-1" class="postbox-container timu-marketing-sidebar" style="width: 280px; float: right; margin-left: 20px;">
		
		<?php 
		/**
		 * Conditional Banner Rendering.
		 * Performance Note: We check file existence locally via $fs->exists() before 
		 * attempting to output an <img> tag to avoid broken 404 requests.
		 */
		if ( $fs->exists( $banner_path ) ) : 
		?>
			<div class="postbox">
				<img src="<?php echo esc_url( $banner_url ); ?>" style="width:100%; height:auto; display:block;" alt="<?php esc_attr_e( 'Plugin Banner', 'timu' ); ?>">
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
				<?php 
				if ( is_array( $tools ) ) : 
					foreach ( array_slice( $tools, 0, 5 ) as $tool ) : 
						if ( ! is_array( $tool ) || empty( $tool['slug'] ) ) {
							continue;
						}

						$status       = $this->get_plugin_status( $tool['slug'] ); 
						$plugin_file  = $tool['slug'] . '/' . $tool['slug'] . '.php';
						$activate_url = wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . urlencode( $plugin_file ) ), 'activate-plugin_' . $plugin_file );
						?>
						<div class="timu-tool-item" style="margin-bottom: 15px; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">
							<img src="<?php echo esc_url( $tool['icon'] ?: $this->plugin_url . 'core/assets/default-icon.png' ); ?>" 
								 alt="<?php echo esc_attr( $tool['name'] ); ?>" 
								 style="width:32px; height:32px; float:left; margin-right:10px;">
							<div style="overflow:hidden;">
								<h4 style="margin:0 0 4px; font-size:13px;"><?php echo esc_html( $tool['name'] ); ?></h4>
								
								<?php if ( $status['installed'] ) : ?>
									<?php if ( $status['active'] ) : ?>
										<span style="font-size:11px; color:#646970; font-weight:600;"><?php esc_html_e( 'Active', 'timu' ); ?></span>
									<?php else : ?>
										<a href="<?php echo esc_url( $activate_url ); ?>" style="font-size:11px; color:#2271b1; text-decoration:none;"><?php esc_html_e( 'Activate &rarr;', 'timu' ); ?></a>
									<?php endif; ?>
								<?php else : ?>
									<a href="#" class="timu-install-btn" 
									   data-slug="<?php echo esc_attr( $tool['slug'] ); ?>" 
									   data-url="<?php echo esc_url( $tool['url'] ); ?>" 
									   style="font-size:11px; color:#2271b1; text-decoration:none;">
									   <?php esc_html_e( 'Install Now &rarr;', 'timu' ); ?>
									</a>
								<?php endif; ?>
							</div>
						</div>
					<?php 
					endforeach; 
				endif; 
				?>
				<p style="text-align:center; margin-top:10px;">
					<a href="https://thisismyurl.com" style="font-size:11px; color:#999; text-decoration:none;" target="_blank">
						<?php esc_html_e( 'See More Tools', 'timu' ); ?>
					</a>
				</p>
			</div>
		</div>
	</div>
	<?php
}

protected function get_plugin_status( $slug ) {
	// 1. Ensure the required WordPress plugin functions are loaded.
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	/**
	 * 2. Check Installation.
	 * We scan the plugins directory for the specific main file.
	 */
	$all_plugins = get_plugins();
	$plugin_file = "{$slug}/{$slug}.php";
	$is_installed = isset( $all_plugins[ $plugin_file ] );

	/**
	 * 3. Check Activation & Registration.
	 * Registration check ensures the user has already entered a key in the sibling tool.
	 */
	$is_active  = ( $is_installed && is_plugin_active( $plugin_file ) );
	$options    = get_option( "{$slug}_options", array() );
	$is_reg     = ! empty( $options['registration_key'] );

	return array(
		'installed'  => $is_installed,
		'active'     => $is_active,
		'registered' => $is_reg,
	);
}

/**
 * Renders the standardized footer for the plugin settings page.
 *
 * This method provides a clean visual break and links back to the agency website 
 * for support and additional tools. It uses standard WordPress HTML patterns for 
 * consistency within the dashboard.
 *
 * @since 1.260102
 * @access protected
 */
protected function render_core_footer() {
	?>
	<div class="clear"></div>
	<div class="timu-footer-links" style="margin-top: 50px; border-top: 1px solid #dcdcde; padding-top: 20px; color: #646970; font-size: 11px;">
		<p>
			&copy; <?php echo esc_html( date( 'Y' ) ); ?> 
			<a href="https://thisismyurl.com/" target="_blank" style="color: #646970; text-decoration: none;">
				<?php esc_html_e( 'thisismyurl.com', 'timu' ); ?>
			</a>
		</p>
	</div>
	<?php
}

	}
}