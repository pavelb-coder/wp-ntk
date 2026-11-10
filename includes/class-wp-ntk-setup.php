<?php
/**
 * Установка плагина: роль наставника и страница личного кабинета.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_NTK_Setup {

	/** Роль, которую получают зарегистрированные наставники. */
	const ROLE = 'wp_ntk_mentor';

	/** Шорткод личного кабинета. */
	const SHORTCODE = 'wp_ntk_account';

	/** Опция с ID страницы личного кабинета. */
	const PAGE_OPTION = 'wp_ntk_account_page_id';

	/** Метка «плагин только что активирован» для сообщения в админке. */
	const NOTICE_TRANSIENT = 'wp_ntk_activation_notice';

	/** Текущие условия согласия. */
	public static function consent_text() {
		return (string) get_option( 'wp_ntk_consent_text', 'Я согласен(на) на обработку персональных данных.' );
	}
	public static function consent_url() {
		return (string) get_option( 'wp_ntk_consent_url', '' );
	}
	public static function consent_revision() {
		return hash( 'sha256', self::consent_text() . "\n" . self::consent_url() );
	}

	/**
	 * Активация плагина.
	 */
	public static function activate() {
		self::ensure_role();
		self::ensure_account_page();
		set_transient( self::NOTICE_TRANSIENT, 1, HOUR_IN_SECONDS );
	}

	/**
	 * Создаёт роль наставника, если её ещё нет.
	 *
	 * Права минимальные: только чтение, как у подписчика.
	 */
	public static function ensure_role() {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, __( 'Наставник проекта', 'wp-ntk' ), array( 'read' => true ) );
		}
	}

	/**
	 * Является ли пользователь наставником.
	 *
	 * @param mixed $user Пользователь.
	 * @return bool
	 */
	public static function is_mentor( $user ) {
		return $user instanceof WP_User && $user->exists() && in_array( self::ROLE, (array) $user->roles, true );
	}

	/**
	 * ID опубликованной страницы личного кабинета.
	 *
	 * @return int 0, если страницы нет.
	 */
	public static function account_page_id() {
		$page_id = (int) get_option( self::PAGE_OPTION );
		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return $page_id;
		}
		return 0;
	}

	/**
	 * Адрес личного кабинета. Если страницы нет, возвращает адрес главной.
	 *
	 * @param array<string,string> $args Параметры запроса.
	 * @return string
	 */
	public static function account_url( $args = array() ) {
		$page_id = self::account_page_id();
		$url     = $page_id ? get_permalink( $page_id ) : home_url( '/' );
		if ( $args ) {
			$url = add_query_arg( $args, $url );
		}
		return $url;
	}

	/**
	 * Создаёт страницу личного кабинета, если её ещё нет.
	 *
	 * Повторная активация новую страницу не создаёт. Если шорткод уже размещён
	 * на какой-то странице вручную, используется она.
	 *
	 * @return int ID страницы или 0 при ошибке.
	 */
	public static function ensure_account_page() {
		global $wpdb;

		$page_id = (int) get_option( self::PAGE_OPTION );
		$status  = $page_id ? get_post_status( $page_id ) : false;
		if ( $status && 'trash' !== $status ) {
			return $page_id;
		}

		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1",
				'%' . $wpdb->esc_like( '[' . self::SHORTCODE ) . '%'
			)
		);
		if ( $existing ) {
			update_option( self::PAGE_OPTION, $existing );
			return $existing;
		}

		$page_id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => __( 'Личный кабинет', 'wp-ntk' ),
				'post_name'      => 'lichnyj-kabinet',
				'post_content'   => "<!-- wp:shortcode -->\n[" . self::SHORTCODE . "]\n<!-- /wp:shortcode -->",
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			return 0;
		}

		update_option( self::PAGE_OPTION, (int) $page_id );
		return (int) $page_id;
	}
}
