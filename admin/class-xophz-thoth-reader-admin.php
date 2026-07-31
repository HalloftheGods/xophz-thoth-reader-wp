<?php
/**
 * Admin setup and settings management for Xophz Thoth Reader plugin.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Xophz_Thoth_Reader_Admin {

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	public function add_plugin_admin_menu() {
		add_options_page(
			__( 'Thoth Reader Settings', 'xophz-thoth-reader' ),
			__( 'Thoth Reader', 'xophz-thoth-reader' ),
			'manage_options',
			'xophz-thoth-reader',
			array( $this, 'display_plugin_setup_page' )
		);
	}

	public function register_settings() {
		register_setting( 'xophz_thoth_reader_options', 'xophz_thoth_reader_custom_slug', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_slug' ),
			'default'           => 'thoth-reader',
		) );

		register_setting( 'xophz_thoth_reader_options', 'xophz_thoth_reader_load_mode', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'custom_slug',
		) );

		register_setting( 'xophz_thoth_reader_options', 'xophz_thoth_reader_load_page_id', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		) );
	}

	public function sanitize_slug( $slug ) {
		$clean = sanitize_title( $slug );
		return empty( $clean ) ? 'thoth-reader' : $clean;
	}

	public function flush_rewrites_on_save( $old_value, $new_value ) {
		if ( $old_value !== $new_value ) {
			$public = new Xophz_Thoth_Reader_Public( $this->plugin_name, $this->version );
			$public->register_endpoints();
			flush_rewrite_rules();
		}
	}

	private function is_dev_mode() {
		return ( defined( 'WP_ENV' ) && WP_ENV === 'development' ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
	}

	private function check_dev_server() {
		$vite_port = '8089';
		$internal_host = 'compass';
		$response = @file_get_contents( "http://{$internal_host}:{$vite_port}/" );
		return ! empty( $response );
	}

	public function display_plugin_setup_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$custom_slug  = get_option( 'xophz_thoth_reader_custom_slug', 'thoth-reader' );
		$load_mode    = get_option( 'xophz_thoth_reader_load_mode', 'custom_slug' );
		$load_page_id = get_option( 'xophz_thoth_reader_load_page_id', 0 );

		$is_dev       = $this->is_dev_mode();
		$dev_active   = $is_dev ? $this->check_dev_server() : false;
		$dist_exists  = file_exists( XOPHZ_THOTH_READER_PATH . 'public/dist/index.html' );
		$app_url      = home_url( '/' . ltrim( $custom_slug, '/' ) );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<span class="dashicons dashicons-[#c5a059]" style="font-size: 28px; width: 28px; height: 28px; margin-right: 8px; vertical-align: middle; color: #c5a059;"></span>
				<?php esc_html_e( 'Xophz Thoth Reader Settings', 'xophz-thoth-reader' ); ?>
			</h1>
			<hr class="wp-header-end" />

			<div style="margin-top: 20px; display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
				<div>
					<div class="card" style="max-width: 100%; padding: 20px; background: #0e0e11; color: #e7e5e4; border: 1px solid rgba(197, 160, 89, 0.4); border-radius: 6px; box-shadow: 0 4px 20px rgba(0,0,0,0.4);">
						<h2 style="color: #c5a059; margin-top: 0; font-family: Georgia, serif; font-size: 1.4em;">
							<?php esc_html_e( 'URL Routing & Deployment Configuration', 'xophz-thoth-reader' ); ?>
						</h2>
						<p style="color: #a8a29e; font-size: 13px;">
							<?php esc_html_e( 'Configure how the Svelte 5 Thoth Reader web app is loaded and routed across your WordPress site.', 'xophz-thoth-reader' ); ?>
						</p>

						<form method="post" action="options.php">
							<?php
							settings_fields( 'xophz_thoth_reader_options' );
							do_settings_sections( 'xophz_thoth_reader_options' );
							?>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row">
										<label for="xophz_thoth_reader_custom_slug"><?php esc_html_e( 'Deployment URL Slug', 'xophz-thoth-reader' ); ?></label>
									</th>
									<td>
										<input
											type="text"
											id="xophz_thoth_reader_custom_slug"
											name="xophz_thoth_reader_custom_slug"
											value="<?php echo esc_attr( $custom_slug ); ?>"
											class="regular-text"
											style="background: #050505; color: #f5e4bc; border: 1px solid rgba(197, 160, 89, 0.5); padding: 8px 12px; border-radius: 4px; font-family: monospace;"
										/>
										<p class="description" style="color: #a8a29e; margin-top: 6px;">
											<?php esc_html_e( 'URL path segment where the application is accessible. Default: ', 'xophz-thoth-reader' ); ?> <code>thoth-reader</code>
										</p>
										<?php if ( ! empty( $custom_slug ) ) : ?>
											<p style="margin-top: 8px; font-size: 12px; font-family: monospace;">
												<span style="color: #c5a059;">Live App URL:</span>
												<a href="<?php echo esc_url( $app_url ); ?>" target="_blank" rel="noopener noreferrer" style="color: #62c9ff; text-decoration: underline;">
													<?php echo esc_html( $app_url ); ?>
												</a>
											</p>
										<?php endif; ?>
									</td>
								</tr>

								<tr>
									<th scope="row">
										<label for="xophz_thoth_reader_load_mode"><?php esc_html_e( 'App Routing Mode', 'xophz-thoth-reader' ); ?></label>
									</th>
									<td>
										<select
											id="xophz_thoth_reader_load_mode"
											name="xophz_thoth_reader_load_mode"
											style="background: #050505; color: #f5e4bc; border: 1px solid rgba(197, 160, 89, 0.5); padding: 8px 12px; border-radius: 4px;"
										>
											<option value="custom_slug" <?php selected( $load_mode, 'custom_slug' ); ?>>
												<?php esc_html_e( 'Dedicated Standalone Route (Recommended)', 'xophz-thoth-reader' ); ?>
											</option>
											<option value="shortcode_only" <?php selected( $load_mode, 'shortcode_only' ); ?>>
												<?php esc_html_e( 'Shortcode Embedding Only ([xophz_thoth_reader])', 'xophz-thoth-reader' ); ?>
											</option>
											<option value="homepage" <?php selected( $load_mode, 'homepage' ); ?>>
												<?php esc_html_e( 'Full Site Takeover / Homepage', 'xophz-thoth-reader' ); ?>
											</option>
										</select>
										<p class="description" style="color: #a8a29e; margin-top: 6px;">
											<?php esc_html_e( 'Controls how WordPress captures incoming web requests for the Thoth Reader application.', 'xophz-thoth-reader' ); ?>
										</p>
									</td>
								</tr>
							</table>

							<?php submit_button( __( 'Save Settings & Flush Rewrites', 'xophz-thoth-reader' ), 'primary', 'submit', true, array(
								'style' => 'background: #c5a059; color: #08080c; border: none; font-weight: bold; padding: 8px 18px; border-radius: 4px; cursor: pointer;',
							) ); ?>
						</form>
					</div>

					<div class="card" style="max-width: 100%; padding: 20px; margin-top: 20px; background: #0e0e11; color: #e7e5e4; border: 1px solid rgba(255,255,255,0.1); border-radius: 6px;">
						<h3 style="color: #c5a059; margin-top: 0; font-family: Georgia, serif;">
							<?php esc_html_e( 'Shortcode Usage Guide', 'xophz-thoth-reader' ); ?>
						</h3>
						<p style="color: #a8a29e; font-size: 13px;">
							<?php esc_html_e( 'You can embed the complete Thoth Reader application inside any WordPress post, page, or widget using the standard shortcode:', 'xophz-thoth-reader' ); ?>
						</p>
						<div style="background: #050505; border: 1px border-white/10; padding: 12px; border-radius: 4px; font-family: monospace; color: #c5a059;">
							[xophz_thoth_reader]
						</div>
					</div>
				</div>

				<div>
					<div class="card" style="padding: 20px; background: #0e0e11; color: #e7e5e4; border: 1px solid rgba(197, 160, 89, 0.3); border-radius: 6px;">
						<h3 style="color: #c5a059; margin-top: 0; font-family: Georgia, serif; font-size: 1.2em;">
							<?php esc_html_e( 'Server Environment Status', 'xophz-thoth-reader' ); ?>
						</h3>
						<ul style="list-style: none; padding: 0; margin: 15px 0 0 0; font-size: 13px;">
							<li style="margin-bottom: 12px; display: flex; items-center; justify-content: space-between;">
								<span><?php esc_html_e( 'Environment:', 'xophz-thoth-reader' ); ?></span>
								<strong style="color: <?php echo $is_dev ? '#f59e0b' : '#10b981'; ?>;">
									<?php echo $is_dev ? 'DEVELOPMENT' : 'PRODUCTION'; ?>
								</strong>
							</li>

							<li style="margin-bottom: 12px; display: flex; items-center; justify-content: space-between;">
								<span><?php esc_html_e( 'Vite Dev Server (Port 8089):', 'xophz-thoth-reader' ); ?></span>
								<strong style="color: <?php echo $dev_active ? '#10b981' : ($is_dev ? '#ef4444' : '#6b7280'); ?>;">
									<?php echo $dev_active ? 'CONNECTED (HOTMODE)' : ($is_dev ? 'OFFLINE' : 'N/A'); ?>
								</strong>
							</li>

							<li style="margin-bottom: 12px; display: flex; items-center; justify-content: space-between;">
								<span><?php esc_html_e( 'Production Dist Build:', 'xophz-thoth-reader' ); ?></span>
								<strong style="color: <?php echo $dist_exists ? '#10b981' : '#ef4444'; ?>;">
									<?php echo $dist_exists ? 'READY (public/dist)' : 'NOT BUILT'; ?>
								</strong>
							</li>
						</ul>

						<hr style="border-color: rgba(255,255,255,0.1); margin: 16px 0;" />

						<div style="font-size: 12px; color: #a8a29e; font-family: monospace;">
							<div>Plugin Version: <?php echo esc_html( $this->version ); ?></div>
							<div>Frontend Framework: Svelte 5 (Runes)</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
