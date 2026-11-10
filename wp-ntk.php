<?php
/**
 * Plugin Name: WP NTK
 * Version:     1.0.0
 * Text Domain: wp-ntk
 */

// Файл нельзя запускать напрямую, в обход WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Версия плагина в формате x.y.z. Должна совпадать с полем Version в заголовке
// и со Stable tag в readme.txt.
define( 'WP_NTK_VERSION', '1.0.0' );
