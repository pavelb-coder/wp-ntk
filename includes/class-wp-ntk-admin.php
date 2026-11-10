<?php
/**
 * Консоль WordPress: данные наставника в профиле и в списке пользователей.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_NTK_Admin {

	/**
	 * Подключение к WordPress.
	 */
	public function hooks() {
		add_action( 'show_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_fields' ) );
		add_filter( 'manage_users_columns', array( $this, 'add_columns' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_column' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'activation_notice' ) );
	}

	/**
	 * Блок «Данные наставника проекта» в профиле пользователя.
	 *
	 * Показывается у наставников и у тех, у кого анкета уже заполнена.
	 *
	 * @param WP_User $user Пользователь, чей профиль открыт.
	 */
	public function render_profile_fields( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$values = WP_NTK_Fields::get_values( $user->ID );
		if ( ! WP_NTK_Setup::is_mentor( $user ) && '' === implode( '', $values ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Данные наставника проекта', 'wp-ntk' ); ?></h2>
		<input type="hidden" name="wp_ntk_admin_fields" value="1">
		<table class="form-table" role="presentation">
			<?php foreach ( WP_NTK_Fields::all() as $key => $field ) : ?>
				<?php $name = WP_NTK_Fields::PREFIX . $key; ?>
				<tr>
					<th><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
					<td>
						<?php if ( 'textarea' === $field['type'] ) : ?>
							<textarea id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="<?php echo (int) $field['rows']; ?>" class="large-text" maxlength="<?php echo (int) $field['max']; ?>"><?php echo esc_textarea( $values[ $key ] ); ?></textarea>
						<?php elseif ( 'select' === $field['type'] ) : ?>
							<select id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>"><option value="">—</option><?php if ( '' !== $values[ $key ] && ! in_array( $values[ $key ], $field['options'], true ) ) : ?><option value="<?php echo esc_attr( $values[ $key ] ); ?>" selected><?php echo esc_html( $values[ $key ] ); ?> (прежнее значение)</option><?php endif; ?><?php foreach ( $field['options'] as $option ) : ?><option value="<?php echo esc_attr( $option ); ?>" <?php selected( $values[ $key ], $option ); ?>><?php echo esc_html( $option ); ?></option><?php endforeach; ?></select>
						<?php elseif ( 'checkbox' === $field['type'] ) : ?>
							<input type="checkbox" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $values[ $key ], '1' ); ?>>
						<?php else : ?>
							<input type="<?php echo esc_attr( $field['type'] ); ?>" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $values[ $key ] ); ?>" class="regular-text" maxlength="<?php echo (int) $field['max']; ?>">
						<?php endif; ?>
						<?php if ( ! empty( $field['hint'] ) ) : ?>
							<p class="description"><?php echo esc_html( $field['hint'] ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * Сохранение блока «Данные наставника проекта».
	 *
	 * Защитный код формы профиля WordPress проверяет сам до вызова этого метода.
	 *
	 * @param int $user_id ID пользователя.
	 */
	public function save_profile_fields( $user_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce формы профиля проверяет WordPress.
		if ( ! isset( $_POST['wp_ntk_admin_fields'] ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$values = WP_NTK_Fields::from_request();
		foreach ( WP_NTK_Fields::all() as $key => $field ) {
			if ( WP_NTK_Fields::length( $values[ $key ] ) > $field['max'] ) {
				$values[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $values[ $key ], 0, $field['max'], 'UTF-8' ) : substr( $values[ $key ], 0, $field['max'] );
			}
		}

		WP_NTK_Fields::save( $user_id, $values );
		if ( WP_NTK_Setup::is_mentor( get_userdata( $user_id ) ) ) { do_action( 'wp_ntk_mentor_profile_updated', $user_id ); }
	}

	/**
	 * Колонки с данными наставника в списке пользователей.
	 *
	 * @param array<string,string> $columns Колонки.
	 * @return array<string,string>
	 */
	public function add_columns( $columns ) {
		$columns['wp_ntk_fio']          = __( 'ФИО наставника', 'wp-ntk' );
		$columns['wp_ntk_organization'] = __( 'Образовательная организация', 'wp-ntk' );
		return $columns;
	}

	/**
	 * Содержимое колонок с данными наставника.
	 *
	 * @param string $output  Содержимое колонки.
	 * @param string $column  Имя колонки.
	 * @param int    $user_id ID пользователя.
	 * @return string
	 */
	public function render_column( $output, $column, $user_id ) {
		if ( 'wp_ntk_fio' === $column ) {
			return esc_html( get_user_meta( $user_id, WP_NTK_Fields::meta_key( 'fio' ), true ) );
		}
		if ( 'wp_ntk_organization' === $column ) {
			return nl2br( esc_html( get_user_meta( $user_id, WP_NTK_Fields::meta_key( 'organization' ), true ) ) );
		}
		return $output;
	}

	/**
	 * Сообщение после активации: где находится личный кабинет.
	 */
	public function activation_notice() {
		if ( ! get_transient( WP_NTK_Setup::NOTICE_TRANSIENT ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		delete_transient( WP_NTK_Setup::NOTICE_TRANSIENT );

		$page_id = WP_NTK_Setup::account_page_id();
		if ( ! $page_id ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s <a href="%s">%s</a>. %s <code>[%s]</code></p></div>',
			esc_html__( 'WP NTK: личный кабинет с формой регистрации размещён на странице', 'wp-ntk' ),
			esc_url( get_permalink( $page_id ) ),
			esc_html( get_the_title( $page_id ) ),
			esc_html__( 'Шорткод для вставки на другую страницу:', 'wp-ntk' ),
			esc_html( WP_NTK_Setup::SHORTCODE )
		);
	}
}
