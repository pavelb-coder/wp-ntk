<?php
/** Закрытые вложения проектов. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class WP_NTK_Project_Files {
 const OPTION = 'wp_ntk_project_file_sections';
 const META = '_wp_ntk_project_files';
 const ARCHIVE_META = '_wp_ntk_unavailable_file_history';
 const DELETED_LABELS = 'wp_ntk_deleted_file_section_labels';
 const TYPES = array( 'pdf' => 'PDF', 'docx' => 'DOCX', 'xlsx' => 'XLSX', 'pptx' => 'PPTX', 'jpg' => 'JPG', 'png' => 'PNG' );
 public function hooks() {
  add_action( 'admin_menu', array( $this, 'menu' ), 20 );
  add_action( 'admin_post_wp_ntk_file_sections', array( $this, 'save_sections' ) );
  add_action( 'admin_post_wp_ntk_delete_file_section', array( $this, 'delete_section' ) );
  add_action( 'admin_post_wp_ntk_project_download', array( $this, 'download' ) );
  add_action( 'before_delete_post', array( $this, 'delete_project_files' ), 10, 2 );
 }
 public static function sections() { $v = get_option( self::OPTION, array() ); return is_array( $v ) ? $v : array(); }
 public static function section_label( $key, $file ) { $sections = self::sections(); $deleted = get_option( self::DELETED_LABELS, array() ); if ( isset( $sections[$key]['name'] ) ) { return $sections[$key]['name']; } if ( is_array( $deleted ) && isset( $deleted[$key] ) ) { return $deleted[$key]; } return isset( $file['section'] ) ? $file['section'] : ''; }
 public static function files( $id ) { $v = $id ? get_post_meta( $id, self::META, true ) : array(); return is_array( $v ) ? $v : array(); }
 /** Каталог задаётся вне публичных директорий и заранее создаётся администратором сервера. */
 public static function root() {
  if ( ! defined( 'WP_NTK_PRIVATE_DIR' ) || ! is_string( WP_NTK_PRIVATE_DIR ) || '' === WP_NTK_PRIVATE_DIR ) { return false; }
  $root = realpath( WP_NTK_PRIVATE_DIR );
  $doc = isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( $_SERVER['DOCUMENT_ROOT'] ) : false;
  if ( ! $root || ! $doc || ! is_dir( $root ) || ! is_writable( $root ) ) { return false; }
  foreach ( array( $doc, realpath( ABSPATH ), realpath( WP_CONTENT_DIR ) ) as $public ) {
   if ( $public && ( $root === $public || 0 === strpos( $root . DIRECTORY_SEPARATOR, rtrim( $public, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) || 0 === strpos( $public . DIRECTORY_SEPARATOR, rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) ) { return false; }
  }
  return $root;
 }
 public function menu() { add_submenu_page( null, 'Файлы проектов', 'Файлы проектов', 'manage_options', 'wp-ntk-project-files', array( $this, 'page' ) ); }
 private function allowed() { if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Недостаточно прав.', '', array( 'response' => 403 ) ); } }
 private function section_input( $row ) {
  if ( ! is_array( $row ) ) { wp_die( 'Некорректные настройки раздела.' ); }
  $name = isset( $row['name'] ) && is_string( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
  $max = isset( $row['max'] ) && is_string( $row['max'] ) ? $row['max'] : '';
  $types = isset( $row['types'] ) && is_array( $row['types'] ) ? array_values( array_intersect( array_keys( self::TYPES ), $row['types'] ) ) : array();
  if ( '' === $name || WP_NTK_Fields::length( $name ) > 150 || ! ctype_digit( $max ) || (int) $max < 1 || (int) $max > 50 || ! $types ) { wp_die( 'Укажите название, форматы и размер от 1 до 50 МБ для каждого раздела.' ); }
  return array( 'name' => $name, 'max' => (int) $max, 'types' => $types, 'required' => empty( $row['required'] ) ? 0 : 1, 'active' => empty( $row['active'] ) ? 0 : 1 );
 }
 public function save_sections() {
  $this->allowed(); check_admin_referer( 'wp_ntk_file_sections' );
  $stored = self::sections(); $posted = isset( $_POST['sections'] ) && is_array( $_POST['sections'] ) ? wp_unslash( $_POST['sections'] ) : array(); $out = array();
  foreach ( $stored as $key => $old ) { if ( ! preg_match( '/^f_[a-f0-9]{12}$/D', $key ) || ! isset( $posted[$key] ) ) { wp_die( 'Некорректные настройки разделов.' ); } $out[$key] = $this->section_input( $posted[$key] ); }
  $new = isset( $_POST['new_section'] ) && is_array( $_POST['new_section'] ) ? wp_unslash( $_POST['new_section'] ) : array();
  if ( ! empty( $new['name'] ) ) {
   if ( count( $out ) >= 12 ) { wp_die( 'Допускается не более 12 разделов.' ); }
   $section = $this->section_input( $new ); $key = 'f_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
   if ( isset( $out[$key] ) ) { wp_die( 'Не удалось создать раздел, попробуйте ещё раз.' ); }
   $out[$key] = $section;
  }
  update_option( self::OPTION, $out, false ); do_action( 'wp_ntk_project_changed' ); wp_safe_redirect( admin_url( 'admin.php?page=wp-ntk-projects&tab=files&saved=1' ) ); exit;
 }
 /** Удаляется только настройка раздела; метаданные и ранее загруженные файлы остаются. */
 public function delete_section() {
  $this->allowed();
  $key = isset( $_POST['section'] ) && is_string( $_POST['section'] ) ? wp_unslash( $_POST['section'] ) : '';
  if ( ! preg_match( '/^f_[a-f0-9]{12}$/D', $key ) ) { wp_die( 'Некорректный раздел.', '', array( 'response' => 400 ) ); }
  check_admin_referer( 'wp_ntk_delete_file_section_' . $key );
  $sections = self::sections(); if ( ! isset( $sections[$key] ) ) { wp_die( 'Раздел не найден.', '', array( 'response' => 404 ) ); }
  $deleted = get_option( self::DELETED_LABELS, array() ); if ( ! is_array( $deleted ) ) { $deleted = array(); }
  $deleted[$key] = $sections[$key]['name']; update_option( self::DELETED_LABELS, $deleted, false );
  if ( get_option( self::DELETED_LABELS ) !== $deleted ) { wp_die( 'Не удалось сохранить название раздела. Попробуйте ещё раз.' ); }
  unset( $sections[$key] ); update_option( self::OPTION, $sections, false );
  if ( self::sections() !== $sections ) { wp_die( 'Не удалось удалить раздел. Попробуйте ещё раз.' ); }
  do_action( 'wp_ntk_project_changed' );
  wp_safe_redirect( admin_url( 'admin.php?page=wp-ntk-projects&tab=files&section_deleted=1' ) ); exit;
 }
 private function row( $prefix, $section ) {
  echo '<p><label>Название <input class="regular-text" name="' . esc_attr( $prefix . '[name]' ) . '" maxlength="150" value="' . esc_attr( $section['name'] ) . '"></label> ';
  echo '<label>До <input type="number" name="' . esc_attr( $prefix . '[max]' ) . '" min="1" max="50" value="' . (int) $section['max'] . '" style="width:70px"> МБ</label> ';
  echo '<label><input type="checkbox" name="' . esc_attr( $prefix . '[required]' ) . '" value="1" ' . checked( ! empty( $section['required'] ), true, false ) . '> Обязательно</label> ';
  echo '<label><input type="checkbox" name="' . esc_attr( $prefix . '[active]' ) . '" value="1" ' . checked( ! empty( $section['active'] ), true, false ) . '> Показывать раздел</label></p><p>Форматы: ';
  foreach ( self::TYPES as $key => $label ) { echo '<label style="margin-right:12px"><input type="checkbox" name="' . esc_attr( $prefix . '[types][]' ) . '" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $section['types'], true ), true, false ) . '> ' . esc_html( $label ) . '</label>'; }
  echo '</p>';
 }
 public function page() {
  $this->allowed(); $sections = self::sections();
  echo '<div class="wrap"><h1>Файлы проектов</h1>';
  if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>Разделы сохранены.</p></div>'; }
  if ( isset( $_GET['section_deleted'] ) ) { echo '<div class="notice notice-success"><p>Раздел удалён. Ранее загруженные файлы сохранены.</p></div>'; }
  if ( ! self::smb_ready( true ) ) { echo '<div class="notice notice-error"><p>Сетевая папка недоступна для хранения вложений. Проверьте настройки раздела «Сетевая папка», smbclient и права записи. Старые файлы, загруженные в локальную закрытую папку, остаются в ней.</p></div>'; }
  else { echo '<div class="notice notice-success"><p>Сетевая папка для новых вложений доступна. Ограничьте доступ к ней средствами SMB.</p></div>'; }
  echo '<p>По одному файлу в каждом включённом разделе. Изменения и удаление файлов возможны только до окончания приёма. Выключенный раздел скрывается из форм; ранее загруженные файлы сохраняются. Ниже можно настроить экспорт в SMB. При удалении раздела ранее загруженные файлы сохраняются и доступны для просмотра, но раздел исчезает из формы. Ограничения PHP и веб-сервера могут быть ниже указанных.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wp_ntk_file_sections">'; wp_nonce_field( 'wp_ntk_file_sections' );
  foreach ( $sections as $key => $section ) { echo '<hr><h2>Редактировать: ' . esc_html( $section['name'] ) . '</h2>'; $this->row( 'sections[' . $key . ']', $section ); echo '<p><button class="button button-link-delete" type="submit" form="wp-ntk-delete-' . esc_attr( $key ) . '" onclick="return confirm(&quot;Удалить раздел? Ранее загруженные файлы останутся. Несохранённые изменения в настройках разделов будут потеряны.&quot;);">Удалить раздел</button></p>'; }
  echo '<hr><h2>Добавить раздел</h2><p>Оставьте название пустым, если не добавляете раздел.</p>'; $this->row( 'new_section', array( 'name' => '', 'max' => 10, 'required' => 0, 'active' => 1, 'types' => array( 'pdf' ) ) );
  submit_button( 'Сохранить разделы' ); echo '</form>';
  foreach ( $sections as $key => $section ) { echo '<form id="wp-ntk-delete-' . esc_attr( $key ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wp_ntk_delete_file_section"><input type="hidden" name="section" value="' . esc_attr( $key ) . '">'; wp_nonce_field( 'wp_ntk_delete_file_section_' . $key ); echo '</form>'; }
  $export = new WP_NTK_Project_Export( new WP_NTK_Network() ); $export->render_settings();
  echo '</div>';
 }
 private static function valid_file( $tmp, $ext ) {
  if ( ! is_uploaded_file( $tmp ) || ! function_exists( 'finfo_open' ) ) { return false; }
  $fi = finfo_open( FILEINFO_MIME_TYPE ); if ( ! $fi ) { return false; } $mime = finfo_file( $fi, $tmp ); finfo_close( $fi );
  if ( 'pdf' === $ext ) { $h = fopen( $tmp, 'rb' ); $magic = $h ? fread( $h, 5 ) : ''; if ( $h ) { fclose( $h ); } return 'application/pdf' === $mime && '%PDF-' === $magic; }
  if ( in_array( $ext, array( 'jpg','png' ), true ) ) { $img = @getimagesize( $tmp ); return is_array( $img ) && isset( $img['mime'] ) && $mime === $img['mime'] && $mime === ( 'jpg' === $ext ? 'image/jpeg' : 'image/png' ); }
  if ( ! in_array( $ext, array( 'docx','xlsx','pptx' ), true ) || ! class_exists( 'ZipArchive' ) || ! in_array( $mime, array( 'application/zip', 'application/octet-stream', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.openxmlformats-officedocument.presentationml.presentation' ), true ) ) { return false; }
  $zip = new ZipArchive(); if ( true !== $zip->open( $tmp, ZipArchive::RDONLY ) ) { return false; }
  $target = array( 'docx'=>'word/document.xml', 'xlsx'=>'xl/workbook.xml', 'pptx'=>'ppt/presentation.xml' );
  $ok = false !== $zip->locateName( '[Content_Types].xml' ) && false !== $zip->locateName( $target[$ext] ); $zip->close(); return $ok;
 }
 /** Идентификатор ресурса не позволяет незаметно читать файлы с другого SMB-пути. */
 private static function target() {
  $config = get_option( WP_NTK_Network::CONFIG, array() );
  if ( ! is_array( $config ) || empty( $config['path'] ) || ! is_string( $config['path'] ) ) { return false; }
  return hash( 'sha256', strtolower( str_replace( '\\', '/', trim( $config['path'] ) ) ) );
 }
 private static function smb_dirs() { return array( 'wp-ntk-private', 'site-' . (int) get_current_blog_id() ); }
 private static function smb_ready( $create = false, $target = null ) {
  if ( null === $target ) { $target = self::target(); }
  if ( ! $target ) { return false; }
  $net = new WP_NTK_Network(); $parent = array();
  foreach ( self::smb_dirs() as $dir ) {
   $path = array_merge( $parent, array( $dir ) );
   if ( ! $net->project_command( $path, 'ls', false, $target ) ) {
    if ( ! $create || ( ! $net->project_command( $parent, 'mkdir "' . $dir . '"', false, $target ) && ! $net->project_command( $path, 'ls', false, $target ) ) ) { return false; }
   }
   $parent = $path;
  }
  return true;
 }
 private static function valid_blob( $blob ) { return is_string( $blob ) && (bool) preg_match( '/^[a-f0-9]{40}\\.bin$/D', $blob ); }
 private static function remote_path_safe( $path ) { return is_string( $path ) && false === strpbrk( $path, "\";\\\r\n" ); }
 /** Защищённый временный файл нужен только на время чтения из SMB. */
 private static function temporary() {
  $dir = realpath( sys_get_temp_dir() ); $doc = ! empty( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( $_SERVER['DOCUMENT_ROOT'] ) : false;
  if ( ! $dir || ! $doc ) { return false; }
  foreach ( array( $doc, realpath( ABSPATH ), realpath( WP_CONTENT_DIR ) ) as $public ) {
   if ( $public && ( $dir === $public || 0 === strpos( $dir . DIRECTORY_SEPARATOR, rtrim( $public, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) || 0 === strpos( $public . DIRECTORY_SEPARATOR, rtrim( $dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) ) { return false; }
  }
  $file = tempnam( $dir, 'ntkfile_' );
  if ( ! $file || ! self::remote_path_safe( $file ) || ! chmod( $file, 0600 ) ) { if ( $file ) { @unlink( $file ); } return false; }
  return $file;
 }
 public static function create_temp_file() { return self::temporary(); }
 private static function storage_ready( $f ) {
  if ( ! is_array( $f ) ) { return false; }
  if ( isset( $f['storage'] ) && 'smb' === $f['storage'] ) { return ! empty( $f['target'] ) && self::target() === $f['target'] && self::smb_ready(); }
  return (bool) self::root();
 }
 public static function is_storage_ready( $f ) { return self::storage_ready( $f ); }
 public static function archives( $id ) { $rows = get_post_meta( $id, self::ARCHIVE_META, false ); return is_array( $rows ) ? $rows : array(); }
 private static function remove_blob( $f ) {
  if ( ! is_array( $f ) || empty( $f['blob'] ) || ! self::valid_blob( $f['blob'] ) ) { return false; }
  if ( isset( $f['storage'] ) && 'smb' === $f['storage'] ) {
   if ( empty( $f['target'] ) || self::target() !== $f['target'] ) { return false; }
   $net = new WP_NTK_Network(); return $net->project_command( self::smb_dirs(), 'del "' . $f['blob'] . '"', false, $f['target'] );
  }
  $root = self::root(); if ( ! $root ) { return false; }
  $path = $root . DIRECTORY_SEPARATOR . $f['blob'];
  return ! is_link( $path ) && ( ! is_file( $path ) || @unlink( $path ) );
 }
 public static function rollback( $new ) { foreach ( $new as $f ) { if ( ! self::remove_blob( $f ) ) { error_log( 'WP NTK: не удалось удалить файл из закрытого хранилища.' ); } } }
 /** Возвращает путь к временному файлу SMB или локальному файлу старого формата. */
 public static function local_copy( $f, &$temporary ) {
  $temporary = false;
  if ( ! is_array( $f ) || empty( $f['blob'] ) || ! self::valid_blob( $f['blob'] ) ) { return false; }
  if ( isset( $f['storage'] ) && 'smb' === $f['storage'] ) {
   if ( empty( $f['target'] ) || self::target() !== $f['target'] || ! self::smb_ready( false, $f['target'] ) ) { return false; }
   $tmp = self::temporary(); if ( ! $tmp ) { return false; }
   $net = new WP_NTK_Network();
   if ( ! $net->project_command( self::smb_dirs(), 'get "' . $f['blob'] . '" "' . $tmp . '"', false, $f['target'] ) || ! is_file( $tmp ) || ! is_readable( $tmp ) ) { @unlink( $tmp ); return false; }
   $temporary = true; return $tmp;
  }
  $root = self::root(); if ( ! $root ) { return false; }
  $path = $root . DIRECTORY_SEPARATOR . $f['blob']; return is_file( $path ) && ! is_link( $path ) && is_readable( $path ) ? $path : false;
 }
 /** Удаление одного необязательного вложения; сохранение метаданных предшествует удалению копии. */
 public static function delete_one( $id, $key, $blob ) {
  $sections = self::sections();
  if ( ! isset( $sections[$key] ) || empty( $sections[$key]['active'] ) ) { return 'unavailable'; }
  if ( ! empty( $sections[$key]['required'] ) ) { return 'required'; }
  $previous = self::files( $id );
  if ( ! isset( $previous[$key] ) || ! is_array( $previous[$key] ) || ! isset( $previous[$key]['blob'] ) || $blob !== $previous[$key]['blob'] ) { return 'changed'; }
  $file = $previous[$key]; if ( ! self::storage_ready( $file ) ) { return 'storage'; }
  $remaining = $previous; unset( $remaining[$key] );
  // Старое значение обязательно: не затираем одновременное изменение другого поля.
  if ( false === update_post_meta( $id, self::META, wp_slash( $remaining ), $previous ) ) { return 'changed'; }
  do_action( 'wp_ntk_project_changed' );
  $removed = self::remove_blob( $file );
  if ( ! $removed ) { error_log( 'WP NTK: метаданные вложения удалены, но не удалось удалить файл из хранилища: ' . $file['blob'] ); }
  $sync = new WP_NTK_Project_Export( new WP_NTK_Network() ); $exported = $sync->sync_deleted_file();
  if ( ! $removed ) { return 'cleanup'; }
  return $exported ? 'deleted' : 'export_pending';
 }
 /** Проверяет и переносит загруженные файлы; при ошибке удаляет все новые файлы. */
 public static function prepare( $id ) {
  $current = self::files( $id ); $files = $current; $new = array(); $retired = array(); $archives = array(); $errors = array(); $sections = self::sections();
  foreach ( $sections as $key => $section ) {
   if ( empty( $section['active'] ) ) { continue; }
   $u = isset( $_FILES['wp_ntk_attachment'] ) && is_array( $_FILES['wp_ntk_attachment'] ) ? $_FILES['wp_ntk_attachment'] : array();
   $err = isset( $u['error'][$key] ) && is_int( $u['error'][$key] ) ? $u['error'][$key] : UPLOAD_ERR_NO_FILE;
   $remove = isset( $_POST['wp_ntk_remove_file'][$key] ) && '1' === $_POST['wp_ntk_remove_file'][$key];
   if ( UPLOAD_ERR_NO_FILE === $err ) { if ( $remove && isset( $files[$key] ) ) { if ( ! self::storage_ready( $files[$key] ) ) { $errors[] = 'Хранилище файла недоступно. Обратитесь к администратору.'; } else { $retired[] = $files[$key]; unset( $files[$key] ); } } }
   elseif ( UPLOAD_ERR_OK !== $err ) { $errors[] = 'Ошибка загрузки файла: ' . $section['name'] . '.'; }
   else {
    // Сохраняем исходное имя отдельно от случайного имени закрытого файла.
    $name = isset( $u['name'][$key] ) && is_string( $u['name'][$key] ) ? wp_check_invalid_utf8( wp_unslash( $u['name'][$key] ) ) : '';
    $name = preg_replace( '/[\x00-\x1f\x7f]/u', '', str_replace( array( '/', '\\' ), '_', $name ) );
    if ( ! is_string( $name ) || strlen( $name ) > 255 ) { $name = ''; }
    $tmp = isset( $u['tmp_name'][$key] ) && is_string( $u['tmp_name'][$key] ) ? $u['tmp_name'][$key] : '';
    $size = $tmp && is_uploaded_file( $tmp ) ? filesize( $tmp ) : false;
    $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ); if ( 'jpeg' === $ext ) { $ext = 'jpg'; }
    $old_unavailable = isset( $files[$key] ) && ! self::storage_ready( $files[$key] );
    $confirmation = isset( $_POST['wp_ntk_replace_unavailable'][$key] ) && is_string( $_POST['wp_ntk_replace_unavailable'][$key] ) ? wp_unslash( $_POST['wp_ntk_replace_unavailable'][$key] ) : '';
    $confirm = '' !== $confirmation && isset( $files[$key]['blob'] ) && $confirmation === $files[$key]['blob'];
    if ( '' !== $confirmation && ! $confirm ) { $errors[] = 'Прежний файл изменился. Обновите страницу и подтвердите замену заново.'; }
    elseif ( $old_unavailable && ! $confirm ) { $errors[] = 'Хранилище прежнего файла недоступно. Чтобы заменить файл в разделе «' . $section['name'] . '», подтвердите замену и снова выберите новый файл.'; }
    elseif ( ! $size || $size > (int) $section['max'] * 1048576 || $size > wp_max_upload_size() ) { $errors[] = 'Файл раздела «' . $section['name'] . '» пуст или превышает разрешённый размер.'; }
    elseif ( ! in_array( $ext, $section['types'], true ) || ! self::valid_file( $tmp, $ext ) ) { $errors[] = 'Недопустимый формат файла в разделе «' . $section['name'] . '». Разрешены: ' . strtoupper( implode( ', ', $section['types'] ) ) . '. Проверьте расширение и содержимое файла.'; }
    else {
     try { $blob = bin2hex( random_bytes( 20 ) ) . '.bin'; } catch ( Throwable $e ) { $blob = ''; }
     $record = array( 'blob' => $blob, 'storage' => 'smb', 'target' => self::target(), 'name' => $name, 'section' => $section['name'], 'ext' => $ext, 'size' => $size );
     $net = new WP_NTK_Network(); $dirs = self::smb_dirs();
     if ( ! $blob || ! $record['target'] || ! self::remote_path_safe( $tmp ) || ! self::smb_ready( true, $record['target'] ) || 'missing' !== $net->project_command( $dirs, 'ls "' . $blob . '"', true, $record['target'] ) ) { $errors[] = 'Сетевая папка для файлов недоступна. Обратитесь к администратору.'; }
     else {
      $new[] = $record;
      if ( ! $net->project_command( $dirs, 'put "' . $tmp . '" "' . $blob . '"', false, $record['target'] ) ) { $errors[] = 'Не удалось передать файл раздела «' . $section['name'] . '» в сетевую папку.'; }
      else {
       if ( isset( $files[$key]['blob'] ) ) {
        if ( $confirm ) { $archives[] = array( 'id' => wp_generate_uuid4(), 'section_key' => $key, 'section' => self::section_label( $key, $files[$key] ), 'archived_at' => gmdate( 'Y-m-d H:i:s' ), 'file' => $files[$key] ); }
        else { $retired[] = $files[$key]; }
       }
       $files[$key] = $record;
      }
     }
    }
   }
   if ( ! empty( $section['required'] ) && ! isset( $files[$key] ) ) { $errors[] = 'Прикрепите файл в разделе «' . $section['name'] . '».'; }
  }
  if ( $errors ) { self::rollback( $new ); return array( 'errors' => $errors ); }
  return array( 'errors' => array(), 'files' => $files, 'new' => $new, 'retired' => $retired, 'archives' => $archives, 'original' => $current );
 }
 public static function commit( $id, $prepared ) {
  $expected = $prepared['files']; $archive_ids = array();
  if ( ! empty( $prepared['archives'] ) ) {
   // До переключения на новый файл записываем информацию для восстановления прежнего.
   foreach ( $prepared['archives'] as $entry ) {
    $mid = add_post_meta( $id, self::ARCHIVE_META, wp_slash( $entry ) );
    if ( ! $mid ) { foreach ( $archive_ids as $saved_mid ) { delete_metadata_by_mid( 'post', $saved_mid ); } self::rollback( $prepared['new'] ); return false; }
    $archive_ids[] = $mid;
   }
   $updated = update_post_meta( $id, self::META, wp_slash( $expected ), $prepared['original'] );
   $observed = self::files( $id );
   if ( $observed !== $expected ) {
    if ( false === $updated && $observed === $prepared['original'] ) { foreach ( $archive_ids as $saved_mid ) { delete_metadata_by_mid( 'post', $saved_mid ); } self::rollback( $prepared['new'] ); }
    else { error_log( 'WP NTK: неоднозначное состояние при замене недоступного файла проекта ' . (int) $id . '; старые сведения и новые файлы сохранены для проверки.' ); }
    return false;
   }
  } else {
   update_post_meta( $id, self::META, wp_slash( $expected ) );
   if ( self::files( $id ) !== $expected ) { self::rollback( $prepared['new'] ); return false; }
  }
  foreach ( $prepared['retired'] as $f ) { if ( ! self::remove_blob( $f ) ) { error_log( 'WP NTK: не удалось удалить прежний файл проекта из хранилища.' ); } } return true;
 }
 public static function url( $id, $key ) { return wp_nonce_url( add_query_arg( array( 'action' => 'wp_ntk_project_download', 'project' => $id, 'section' => $key ), admin_url( 'admin-post.php' ) ), 'wp_ntk_file_' . $id . '_' . $key ); }
 public function download() {
  $id = isset( $_GET['project'] ) && is_string( $_GET['project'] ) && ctype_digit( $_GET['project'] ) ? (int) $_GET['project'] : 0;
  $key = isset( $_GET['section'] ) && is_string( $_GET['section'] ) ? wp_unslash( $_GET['section'] ) : '';
  if ( ! $id || ! preg_match( '/^f_[a-f0-9]{12}$/D', $key ) ) { wp_die( 'Файл недоступен.', '', array( 'response' => 404 ) ); }
  check_admin_referer( 'wp_ntk_file_' . $id . '_' . $key );
  $p = get_post( $id ); $admin = current_user_can( 'manage_options' );
  if ( ! $p || WP_NTK_Projects::TYPE !== $p->post_type || ! in_array( $p->post_status, array( 'private','trash' ), true ) || ! is_user_logged_in() || ( ! $admin && ( 'private' !== $p->post_status || (int) $p->post_author !== get_current_user_id() ) ) ) { wp_die( 'Файл недоступен.', '', array( 'response' => 403 ) ); }
  $files = self::files( $id ); $f = isset( $files[$key] ) && is_array( $files[$key] ) ? $files[$key] : array();
  $temporary = false; $path = self::local_copy( $f, $temporary );
  if ( ! $path ) { wp_die( 'Файл недоступен.', '', array( 'response' => 404 ) ); }
  $name = isset( $f['name'] ) ? sanitize_file_name( $f['name'] ) : 'file'; $name = str_replace( array( '"', "\r", "\n" ), '', $name );
  nocache_headers(); header( 'Content-Type: application/octet-stream' ); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Disposition: attachment; filename="download.' . ( isset( $f['ext'] ) && isset( self::TYPES[$f['ext']] ) ? $f['ext'] : 'bin' ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) ); header( 'Content-Length: ' . filesize( $path ) ); readfile( $path ); if ( $temporary ) { @unlink( $path ); } exit;
 }
 public function delete_project_files( $id, $post ) { if ( $post && WP_NTK_Projects::TYPE === $post->post_type ) { do_action( 'wp_ntk_project_changed' ); foreach ( self::files( $id ) as $f ) { if ( isset( $f['blob'] ) && ! self::remove_blob( $f ) ) { error_log( 'WP NTK: не удалось удалить файл удалённого проекта из хранилища.' ); } } } }
}
