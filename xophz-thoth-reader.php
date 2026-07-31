<?php
/**
 * Plugin Name:       Xophz Thoth Reader
 * Description:       Standalone WordPress backend, router, and REST API for the Svelte 5 Thoth Reader web app.
 * Version:           26.7.30
 * Author:            Hall of the Gods, Inc.
 * Category:          Command Deck
 * Group:             Ecosystem
 * Text Domain:       xophz-thoth-reader
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'XOPHZ_THOTH_READER_VERSION', '26.7.30' );
define( 'XOPHZ_THOTH_READER_PATH', plugin_dir_path( __FILE__ ) );
define( 'XOPHZ_THOTH_READER_URL', plugin_dir_url( __FILE__ ) );

require_once XOPHZ_THOTH_READER_PATH . 'admin/class-xophz-thoth-reader-admin.php';
require_once XOPHZ_THOTH_READER_PATH . 'public/class-xophz-thoth-reader-public.php';
require_once XOPHZ_THOTH_READER_PATH . 'includes/class-thoth-reader-api.php';

function run_xophz_thoth_reader() {
	$admin = new Xophz_Thoth_Reader_Admin( 'xophz-thoth-reader', XOPHZ_THOTH_READER_VERSION );
	add_action( 'admin_menu', array( $admin, 'add_plugin_admin_menu' ) );
	add_action( 'admin_init', array( $admin, 'register_settings' ) );
	add_action( 'update_option_xophz_thoth_reader_custom_slug', array( $admin, 'flush_rewrites_on_save' ), 10, 2 );
	add_action( 'update_option_xophz_thoth_reader_load_mode', array( $admin, 'flush_rewrites_on_save' ), 10, 2 );

	$public = new Xophz_Thoth_Reader_Public( 'xophz-thoth-reader', XOPHZ_THOTH_READER_VERSION );
	add_action( 'init', array( $public, 'register_endpoints' ) );
	add_filter( 'query_vars', array( $public, 'register_query_vars' ) );
	add_action( 'template_redirect', array( $public, 'template_redirect' ) );
	add_shortcode( 'xophz_thoth_reader', array( $public, 'render_shortcode' ) );

	$api = new Thoth_Reader_API();
	add_action( 'rest_api_init', array( $api, 'register_routes' ) );
}
add_action( 'plugins_loaded', 'run_xophz_thoth_reader' );

function xophz_thoth_reader_activate() {
	$public = new Xophz_Thoth_Reader_Public( 'xophz-thoth-reader', XOPHZ_THOTH_READER_VERSION );
	$public->register_endpoints();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'xophz_thoth_reader_activate' );

function xophz_thoth_reader_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'xophz_thoth_reader_deactivate' );

function xophz_thoth_reader_action_links( $links ) {
	$settings_link = '<a href="options-general.php?page=xophz-thoth-reader">' . __( 'Settings', 'xophz-thoth-reader' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'xophz_thoth_reader_action_links' );
