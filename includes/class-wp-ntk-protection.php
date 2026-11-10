<?php
/** Дополнительная защита форм плагина. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WP_NTK_Protection {
 const CAPTCHA_OPTION = 'wp_ntk_captcha_enabled';
 const LIMIT_OPTION = 'wp_ntk_limit_enabled';
 const LIMIT = 5;
 const WINDOW = 900;

 /** Ограничение учитывает все отправки, независимо от результата. */
 public static function check_limit( $action ) {
  if ( '1' !== get_option( self::LIMIT_OPTION, '0' ) ) { return ''; }
  $ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
  if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) { $ip = 'unknown'; }
  $key = 'wp_ntk_rate_' . substr( hash_hmac( 'sha256', $action . '|' . $ip, wp_salt( 'nonce' ) ), 0, 40 );
  $count = (int) get_transient( $key );
  if ( $count >= self::LIMIT ) { return __( 'Слишком много попыток. Попробуйте позже, через 15 минут.', 'wp-ntk' ); }
  // Простой счётчик на транзиентах; при одновременных запросах возможна погрешность.
  set_transient( $key, $count + 1, self::WINDOW );
  return '';
 }
 public static function render_captcha( $action ) {
  if ( '1' !== get_option( self::CAPTCHA_OPTION, '0' ) ) { return; }
  $a = wp_rand( 1, 9 ); $b = wp_rand( 1, 9 ); $time = time();
  $payload = $action . '|' . $a . '|' . $b . '|' . $time;
  $token = base64_encode( $payload ) . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) );
  $id = 'wp-ntk-captcha-' . $action;
  echo '<div class="wp-ntk-field"><label class="wp-ntk-label" for="' . esc_attr( $id ) . '">' . esc_html( sprintf( __( 'Проверка: сколько будет %1$d + %2$d?', 'wp-ntk' ), $a, $b ) ) . ' <span class="wp-ntk-required" aria-hidden="true">*</span></label>';
  echo '<input class="wp-ntk-input" type="number" inputmode="numeric" id="' . esc_attr( $id ) . '" name="wp_ntk_captcha_answer" required autocomplete="off">';
  echo '<input type="hidden" name="wp_ntk_captcha_token" value="' . esc_attr( $token ) . '"></div>';
 }
 public static function check_captcha( $action ) {
  if ( '1' !== get_option( self::CAPTCHA_OPTION, '0' ) ) { return ''; }
  $token = isset( $_POST['wp_ntk_captcha_token'] ) && is_string( $_POST['wp_ntk_captcha_token'] ) ? wp_unslash( $_POST['wp_ntk_captcha_token'] ) : '';
  $answer = isset( $_POST['wp_ntk_captcha_answer'] ) && is_string( $_POST['wp_ntk_captcha_answer'] ) ? trim( wp_unslash( $_POST['wp_ntk_captcha_answer'] ) ) : '';
  $parts = explode( '.', $token );
  $payload = count( $parts ) === 2 ? base64_decode( $parts[0], true ) : false;
  if ( is_string( $payload ) && hash_equals( hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) ), $parts[1] ) ) {
   $fields = explode( '|', $payload );
   if ( count( $fields ) === 4 && $fields[0] === $action && ctype_digit( $fields[1] ) && ctype_digit( $fields[2] ) && ctype_digit( $fields[3] ) && (int) $fields[3] <= time() && (int) $fields[3] >= time() - HOUR_IN_SECONDS && ctype_digit( $answer ) && (int) $answer === (int) $fields[1] + (int) $fields[2] ) { return ''; }
  }
  return __( 'Неверный ответ на проверочный вопрос. Попробуйте ещё раз.', 'wp-ntk' );
 }
 public static function check( $action ) {
  $limit = self::check_limit( $action );
  return $limit ? $limit : self::check_captcha( $action );
 }
}
