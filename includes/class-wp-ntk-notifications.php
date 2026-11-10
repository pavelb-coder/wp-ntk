<?php
/** Уведомление наставника после регистрации. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WP_NTK_Notifications {
 const OPTION = 'wp_ntk_registration_email';
 const STATUS = 'wp_ntk_registration_email_status';

 public function hooks() {
  add_action( 'admin_menu', array( $this, 'menu' ), 20 );
  add_action( 'admin_post_wp_ntk_notifications_save', array( $this, 'save' ) );
  add_action( 'wp_ntk_mentor_registered', array( $this, 'send' ), 10, 2 );
 }
 private function settings() {
  $defaults = array(
   'enabled' => 0,
   'subject' => 'Регистрация на сайте {site}',
   'message' => "Здравствуйте, {name}!\n\nВаш аккаунт зарегистрирован на сайте {site}.\nВход в личный кабинет: {account_url}\n\nВаш email для входа: {email}",
  );
  $settings = get_option( self::OPTION, array() );
  return is_array( $settings ) ? array_merge( $defaults, $settings ) : $defaults;
 }
 public function menu() { add_submenu_page( 'wp-ntk', 'Email-уведомления', 'Email-уведомления', 'manage_options', 'wp-ntk-notifications', array( $this, 'page' ) ); }
 public function save() {
  if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Недостаточно прав.', '', array( 'response' => 403 ) ); }
  check_admin_referer( 'wp_ntk_notifications_save' );
  $subject = isset( $_POST['subject'] ) && is_string( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
  $message = isset( $_POST['message'] ) && is_string( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
  if ( '' === trim( $subject ) || '' === trim( $message ) || WP_NTK_Fields::length( $subject ) > 200 || WP_NTK_Fields::length( $message ) > 10000 ) {
   wp_die( 'Укажите тему письма (до 200 символов) и текст (до 10000 символов). Вернитесь назад и исправьте настройки.' );
  }
  update_option( self::OPTION, array( 'enabled' => empty( $_POST['enabled'] ) ? 0 : 1, 'subject' => $subject, 'message' => $message ), false );
  wp_safe_redirect( admin_url( 'admin.php?page=wp-ntk-notifications&saved=1' ) ); exit;
 }
 public function page() {
  if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Недостаточно прав.', '', array( 'response' => 403 ) ); }
  $config = $this->settings(); $status = get_option( self::STATUS, array() );
  ?>
  <div class="wrap"><h1>Email-уведомления</h1>
  <?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p>Настройки сохранены.</p></div><?php endif; ?>
  <p>Письмо отправляется после регистрации наставника на email его учётной записи. На email организации оно не отправляется.</p>
  <p>Доступные подстановки: <code>{name}</code> — ФИО, <code>{email}</code> — email аккаунта, <code>{site}</code> — название сайта, <code>{account_url}</code> — адрес кабинета. Пароль в письмо не включается. Письмо отправляется обычным текстом.</p>
  <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wp_ntk_notifications_save"><?php wp_nonce_field( 'wp_ntk_notifications_save' ); ?>
  <p><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $config['enabled'] ) ); ?>> Отправлять письмо наставнику после регистрации</label></p>
  <p><label for="wp-ntk-mail-subject">Тема письма</label><br><input id="wp-ntk-mail-subject" class="large-text" name="subject" maxlength="200" required value="<?php echo esc_attr( $config['subject'] ); ?>"></p>
  <p><label for="wp-ntk-mail-message">Текст письма</label><br><textarea id="wp-ntk-mail-message" class="large-text" rows="10" name="message" maxlength="10000" required><?php echo esc_textarea( $config['message'] ); ?></textarea></p>
  <?php submit_button( 'Сохранить настройки' ); ?></form>
  <p>Для доставки нужна работающая почта WordPress. Успешная передача в почтовую систему не гарантирует получение письма.</p>
  <?php if ( is_array( $status ) && ! empty( $status['message'] ) ) : ?><p><strong>Последняя попытка:</strong> <?php echo esc_html( $status['message'] ); ?> (<?php echo esc_html( $status['time'] ); ?> UTC)</p><?php endif; ?>
  </div><?php
 }
 public function send( $user_id, $values ) {
  $config = $this->settings();
  if ( empty( $config['enabled'] ) ) { return; }
  $user = get_userdata( $user_id );
  if ( ! $user || ! is_email( $user->user_email ) ) { return; }
  $replace = array(
   '{name}' => isset( $values['fio'] ) ? $values['fio'] : $user->display_name,
   '{email}' => $user->user_email,
   '{site}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
   '{account_url}' => WP_NTK_Setup::account_url(),
  );
  $subject = sanitize_text_field( strtr( $config['subject'], $replace ) );
  $message = strtr( $config['message'], $replace );
  // Письмо не должно препятствовать завершению регистрации пользователя.
  try { $sent = wp_mail( $user->user_email, $subject, $message, array( 'Content-Type: text/plain; charset=UTF-8' ) ); }
  catch ( Throwable $e ) { $sent = false; }
  update_option( self::STATUS, array( 'message' => $sent ? 'Письмо передано почтовой системе.' : 'Не удалось передать письмо почтовой системе.', 'time' => gmdate( 'Y-m-d H:i:s' ) ), false );
 }
}
