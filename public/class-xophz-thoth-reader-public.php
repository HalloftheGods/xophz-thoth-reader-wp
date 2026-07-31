<?php
/**
 * Public renderer and router for the Thoth Reader web app.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Xophz_Thoth_Reader_Public {

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	public function register_endpoints() {
		$load_mode   = get_option( 'xophz_thoth_reader_load_mode', 'custom_slug' );
		$custom_slug = get_option( 'xophz_thoth_reader_custom_slug', 'thoth-reader' );

		if ( $load_mode !== 'shortcode_only' && ! empty( $custom_slug ) ) {
			add_rewrite_rule(
				'^' . preg_quote( $custom_slug, '/' ) . '(/.*)?$',
				'index.php?xophz_thoth_reader=1',
				'top'
			);
		}
	}

	public function register_query_vars( $vars ) {
		$vars[] = 'xophz_thoth_reader';
		return $vars;
	}

	private function is_dev_mode() {
		return ( defined( 'WP_ENV' ) && WP_ENV === 'development' ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
	}

	public function template_redirect() {
		global $wp_query;

		// Do not intercept WordPress admin or login routes
		$request_uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( strpos( $request_uri, '/wp-admin' ) === 0 || strpos( $request_uri, '/wp-login.php' ) === 0 ) {
			return;
		}

		$is_route_match = isset( $wp_query->query_vars['xophz_thoth_reader'] );
		$load_mode      = get_option( 'xophz_thoth_reader_load_mode', 'custom_slug' );
		$is_homepage    = ( $load_mode === 'homepage' && ( is_front_page() || is_404() ) );

		if ( $is_route_match || $is_homepage ) {
			status_header( 200 );
			$wp_query->is_404 = false;
			$custom_slug      = get_option( 'xophz_thoth_reader_custom_slug', 'thoth-reader' );
			$this->render_thoth_reader_shell( $custom_slug );
			exit;
		}
	}

	private function render_thoth_reader_shell( $app_base ) {
		$is_dev    = $this->is_dev_mode();
		$vite_port = '8089';

		if ( isset( $_SERVER['HTTP_HOST'] ) ) {
			$host_parts = explode( ':', $_SERVER['HTTP_HOST'] );
			$wp_host    = $host_parts[0];
		} else {
			$wp_host = wp_parse_url( home_url(), PHP_URL_HOST );
		}

		$vite_url = "//" . $wp_host . ":" . $vite_port;

		if ( $is_dev ) {
			$internal_host = 'compass';
			$dev_html      = @file_get_contents( "http://{$internal_host}:{$vite_port}/" );

			if ( ! $dev_html ) {
				$dev_html = @file_get_contents( "http://127.0.0.1:{$vite_port}/" );
			}

			if ( $dev_html ) {
				// Rewrite relative src/href/import/from URLs for local Vite dev server
				$dev_html = str_replace( 'src="/', 'src="' . $vite_url . '/', $dev_html );
				$dev_html = str_replace( 'href="/', 'href="' . $vite_url . '/', $dev_html );
				$dev_html = str_replace( 'import("/', 'import("' . $vite_url . '/', $dev_html );
				$dev_html = str_replace( 'from "/', 'from="' . $vite_url . '/', $dev_html );
				$dev_html = str_replace( "from '/", "from '" . $vite_url . "/", $dev_html );

				// Inject Vite client for Svelte 5 HMR/live updates if missing
				if ( strpos( $dev_html, '/@vite/client' ) === false ) {
					$vite_client = '<script type="module" src="' . esc_url( $vite_url ) . '/@vite/client"></script>';
					$dev_html    = str_replace( '</head>', $vite_client . "\n</head>", $dev_html );
				}

				// Inject WP API Settings
				$nonce            = wp_create_nonce( 'wp_rest' );
				$user_id          = get_current_user_id();
				$wp_api_settings  = "<script>window.wpApiSettings = { root: '" . esc_url_raw( rest_url() ) . "', nonce: '" . $nonce . "', pluginUrl: '" . esc_url_raw( XOPHZ_THOTH_READER_URL ) . "', version: '" . esc_js( $this->version ) . "', userId: " . $user_id . " };</script>";
				$dev_html         = str_replace( '</head>', $wp_api_settings . "\n</head>", $dev_html );

				echo $dev_html;
				exit;
			}
		}

		// Production Mode: Load built index.html from public/dist/
		$index_file = XOPHZ_THOTH_READER_PATH . 'public/dist/index.html';

		if ( file_exists( $index_file ) ) {
			$content  = file_get_contents( $index_file );
			$dist_url = XOPHZ_THOTH_READER_URL . 'public/dist/';

			// Rewrite production asset paths
			$content = str_replace( '"/assets/', '"' . $dist_url . 'assets/', $content );
			$content = str_replace( "'/assets/", "'" . $dist_url . "assets/", $content );
			$content = str_replace( '"/vite.svg"', '"' . $dist_url . 'vite.svg"', $content );
			$content = str_replace( '"/favicon', '"' . $dist_url . 'favicon', $content );

			// Inject WP API Settings
			$nonce           = wp_create_nonce( 'wp_rest' );
			$user_id         = get_current_user_id();
			$wp_api_settings = "<script>window.wpApiSettings = { root: '" . esc_url_raw( rest_url() ) . "', nonce: '" . $nonce . "', pluginUrl: '" . esc_url_raw( XOPHZ_THOTH_READER_URL ) . "', version: '" . esc_js( $this->version ) . "', userId: " . $user_id . " };</script>";
			$content         = str_replace( '</head>', $wp_api_settings . "\n</head>", $content );

			echo $content;
			exit;
		} else {
			header( 'Content-Type: text/html; charset=utf-8' );
			echo '<div style="font-family: monospace; background: #0e0e11; color: #c5a059; padding: 40px; border-radius: 8px; max-width: 600px; margin: 50px auto; border: 1px solid rgba(197,160,89,0.4); text-align: center;">';
			echo '<h2 style="margin-top: 0; color: #f5e4bc;">Book of Thoth Reader - Build Required</h2>';
			echo '<p style="color: #a8a29e; font-size: 14px;">The production build bundle is not present in <code>public/dist/index.html</code>.</p>';
			echo '<p style="background: #050505; padding: 12px; border-radius: 4px; color: #62c9ff;">pnpm --filter thoth-reader build</p>';
			echo '</div>';
			exit;
		}
	}

	public function render_shortcode( $atts = array() ) {
		$atts = shortcode_atts( array(
			'height' => '800px',
			'width'  => '100%',
		), $atts, 'xophz_thoth_reader' );

		$custom_slug = get_option( 'xophz_thoth_reader_custom_slug', 'thoth-reader' );
		$app_url     = home_url( '/' . ltrim( $custom_slug, '/' ) );

		ob_start();
		?>
		<div class="xophz-thoth-reader-embed-container" style="width: <?php echo esc_attr( $atts['width'] ); ?>; height: <?php echo esc_attr( $atts['height'] ); ?>; border: 1px solid rgba(197,160,89,0.3); border-radius: 8px; overflow: hidden; background: #08080c;">
			<iframe
				src="<?php echo esc_url( $app_url ); ?>"
				style="width: 100%; height: 100%; border: none;"
				title="Xophz Thoth Reader"
				loading="lazy"
			></iframe>
		</div>
		<?php
		return ob_get_clean();
	}
}
