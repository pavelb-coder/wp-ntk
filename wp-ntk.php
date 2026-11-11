<?php
/**
 * Plugin Name:       WP NTK
 * Description:       Личный кабинет наставника проекта с формой регистрации.
 * Version:           3.0.0
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
define( 'WP_NTK_VERSION', '3.0.0' );

define( 'WP_NTK_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_NTK_URL', plugin_dir_url( __FILE__ ) );

require_once WP_NTK_DIR . 'includes/class-wp-ntk-fields.php';
require_once WP_NTK_DIR . 'includes/class-wp-ntk-setup.php';
require_once WP_NTK_DIR . 'includes/class-wp-ntk-protection.php';
require_once WP_NTK_DIR . 'includes/class-wp-ntk-recovery.php';
require_once WP_NTK_DIR . 'includes/class-wp-ntk-account.php';
require_once WP_NTK_DIR . 'includes/class-wp-ntk-admin.php';
require_once WP_NTK_DIR . 'includes/class-wp-ntk-panel.php';
require_once WP_NTK_DIR . 'includes/class-wp-ntk-field-editor.php';
require_once WP_NTK_DIR . 'includes/class-wp-ntk-network.php';
require_once WP_NTK_DIR . 'includes/class-wp-ntk-projects.php';
require_once WP_NTK_DIR . 'includes/class-wp-ntk-project-files.php';
require_once WP_NTK_DIR . 'includes/class-wp-ntk-project-export.php';
require_once WP_NTK_DIR . 'includes/class-wp-ntk-notifications.php';
require_once WP_NTK_DIR . 'includes/class-wp-ntk-appearance.php';

register_activation_hook( __FILE__, array( 'WP_NTK_Setup', 'activate' ) );

/**
 * Запуск плагина.
 */
function wp_ntk_init() {
	$appearance = new WP_NTK_Appearance();
	$appearance->hooks();
	$notifications = new WP_NTK_Notifications();
	$notifications->hooks();
	$project_files = new WP_NTK_Project_Files();
	$project_files->hooks();
	$projects = new WP_NTK_Projects();
	$projects->hooks();
	$network = new WP_NTK_Network();
	$network->hooks();
	$project_export = new WP_NTK_Project_Export( $network );
	$project_export->hooks();
	$recovery = new WP_NTK_Recovery();
	$recovery->hooks();
	$account = new WP_NTK_Account( $recovery, $projects );
	$account->hooks();

	if ( is_admin() ) {
		$admin = new WP_NTK_Admin();
		$admin->hooks();
		$panel = new WP_NTK_Panel();
		$panel->hooks();
		$fields = new WP_NTK_Field_Editor();
		$fields->hooks();
	}
}
add_action( 'plugins_loaded', 'wp_ntk_init' );
