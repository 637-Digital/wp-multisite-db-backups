<?php
/**
 * Plugin Name:       WP Multisite DB Backups
 * Plugin URI:        https://github.com/637-Digital/wp-multisite-db-backups
 * Description:       Automatically exports each site's database in a WordPress multisite network and uploads backups to Backblaze B2.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            637 Digital Solutions
 * Author URI:        https://www.637digital.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-multisite-db-backups
 * Domain Path:       /languages
 * Network:           true
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'MSB_VERSION', '1.0.0' );
define( 'MSB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MSB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load the main plugin functionality
require_once MSB_PLUGIN_DIR . 'wp-multisite-db-backup/multi-site-content-backup.php';

register_activation_hook( __FILE__, 'msb_activate' );
register_deactivation_hook( __FILE__, 'msb_deactivate' );
