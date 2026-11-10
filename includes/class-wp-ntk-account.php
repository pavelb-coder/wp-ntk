<?php
/**
 * Личный кабинет на сайте: регистрация, вход, анкета наставника, смена пароля.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_NTK_Account {

	/** HTML-id блока кабинета: к нему страница прокручивается после входа и регистрации. */
	const ANCHOR = 'wp-ntk-account';

	/**
	 * Ошибки по формам: «форма => ( ключ поля => сообщение )».
	 * Ключ «_form» — ошибка, относящаяся к форме целиком.
	 *
	 * @var array<string,array<string,string>>
	 */
	private $errors = array();

	/**
	 * Значения, введённые в форму, для повторного вывода после ошибки.
	 * Пароли сюда не попадают.
	 *
	 * @var array<string,array<string,string>>
	 */
	private $values = array();

	/**
	 * Подключение к WordPress.
	 */
	public function hooks() {
		add_shortcode( WP_NTK_Setup::SHORTCODE, array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'handle_request' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_filter( 'show_admin_bar', array( $this, 'filter_admin_bar' ) );
		add_action( 'admin_init', array( $this, 'keep_mentors_out_of_admin' ) );
	}

	/* ---------------------------------------------------------------------
	 * Обработка отправленных форм
	 * ------------------------------------------------------------------- */

	/**
	 * Разбирает отправленную форму до вывода страницы.
	 *
	 * При успехе перенаправляет на страницу кабинета, при ошибке запоминает
	 * сообщения и введённые значения — их покажет шорткод.
	 */
	public function handle_request() {
		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверяется в обработчике каждой формы.
		$action = $is_post && isset( $_POST['wp_ntk_action'] ) && is_string( $_POST['wp_ntk_action'] ) ? sanitize_key( wp_unslash( $_POST['wp_ntk_action'] ) ) : '';

		if ( '' === $action ) {
			if ( $this->is_account_page() ) {
				$this->no_cache();
			}
			return;
		}

		$this->no_cache();

		switch ( $action ) {
			case 'register':
				$this->handle_register();
				break;
			case 'login':
				$this->handle_login();
				break;
			case 'profile':
				$this->handle_profile();
				break;
			case 'password':
				$this->handle_password();
				break;
		}
	}

	/**
	 * Регистрация наставника.
	 */
	private function handle_register() {
		if ( is_user_logged_in() ) {
			$this->redirect();
		}

		$values   = WP_NTK_Fields::from_request();
		$email    = $this->post_text( 'wp_ntk_email' );
		$password = trim( $this->post_password( 'wp_ntk_password' ) );

		$this->values['register'] = array_merge( $values, array( 'email' => $email ) );

		if ( ! $this->verify_nonce( 'register' ) ) {
			$this->errors['register'] = array( '_form' => $this->expired_message() );
			return;
		}

		// Поле-ловушка: человек его не видит и не заполняет, а спам-бот заполняет.
		if ( '' !== $this->post_text( 'wp_ntk_website' ) ) {
			$this->errors['register'] = array( '_form' => __( 'Не удалось отправить форму. Попробуйте ещё раз.', 'wp-ntk' ) );
			return;
		}

		$errors = WP_NTK_Fields::validate( $values );

		if ( '' === $email ) {
			$errors['email'] = __( 'Заполните это поле.', 'wp-ntk' );
		} elseif ( ! is_email( $email ) || strlen( $email ) > WP_NTK_Fields::EMAIL_MAX ) {
			$errors['email'] = __( 'Укажите корректный адрес электронной почты.', 'wp-ntk' );
		} elseif ( email_exists( $email ) ) {
			$errors['email'] = __( 'Аккаунт с такой электронной почтой уже есть. Войдите или восстановите пароль.', 'wp-ntk' );
		}

		$password_error = WP_NTK_Fields::password_error( $password );
		if ( '' !== $password_error ) {
			$errors['password'] = $password_error;
		}

		if ( $errors ) {
			$this->errors['register'] = $errors;
			return;
		}

		WP_NTK_Setup::ensure_role();

		// wp_insert_user() ждёт данные с экранирующими слэшами. Пароль передаётся
		// в том виде, в каком пришёл: так же его сверяет WordPress при входе.
		$user_id = wp_insert_user(
			array(
				'user_login'   => $this->generate_login(),
				'user_email'   => wp_slash( $email ),
				'user_pass'    => $password,
				'display_name' => wp_slash( $values['fio'] ),
				'role'         => WP_NTK_Setup::ROLE,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			$this->errors['register'] = array( '_form' => wp_strip_all_tags( $user_id->get_error_message() ) );
			return;
		}

		WP_NTK_Fields::save( $user_id, $values );

		/**
		 * Наставник зарегистрировался.
		 *
		 * @param int                  $user_id ID нового пользователя.
		 * @param array<string,string> $values  Данные анкеты.
		 */
		do_action( 'wp_ntk_mentor_registered', $user_id, $values );

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id );

		$this->redirect( array( 'wp_ntk_notice' => 'registered' ) );
	}

	/**
	 * Вход в кабинет.
	 */
	private function handle_login() {
		if ( is_user_logged_in() ) {
			$this->redirect();
		}

		$email    = $this->post_text( 'wp_ntk_login_email' );
		$password = $this->post_password( 'wp_ntk_login_password' );

		$this->values['login'] = array( 'login_email' => $email );

		if ( ! $this->verify_nonce( 'login' ) ) {
			$this->errors['login'] = array( '_form' => $this->expired_message() );
			return;
		}

		if ( '' === $email || '' === $password ) {
			$this->errors['login'] = array( '_form' => __( 'Введите электронную почту и пароль.', 'wp-ntk' ) );
			return;
		}

		// Наставник входит по адресу почты: свой логин WordPress ему знать не нужно.
		$user  = is_email( $email ) ? get_user_by( 'email', $email ) : false;
		$login = $user ? $user->user_login : $email;

		$result = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $password,
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверен выше.
				'remember'      => ! empty( $_POST['wp_ntk_remember'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->errors['login'] = array( '_form' => $this->login_error_message( $result ) );
			return;
		}

		$this->redirect();
	}

	/**
	 * Сохранение анкеты в кабинете.
	 */
	private function handle_profile() {
		if ( ! is_user_logged_in() ) {
			$this->redirect();
		}

		$values                  = WP_NTK_Fields::from_request();
		$this->values['profile'] = $values;

		if ( ! $this->verify_nonce( 'profile' ) ) {
			$this->errors['profile'] = array( '_form' => $this->expired_message() );
			return;
		}

		$errors = WP_NTK_Fields::validate( $values );
		if ( $errors ) {
			$this->errors['profile'] = $errors;
			return;
		}

		$user = wp_get_current_user();
		WP_NTK_Fields::save( $user->ID, $values );

		// У наставника отображаемое имя в WordPress совпадает с ФИО.
		if ( WP_NTK_Setup::is_mentor( $user ) && $user->display_name !== $values['fio'] ) {
			wp_update_user(
				array(
					'ID'           => $user->ID,
					'display_name' => wp_slash( $values['fio'] ),
				)
			);
		}

		$this->redirect( array( 'wp_ntk_notice' => 'profile_updated' ), 'wp-ntk-profile' );
	}

	/**
	 * Смена пароля в кабинете.
	 */
	private function handle_password() {
		if ( ! is_user_logged_in() ) {
			$this->redirect();
		}

		$current = trim( $this->post_password( 'wp_ntk_current_password' ) );
		$new     = trim( $this->post_password( 'wp_ntk_new_password' ) );

		if ( ! $this->verify_nonce( 'password' ) ) {
			$this->errors['password'] = array( '_form' => $this->expired_message() );
			return;
		}

		$user   = wp_get_current_user();
		$errors = array();

		if ( '' === $current ) {
			$errors['current_password'] = __( 'Заполните это поле.', 'wp-ntk' );
		} elseif ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			$errors['current_password'] = __( 'Текущий пароль указан неверно.', 'wp-ntk' );
		}

		$new_error = WP_NTK_Fields::password_error( $new );
		if ( '' !== $new_error ) {
			$errors['new_password'] = $new_error;
		} elseif ( ! $errors && $new === $current ) {
			$errors['new_password'] = __( 'Новый пароль совпадает с текущим.', 'wp-ntk' );
		}

		if ( $errors ) {
			$this->errors['password'] = $errors;
			return;
		}

		// wp_update_user() сам обновляет cookie входа, поэтому пользователь остаётся в кабинете.
		$result = wp_update_user(
			array(
				'ID'        => $user->ID,
				'user_pass' => $new,
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->errors['password'] = array( '_form' => wp_strip_all_tags( $result->get_error_message() ) );
			return;
		}

		$this->redirect( array( 'wp_ntk_notice' => 'password_changed' ), 'wp-ntk-password' );
	}

	/* ---------------------------------------------------------------------
	 * Вывод
	 * ------------------------------------------------------------------- */

	/**
	 * Шорткод [wp_ntk_account].
	 *
	 * Гостю показывает вход или регистрацию, вошедшему пользователю — кабинет.
	 *
	 * @return string
	 */
	public function render() {
		wp_enqueue_style( 'wp-ntk' );
		wp_enqueue_script( 'wp-ntk' );

		// Если запомненной страницы кабинета больше нет, запоминаем текущую.
		if ( is_page() && ! WP_NTK_Setup::account_page_id() ) {
			update_option( WP_NTK_Setup::PAGE_OPTION, get_queried_object_id() );
		}

		ob_start();
		echo '<div class="wp-ntk" id="' . esc_attr( self::ANCHOR ) . '">';
		$this->render_notice( array( 'registered', 'logged_out' ) );

		if ( is_user_logged_in() ) {
			$this->render_cabinet();
		} elseif ( 'register' === $this->guest_view() ) {
			$this->render_register();
		} else {
			$this->render_login();
		}

		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Что показать гостю: вход или регистрацию.
	 *
	 * @return string
	 */
	private function guest_view() {
		if ( isset( $this->values['register'] ) ) {
			return 'register';
		}
		if ( isset( $this->values['login'] ) ) {
			return 'login';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- параметр только переключает вид.
		$view = isset( $_GET['wp_ntk_view'] ) && is_string( $_GET['wp_ntk_view'] ) ? sanitize_key( wp_unslash( $_GET['wp_ntk_view'] ) ) : '';
		return 'register' === $view ? 'register' : 'login';
	}

	/**
	 * Сообщение об успешном действии после перенаправления.
	 *
	 * Выводится рядом с тем, к чему относится: о сохранении анкеты — в форме
	 * анкеты, о смене пароля — в форме пароля, остальные — в начале блока.
	 *
	 * @param string[] $codes Коды сообщений, уместных в этом месте страницы.
	 */
	private function render_notice( $codes ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- параметр только выбирает готовый текст.
		$code = isset( $_GET['wp_ntk_notice'] ) && is_string( $_GET['wp_ntk_notice'] ) ? sanitize_key( wp_unslash( $_GET['wp_ntk_notice'] ) ) : '';
		if ( ! in_array( $code, $codes, true ) ) {
			return;
		}

		$messages = array(
			'registered'       => __( 'Регистрация завершена. Добро пожаловать в личный кабинет!', 'wp-ntk' ),
			'profile_updated'  => __( 'Данные сохранены.', 'wp-ntk' ),
			'password_changed' => __( 'Пароль изменён.', 'wp-ntk' ),
			'logged_out'       => __( 'Вы вышли из личного кабинета.', 'wp-ntk' ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}
		// «Вы вышли» уместно только для гостя, остальные сообщения — только для вошедшего.
		if ( ( 'logged_out' === $code ) === is_user_logged_in() ) {
			return;
		}

		printf( '<div class="wp-ntk-notice wp-ntk-notice--success" role="status">%s</div>', esc_html( $messages[ $code ] ) );
	}

	/**
	 * Форма регистрации.
	 */
	private function render_register() {
		$values = isset( $this->values['register'] ) ? $this->values['register'] : array();
		$action = add_query_arg( 'wp_ntk_view', 'register', $this->page_url() ) . '#wp-ntk-register';
		?>
		<h2 class="wp-ntk-title"><?php esc_html_e( 'Регистрация наставника проекта', 'wp-ntk' ); ?></h2>
		<form class="wp-ntk-form" id="wp-ntk-register" method="post" action="<?php echo esc_url( $action ); ?>">
			<?php $this->render_form_errors( 'register' ); ?>

			<fieldset class="wp-ntk-fieldset">
				<legend class="wp-ntk-legend"><?php esc_html_e( 'Сведения о наставнике и организации', 'wp-ntk' ); ?></legend>
				<?php
				foreach ( WP_NTK_Fields::all() as $key => $field ) {
					$this->render_field( 'register', $key, $field, isset( $values[ $key ] ) ? $values[ $key ] : '' );
				}
				?>
			</fieldset>

			<fieldset class="wp-ntk-fieldset">
				<legend class="wp-ntk-legend"><?php esc_html_e( 'Данные для входа в личный кабинет', 'wp-ntk' ); ?></legend>
				<?php
				$this->render_field(
					'register',
					'email',
					array(
						'label'        => __( 'Email наставника для регистрации аккаунта', 'wp-ntk' ),
						'type'         => 'email',
						'max'          => WP_NTK_Fields::EMAIL_MAX,
						'autocomplete' => 'email',
					),
					isset( $values['email'] ) ? $values['email'] : ''
				);
				$this->render_field(
					'register',
					'password',
					array(
						'label'        => __( 'Пароль', 'wp-ntk' ),
						/* translators: %d: минимальное число символов. */
						'hint'         => sprintf( __( 'Не короче %d символов.', 'wp-ntk' ), WP_NTK_Fields::PASSWORD_MIN ),
						'type'         => 'password',
						'min'          => WP_NTK_Fields::PASSWORD_MIN,
						'autocomplete' => 'new-password',
					)
				);
				?>
			</fieldset>

			<div class="wp-ntk-hp" aria-hidden="true">
				<label for="wp-ntk-register-website"><?php esc_html_e( 'Не заполняйте это поле', 'wp-ntk' ); ?></label>
				<input type="text" id="wp-ntk-register-website" name="wp_ntk_website" value="" tabindex="-1" autocomplete="off">
			</div>

			<?php $this->render_hidden( 'register' ); ?>
			<div class="wp-ntk-actions">
				<button type="submit" class="wp-ntk-button"><?php esc_html_e( 'Зарегистрироваться', 'wp-ntk' ); ?></button>
			</div>
		</form>
		<p class="wp-ntk-switch">
			<?php esc_html_e( 'Уже зарегистрированы?', 'wp-ntk' ); ?>
			<a href="<?php echo esc_url( $this->page_url() ); ?>"><?php esc_html_e( 'Войти', 'wp-ntk' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Форма входа.
	 */
	private function render_login() {
		$values = isset( $this->values['login'] ) ? $this->values['login'] : array();
		?>
		<h2 class="wp-ntk-title"><?php esc_html_e( 'Вход в личный кабинет', 'wp-ntk' ); ?></h2>
		<form class="wp-ntk-form wp-ntk-form--narrow" id="wp-ntk-login" method="post" action="<?php echo esc_url( $this->page_url() . '#wp-ntk-login' ); ?>">
			<?php
			$this->render_form_errors( 'login' );
			$this->render_field(
				'login',
				'login_email',
				array(
					'label'        => __( 'Электронная почта', 'wp-ntk' ),
					'type'         => 'email',
					'max'          => WP_NTK_Fields::EMAIL_MAX,
					'autocomplete' => 'username',
				),
				isset( $values['login_email'] ) ? $values['login_email'] : ''
			);
			$this->render_field(
				'login',
				'login_password',
				array(
					'label'        => __( 'Пароль', 'wp-ntk' ),
					'type'         => 'password',
					'autocomplete' => 'current-password',
				)
			);
			?>
			<p class="wp-ntk-check">
				<label><input type="checkbox" name="wp_ntk_remember" value="1"> <?php esc_html_e( 'Запомнить меня', 'wp-ntk' ); ?></label>
			</p>
			<?php $this->render_hidden( 'login' ); ?>
			<div class="wp-ntk-actions">
				<button type="submit" class="wp-ntk-button"><?php esc_html_e( 'Войти', 'wp-ntk' ); ?></button>
			</div>
		</form>
		<p class="wp-ntk-switch">
			<?php esc_html_e( 'Ещё нет аккаунта?', 'wp-ntk' ); ?>
			<a href="<?php echo esc_url( add_query_arg( 'wp_ntk_view', 'register', $this->page_url() ) ); ?>"><?php esc_html_e( 'Зарегистрироваться', 'wp-ntk' ); ?></a>
		</p>
		<p class="wp-ntk-switch">
			<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Забыли пароль?', 'wp-ntk' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Кабинет вошедшего пользователя.
	 */
	private function render_cabinet() {
		$user   = wp_get_current_user();
		$saved  = WP_NTK_Fields::get_values( $user->ID );
		$values = isset( $this->values['profile'] ) ? $this->values['profile'] : $saved;
		$name   = '' !== $saved['fio'] ? $saved['fio'] : $user->display_name;
		$logout = wp_logout_url( add_query_arg( 'wp_ntk_notice', 'logged_out', $this->page_url() ) . '#' . self::ANCHOR );
		?>
		<div class="wp-ntk-head">
			<p class="wp-ntk-user">
				<strong class="wp-ntk-user-name"><?php echo esc_html( $name ); ?></strong>
				<span class="wp-ntk-user-email"><?php echo esc_html( $user->user_email ); ?></span>
			</p>
			<a class="wp-ntk-button wp-ntk-button--secondary" href="<?php echo esc_url( $logout ); ?>"><?php esc_html_e( 'Выйти', 'wp-ntk' ); ?></a>
		</div>

		<h2 class="wp-ntk-title"><?php esc_html_e( 'Мои данные', 'wp-ntk' ); ?></h2>
		<form class="wp-ntk-form" id="wp-ntk-profile" method="post" action="<?php echo esc_url( $this->page_url() . '#wp-ntk-profile' ); ?>">
			<?php
			$this->render_form_errors( 'profile' );
			$this->render_notice( array( 'profile_updated' ) );
			foreach ( WP_NTK_Fields::all() as $key => $field ) {
				$this->render_field( 'profile', $key, $field, isset( $values[ $key ] ) ? $values[ $key ] : '' );
			}
			$this->render_hidden( 'profile' );
			?>
			<div class="wp-ntk-actions">
				<button type="submit" class="wp-ntk-button"><?php esc_html_e( 'Сохранить изменения', 'wp-ntk' ); ?></button>
			</div>
		</form>

		<h2 class="wp-ntk-title"><?php esc_html_e( 'Смена пароля', 'wp-ntk' ); ?></h2>
		<form class="wp-ntk-form wp-ntk-form--narrow" id="wp-ntk-password" method="post" action="<?php echo esc_url( $this->page_url() . '#wp-ntk-password' ); ?>">
			<?php
			$this->render_form_errors( 'password' );
			$this->render_notice( array( 'password_changed' ) );
			$this->render_field(
				'password',
				'current_password',
				array(
					'label'        => __( 'Текущий пароль', 'wp-ntk' ),
					'type'         => 'password',
					'autocomplete' => 'current-password',
				)
			);
			$this->render_field(
				'password',
				'new_password',
				array(
					'label'        => __( 'Новый пароль', 'wp-ntk' ),
					/* translators: %d: минимальное число символов. */
					'hint'         => sprintf( __( 'Не короче %d символов.', 'wp-ntk' ), WP_NTK_Fields::PASSWORD_MIN ),
					'type'         => 'password',
					'min'          => WP_NTK_Fields::PASSWORD_MIN,
					'autocomplete' => 'new-password',
				)
			);
			$this->render_hidden( 'password' );
			?>
			<div class="wp-ntk-actions">
				<button type="submit" class="wp-ntk-button"><?php esc_html_e( 'Изменить пароль', 'wp-ntk' ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * Одно поле формы с подписью, подсказкой и сообщением об ошибке.
	 *
	 * @param string              $form  Форма: register, login, profile, password.
	 * @param string              $key   Ключ поля; имя в запросе — wp_ntk_{ключ}.
	 * @param array<string,mixed> $field Описание: label, type, hint, max, min, rows, autocomplete.
	 * @param string              $value Текущее значение. Для паролей не используется.
	 */
	private function render_field( $form, $key, $field, $value = '' ) {
		$type  = isset( $field['type'] ) ? $field['type'] : 'text';
		$id    = 'wp-ntk-' . $form . '-' . str_replace( '_', '-', $key );
		$error = isset( $this->errors[ $form ][ $key ] ) ? $this->errors[ $form ][ $key ] : '';

		$described_by = array();
		if ( ! empty( $field['hint'] ) ) {
			$described_by[] = $id . '-hint';
		}
		if ( '' !== $error ) {
			$described_by[] = $id . '-error';
		}

		$attributes = array(
			'id'           => $id,
			'name'         => WP_NTK_Fields::PREFIX . $key,
			'class'        => 'wp-ntk-input',
			'autocomplete' => isset( $field['autocomplete'] ) ? $field['autocomplete'] : 'off',
		);
		if ( ! empty( $field['max'] ) ) {
			$attributes['maxlength'] = (string) $field['max'];
		}
		if ( ! empty( $field['min'] ) ) {
			$attributes['minlength'] = (string) $field['min'];
		}
		if ( $described_by ) {
			$attributes['aria-describedby'] = implode( ' ', $described_by );
		}
		if ( '' !== $error ) {
			$attributes['aria-invalid'] = 'true';
		}

		$html = '';
		foreach ( $attributes as $attribute => $attribute_value ) {
			$html .= sprintf( ' %s="%s"', $attribute, esc_attr( $attribute_value ) );
		}
		$html .= ' required';
		?>
		<div class="wp-ntk-field<?php echo '' !== $error ? ' wp-ntk-field--error' : ''; ?>">
			<label class="wp-ntk-label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?> <span class="wp-ntk-required" aria-hidden="true">*</span></label>
			<?php if ( ! empty( $field['hint'] ) ) : ?>
				<p class="wp-ntk-hint" id="<?php echo esc_attr( $id . '-hint' ); ?>"><?php echo esc_html( $field['hint'] ); ?></p>
			<?php endif; ?>
			<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $html собран выше из экранированных значений. ?>
			<?php if ( 'textarea' === $type ) : ?>
				<textarea<?php echo $html; ?> rows="<?php echo (int) ( isset( $field['rows'] ) ? $field['rows'] : 3 ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
			<?php elseif ( 'password' === $type ) : ?>
				<div class="wp-ntk-password">
					<input type="password"<?php echo $html; ?>>
					<button type="button" class="wp-ntk-toggle" aria-controls="<?php echo esc_attr( $id ); ?>" aria-pressed="false" data-show="<?php esc_attr_e( 'Показать', 'wp-ntk' ); ?>" data-hide="<?php esc_attr_e( 'Скрыть', 'wp-ntk' ); ?>" hidden><?php esc_html_e( 'Показать', 'wp-ntk' ); ?></button>
				</div>
			<?php else : ?>
				<input type="<?php echo esc_attr( $type ); ?>"<?php echo $html; ?> value="<?php echo esc_attr( $value ); ?>">
			<?php endif; ?>
			<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( '' !== $error ) : ?>
				<p class="wp-ntk-error" id="<?php echo esc_attr( $id . '-error' ); ?>"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Общее сообщение об ошибке над формой.
	 *
	 * @param string $form Форма.
	 */
	private function render_form_errors( $form ) {
		if ( empty( $this->errors[ $form ] ) ) {
			return;
		}
		$message = isset( $this->errors[ $form ]['_form'] )
			? $this->errors[ $form ]['_form']
			: __( 'Форма не отправлена. Исправьте поля, отмеченные ниже.', 'wp-ntk' );

		printf( '<div class="wp-ntk-notice wp-ntk-notice--error" role="alert">%s</div>', esc_html( $message ) );
	}

	/**
	 * Скрытые поля формы: действие и защитный код.
	 *
	 * @param string $action Действие.
	 */
	private function render_hidden( $action ) {
		printf( '<input type="hidden" name="wp_ntk_action" value="%s">', esc_attr( $action ) );
		printf( '<input type="hidden" name="wp_ntk_nonce" value="%s">', esc_attr( wp_create_nonce( 'wp_ntk_' . $action ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Стили, скрипт, панель администратора
	 * ------------------------------------------------------------------- */

	/**
	 * Регистрирует стили и скрипт; на странице кабинета стили подключаются сразу.
	 */
	public function register_assets() {
		wp_register_style( 'wp-ntk', WP_NTK_URL . 'assets/css/wp-ntk.css', array(), WP_NTK_VERSION );
		wp_register_script( 'wp-ntk', WP_NTK_URL . 'assets/js/wp-ntk.js', array(), WP_NTK_VERSION, true );

		if ( $this->is_account_page() ) {
			wp_enqueue_style( 'wp-ntk' );
		}
	}

	/**
	 * Наставнику верхняя панель WordPress не показывается.
	 *
	 * @param bool $show Показывать ли панель.
	 * @return bool
	 */
	public function filter_admin_bar( $show ) {
		return $this->is_plain_mentor( wp_get_current_user() ) ? false : $show;
	}

	/**
	 * Наставник, открывший консоль WordPress, попадает в личный кабинет.
	 */
	public function keep_mentors_out_of_admin() {
		global $pagenow;

		if ( wp_doing_ajax() || 'admin-post.php' === $pagenow ) {
			return;
		}
		if ( ! $this->is_plain_mentor( wp_get_current_user() ) ) {
			return;
		}

		wp_safe_redirect( WP_NTK_Setup::account_url() );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Вспомогательное
	 * ------------------------------------------------------------------- */

	/**
	 * Наставник без дополнительных прав на сайте.
	 *
	 * @param WP_User $user Пользователь.
	 * @return bool
	 */
	private function is_plain_mentor( $user ) {
		return WP_NTK_Setup::is_mentor( $user ) && ! $user->has_cap( 'edit_posts' );
	}

	/**
	 * Открыта ли страница или запись с шорткодом кабинета.
	 *
	 * @return bool
	 */
	private function is_account_page() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		return $post instanceof WP_Post && has_shortcode( $post->post_content, WP_NTK_Setup::SHORTCODE );
	}

	/**
	 * Адрес текущей страницы с кабинетом.
	 *
	 * @return string
	 */
	private function page_url() {
		if ( is_singular() ) {
			$url = get_permalink( get_queried_object_id() );
			if ( $url ) {
				return $url;
			}
		}
		return WP_NTK_Setup::account_url();
	}

	/**
	 * Перенаправление на страницу кабинета.
	 *
	 * Якорь указывается всегда: иначе браузер оставит якорь отправленной формы
	 * и прокрутит страницу мимо сообщения о результате.
	 *
	 * @param array<string,string> $args   Параметры запроса.
	 * @param string               $anchor HTML-id, к которому прокрутить страницу.
	 */
	private function redirect( $args = array(), $anchor = self::ANCHOR ) {
		$url = $this->page_url();
		if ( $args ) {
			$url = add_query_arg( $args, $url );
		}
		wp_safe_redirect( $url . '#' . $anchor );
		exit;
	}

	/**
	 * Страницы кабинета нельзя кешировать: на них личные данные и защитные коды форм.
	 */
	private function no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
	}

	/**
	 * Текстовое значение из отправленной формы.
	 *
	 * @param string $name Имя поля.
	 * @return string
	 */
	private function post_text( $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверяется в обработчике формы.
		return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
	}

	/**
	 * Пароль из отправленной формы.
	 *
	 * Возвращается как есть, без очистки и без снятия слэшей: именно в таком
	 * виде WordPress хеширует пароли и сверяет их при входе.
	 *
	 * @param string $name Имя поля.
	 * @return string
	 */
	private function post_password( $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? $_POST[ $name ] : '';
	}

	/**
	 * Проверка защитного кода формы.
	 *
	 * @param string $action Действие.
	 * @return bool
	 */
	private function verify_nonce( $action ) {
		return (bool) wp_verify_nonce( $this->post_text( 'wp_ntk_nonce' ), 'wp_ntk_' . $action );
	}

	/**
	 * Сообщение об устаревшей форме.
	 *
	 * @return string
	 */
	private function expired_message() {
		return __( 'Срок действия формы истёк. Обновите страницу и отправьте форму ещё раз.', 'wp-ntk' );
	}

	/**
	 * Сообщение об ошибке входа.
	 *
	 * Не уточняет, что именно неверно — почта или пароль, чтобы по ответу
	 * нельзя было проверить, зарегистрирован ли адрес.
	 *
	 * @param WP_Error $error Ошибка входа.
	 * @return string
	 */
	private function login_error_message( $error ) {
		$credentials = array( 'invalid_username', 'invalid_email', 'incorrect_password', 'empty_username', 'empty_password', 'invalidcombo' );
		if ( in_array( $error->get_error_code(), $credentials, true ) ) {
			return __( 'Неверная электронная почта или пароль.', 'wp-ntk' );
		}

		$message = trim( wp_strip_all_tags( $error->get_error_message() ) );
		return '' !== $message ? $message : __( 'Не удалось войти. Попробуйте ещё раз.', 'wp-ntk' );
	}

	/**
	 * Логин WordPress для нового наставника.
	 *
	 * Логин не строится из адреса почты: он виден в адресе страницы автора,
	 * и почта через него утекала бы наружу. Входит наставник по адресу почты.
	 *
	 * @return string
	 */
	private function generate_login() {
		do {
			$login = 'mentor_' . strtolower( wp_generate_password( 10, false ) );
		} while ( username_exists( $login ) );

		return $login;
	}
}
