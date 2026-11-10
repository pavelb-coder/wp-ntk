<?php
/**
 * Plugin Name:       WP NTK
 * Description:       Личный кабинет наставника проекта с формой регистрации.
 * Version:           1.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Text Domain:       wp-ntk
 */

// Файл нельзя запускать напрямую, в обход WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Версия плагина в формате x.y.z. Должна совпадать с полем Version в заголовке
// и со Stable tag в readme.txt.
define( 'WP_NTK_VERSION', '1.1.0' );

define( 'WP_NTK_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_NTK_URL', plugin_dir_url( __FILE__ ) );

require_once WP_NTK_DIR . 'includes/class-wp-ntk-fields.php';
require_once WP_NTK_DIR . 'includes/class-wp-ntk-setup.php';
require_once WP_NTK_DIR . 'includes/class-wp-ntk-account.php';
require_once WP_NTK_DIR . 'includes/class-wp-ntk-admin.php';

register_activation_hook( __FILE__, array( 'WP_NTK_Setup', 'activate' ) );

/**
 * Запуск плагина.
 */
function wp_ntk_init() {
	$account = new WP_NTK_Account();
	$account->hooks();

	if ( is_admin() ) {
		$admin = new WP_NTK_Admin();
		$admin->hooks();
	}
}
add_action( 'plugins_loaded', 'wp_ntk_init' );
