<?php
/** Восстановление пароля через страницу личного кабинета. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WP_NTK_Recovery {
 private $errors = array();
 private $view = '';
 private $login = '';
 private $key = '';

 public function hooks() {
  add_action( 'template_redirect', array( $this, 'handle' ), 4 );
 }
 private function input( $source, $name ) {
  return isset( $source[ $name ] ) && is_string( $source[ $name ] ) ? wp_unslash( $source[ $name ] ) : '';
 }
 public function view() {
  if ( $this->view ) { return $this->view; }
  $view = sanitize_key( $this->input( $_GET, 'wp_ntk_view' ) );
  return in_array( $view, array( 'forgot', 'reset' ), true ) ? $view : '';
 }
 public function handle() {
  if ( ! is_singular() ) { return; }
  $post = get_queried_object();
  if ( ! $post instanceof WP_Post || ! has_shortcode( $post->post_content, WP_NTK_Setup::SHORTCODE ) ) { return; }
  $action = sanitize_key( $this->input( $_POST, 'wp_ntk_recovery_action' ) );
  $view = $this->view();
  if ( ! $action && ! $view ) { return; }
  nocache_headers();
  header( 'Referrer-Policy: no-referrer' );
  if ( is_user_logged_in() ) { return; }
  if ( 'reset' === $view && ! $action ) {
   $this->login = $this->input( $_GET, 'login' );
   $this->key = $this->input( $_GET, 'key' );
  }
  if ( ! $action ) { return; }
  if ( ! in_array( $action, array( 'forgot', 'reset' ), true ) ) { return; }
  $this->view = $action;
  if ( ! wp_verify_nonce( $this->input( $_POST, 'wp_ntk_recovery_nonce' ), 'wp_ntk_recovery_' . $action ) ) {
   $this->errors[] = 'Срок действия формы истёк. Обновите страницу и попробуйте снова.'; return;
  }
  if ( 'reset' === $action ) {
   $this->login = $this->input( $_POST, 'wp_ntk_reset_login' );
   $this->key = $this->input( $_POST, 'wp_ntk_reset_key' );
  }
  $protection_error = WP_NTK_Protection::check( $action );
  if ( $protection_error ) { $this->errors[] = $protection_error; return; }
  if ( 'forgot' === $action ) {
   $email = sanitize_email( $this->input( $_POST, 'wp_ntk_recovery_email' ) );
   if ( ! is_email( $email ) ) { $this->errors[] = 'Укажите корректный адрес электронной почты.'; return; }
   // Ответ одинаков для существующих и несуществующих адресов.
   $user = get_user_by( 'email', $email );
   if ( $user && apply_filters( 'allow_password_reset', true, $user->ID ) === true ) {
    $key = get_password_reset_key( $user );
    if ( ! is_wp_error( $key ) ) {
     $url = add_query_arg( array( 'wp_ntk_view' => 'reset', 'login' => $user->user_login, 'key' => $key ), get_permalink( $post ) );
     $message = "Для восстановления пароля перейдите по ссылке:\n\n" . $url . "\n\nЕсли вы не запрашивали восстановление, проигнорируйте это письмо.";
     wp_mail( $user->user_email, '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] Восстановление пароля', $message );
    }
   }
   wp_safe_redirect( add_query_arg( array( 'wp_ntk_view' => 'forgot', 'wp_ntk_recovery_notice' => 'sent' ), get_permalink( $post ) ) . '#wp-ntk-account' ); exit;
  }
  $this->login = $this->input( $_POST, 'wp_ntk_reset_login' );
  $this->key = $this->input( $_POST, 'wp_ntk_reset_key' );
  $user = check_password_reset_key( $this->key, $this->login );
  if ( is_wp_error( $user ) ) { $this->errors[] = 'Ссылка недействительна или истекла. Запросите новую ссылку.'; $this->key = ''; return; }
  $password = trim( $this->input( $_POST, 'wp_ntk_reset_password' ) );
  $confirmation = trim( $this->input( $_POST, 'wp_ntk_reset_confirmation' ) );
  $error = WP_NTK_Fields::registration_password_error( wp_slash( $password ) );
  if ( $error ) { $this->errors[] = $error; }
  if ( '' === $confirmation || $password !== $confirmation ) { $this->errors[] = 'Пароли не совпадают.'; }
  if ( $this->errors ) { return; }
  reset_password( $user, $password );
  wp_safe_redirect( add_query_arg( 'wp_ntk_recovery_notice', 'changed', get_permalink( $post ) ) . '#wp-ntk-account' ); exit;
 }
 public function render() {
  $view = $this->view();
  $url = get_permalink( get_queried_object_id() );
  $notice = sanitize_key( $this->input( $_GET, 'wp_ntk_recovery_notice' ) );
  if ( 'changed' === $notice ) { echo '<div class="wp-ntk-notice wp-ntk-notice--success">Пароль изменён. Теперь можно войти.</div>'; }
  if ( ! $view ) { return false; }
  echo '<h2 class="wp-ntk-title">Восстановление пароля</h2>';
  if ( 'sent' === $notice && 'forgot' === $view ) { echo '<div class="wp-ntk-notice wp-ntk-notice--success">Если аккаунт с таким адресом существует, на него отправлена ссылка для восстановления. Проверьте также папку «Спам».</div>'; }
  foreach ( $this->errors as $error ) { echo '<div class="wp-ntk-notice wp-ntk-notice--error" role="alert">' . esc_html( $error ) . '</div>'; }
  if ( 'reset' === $view && ! $this->errors ) {
   $user = check_password_reset_key( $this->key, $this->login );
   if ( is_wp_error( $user ) ) { $this->key = ''; echo '<p>Ссылка недействительна или истекла. Запросите новую ссылку.</p>'; }
  }
  if ( 'forgot' === $view || $this->key ) {
   echo '<form class="wp-ntk-form wp-ntk-form--narrow" method="post" action="' . esc_url( add_query_arg( 'wp_ntk_view', $view, $url ) . '#wp-ntk-account' ) . '">';
   echo '<input type="hidden" name="wp_ntk_recovery_action" value="' . esc_attr( $view ) . '">';
   wp_nonce_field( 'wp_ntk_recovery_' . $view, 'wp_ntk_recovery_nonce' );
   if ( 'forgot' === $view ) {
    echo '<div class="wp-ntk-field"><label class="wp-ntk-label" for="wp-ntk-recovery-email">Электронная почта аккаунта</label><input class="wp-ntk-input" type="email" id="wp-ntk-recovery-email" name="wp_ntk_recovery_email" autocomplete="email" required></div>';
   } else {
    echo '<input type="hidden" name="wp_ntk_reset_login" value="' . esc_attr( $this->login ) . '"><input type="hidden" name="wp_ntk_reset_key" value="' . esc_attr( $this->key ) . '">';
    echo '<p class="wp-ntk-hint">Не менее 12 символов: заглавная и строчная латинские буквы, цифра и специальный символ.</p>';
    echo '<div class="wp-ntk-field"><label class="wp-ntk-label" for="wp-ntk-reset-password">Новый пароль</label><input class="wp-ntk-input" type="password" id="wp-ntk-reset-password" name="wp_ntk_reset_password" minlength="12" autocomplete="new-password" required></div>';
    echo '<div class="wp-ntk-field"><label class="wp-ntk-label" for="wp-ntk-reset-confirmation">Повторите пароль</label><input class="wp-ntk-input" type="password" id="wp-ntk-reset-confirmation" name="wp_ntk_reset_confirmation" minlength="12" autocomplete="new-password" required></div>';
   }
   WP_NTK_Protection::render_captcha( $view );
   echo '<div class="wp-ntk-actions"><button class="wp-ntk-button" type="submit">' . ( 'forgot' === $view ? 'Отправить ссылку' : 'Изменить пароль' ) . '</button></div></form>';
  }
  echo '<p class="wp-ntk-switch"><a href="' . esc_url( $url ) . '">Вернуться ко входу</a>';
  if ( 'reset' === $view && ! $this->key ) { echo ' · <a href="' . esc_url( add_query_arg( 'wp_ntk_view', 'forgot', $url ) ) . '">Запросить новую ссылку</a>'; }
  echo '</p>';
  return true;
 }
}
