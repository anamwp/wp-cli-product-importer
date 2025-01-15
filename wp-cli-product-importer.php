<?php
/**
 * Plugin Name:     WP CLI Product Importer
 * Plugin URI:      PLUGIN SITE HERE
 * Description:     PLUGIN DESCRIPTION HERE
 * Author:          Anam Hossain
 * Author URI:      https://anam.rocks
 * Text Domain:     wp-cli-product-importer
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         WP_CLI_Product_Importer
 */

// Your code starts here.
require plugin_dir_path( __FILE__ ) . 'cli/class-wp-cli-product-importer-manage-product.php';
new WP_CLI_Product_Importer_Manage_Product();
