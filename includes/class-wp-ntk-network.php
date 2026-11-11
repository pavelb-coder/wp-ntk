<?php
/** Экспорт списка наставников в SMB-ресурс. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WP_NTK_Network {
 const CONFIG = 'wp_ntk_smb_config';
 const STATUS = 'wp_ntk_smb_status';
 const EVENT = 'wp_ntk_smb_export_event';
 const LOCK = 'wp_ntk_smb_lock';

 public function hooks() {
  add_action( 'admin_menu', array( $this, 'menu' ), 20 );
  add_action( 'admin_post_wp_ntk_smb_save', array( $this, 'save' ) );
  add_action( 'admin_post_wp_ntk_smb_test', array( $this, 'test' ) );
  add_action( 'admin_post_wp_ntk_smb_export', array( $this, 'manual_export' ) );
  add_action( self::EVENT, array( $this, 'scheduled_export' ) );
  add_action( 'wp_ntk_mentor_registered', array( $this, 'schedule' ) );
  add_action( 'wp_ntk_mentor_profile_updated', array( $this, 'schedule' ) );
  add_action( 'delete_user', array( $this, 'schedule' ) );
  add_action( 'deleted_user', array( $this, 'schedule' ) );
 }
 public function menu() { add_submenu_page( 'wp-ntk', 'Сетевая папка', 'Сетевая папка', 'manage_options', 'wp-ntk-network', array( $this, 'page' ) ); }
 private function config() { $c = get_option( self::CONFIG, array() ); return is_array( $c ) ? $c : array(); }
 private function read( $name ) { return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? trim( wp_unslash( $_POST[ $name ] ) ) : ''; }
 private function secret_key() { return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true ); }
 private function encrypt( $password ) {
  if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) ) { return false; }
  try { $iv = random_bytes( 12 ); } catch ( Exception $e ) { return false; }
  $tag = ''; $data = openssl_encrypt( $password, 'aes-256-gcm', $this->secret_key(), OPENSSL_RAW_DATA, $iv, $tag );
  return false === $data || 16 !== strlen( $tag ) ? false : base64_encode( $iv . $tag . $data );
 }
 private function decrypt( $value ) {
  if ( ! function_exists( 'openssl_decrypt' ) || ! is_string( $value ) ) { return false; }
  $raw = base64_decode( $value, true );
  if ( false === $raw || strlen( $raw ) < 28 ) { return false; }
  return openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $this->secret_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
 }
 /** Только UNC-путь с безопасными компонентами для командного интерфейса smbclient. */
 private function parse_path( $path ) {
  $path = str_replace( '\\', '/', trim( $path ) );
  if ( 0 !== strpos( $path, '//' ) ) { return false; }
  $parts = explode( '/', substr( $path, 2 ) );
  if ( count( $parts ) < 2 || ! preg_match( '/^[a-zA-Z0-9._-]+$/D', $parts[0] ) || '' === $parts[1] ) { return false; }
  foreach ( array_slice( $parts, 1 ) as $part ) {
   if ( '' === $part || '.' === $part || '..' === $part || strlen( $part ) > 255 || preg_match( '/[\x00-\x1f\x7f";\\\\]/', $part ) ) { return false; }
  }
  return array( 'service' => '//' . $parts[0] . '/' . $parts[1], 'dirs' => array_slice( $parts, 2 ) );
 }
 private function allowed() { if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Недостаточно прав.' ); } }
 public function save() {
  $this->allowed(); check_admin_referer( 'wp_ntk_smb_save' );
  $path = $this->read( 'path' ); $user = $this->read( 'user' ); $domain = $this->read( 'domain' ); $password = isset( $_POST['password'] ) && is_string( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';
  if ( ! $this->parse_path( $path ) || '' === $user || strlen( $user ) > 200 || strlen( $domain ) > 200 || strlen( $password ) > 1024 || preg_match( '/[\r\n\x00]/', $user . $domain . $password ) ) { wp_die( 'Проверьте UNC-путь и логин: путь должен иметь вид //сервер/ресурс/каталог, без пустых и специальных компонентов.' ); }
  $c = $this->config();
  if ( '' !== $password ) { $c['secret'] = $this->encrypt( $password ); if ( false === $c['secret'] ) { wp_die( 'Не удалось зашифровать пароль: требуется расширение OpenSSL.' ); } }
  if ( empty( $c['secret'] ) || false === $this->decrypt( $c['secret'] ) ) { wp_die( 'Укажите пароль для сетевой папки.' ); }
  $c['path'] = $path; $c['user'] = $user; $c['domain'] = $domain; $c['auto'] = empty( $_POST['auto'] ) ? 0 : 1;
  update_option( self::CONFIG, $c, false );
  wp_safe_redirect( admin_url( 'admin.php?page=wp-ntk-network&saved=1' ) ); exit;
 }
 public function page() {
  $this->allowed(); $c = $this->config(); $status = get_option( self::STATUS, array() );
  ?>
  <div class="wrap"><h1>Сетевая папка SMB</h1>
  <?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p>Настройки сохранены.</p></div><?php endif; ?>
  <p>Файл <code>mentors.csv</code> содержит персональные данные. Ограничьте доступ к папке и убедитесь, что передача и хранение соответствуют политике организации. Файл перезаписывается целиком, не атомарно: при сбое передачи он может оказаться неполным. Делайте резервные копии.</p>
  <p>Для подключения на сервере WordPress нужны сервер с поддержкой шифрования SMB3, <code>smbclient</code>, разрешённый <code>proc_open</code> и OpenSSL. Пароль хранится в базе в зашифрованном виде; ключ зависит от секретных ключей WordPress в wp-config.php. После их смены пароль нужно ввести заново.</p>
  <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wp_ntk_smb_save"><?php wp_nonce_field( 'wp_ntk_smb_save' ); ?>
  <table class="form-table"><tr><th><label for="ntk-smb-path">Путь к папке</label></th><td><input class="regular-text" id="ntk-smb-path" name="path" required placeholder="//server/share/folder" value="<?php echo esc_attr( isset( $c['path'] ) ? $c['path'] : '' ); ?>"></td></tr>
  <tr><th><label for="ntk-smb-user">Логин</label></th><td><input class="regular-text" id="ntk-smb-user" name="user" required value="<?php echo esc_attr( isset( $c['user'] ) ? $c['user'] : '' ); ?>"></td></tr>
  <tr><th><label for="ntk-smb-domain">Домен (необязательно)</label></th><td><input class="regular-text" id="ntk-smb-domain" name="domain" value="<?php echo esc_attr( isset( $c['domain'] ) ? $c['domain'] : '' ); ?>"></td></tr>
  <tr><th><label for="ntk-smb-password">Пароль</label></th><td><input type="password" class="regular-text" id="ntk-smb-password" name="password" autocomplete="new-password"><p class="description"><?php echo empty( $c['secret'] ) ? 'Введите пароль.' : 'Пароль сохранён. Оставьте поле пустым, чтобы его не менять.'; ?></p></td></tr>
  <tr><th>Автоматически</th><td><label><input type="checkbox" name="auto" value="1" <?php checked( ! empty( $c['auto'] ) ); ?>> Выгружать после регистрации, изменения анкеты и удаления пользователя</label><p class="description">Выгрузка запускается через WP-Cron: без посещений сайта или настроенного системного cron она может задержаться.</p></td></tr></table><?php submit_button( 'Сохранить настройки' ); ?></form>
  <h2>Проверка и выгрузка</h2><p>Проверка только читает каталог; для выгрузки нужны также права записи.</p>
  <?php foreach ( array( 'test' => 'Проверить подключение', 'export' => 'Выгрузить сейчас' ) as $action => $label ) : ?><form style="display:inline-block;margin-right:8px" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wp_ntk_smb_<?php echo esc_attr( $action ); ?>"><?php wp_nonce_field( 'wp_ntk_smb_' . $action ); ?><button class="button"><?php echo esc_html( $label ); ?></button></form><?php endforeach; ?>
  <?php if ( is_array( $status ) && ! empty( $status['message'] ) ) : ?><p><strong>Последний результат:</strong> <?php echo esc_html( $status['message'] ); ?> (<?php echo esc_html( isset( $status['time'] ) ? $status['time'] : '' ); ?> UTC)</p><?php endif; ?>
  </div><?php
 }
 private function status( $message ) { update_option( self::STATUS, array( 'message' => $message, 'time' => gmdate( 'Y-m-d H:i:s' ) ), false ); }
 public function test() { $this->allowed(); check_admin_referer( 'wp_ntk_smb_test' ); $ok = $this->send( '' ); $this->status( $ok ? 'Подключение к каталогу успешно.' : 'Не удалось подключиться. Проверьте путь, реквизиты, smbclient и права.' ); wp_safe_redirect( admin_url( 'admin.php?page=wp-ntk-network' ) ); exit; }
 public function manual_export() { $this->allowed(); check_admin_referer( 'wp_ntk_smb_export' ); $this->export(); wp_safe_redirect( admin_url( 'admin.php?page=wp-ntk-network' ) ); exit; }
 public function schedule() { $c = $this->config(); if ( ! empty( $c['auto'] ) && ! wp_next_scheduled( self::EVENT ) ) { wp_schedule_single_event( time() + 10, self::EVENT ); } }
 public function scheduled_export() { $c = $this->config(); if ( ! empty( $c['auto'] ) ) { $this->export(); } }
 private function export() {
  $now = time(); $old = get_option( self::LOCK );
  if ( is_array( $old ) && isset( $old['time'] ) && (int) $old['time'] < $now - 600 ) { delete_option( self::LOCK ); }
  $token = wp_generate_uuid4();
  if ( ! add_option( self::LOCK, array( 'token' => $token, 'time' => $now ), '', 'no' ) ) { $this->status( 'Выгрузка уже выполняется. Попробуйте позже.' ); $this->schedule(); return false; }
  $file = false; $ok = false;
  try {
   $file = tempnam( sys_get_temp_dir(), 'ntkcsv_' );
   if ( false === $file || ! chmod( $file, 0600 ) ) { throw new RuntimeException(); }
   $f = fopen( $file, 'wb' ); if ( ! $f ) { throw new RuntimeException(); }
   if ( 3 !== fwrite( $f, "\xEF\xBB\xBF" ) ) { fclose( $f ); throw new RuntimeException(); } $fields = WP_NTK_Fields::all(); $headers = array( 'ID', 'Email аккаунта', 'Дата регистрации' );
   foreach ( $fields as $field ) { $headers[] = $field['label']; } foreach ( $headers as &$header ) { if ( preg_match( '/^[\s\x00-\x1F]*[=+\-@]/u', $header ) ) { $header = "'" . $header; } } unset( $header ); if ( false === fputcsv( $f, $headers, ';' ) ) { fclose( $f ); throw new RuntimeException(); }
   $offset = 0;
   do {
    $users = get_users( array( 'role' => WP_NTK_Setup::ROLE, 'number' => 200, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC' ) );
    foreach ( $users as $user ) {
     $row = array( $user->ID, $user->user_email, $user->user_registered );
     foreach ( WP_NTK_Fields::get_values( $user->ID ) as $v ) { $row[] = $v; }
     foreach ( $row as &$value ) { $value = (string) $value; if ( preg_match( '/^[\s\x00-\x1F]*[=+\-@]/u', $value ) ) { $value = "'" . $value; } } unset( $value );
     if ( false === fputcsv( $f, $row, ';' ) ) { fclose( $f ); throw new RuntimeException(); }
    }
    $offset += count( $users );
   } while ( count( $users ) === 200 );
   if ( ! fclose( $f ) ) { throw new RuntimeException(); } $ok = $this->send( $file );
  } catch ( Throwable $e ) { $ok = false; }
  finally { if ( $file && is_file( $file ) ) { @unlink( $file ); } $lock = get_option( self::LOCK ); if ( is_array( $lock ) && isset( $lock['token'] ) && $token === $lock['token'] ) { delete_option( self::LOCK ); } }
  $this->status( $ok ? 'Выгрузка mentors.csv завершена.' : 'Выгрузка не выполнена. Проверьте подключение и права записи.' );
  return $ok;
 }
 /** Операция в выделенной подпапке проектов; команды и имена формируются самим плагином. */
 public function project_command( $dirs, $command, $probe = false ) {
  $c = $this->config(); $parsed = $this->parse_path( isset( $c['path'] ) ? $c['path'] : '' );
  $password = $this->decrypt( isset( $c['secret'] ) ? $c['secret'] : '' );
  if ( ! $parsed || false === $password || empty( $c['user'] ) || ! function_exists( 'proc_open' ) || ! is_array( $dirs ) || ! is_string( $command ) || preg_match( '/[\r\n;]/', $command ) ) { return false; }
  foreach ( $dirs as $dir ) { if ( ! is_string( $dir ) || ! preg_match( '/^[A-Za-z0-9_.-]{1,100}$/D', $dir ) || '.' === $dir || '..' === $dir ) { return false; } }
  $binary = ''; foreach ( array( '/usr/bin/smbclient', '/usr/local/bin/smbclient', '/bin/smbclient' ) as $candidate ) { if ( is_executable( $candidate ) ) { $binary = $candidate; break; } }
  if ( ! $binary ) { return false; }
  $auth = tempnam( sys_get_temp_dir(), 'ntkauth_' ); if ( false === $auth ) { return false; }
  try {
   if ( ! chmod( $auth, 0600 ) ) { return false; }
   $contents = 'username = ' . $c['user'] . "\npassword = " . $password . "\n" . ( empty( $c['domain'] ) ? '' : 'domain = ' . $c['domain'] . "\n" );
   if ( false === file_put_contents( $auth, $contents, LOCK_EX ) ) { return false; }
   $args = array( $binary, $parsed['service'], '-A', $auth, '-m', 'SMB3', '--client-protection=encrypt', '-t', '20' );
   $all_dirs = array_merge( $parsed['dirs'], $dirs ); if ( $all_dirs ) { $args[] = '-D'; $args[] = implode( '\\', $all_dirs ); }
   $args[] = '-c'; $args[] = $command;
   $proc = proc_open( $args, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes ); if ( ! is_resource( $proc ) ) { return false; }
   fclose( $pipes[0] ); stream_set_blocking( $pipes[1], false ); stream_set_blocking( $pipes[2], false ); $output = ''; $deadline = microtime( true ) + 60; $exit = -1;
   do { $output .= stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] ); $state = proc_get_status( $proc ); if ( ! $state['running'] ) { $exit = $state['exitcode']; break; } if ( microtime( true ) > $deadline ) { proc_terminate( $proc, 9 ); break; } usleep( 100000 ); } while ( true );
   $output .= stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); proc_close( $proc );
   if ( $probe ) { if ( 0 === $exit && ! preg_match( '/NT_STATUS_|ERRDOS|ERRSRV|failed|error|timed out/i', $output ) ) { return 'exists'; } if ( preg_match( '/NT_STATUS_(?:NO_SUCH_FILE|OBJECT_NAME_NOT_FOUND)/', $output ) && ! preg_match( '/NT_STATUS_(?!NO_SUCH_FILE|OBJECT_NAME_NOT_FOUND)[A-Z_]+|ERRDOS|ERRSRV|timed out/i', $output ) ) { return 'missing'; } return 'unknown'; }
   if ( 0 === strpos( $command, 'del ' ) && preg_match( '/NT_STATUS_(?:NO_SUCH_FILE|OBJECT_NAME_NOT_FOUND)/', $output ) && ! preg_match( '/NT_STATUS_(?!NO_SUCH_FILE|OBJECT_NAME_NOT_FOUND)[A-Z_]+|ERRDOS|ERRSRV|timed out/i', $output ) ) { return true; }
   return 0 === $exit && ! preg_match( '/NT_STATUS_|ERRDOS|ERRSRV|failed|error|timed out/i', $output );
  } finally { @unlink( $auth ); }
 }
 /** Запускает smbclient без оболочки и без пароля в аргументах процесса. */
 private function send( $file ) {
  $c = $this->config(); $parsed = $this->parse_path( isset( $c['path'] ) ? $c['path'] : '' );
  $password = $this->decrypt( isset( $c['secret'] ) ? $c['secret'] : '' );
  if ( ! $parsed || false === $password || empty( $c['user'] ) || ! function_exists( 'proc_open' ) ) { return false; }
  $binary = ''; foreach ( array( '/usr/bin/smbclient', '/usr/local/bin/smbclient', '/bin/smbclient' ) as $candidate ) { if ( is_executable( $candidate ) ) { $binary = $candidate; break; } }
  if ( ! $binary ) { return false; }
  $auth = tempnam( sys_get_temp_dir(), 'ntkauth_' ); if ( false === $auth ) { return false; }
  try {
   if ( ! chmod( $auth, 0600 ) ) { return false; }
   $contents = 'username = ' . $c['user'] . "\npassword = " . $password . "\n" . ( empty( $c['domain'] ) ? '' : 'domain = ' . $c['domain'] . "\n" );
   if ( false === file_put_contents( $auth, $contents, LOCK_EX ) ) { return false; }
   $commands = array();
   if ( $file ) {
    if ( false !== strpbrk( $file, "\";\r\n" ) ) { return false; }
    $commands[] = 'put "' . $file . '" "mentors.csv"';
   } else { $commands[] = 'ls'; }
   $args = array( $binary, $parsed['service'], '-A', $auth, '-m', 'SMB3', '--client-protection=encrypt', '-t', '20' );
   if ( $parsed['dirs'] ) { $args[] = '-D'; $args[] = implode( '\\', $parsed['dirs'] ); }
   $args[] = '-c'; $args[] = implode( '; ', $commands );
   $proc = proc_open( $args, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
   if ( ! is_resource( $proc ) ) { return false; }
   fclose( $pipes[0] ); stream_set_blocking( $pipes[1], false ); stream_set_blocking( $pipes[2], false ); $output = ''; $deadline = microtime( true ) + 30; $exit = -1;
   do { $output .= stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] ); $state = proc_get_status( $proc ); if ( ! $state['running'] ) { $exit = $state['exitcode']; break; } if ( microtime( true ) > $deadline ) { proc_terminate( $proc, 9 ); break; } usleep( 100000 ); } while ( true );
   $output .= stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); proc_close( $proc );
   return 0 === $exit && ! preg_match( '/NT_STATUS_|ERRDOS|ERRSRV|failed|error|timed out/i', $output );
  } finally { @unlink( $auth ); }
 }
}
