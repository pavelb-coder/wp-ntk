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
 const DELETE_RETRY = 'wp_ntk_project_export_delete_retry';
 private $network;
 private $target;
 public function __construct( $network ) { $this->network = $network; }
 public function hooks() {
  add_action( 'admin_menu', array( $this, 'menu' ), 20 );
  add_action( 'admin_post_wp_ntk_project_export_settings', array( $this, 'save' ) );
  add_action( 'admin_post_wp_ntk_project_export_now', array( $this, 'manual' ) );
  add_action( self::EVENT, array( $this, 'run_auto' ) ); add_action( self::CHANGE, array( $this, 'run_auto' ) );
  add_action( 'wp_ntk_project_changed', array( $this, 'schedule_change' ) );
  add_action( self::DELETE_RETRY, array( $this, 'retry_deleted_file' ) );
  add_action( 'wp_ntk_mentor_profile_updated', array( $this, 'schedule_change' ) );
  add_action( 'profile_update', array( $this, 'schedule_change' ) );
  add_action( 'deleted_user', array( $this, 'schedule_change' ) );
 }
 private function config() { $v = get_option( self::OPTION, array() ); return is_array( $v ) ? $v : array(); }
 private function allowed() { if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Недостаточно прав.', '', array( 'response' => 403 ) ); } }
 public function menu() { add_submenu_page( null, 'Экспорт проектов', 'Экспорт проектов', 'manage_options', 'wp-ntk-project-export', array( $this, 'page' ) ); }
 public function page() { $this->allowed(); $page = new WP_NTK_Project_Files(); $page->page(); }
 public function render_settings() {
  $this->allowed(); $c = $this->config(); $status = get_option( self::STATUS, array() );
  echo '<section id="wp-ntk-export"><hr><h2>Экспорт проектов в SMB</h2><p>Используются настройки подключения из раздела «Сетевая папка». Папки номинаций создаются прямо в этой папке по названиям из настроек проектов: <code>Название номинации/Название проекта</code>. Имена проектов и файлов сохраняются по возможности; недопустимые символы заменяются, длинные имена сокращаются, совпадающие названия номинаций объединяются в одну папку, а при совпадениях названий проектов добавляется суффикс. В каждой папке — данные проекта в XLSX и PDF, анкета наставника в XLSX и вложения. Для создания XLSX нужны ZipArchive, для PDF — iconv и zlib. Временная папка PHP должна быть недоступна по HTTP. Папка <code>wp-ntk-private</code> — основное хранилище, она не является экспортом и не копируется при выгрузке.</p>';
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
  wp_safe_redirect( admin_url( 'admin.php?page=wp-ntk-projects&tab=files' ) . '#wp-ntk-export' ); exit;
 }
 public function manual() { $this->allowed(); check_admin_referer( 'wp_ntk_project_export_now' ); $this->export(); wp_safe_redirect( admin_url( 'admin.php?page=wp-ntk-projects&tab=files' ) . '#wp-ntk-export' ); exit; }
 public function schedule_change() { $c = $this->config(); if ( ! empty( $c['auto'] ) && ! wp_next_scheduled( self::CHANGE ) ) { wp_schedule_single_event( time() + 20, self::CHANGE ); } }
 /** Удаление вложения запускает синхронизацию независимо от флага автоэкспорта. */
 public function sync_deleted_file() {
  $cfg = get_option( WP_NTK_Network::CONFIG, array() );
  if ( empty( $cfg['path'] ) || ! is_string( $cfg['path'] ) ) { return true; }
  $target = hash( 'sha256', strtolower( str_replace( '\\', '/', trim( $cfg['path'] ) ) ) );
  $all = get_option( self::MANIFEST, array() );
  if ( ! is_array( $all ) || empty( $all[$target] ) ) { return true; }
  if ( $this->export() ) { return true; }
  if ( ! wp_next_scheduled( self::DELETE_RETRY ) ) { wp_schedule_single_event( time() + 60, self::DELETE_RETRY ); }
  return false;
 }
 public function retry_deleted_file() { $this->sync_deleted_file(); }
 public function run_auto() { $c = $this->config(); if ( ! empty( $c['auto'] ) ) { $this->export(); } }
 private function status( $text ) { update_option( self::STATUS, array( 'message' => $text, 'time' => gmdate( 'Y-m-d H:i:s' ) ), false ); }
 private function command( $dirs, $cmd ) { return $this->network->project_command( $dirs, $cmd, false, $this->target ); }
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
  if ( ! WP_NTK_Network::valid_project_dir( $name ) || false !== strpbrk( $file, "\";\\\r\n" ) || ! is_file( $file ) || is_link( $file ) || ! is_readable( $file ) ) { return false; }
  return $this->command( $dirs, 'put "' . $file . '" "' . $name . '"' );
 }
 /** Названия безопасны для Windows и команд smbclient; длина ограничена байтами UTF-8. */
 private function nomination_dirs( $names ) {
  $result = array(); $used = array();
  foreach ( $names as $i => $name ) {
   if ( ! ctype_digit( (string) $i ) || (int) $i < 1 || ! is_string( $name ) ) { throw new RuntimeException( 'Некорректные настройки номинации.' ); }
   $name = preg_replace( '/[\x00-\x1f\x7f\\\\\/:*?"<>|;]/u', '_', $name );
   if ( ! is_string( $name ) ) { throw new RuntimeException( 'Некорректная кодировка названия номинации.' ); }
   $name = trim( $name, " .\t\r\n" );
   if ( '' === $name || preg_match( '/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $name ) || in_array( strtolower( $name ), array( 'wp-ntk-private', 'wp-ntk-projects' ), true ) ) { $name = 'Номинация ' . $i; }
   preg_match_all( '/./us', $name, $chars ); $name = ''; foreach ( $chars[0] as $char ) { if ( strlen( $name . $char ) > 170 ) { break; } $name .= $char; }
   $name = rtrim( $name, ' .' ); if ( '' === $name ) { $name = 'Номинация ' . $i; }
   $folded = $this->fold( $name );
   // Совпадающие после очистки имена используют один и тот же каталог.
   if ( ! isset( $used[$folded] ) ) { $used[$folded] = $name; }
   $candidate = $used[$folded];
   if ( ! WP_NTK_Network::valid_project_dir( $candidate ) ) { throw new RuntimeException( 'Некорректное название папки номинации.' ); }
   $result[(string) $i] = $candidate;
  }
  return $result;
 }
 /** Сохраняем читаемые имена, исключая запрещённые SMB-символы и управляющие коды. */
 private function safe_component( $name, $fallback, $limit = 170 ) {
  $name = is_string( $name ) ? preg_replace( '/[\x00-\x1f\x7f\\\\\/:*?"<>|;]/u', '_', $name ) : '';
  if ( ! is_string( $name ) ) { throw new RuntimeException( 'Некорректная кодировка имени.' ); }
  $name = trim( $name, " .\t\r\n" );
  if ( '' === $name || preg_match( '/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $name ) ) { $name = $fallback; }
  preg_match_all( '/./us', $name, $chars ); $result = ''; foreach ( $chars[0] as $char ) { if ( strlen( $result . $char ) > $limit ) { break; } $result .= $char; }
  $result = rtrim( $result, ' .' ); if ( '' === $result ) { $result = $fallback; }
  if ( ! WP_NTK_Network::valid_project_dir( $result ) ) { throw new RuntimeException( 'Недопустимое имя файла или папки.' ); }
  return $result;
 }
 private function fold( $name ) {
  if ( function_exists( 'mb_strtolower' ) ) { return mb_strtolower( $name, 'UTF-8' ); }
  $upper = preg_split( '//u', 'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ', -1, PREG_SPLIT_NO_EMPTY );
  $lower = preg_split( '//u', 'абвгдеёжзийклмнопрстуфхцчшщъыьэюя', -1, PREG_SPLIT_NO_EMPTY );
  return strtolower( strtr( $name, array_combine( $upper, $lower ) ) );
 }
 private function project_dirs( $projects, $nomination_dirs ) {
  $used = array(); $out = array(); $cross_nomination = array();
  foreach ( $projects as $p ) {
   $nom = (string) get_post_meta( $p->ID, '_wp_ntk_nomination', true );
   if ( ! isset( $nomination_dirs[$nom] ) ) { throw new RuntimeException( 'У проекта ID ' . (int) $p->ID . ' неизвестная номинация.' ); }
   $group = $this->fold( $nomination_dirs[$nom] ); $title = $this->safe_component( $p->post_title, 'Проект ' . (int) $p->ID, 160 );
   $cross_nomination[$group][$this->fold( $title )][$nom] = true;
  }
  foreach ( $projects as $p ) {
   $nom = (string) get_post_meta( $p->ID, '_wp_ntk_nomination', true );
   if ( ! ctype_digit( $nom ) || (int) $nom < 1 ) { throw new RuntimeException( 'У проекта ID ' . (int) $p->ID . ' некорректная номинация.' ); }
   if ( ! isset( $nomination_dirs[$nom] ) ) { throw new RuntimeException( 'У проекта ID ' . (int) $p->ID . ' неизвестная номинация.' ); }
   $group = $this->fold( $nomination_dirs[$nom] ); $title = $this->safe_component( $p->post_title, 'Проект ' . (int) $p->ID, 160 );
   // При слиянии номинаций не перезаписываем прежнюю папку другого проекта с тем же названием.
   $cross = count( $cross_nomination[$group][$this->fold( $title )] ) > 1;
   $candidate = $cross ? $title . ' (ID ' . (int) $p->ID . ')' : $title; $n = 0;
   while ( isset( $used[$group][$this->fold( $candidate )] ) ) { $candidate = $title . ' (ID ' . (int) $p->ID . ( $n ? '-' . $n : '' ) . ')'; ++$n; }
   $used[$group][$this->fold( $candidate )] = true; $out[(int) $p->ID] = $candidate;
  }
  return $out;
 }
 private function export_filename( $f, $key, &$used ) {
  $ext = $f['ext']; $source = isset( $f['name'] ) && is_string( $f['name'] ) ? $f['name'] : '';
  $original_ext = pathinfo( $source, PATHINFO_EXTENSION );
  if ( strtolower( $original_ext ) === $ext || ( 'jpg' === $ext && 'jpeg' === strtolower( $original_ext ) ) ) { $suffix = '.' . $original_ext; $stem = substr( $source, 0, -strlen( $suffix ) ); }
  else { $suffix = '.' . $ext; $stem = $source; }
  $stem = $this->safe_component( $stem, 'file-' . $key, 160 ); $name = $stem . $suffix; $i = 2;
  while ( isset( $used[$this->fold( $name )] ) ) { $name = $stem . ' (' . $i++ . ')' . $suffix; }
  if ( ! WP_NTK_Network::valid_project_dir( $name ) ) { throw new RuntimeException( 'Некорректное имя вложения.' ); }
  $used[$this->fold( $name )] = true; return $name;
 }
 private function store_manifest( $all ) { update_option( self::MANIFEST, $all, false ); return get_option( self::MANIFEST ) === $all; }
 private function export() {
  $old = get_option( self::LOCK ); if ( is_array( $old ) && ! empty( $old['time'] ) && (int) $old['time'] < time() - 3600 ) { delete_option( self::LOCK ); }
  $token = wp_generate_uuid4(); if ( ! add_option( self::LOCK, array( 'token' => $token, 'time' => time() ), '', 'no' ) ) { $this->status( 'Синхронизация уже выполняется.' ); return false; }
  $copies = array(); $documents = array(); $ok = false; $count = 0;
  try {
   $cfg = get_option( WP_NTK_Network::CONFIG, array() ); if ( empty( $cfg['path'] ) ) { throw new RuntimeException( 'Не настроена сетевая папка.' ); }
   // Отдельный реестр для каждого сетевого пути: при смене адреса нельзя удалять файлы другого ресурса.
   $target = hash( 'sha256', strtolower( str_replace( '\\', '/', trim( $cfg['path'] ) ) ) ); $this->target = $target;
   $all = get_option( self::MANIFEST, array() ); if ( ! is_array( $all ) ) { $all = array(); } if ( ! isset( $all[$target] ) || ! is_array( $all[$target] ) ) { $all[$target] = array(); }
   $wanted = array(); $old_base = array( 'wp-ntk-projects', 'site-' . (int) get_current_blog_id() );
   $projects = get_posts( array( 'post_type' => WP_NTK_Projects::TYPE, 'post_status' => 'private', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true ) );
   $conf = get_option( WP_NTK_Projects::OPTION, array() ); $active_names = isset( $conf['nominations'] ) && is_array( $conf['nominations'] ) ? $conf['nominations'] : array( 1 => 'Номинация 1', 2 => 'Номинация 2', 3 => 'Номинация 3', 4 => 'Номинация 4' ); $names = array_replace( isset( $conf['retired_nominations'] ) && is_array( $conf['retired_nominations'] ) ? $conf['retired_nominations'] : array(), $active_names ); $nomination_dirs = $this->nomination_dirs( $names );
   foreach ( $active_names as $active_id => $unused ) { $dir = $nomination_dirs[$active_id]; if ( ! $this->ensure_dirs( array( $dir ) ) ) { throw new RuntimeException( 'Не удалось создать папку номинации. Проверьте подключение и права.' ); } }
   $project_dirs = $this->project_dirs( $projects, $nomination_dirs );
   foreach ( $projects as $p ) {
    $nom = (string) get_post_meta( $p->ID, '_wp_ntk_nomination', true ); if ( ! ctype_digit( $nom ) || ! isset( $nomination_dirs[$nom] ) ) { throw new RuntimeException( 'У проекта ID ' . (int) $p->ID . ' неизвестная номинация. Проверьте настройки.' ); }
    $dirs = array( $nomination_dirs[$nom], $project_dirs[(int) $p->ID] );
    if ( ! $this->ensure_dirs( $dirs ) ) { throw new RuntimeException( 'Не удалось создать папку проекта.' ); }
    $files = WP_NTK_Project_Files::files( $p->ID ); ksort( $files ); $list = array(); $uploads = array();
    $doc_stem = $this->safe_component( $p->post_title, 'Проект ' . (int) $p->ID, 160 );
    $xlsx_name = $doc_stem . '.xlsx'; $pdf_name = $doc_stem . '.pdf';
    // При названии «наставник» резервируем имя анкеты и добавляем суффикс документу проекта.
    if ( $this->fold( $xlsx_name ) === $this->fold( 'наставник.xlsx' ) ) { $xlsx_name = $doc_stem . ' (проект).xlsx'; }
    $used_names = array( $this->fold( $xlsx_name ) => true, $this->fold( $pdf_name ) => true, $this->fold( 'наставник.xlsx' ) => true );
    foreach ( $files as $key => $f ) {
     if ( ! preg_match( '/^f_[a-f0-9]{12}$/D', $key ) || empty( $f['blob'] ) || ! preg_match( '/^[a-f0-9]{40}\\.bin$/D', $f['blob'] ) || empty( $f['ext'] ) || ! isset( WP_NTK_Project_Files::TYPES[$f['ext']] ) ) { throw new RuntimeException( 'Файл проекта или закрытое хранилище недоступны.' ); }
     $temporary = false; $path = WP_NTK_Project_Files::local_copy( $f, $temporary );
     if ( ! $path ) { throw new RuntimeException( 'Не удалось прочитать вложение проекта ID ' . (int) $p->ID . '. Проверьте доступ к SMB-хранилищу и закрытому временному каталогу PHP.' ); }
     if ( $temporary ) { $copies[] = $path; }
     $remote = $this->export_filename( $f, $key, $used_names );
     $uploads[$remote] = $path; $list[] = array( 'раздел' => WP_NTK_Project_Files::section_label( $key, $f ), 'имя_оригинала' => isset( $f['name'] ) ? $f['name'] : '', 'имя_в_папке' => $remote );
    }
    $author = get_userdata( $p->post_author );
    $project_rows = array(
     array( 'Название проекта', $p->post_title ),
     array( 'Номинация', isset( $names[$nom] ) ? $names[$nom] : 'Номинация ' . $nom ),
     array( 'Дата создания', $p->post_date )
    );
    $extra_fields = WP_NTK_Project_Fields::ordered(); $extra_values = WP_NTK_Project_Fields::values( $p->ID, $extra_fields );
    foreach ( $extra_fields as $field_key => $field ) { $project_rows[] = array( $field['label'], WP_NTK_Project_Fields::display_value( $field, $extra_values[$field_key] ) ); }
    if ( $list ) { foreach ( $list as $item ) { $project_rows[] = array( 'Вложение: ' . $item['раздел'], $item['имя_оригинала'] . ' (в папке: ' . $item['имя_в_папке'] . ')' ); } }
    else { $project_rows[] = array( 'Вложения', 'Нет' ); }
    $mentor_rows = array( array( 'ID наставника', (string) $p->post_author ), array( 'Имя пользователя', $author ? $author->display_name : 'Учётная запись недоступна' ), array( 'Email аккаунта', $author ? $author->user_email : '' ), array( 'Дата регистрации', $author ? $author->user_registered : '' ) );
    $field_defs = WP_NTK_Fields::all(); $values = $author ? WP_NTK_Fields::get_values( $p->post_author ) : array();
    foreach ( $field_defs as $field_key => $field ) { $mentor_rows[] = array( $field['label'], isset( $values[$field_key] ) ? $values[$field_key] : '' ); }
    foreach ( array( $xlsx_name, $pdf_name, 'наставник.xlsx' ) as $doc_name ) {
     $doc = WP_NTK_Project_Files::create_temp_file(); if ( ! $doc ) { throw new RuntimeException( 'Закрытый временный каталог PHP недоступен или расположен в публичной области сайта.' ); }
     $documents[] = $doc;
     if ( $pdf_name === $doc_name ) { WP_NTK_Export_Documents::pdf( $doc, $project_rows ); }
     else { WP_NTK_Export_Documents::xlsx( $doc, 'наставник.xlsx' === $doc_name ? $mentor_rows : $project_rows, 'наставник.xlsx' === $doc_name ? 'Наставник' : 'Проект' ); }
     $uploads[$doc_name] = $doc;
    }
    foreach ( $uploads as $name => $local ) {
     $relative = implode( '/', $dirs ) . '/' . $name; $wanted[$relative] = 1;
     // Не перезаписываем файл, который не зарегистрирован как созданный плагином.
     if ( ! isset( $all[$target][$relative] ) ) {
      $state = $this->network->project_command( $dirs, 'ls "' . $name . '"', true, $target );
      if ( 'missing' !== $state ) { throw new RuntimeException( 'Целевой файл уже существует или его состояние неизвестно; используйте выделенную папку выгрузки.' ); }
     }
     // Сначала записываем намерение: даже при обрыве передачи файл можно будет очистить позже.
     $all[$target][$relative] = 1; if ( ! $this->store_manifest( $all ) ) { throw new RuntimeException( 'Не удалось сохранить реестр выгрузки.' ); }
     if ( ! $this->put( $dirs, $name, $local ) ) { throw new RuntimeException( 'Ошибка записи файла проекта ID ' . (int) $p->ID . ' в сетевую папку. Проверьте SMB-соединение и права записи.' ); }
    }
    foreach ( $copies as $copy ) { @unlink( $copy ); } $copies = array(); foreach ( $documents as $doc ) { @unlink( $doc ); } $documents = array(); ++$count;
   }
   // Удаляем только известные реестру файлы, в том числе из структуры прежних версий.
   $empty_dirs = array();
   foreach ( $all[$target] as $relative => $unused ) {
    if ( isset( $wanted[$relative] ) ) { continue; }
    $parts = explode( '/', $relative ); $name = array_pop( $parts );
    $file_ok = WP_NTK_Network::valid_project_dir( $name ) && ( 'project.json' === $name || (bool) preg_match( '/\.(?:pdf|docx|xlsx|pptx|jpe?g|png)$/iD', $name ) );
    $old_ok = 4 === count( $parts ) && $parts[0] === $old_base[0] && $parts[1] === $old_base[1] && preg_match( '/^nomination-[1-4]$/D', $parts[2] ) && preg_match( '/^project-[0-9]+$/D', $parts[3] );
    $new_ok = 2 === count( $parts ) && WP_NTK_Network::valid_project_dir( $parts[0] ) && ! in_array( strtolower( $parts[0] ), array( 'wp-ntk-private', 'wp-ntk-projects' ), true ) && WP_NTK_Network::valid_project_dir( $parts[1] );
    if ( ! $file_ok || ( ! $old_ok && ! $new_ok ) ) { throw new RuntimeException( 'Некорректная запись реестра выгрузки.' ); }
    if ( ! $this->command( $parts, 'del "' . $name . '"' ) ) { throw new RuntimeException( 'Не удалось удалить устаревший файл в сетевой папке.' ); }
    unset( $all[$target][$relative] ); if ( ! $this->store_manifest( $all ) ) { throw new RuntimeException( 'Не удалось обновить реестр выгрузки.' ); }
    while ( $parts ) { $empty_dirs[implode( '/', $parts )] = $parts; array_pop( $parts ); }
   }
   // Пытаемся убрать только пустые каталоги; любые посторонние данные сохраняются.
   uasort( $empty_dirs, function( $a, $b ) { return count( $b ) - count( $a ); } );
   foreach ( $empty_dirs as $parts ) { if ( 1 === count( $parts ) && in_array( $parts[0], $nomination_dirs, true ) ) { continue; } $name = array_pop( $parts ); $this->command( $parts, 'rmdir "' . $name . '"' ); }
   $ok = true; $this->status( 'Синхронизация завершена. Проектов: ' . $count . '.' );
  } catch ( Throwable $e ) { $this->status( 'Ошибка синхронизации: ' . $e->getMessage() ); }
  finally { $this->target = null; foreach ( $copies as $copy ) { @unlink( $copy ); } foreach ( $documents as $doc ) { @unlink( $doc ); } $lock = get_option( self::LOCK ); if ( is_array( $lock ) && isset( $lock['token'] ) && $token === $lock['token'] ) { delete_option( self::LOCK ); } }
  return $ok;
 }
}
