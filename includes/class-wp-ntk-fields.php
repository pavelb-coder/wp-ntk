<?php
/**
 * Поля анкеты наставника: описание, очистка, проверка и хранение.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_NTK_Fields {

	/** Префикс имён полей в формах и ключей в метаданных пользователя. */
	const PREFIX = 'wp_ntk_';

	/** Предельная длина адреса почты: столько вмещает поле user_email в WordPress. */
	const EMAIL_MAX = 100;

	/** Минимальная длина пароля. */
	const PASSWORD_MIN = 8;

	/** Предельная длина пароля: более длинные WordPress не принимает при входе. */
	const PASSWORD_MAX = 4096;

	/**
	 * Поля анкеты в порядке вывода.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function defaults() {
		return array(
			'fio'          => array(
				'label'        => __( 'ФИО наставника проекта', 'wp-ntk' ),
				'type'         => 'text',
				'max'          => 200,
				'autocomplete' => 'name',
			),
			'organization' => array(
				'label'        => __( 'Образовательная организация (полное название, адрес и номер телефона)', 'wp-ntk' ),
				'type'         => 'textarea',
				'rows'         => 4,
				'max'          => 1000,
				'autocomplete' => 'off',
			),
			'position'     => array(
				'label'        => __( 'Занимаемая должность наставника проекта в образовательной организации', 'wp-ntk' ),
				'type'         => 'text',
				'max'          => 300,
				'autocomplete' => 'organization-title',
			),
			'phones'       => array(
				'label'        => __( 'Номера мобильного телефона и рабочего телефона отдела образовательной организации', 'wp-ntk' ),
				'type'         => 'text',
				'max'          => 200,
				'autocomplete' => 'off',
			),
			'municipality' => array(
				'label'        => __( 'Муниципальное образование', 'wp-ntk' ),
				'hint'         => __( 'Укажите полное наименование муниципального образования с расшифровкой статуса (район, городской округ, сельское поселение) и указанием административного центра (город, посёлок, село и т.д.).', 'wp-ntk' ),
				'type'         => 'textarea',
				'rows'         => 3,
				'max'          => 500,
				'autocomplete' => 'off',
			),
			'org_email'    => array(
				'label'        => __( 'Электронная почта (организации)', 'wp-ntk' ),
				'type'         => 'email',
				'max'          => self::EMAIL_MAX,
				'autocomplete' => 'off',
			),
		);
	}

	/** Описания полей с настройками администратора. */
	public static function all() {
		$defaults = self::defaults();
		$settings = get_option( 'wp_ntk_field_settings', array() );
		if ( ! is_array( $settings ) ) { $settings = array(); }
		$fields = array();
		foreach ( $defaults as $key => $field ) {
			$config = isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ? $settings[ $key ] : array();
			$field['label'] = ! empty( $config['label'] ) ? $config['label'] : $field['label'];
			$field['required'] = isset( $config['required'] ) ? (bool) $config['required'] : true;
			$field['order'] = isset( $config['order'] ) ? (int) $config['order'] : ( count( $fields ) + 1 ) * 10;
			$fields[ $key ] = $field;
		}
		foreach ( $settings as $key => $config ) {
			if ( ! preg_match( '/^custom_[a-f0-9]{12}$/', $key ) || ! is_array( $config ) || empty( $config['label'] ) || ! in_array( $config['type'], array( 'text', 'textarea', 'select', 'checkbox' ), true ) ) { continue; }
			$fields[ $key ] = array( 'label' => $config['label'], 'type' => $config['type'], 'required' => ! empty( $config['required'] ), 'order' => (int) $config['order'], 'options' => isset( $config['options'] ) && is_array( $config['options'] ) ? $config['options'] : array(), 'max' => 1000, 'rows' => 3, 'autocomplete' => 'off' );
		}
		uasort( $fields, function( $a, $b ) { return $a['order'] - $b['order']; } );
		return $fields;
	}

	/**
	 * Ключ метаданных пользователя для поля анкеты.
	 *
	 * @param string $key Ключ поля.
	 * @return string
	 */
	public static function meta_key( $key ) {
		return self::PREFIX . $key;
	}

	/**
	 * Сохранённые значения анкеты пользователя.
	 *
	 * @param int $user_id ID пользователя.
	 * @return array<string,string>
	 */
	public static function get_values( $user_id ) {
		$values = array();
		foreach ( array_keys( self::all() ) as $key ) {
			$values[ $key ] = (string) get_user_meta( $user_id, self::meta_key( $key ), true );
		}
		return $values;
	}

	/**
	 * Значения анкеты из отправленной формы, уже очищенные.
	 *
	 * Проверка nonce выполняется в обработчике формы до использования значений.
	 *
	 * @return array<string,string>
	 */
	public static function from_request() {
		$values = array();
		foreach ( self::all() as $key => $field ) {
			$name = self::PREFIX . $key;
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw            = isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : '';
			$values[ $key ] = self::sanitize( $field, $raw );
		}
		return $values;
	}

	/**
	 * Очистка значения поля.
	 *
	 * @param array<string,mixed> $field Описание поля.
	 * @param string              $raw   Значение из формы.
	 * @return string
	 */
	public static function sanitize( $field, $raw ) {
		if ( 'textarea' === $field['type'] ) {
			return trim( sanitize_textarea_field( $raw ) );
		}
		return sanitize_text_field( $raw );
	}

	/**
	 * Проверка значений анкеты.
	 *
	 * @param array<string,string> $values Очищенные значения.
	 * @return array<string,string> Ошибки в виде «ключ поля => сообщение».
	 */
	public static function validate( $values ) {
		$errors = array();

		foreach ( self::all() as $key => $field ) {
			$value = isset( $values[ $key ] ) ? $values[ $key ] : '';

			if ( '' === $value ) {
				if ( ! empty( $field['required'] ) ) { $errors[ $key ] = __( 'Заполните это поле.', 'wp-ntk' ); }
				continue;
			} elseif ( 'checkbox' === $field['type'] && '1' !== $value ) {
				$errors[ $key ] = __( 'Недопустимое значение.', 'wp-ntk' );
			} elseif ( 'select' === $field['type'] && ! in_array( $value, $field['options'], true ) ) {
				$errors[ $key ] = __( 'Выберите вариант из списка.', 'wp-ntk' );
			} elseif ( self::length( $value ) > $field['max'] ) {
				/* translators: %d: предельное число символов. */
				$errors[ $key ] = sprintf( __( 'Слишком длинное значение: допустимо не более %d символов.', 'wp-ntk' ), $field['max'] );
			} elseif ( 'email' === $field['type'] && ! is_email( $value ) ) {
				$errors[ $key ] = __( 'Укажите корректный адрес электронной почты.', 'wp-ntk' );
			} elseif ( 'phones' === $key && (int) preg_match_all( '/\d/', $value ) < 5 ) {
				$errors[ $key ] = __( 'Укажите номера телефонов.', 'wp-ntk' );
			}
		}

		return $errors;
	}

	/**
	 * Проверка нового пароля.
	 *
	 * @param string $password Пароль в том виде, в каком он пришёл в $_POST.
	 * @return string Текст ошибки или пустая строка, если пароль подходит.
	 */
	public static function password_error( $password ) {
		$plain = wp_unslash( $password );

		if ( '' === $plain ) {
			return __( 'Заполните это поле.', 'wp-ntk' );
		}
		if ( self::length( $plain ) < self::PASSWORD_MIN ) {
			/* translators: %d: минимальное число символов. */
			return sprintf( __( 'Пароль должен быть не короче %d символов.', 'wp-ntk' ), self::PASSWORD_MIN );
		}
		if ( strlen( $plain ) > self::PASSWORD_MAX ) {
			return __( 'Пароль слишком длинный.', 'wp-ntk' );
		}
		// Такое же ограничение действует в самом WordPress.
		if ( false !== strpos( $plain, '\\' ) ) {
			return __( 'Пароль не должен содержать символ «\».', 'wp-ntk' );
		}
		return '';
	}

	/** Требования к паролю при регистрации. */
	public static function registration_password_error( $password ) {
		$error = self::password_error( $password );
		if ( '' !== $error ) { return $error; }
		$plain = wp_unslash( $password );
		if ( self::length( $plain ) < 12 || ! preg_match( '/[A-Z]/', $plain ) || ! preg_match( '/[a-z]/', $plain ) || ! preg_match( '/[0-9]/', $plain ) || ! preg_match( '/[^a-zA-Z0-9\s]/u', $plain ) ) {
			return __( 'Пароль должен содержать не менее 12 символов, заглавную и строчную латинские буквы, цифру и специальный символ.', 'wp-ntk' );
		}
		return '';
	}

	/**
	 * Сохранение значений анкеты пользователя.
	 *
	 * @param int                  $user_id ID пользователя.
	 * @param array<string,string> $values  Очищенные значения.
	 */
	public static function save( $user_id, $values ) {
		foreach ( array_keys( self::all() ) as $key ) {
			if ( isset( $values[ $key ] ) ) {
				// update_user_meta() ждёт данные с экранирующими слэшами.
				update_user_meta( $user_id, self::meta_key( $key ), wp_slash( $values[ $key ] ) );
			}
		}
	}

	/**
	 * Длина строки в символах.
	 *
	 * @param string $value Строка.
	 * @return int
	 */
	public static function length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}
}
