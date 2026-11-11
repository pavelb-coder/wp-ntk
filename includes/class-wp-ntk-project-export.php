<?php
/** Синхронизация проектов в выделенном дереве SMB. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class WP_NTK_Project_Export {
 const OPTION = 'wp_ntk_project_export_config';
 const STATUS = 'wp_ntk_project_export_status';
 const MANIFEST = 'wp_ntk_project_export_manifest';
 const LOCK = 'wp_ntk_project_export_lock';
 const EVENT = 'wp_ntk_project_export_periodic';
 const CHANGE = 'wp_ntk_project_export_change';
 private $network;
 public function __construct( $network ) { $this->network = $network; }
 public function hooks() {
  add_action( 'admin_menu', array( $this, 'menu' ), 20 );
  add_action( 'admin_post_wp_ntk_project_export_settings', array( $this, 'save' ) );
  add_action( 'admin_post_wp_ntk_project_export_now', array( $this, 'manual' ) );
  add_action( self::EVENT, array( $this, 'run_auto' ) ); add_action( self::CHANGE, array( $this, 'run_auto' ) );
  add_action( 'wp_ntk_project_changed', array( $this, 'schedule_change' ) );
 }
 private function config() { $v = get_option( self::OPTION, array() ); return is_array( $v ) ? $v : array(); }
 private function allowed() { if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Недостаточно прав.', '', array( 'response' => 403 ) ); } }
 public function menu() { add_submenu_page( null, 'Экспорт проектов', 'Экспорт проектов', 'manage_options', 'wp-ntk-project-export', array( $this, 'page' ) ); }
 public function page() { $this->allowed(); $page = new WP_NTK_Project_Files(); $page->page(); }
 public function render_settings() {
  $this->allowed(); $c = $this->config(); $status = get_option( self::STATUS, array() );
  echo '<section id="wp-ntk-export"><hr><h2>Экспорт проектов в SMB</h2><p>Используются настройки подключения из раздела «Сетевая папка». Внутри неё создаётся отдельное дерево <code>wp-ntk-projects/site-ID/nomination-N/project-ID</code>. Номера номинаций соответствуют настройкам проектов. В каждой папке — данные проекта в UTF-8 JSON и файлы, имена которых указаны в JSON.</p>';
  echo '<p><strong>Важно:</strong> выгрузка содержит персональные данные и копии закрытых вложений. Доступ к сетевой папке настраивается отдельно от WordPress: разрешите его только уполномоченным лицам. Изменённые и удалённые вложения, а также проекты из корзины удаляются из выгрузки при успешной синхронизации; посторонние файлы плагин не удаляет. Используйте выделенную папку исключительно для этого сайта: зарезервированные имена файлов нельзя занимать вручную. Пустые папки могут остаться. Копии в резервных архивах не удаляются. При сбое возможна неполная выгрузка; проверяйте результат и делайте резервные копии.</p>';
  echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wp_ntk_project_export_settings">'; wp_nonce_field( 'wp_ntk_project_export_settings' );
  echo '<p><label><input type="checkbox" name="auto" value="1" ' . checked( ! empty( $c['auto'] ), true, false ) . '> Автоматически после изменения проекта и по расписанию</label></p><p><label>Периодичность: <select name="interval">';
  foreach ( array( 'hourly' => 'Каждый час', 'twicedaily' => 'Два раза в сутки', 'daily' => 'Раз в сутки' ) as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( isset( $c['interval'] ) ? $c['interval'] : 'daily', $key, false ) . '>' . esc_html( $label ) . '</option>'; }
  echo '</select></label></p><p>WP-Cron зависит от посещений сайта; для своевременного запуска настройте системный cron. Все активные проекты проверяются при каждом запуске.</p>'; submit_button( 'Сохранить' ); echo '</form>';
  echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wp_ntk_project_export_now">'; wp_nonce_field( 'wp_ntk_project_export_now' ); submit_button( 'Синхронизировать сейчас', 'secondary' ); echo '</form>';
  if ( ! empty( $status['message'] ) ) { echo '<p><strong>Последний результат:</strong> ' . esc_html( $status['message'] ) . ' (' . esc_html( $status['time'] ) . ' UTC)</p>'; } echo '</section>';
 }
 public function save() {
  $this->allowed(); check_admin_referer( 'wp_ntk_project_export_settings' );
  $interval = isset( $_POST['interval'] ) && is_string( $_POST['interval'] ) ? sanitize_key( wp_unslash( $_POST['interval'] ) ) : 'daily';
  if ( ! in_array( $interval, array( 'hourly', 'twicedaily', 'daily' ), true ) ) { wp_die( 'Некорректный интервал.' ); }
  $auto = empty( $_POST['auto'] ) ? 0 : 1; update_option( self::OPTION, array( 'auto' => $auto, 'interval' => $interval ), false );
  wp_clear_scheduled_hook( self::EVENT ); wp_clear_scheduled_hook( self::CHANGE );
  if ( $auto ) { wp_schedule_event( time() + 60, $interval, self::EVENT ); $this->schedule_change(); }
  wp_safe_redirect( admin_url( 'admin.php?page=wp-ntk-project-files' ) . '#wp-ntk-export' ); exit;
 }
 public function manual() { $this->allowed(); check_admin_referer( 'wp_ntk_project_export_now' ); $this->export(); wp_safe_redirect( admin_url( 'admin.php?page=wp-ntk-project-files' ) . '#wp-ntk-export' ); exit; }
 public function schedule_change() { $c = $this->config(); if ( ! empty( $c['auto'] ) && ! wp_next_scheduled( self::CHANGE ) ) { wp_schedule_single_event( time() + 20, self::CHANGE ); } }
 public function run_auto() { $c = $this->config(); if ( ! empty( $c['auto'] ) ) { $this->export(); } }
 private function status( $text ) { update_option( self::STATUS, array( 'message' => $text, 'time' => gmdate( 'Y-m-d H:i:s' ) ), false ); }
 private function command( $dirs, $cmd ) { return $this->network->project_command( $dirs, $cmd ); }
 private function ensure_dirs( $dirs ) {
  $parent = array();
  foreach ( $dirs as $dir ) {
   $path = array_merge( $parent, array( $dir ) );
   if ( ! $this->command( $path, 'ls' ) && ! $this->command( $parent, 'mkdir "' . $dir . '"' ) && ! $this->command( $path, 'ls' ) ) { return false; }
   $parent = $path;
  }
  return true;
 }
 private function put( $dirs, $name, $file ) {
  if ( ! preg_match( '/^[A-Za-z0-9_.-]+$/D', $name ) || false !== strpbrk( $file, "\";\\\r\n" ) || ! is_file( $file ) || is_link( $file ) || ! is_readable( $file ) ) { return false; }
  return $this->command( $dirs, 'put "' . $file . '" "' . $name . '"' );
 }
 private function store_manifest( $all ) { update_option( self::MANIFEST, $all, false ); return get_option( self::MANIFEST ) === $all; }
 private function export() {
  $old = get_option( self::LOCK ); if ( is_array( $old ) && ! empty( $old['time'] ) && (int) $old['time'] < time() - 3600 ) { delete_option( self::LOCK ); }
  $token = wp_generate_uuid4(); if ( ! add_option( self::LOCK, array( 'token' => $token, 'time' => time() ), '', 'no' ) ) { $this->status( 'Синхронизация уже выполняется.' ); return false; }
  $temp = false; $copies = array(); $ok = false; $count = 0;
  try {
   $cfg = get_option( WP_NTK_Network::CONFIG, array() ); if ( empty( $cfg['path'] ) ) { throw new RuntimeException( 'Не настроена сетевая папка.' ); }
   // Отдельный реестр для каждого сетевого пути: при смене адреса нельзя удалять файлы другого ресурса.
   $target = hash( 'sha256', strtolower( str_replace( '\\', '/', trim( $cfg['path'] ) ) ) );
   $all = get_option( self::MANIFEST, array() ); if ( ! is_array( $all ) ) { $all = array(); } if ( ! isset( $all[$target] ) || ! is_array( $all[$target] ) ) { $all[$target] = array(); }
   $wanted = array(); $base = array( 'wp-ntk-projects', 'site-' . get_current_blog_id() );
   if ( ! $this->ensure_dirs( $base ) ) { throw new RuntimeException( 'Не удалось создать папку выгрузки. Проверьте подключение и права.' ); }
   $projects = get_posts( array( 'post_type' => WP_NTK_Projects::TYPE, 'post_status' => 'private', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true ) );
   $conf = get_option( WP_NTK_Projects::OPTION, array() ); $names = isset( $conf['nominations'] ) && is_array( $conf['nominations'] ) ? $conf['nominations'] : array();
   foreach ( $projects as $p ) {
    $nom = (string) get_post_meta( $p->ID, '_wp_ntk_nomination', true ); if ( ! in_array( $nom, array( '1','2','3','4' ), true ) ) { throw new RuntimeException( 'У проекта некорректная номинация.' ); }
    $dirs = array_merge( $base, array( 'nomination-' . $nom, 'project-' . (int) $p->ID ) );
    if ( ! $this->ensure_dirs( $dirs ) ) { throw new RuntimeException( 'Не удалось создать папку проекта.' ); }
    $files = WP_NTK_Project_Files::files( $p->ID ); $list = array(); $uploads = array();
    foreach ( $files as $key => $f ) {
     if ( ! preg_match( '/^f_[a-f0-9]{12}$/D', $key ) || empty( $f['blob'] ) || ! preg_match( '/^[a-f0-9]{40}\\.bin$/D', $f['blob'] ) || empty( $f['ext'] ) || ! isset( WP_NTK_Project_Files::TYPES[$f['ext']] ) ) { throw new RuntimeException( 'Файл проекта или закрытое хранилище недоступны.' ); }
     $temporary = false; $path = WP_NTK_Project_Files::local_copy( $f, $temporary ); $remote = 'file-' . $key . '.' . $f['ext'];
     if ( ! $path ) { throw new RuntimeException( 'Файл проекта недоступен.' ); }
     if ( $temporary ) { $copies[] = $path; }
     $uploads[$remote] = $path; $list[] = array( 'раздел' => WP_NTK_Project_Files::section_label( $key, $f ), 'имя_оригинала' => isset( $f['name'] ) ? $f['name'] : '', 'имя_в_папке' => $remote );
    }
    $author = get_userdata( $p->post_author );
    $info = array( 'id_проекта' => $p->ID, 'номинация' => isset( $names[$nom] ) ? $names[$nom] : 'Номинация ' . $nom, 'номер_номинации' => $nom, 'название' => $p->post_title, 'описание' => $p->post_content, 'id_автора' => (int) $p->post_author, 'автор' => $author ? $author->display_name : '', 'email_аккаунта' => $author ? $author->user_email : '', 'дата_создания' => $p->post_date, 'файлы' => $list );
    $json = wp_json_encode( $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    $temp = tempnam( sys_get_temp_dir(), 'ntkproject_' ); if ( ! is_string( $json ) || ! $temp || ! chmod( $temp, 0600 ) || strlen( $json ) !== file_put_contents( $temp, $json ) ) { throw new RuntimeException( 'Не удалось подготовить данные проекта.' ); }
    $uploads['project.json'] = $temp;
    foreach ( $uploads as $name => $local ) {
     $relative = implode( '/', $dirs ) . '/' . $name; $wanted[$relative] = 1;
     // Не перезаписываем файл, который не зарегистрирован как созданный плагином.
     if ( ! isset( $all[$target][$relative] ) ) {
      $state = $this->network->project_command( $dirs, 'ls "' . $name . '"', true );
      if ( 'missing' !== $state ) { throw new RuntimeException( 'Целевой файл уже существует или его состояние неизвестно; используйте выделенную папку выгрузки.' ); }
     }
     // Сначала записываем намерение: даже при обрыве передачи файл можно будет очистить позже.
     $all[$target][$relative] = 1; if ( ! $this->store_manifest( $all ) ) { throw new RuntimeException( 'Не удалось сохранить реестр выгрузки.' ); }
     if ( ! $this->put( $dirs, $name, $local ) ) { throw new RuntimeException( 'Ошибка записи файла в сетевую папку.' ); }
    }
    @unlink( $temp ); $temp = false; foreach ( $copies as $copy ) { @unlink( $copy ); } $copies = array(); ++$count;
   }
   // Удаляем только файлы, которые плагин ранее занёс в реестр этого ресурса.
   foreach ( $all[$target] as $relative => $unused ) {
    if ( isset( $wanted[$relative] ) ) { continue; }
    if ( 0 !== strpos( $relative, implode( '/', $base ) . '/' ) || ! preg_match( '/^wp-ntk-projects\\/site-[0-9]+\\/nomination-[1-4]\\/project-[0-9]+\\/(?:project\\.json|file-f_[a-f0-9]{12}\\.(?:pdf|docx|xlsx|pptx|jpg|png))$/D', $relative ) ) { throw new RuntimeException( 'Некорректная запись реестра выгрузки.' ); }
    $parts = explode( '/', $relative ); $name = array_pop( $parts );
    if ( ! $this->command( $parts, 'del "' . $name . '"' ) ) { throw new RuntimeException( 'Не удалось удалить устаревший файл в сетевой папке.' ); }
    unset( $all[$target][$relative] ); if ( ! $this->store_manifest( $all ) ) { throw new RuntimeException( 'Не удалось обновить реестр выгрузки.' ); }
   }
   $ok = true; $this->status( 'Синхронизация завершена. Проектов: ' . $count . '.' );
  } catch ( Throwable $e ) { $this->status( 'Ошибка синхронизации: ' . $e->getMessage() ); }
  finally { if ( $temp && is_file( $temp ) ) { @unlink( $temp ); } foreach ( $copies as $copy ) { @unlink( $copy ); } $lock = get_option( self::LOCK ); if ( is_array( $lock ) && isset( $lock['token'] ) && $token === $lock['token'] ) { delete_option( self::LOCK ); } }
  return $ok;
 }
}
